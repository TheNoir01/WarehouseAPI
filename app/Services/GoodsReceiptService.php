<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Item;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class GoodsReceiptService
{
    public function __construct(
        protected StockService $stockService,
        protected ItemService $itemService
    ) {}

    public function generateReceiptNumber(): string
    {
        $year = date('Y');
        $last = GoodsReceipt::whereYear('created_at', $year)->orderByDesc('id')->first();
        $next = 1;
        if ($last && preg_match('/GR-\d+-(\d+)$/', $last->receipt_number, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }
        return sprintf('GR-%s-%06d', $year, $next);
    }

    /**
     * Process an incoming goods receipt.
     */
    public function createReceipt(array $data, array $items, array $attachments = [], ?int $userId = null): GoodsReceipt
    {
        if (empty($items)) {
            throw new InvalidArgumentException('Transaksi penerimaan barang wajib memiliki minimal 1 item.');
        }

        $userId = $userId ?? auth('sanctum')->id() ?? auth()->id() ?? 1;

        return DB::transaction(function () use ($data, $items, $attachments, $userId) {
            $receiptNumber = !empty($data['receipt_number']) ? $data['receipt_number'] : $this->generateReceiptNumber();
            $warehouseId = !empty($data['warehouse_id']) ? $data['warehouse_id'] : (Warehouse::first()?->id ?? 1);

            $receipt = GoodsReceipt::create([
                'receipt_number' => $receiptNumber,
                'company_id' => $data['company_id'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'supplier_name' => $data['supplier_name'] ?? null,
                'warehouse_id' => $warehouseId,
                'delivery_order_number' => $data['delivery_order_number'] ?? null,
                'received_date' => $data['received_date'] ?? now()->toDateString(),
                'received_by' => $userId,
                'status' => 'completed',
                'notes' => $data['notes'] ?? null,
            ]);

            // Resolve effective supplier name for merging into items description
            $effectiveSupplierName = trim($data['supplier_name'] ?? '');
            if ($effectiveSupplierName === '' && !empty($data['supplier_id'])) {
                $suppObj = Supplier::find($data['supplier_id']);
                $effectiveSupplierName = trim($suppObj?->name ?? '');
            }

            foreach ($items as $idx => $itemRow) {
                // If item doesn't exist yet, create it on the fly
                $itemId = $itemRow['item_id'] ?? null;
                if (empty($itemId) && !empty($itemRow['new_item'])) {
                    $newItemData = $itemRow['new_item'];
                    $newItemData['company_id'] = $data['company_id'];
                    $createdItem = $this->itemService->createItem($newItemData, $userId);
                    $itemId = $createdItem->id;
                }

                $qty = (float) $itemRow['qty'];
                if ($qty <= 0) {
                    throw new InvalidArgumentException("Qty barang harus lebih dari 0.");
                }

                $locationId = !empty($itemRow['warehouse_location_id']) ? $itemRow['warehouse_location_id'] : (\App\Models\WarehouseLocation::first()?->id ?? 1);

                $receiptItem = GoodsReceiptItem::create([
                    'goods_receipt_id' => $receipt->id,
                    'item_id' => $itemId,
                    'warehouse_location_id' => $locationId,
                    'qty' => $qty,
                    'condition' => $itemRow['condition'] ?? 'good',
                    'notes' => $itemRow['notes'] ?? null,
                ]);

                // Register incoming inventory batch for FIFO tracking
                $batchNumber = sprintf('BATCH-GR-%06d-%03d', $receipt->id, $idx + 1);
                \App\Models\InventoryBatch::create([
                    'batch_number' => $batchNumber,
                    'item_id' => $itemId,
                    'company_id' => $data['company_id'],
                    'warehouse_location_id' => $locationId,
                    'qty_initial' => $qty,
                    'qty_remaining' => $qty,
                    'received_at' => $receipt->received_date ? \Carbon\Carbon::parse($receipt->received_date) : now(),
                    'source_type' => GoodsReceipt::class,
                    'source_id' => $receipt->id,
                    'notes' => "Penerimaan No: {$receipt->receipt_number}",
                ]);

                // Mutate stock
                $this->stockService->moveStock(
                    companyId: $data['company_id'],
                    itemId: $itemId,
                    locationId: $locationId,
                    type: 'IN',
                    refType: GoodsReceipt::class,
                    refId: $receipt->id,
                    refNumber: $receipt->receipt_number,
                    qty: $qty,
                    notes: "Penerimaan No: {$receipt->receipt_number}" . (!empty($receipt->delivery_order_number) ? ", SJ: {$receipt->delivery_order_number}" : ""),
                    userId: $userId
                );

                // Update item description with supplier information if supplier is specified
                if (!empty($effectiveSupplierName) && $itemId) {
                    $itemObj = Item::find($itemId);
                    if ($itemObj) {
                        $this->recordSupplierToItem($itemObj, $effectiveSupplierName);
                    }
                }
            }

            // Save attachments
            foreach ($attachments as $attachment) {
                Attachment::create([
                    'entity_type' => GoodsReceipt::class,
                    'entity_id' => $receipt->id,
                    'file_path' => $attachment['file_path'],
                    'file_name' => $attachment['file_name'],
                    'mime_type' => $attachment['mime_type'],
                    'file_size' => $attachment['file_size'],
                    'attachment_type' => $attachment['attachment_type'] ?? 'document',
                    'uploaded_by' => $userId,
                ]);
            }

            AuditLog::record('GOODS_RECEIPT', GoodsReceipt::class, $receipt->id, null, [
                'receipt_number' => $receipt->receipt_number,
                'item_count' => count($items),
            ], $userId);

            return $receipt->load(['items.item', 'items.location', 'company', 'supplier', 'warehouse', 'receivedBy', 'attachments']);
        });
    }

    /**
     * Merge or record supplier name into the item's description without duplication.
     */
    protected function recordSupplierToItem(Item $item, string $supplierName): void
    {
        $currentDesc = (string) ($item->description ?? '');
        $supplierName = trim($supplierName);
        if ($supplierName === '') {
            return;
        }

        if (empty($currentDesc)) {
            $item->update(['description' => 'Supplier: ' . $supplierName]);
            return;
        }

        if (preg_match('/Supplier\s*:\s*([^|\n]+)/i', $currentDesc, $matches)) {
            $existingParts = array_map('trim', explode(',', $matches[1]));
            $exists = false;
            foreach ($existingParts as $ep) {
                if (strcasecmp($ep, $supplierName) === 0) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $existingParts[] = $supplierName;
                $newSupplierStr = 'Supplier: ' . implode(', ', array_filter($existingParts));
                $newDesc = str_replace($matches[0], $newSupplierStr, $currentDesc);
                $item->update(['description' => $newDesc]);
            }
        } else {
            $newDesc = trim($currentDesc) . ' | Supplier: ' . $supplierName;
            $item->update(['description' => $newDesc]);
        }
    }

    /**
     * Update PO number and item pricing (unit_price & total_price) for an existing GoodsReceipt.
     * Note: received_date and inventory_batches.received_at are strictly immutable to preserve FIFO order!
     */
    public function updatePurchasingInfo(GoodsReceipt $receipt, array $data, ?int $userId = null): GoodsReceipt
    {
        $userId = $userId ?? auth('sanctum')->id() ?? auth()->id() ?? 1;
        $user = \App\Models\User::with('role')->find($userId);
        $isPurchasing = ($user?->role?->name === 'purchasing');

        // Jika user adalah role purchasing dan dokumen sudah terkunci (atau edit count >= 3)
        if ($isPurchasing && ($receipt->is_purchasing_locked || (int) $receipt->purchasing_edit_count >= 3)) {
            throw new \Exception("Akses edit No. PO & Harga untuk dokumen [{$receipt->receipt_number}] telah terkunci karena telah diinput/diubah sebanyak 3 kali. Silakan hubungi Admin untuk membuka akses kembali.");
        }

        return DB::transaction(function () use ($receipt, $data, $userId, $isPurchasing) {
            $oldPo = $receipt->po_number;
            $newPo = isset($data['po_number']) ? trim($data['po_number']) : $receipt->po_number;

            $updatePayload = [
                'po_number' => $newPo,
            ];

            // Cek apakah sebelum update ini dokumen sudah memiliki No. PO atau harga
            $hasExistingPo = !empty(trim((string) $oldPo));
            $hasExistingPrice = $receipt->items()->where('unit_price', '>', 0)->exists();
            $alreadyHasData = ($hasExistingPo || $hasExistingPrice);

            // Jika belum ada No. PO & harga, ini penginputan awal (jangan masuk counting).
            // Counting hanya bertambah jika sudah ada No. PO / harga lalu diubah kembali.
            if ($isPurchasing && $alreadyHasData) {
                $newCount = ((int) $receipt->purchasing_edit_count) + 1;
                $updatePayload['purchasing_edit_count'] = $newCount;
                if ($newCount >= 3) {
                    $updatePayload['is_purchasing_locked'] = true;
                    $updatePayload['purchasing_locked_at'] = now();
                }
            }

            $receipt->update($updatePayload);

            $priceChangesOld = [];
            $priceChangesNew = [];

            // Update items pricing if provided
            if (!empty($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $itemData) {
                    $receiptItem = null;
                    if (!empty($itemData['id'])) {
                        $receiptItem = GoodsReceiptItem::with('item')->where('goods_receipt_id', $receipt->id)
                            ->where('id', $itemData['id'])
                            ->first();
                    } elseif (!empty($itemData['item_id'])) {
                        $receiptItem = GoodsReceiptItem::with('item')->where('goods_receipt_id', $receipt->id)
                            ->where('item_id', $itemData['item_id'])
                            ->first();
                    }

                    if ($receiptItem) {
                        $oldUnitPrice = (float) $receiptItem->unit_price;
                        $oldTotalPrice = (float) $receiptItem->total_price;
                        $unitPrice = isset($itemData['unit_price']) ? max(0, (float) $itemData['unit_price']) : (float) $receiptItem->unit_price;
                        $totalPrice = isset($itemData['total_price']) ? (float) $itemData['total_price'] : round($unitPrice * (float) $receiptItem->qty, 2);

                        if (abs($oldUnitPrice - $unitPrice) > 0.001 || abs($oldTotalPrice - $totalPrice) > 0.001) {
                            $itemName = $receiptItem->item?->name ?? "Item #{$receiptItem->item_id}";
                            $itemCode = $receiptItem->item?->item_code ?? '';
                            $priceChangesOld[] = [
                                'item_id' => $receiptItem->item_id,
                                'item_code' => $itemCode,
                                'item_name' => $itemName,
                                'unit_price' => $oldUnitPrice,
                                'total_price' => $oldTotalPrice,
                            ];
                            $priceChangesNew[] = [
                                'item_id' => $receiptItem->item_id,
                                'item_code' => $itemCode,
                                'item_name' => $itemName,
                                'unit_price' => $unitPrice,
                                'total_price' => $totalPrice,
                            ];

                            // Also record into ItemPriceHistory for persistent tracking
                            \App\Models\ItemPriceHistory::create([
                                'item_id' => $receiptItem->item_id,
                                'user_id' => $userId,
                                'old_price' => $oldUnitPrice,
                                'new_price' => $unitPrice,
                                'notes' => "Update Dokumen Penerimaan {$receipt->receipt_number}" . ($newPo ? " (No. PO: {$newPo})" : ''),
                            ]);
                        }

                        $receiptItem->update([
                            'unit_price' => $unitPrice,
                            'total_price' => $totalPrice,
                        ]);

                        // Update unit_price on corresponding inventory batches, preserving received_at!
                        \App\Models\InventoryBatch::where('source_type', GoodsReceipt::class)
                            ->where('source_id', $receipt->id)
                            ->where('item_id', $receiptItem->item_id)
                            ->update([
                                'unit_price' => $unitPrice,
                            ]);
                    }
                }
            }

            // Also sync po_number to items in this receipt if po_number is set
            if (!empty($newPo)) {
                $receiptItemIds = $receipt->items()->pluck('item_id')->filter()->unique()->toArray();
                if (!empty($receiptItemIds)) {
                    \App\Models\Item::whereIn('id', $receiptItemIds)->update(['po_number' => $newPo]);
                }
            }

            AuditLog::record('PURCHASING_UPDATE', GoodsReceipt::class, $receipt->id, [
                'po_number' => $oldPo,
                'items' => $priceChangesOld,
            ], [
                'po_number' => $newPo,
                'items' => $priceChangesNew,
                'updated_by' => $userId,
            ], $userId);

            return $receipt->fresh()->load(['items.item', 'items.location', 'company', 'supplier', 'warehouse', 'receivedBy', 'attachments']);
        });
    }

    /**
     * Unlock PO & price editing for a Goods Receipt (Admin / Maintenance action).
     */
    public function unlockPurchasingInfo(GoodsReceipt $receipt, int $adminUserId): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $adminUserId) {
            $oldCount = (int) $receipt->purchasing_edit_count;
            $wasLocked = (bool) $receipt->is_purchasing_locked;

            $receipt->update([
                'is_purchasing_locked' => false,
                'purchasing_edit_count' => 0,
                'purchasing_unlocked_at' => now(),
                'purchasing_unlocked_by' => $adminUserId,
            ]);

            AuditLog::record('PURCHASING_UNLOCK', GoodsReceipt::class, $receipt->id, [
                'previous_edit_count' => $oldCount,
                'was_locked' => $wasLocked,
            ], [
                'is_purchasing_locked' => false,
                'purchasing_edit_count' => 0,
                'unlocked_by' => $adminUserId,
            ], $adminUserId);

            return $receipt->fresh()->load(['items.item', 'items.location', 'company', 'supplier', 'warehouse', 'receivedBy', 'purchasingUnlockedBy', 'attachments']);
        });
    }
}
