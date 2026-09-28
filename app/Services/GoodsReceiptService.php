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
                'po_number' => $data['po_number'] ?? null,
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

                $unitPrice = isset($itemRow['unit_price']) ? max(0, (float) $itemRow['unit_price']) : 0.0;
                $totalPrice = isset($itemRow['total_price']) ? (float) $itemRow['total_price'] : round($unitPrice * $qty, 2);

                $locationId = !empty($itemRow['warehouse_location_id']) ? $itemRow['warehouse_location_id'] : (\App\Models\WarehouseLocation::first()?->id ?? 1);

                $receiptItem = GoodsReceiptItem::create([
                    'goods_receipt_id' => $receipt->id,
                    'item_id' => $itemId,
                    'warehouse_location_id' => $locationId,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
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
                    'unit_price' => $unitPrice,
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
     * Update PO number and item pricing (unit_price & total_price) for an existing GoodsReceipt.
     * Note: received_date and inventory_batches.received_at are strictly immutable to preserve FIFO order!
     */
    public function updatePurchasingInfo(GoodsReceipt $receipt, array $data, ?int $userId = null): GoodsReceipt
    {
        $userId = $userId ?? auth('sanctum')->id() ?? auth()->id() ?? 1;

        return DB::transaction(function () use ($receipt, $data, $userId) {
            $oldPo = $receipt->po_number;
            $newPo = isset($data['po_number']) ? trim($data['po_number']) : $receipt->po_number;

            $receipt->update([
                'po_number' => $newPo,
            ]);

            // Update items pricing if provided
            if (!empty($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $itemData) {
                    $receiptItem = null;
                    if (!empty($itemData['id'])) {
                        $receiptItem = GoodsReceiptItem::where('goods_receipt_id', $receipt->id)
                            ->where('id', $itemData['id'])
                            ->first();
                    } elseif (!empty($itemData['item_id'])) {
                        $receiptItem = GoodsReceiptItem::where('goods_receipt_id', $receipt->id)
                            ->where('item_id', $itemData['item_id'])
                            ->first();
                    }

                    if ($receiptItem) {
                        $unitPrice = isset($itemData['unit_price']) ? max(0, (float) $itemData['unit_price']) : (float) $receiptItem->unit_price;
                        $totalPrice = isset($itemData['total_price']) ? (float) $itemData['total_price'] : round($unitPrice * (float) $receiptItem->qty, 2);

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

            AuditLog::record('PURCHASING_UPDATE', GoodsReceipt::class, $receipt->id, [
                'po_number' => $oldPo,
            ], [
                'po_number' => $newPo,
                'updated_by' => $userId,
            ], $userId);

            return $receipt->fresh()->load(['items.item', 'items.location', 'company', 'supplier', 'warehouse', 'receivedBy', 'attachments']);
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
     * Correct an existing GoodsReceipt (e.g. wrong Qty, wrong Item selected, wrong Location).
     * Strictly preserves original received_at so FIFO order remains intact.
     */
    public function correctReceipt(GoodsReceipt $receipt, array $data, array $itemsData, ?int $userId = null): GoodsReceipt
    {
        $userId = $userId ?? auth('sanctum')->id() ?? auth()->id() ?? 1;

        return DB::transaction(function () use ($receipt, $data, $itemsData, $userId) {
            $companyId = $receipt->company_id;
            $oldReceiptData = $receipt->load('items')->toArray();

            // 1. Update header fields
            $updateHeader = [];
            if (array_key_exists('delivery_order_number', $data)) {
                $updateHeader['delivery_order_number'] = trim((string) $data['delivery_order_number']) ?: null;
            }
            if (array_key_exists('supplier_id', $data)) {
                $updateHeader['supplier_id'] = $data['supplier_id'] ?: null;
            }
            if (array_key_exists('supplier_name', $data)) {
                $updateHeader['supplier_name'] = trim((string) $data['supplier_name']) ?: null;
            }
            if (array_key_exists('notes', $data)) {
                $updateHeader['notes'] = trim((string) $data['notes']) ?: null;
            }
            if (!empty($updateHeader)) {
                $receipt->update($updateHeader);
            }

            // 2. Process Items corrections
            $existingItems = $receipt->items()->get()->keyBy('id');
            $processedItemIds = [];

            foreach ($itemsData as $idx => $row) {
                $receiptItemId = !empty($row['id']) ? (int) $row['id'] : null;
                $newItemId = (int) ($row['item_id'] ?? 0);
                $newQty = (float) ($row['qty'] ?? 0);
                $newLocationId = !empty($row['warehouse_location_id']) ? (int) $row['warehouse_location_id'] : ($receipt->warehouse_id ?? 1);
                $condition = $row['condition'] ?? 'good';
                $notes = $row['notes'] ?? null;

                if ($newItemId <= 0) {
                    throw new InvalidArgumentException("Item barang wajib dipilih.");
                }
                if ($newQty <= 0) {
                    throw new InvalidArgumentException("Jumlah (Qty) barang harus lebih dari 0.");
                }

                $itemModel = Item::findOrFail($newItemId);
                if ($itemModel->company_id !== $companyId) {
                    throw new InvalidArgumentException("Barang '{$itemModel->name}' bukan milik PT yang sesuai.");
                }

                if ($receiptItemId && isset($existingItems[$receiptItemId])) {
                    $existingItem = $existingItems[$receiptItemId];
                    $processedItemIds[] = $receiptItemId;

                    $oldItemId = (int) $existingItem->item_id;
                    $oldLocationId = (int) $existingItem->warehouse_location_id;
                    $oldQty = (float) $existingItem->qty;
                    $unitPrice = (float) $existingItem->unit_price;

                    // Find corresponding batch
                    $batch = \App\Models\InventoryBatch::where('source_type', GoodsReceipt::class)
                        ->where('source_id', $receipt->id)
                        ->where('item_id', $oldItemId)
                        ->lockForUpdate()
                        ->first();

                    $qtyUsed = 0.0;
                    if ($batch) {
                        $qtyUsed = max(0, (float) $batch->qty_initial - (float) $batch->qty_remaining);
                    }

                    // If item changed
                    if ($oldItemId !== $newItemId) {
                        if ($qtyUsed > 0) {
                            throw new \RuntimeException(
                                "Barang '{$existingItem->item?->name}' sudah terpakai sebanyak " . number_format($qtyUsed, 2, ',', '.') . " di transaksi barang keluar. Tidak dapat mengganti jenis barang ini."
                            );
                        }

                        // Revert old item stock and delete old batch
                        $this->stockService->moveStock(
                            companyId: $companyId,
                            itemId: $oldItemId,
                            locationId: $oldLocationId,
                            type: 'ADJUSTMENT',
                            refType: GoodsReceipt::class,
                            refId: $receipt->id,
                            refNumber: $receipt->receipt_number,
                            qty: -$oldQty,
                            notes: "Koreksi Dokumen {$receipt->receipt_number}: Penggantian barang salah input",
                            userId: $userId
                        );
                        if ($batch) {
                            $batch->delete();
                        }

                        // Add new item stock and create new batch with ORIGINAL received_at to preserve FIFO
                        $originalReceivedAt = $batch?->received_at ?? ($receipt->received_date ? \Carbon\Carbon::parse($receipt->received_date) : now());
                        $batchNumber = sprintf('BATCH-GR-%06d-%03d', $receipt->id, $idx + 1);
                        \App\Models\InventoryBatch::create([
                            'batch_number' => $batchNumber,
                            'item_id' => $newItemId,
                            'company_id' => $companyId,
                            'warehouse_location_id' => $newLocationId,
                            'qty_initial' => $newQty,
                            'qty_remaining' => $newQty,
                            'unit_price' => $unitPrice,
                            'received_at' => $originalReceivedAt, // PRESERVE FIFO!
                            'source_type' => GoodsReceipt::class,
                            'source_id' => $receipt->id,
                            'notes' => "Penerimaan No: {$receipt->receipt_number} (Koreksi Barang)",
                        ]);

                        $this->stockService->moveStock(
                            companyId: $companyId,
                            itemId: $newItemId,
                            locationId: $newLocationId,
                            type: 'ADJUSTMENT',
                            refType: GoodsReceipt::class,
                            refId: $receipt->id,
                            refNumber: $receipt->receipt_number,
                            qty: $newQty,
                            notes: "Koreksi Dokumen {$receipt->receipt_number}: Barang pengganti yang benar",
                            userId: $userId
                        );

                        $existingItem->update([
                            'item_id' => $newItemId,
                            'warehouse_location_id' => $newLocationId,
                            'qty' => $newQty,
                            'total_price' => round($unitPrice * $newQty, 2),
                            'condition' => $condition,
                            'notes' => $notes,
                        ]);
                    } else {
                        // Same item, adjust qty or location
                        if ($newQty < $qtyUsed) {
                            throw new \RuntimeException(
                                "Qty baru (" . number_format($newQty, 2, ',', '.') . ") tidak boleh kurang dari jumlah yang sudah terpakai di transaksi barang keluar (" . number_format($qtyUsed, 2, ',', '.') . ")."
                            );
                        }

                        $qtyDiff = $newQty - $oldQty;

                        if ($oldLocationId !== $newLocationId) {
                            $this->stockService->moveStock(
                                companyId: $companyId,
                                itemId: $oldItemId,
                                locationId: $oldLocationId,
                                type: 'ADJUSTMENT',
                                refType: GoodsReceipt::class,
                                refId: $receipt->id,
                                refNumber: $receipt->receipt_number,
                                qty: -$oldQty,
                                notes: "Koreksi Dokumen {$receipt->receipt_number}: Pindah lokasi rak",
                                userId: $userId
                            );
                            $this->stockService->moveStock(
                                companyId: $companyId,
                                itemId: $oldItemId,
                                locationId: $newLocationId,
                                type: 'ADJUSTMENT',
                                refType: GoodsReceipt::class,
                                refId: $receipt->id,
                                refNumber: $receipt->receipt_number,
                                qty: $newQty,
                                notes: "Koreksi Dokumen {$receipt->receipt_number}: Lokasi rak baru",
                                userId: $userId
                            );

                            if ($batch) {
                                $batch->update([
                                    'warehouse_location_id' => $newLocationId,
                                    'qty_initial' => $newQty,
                                    'qty_remaining' => (float) $batch->qty_remaining + $qtyDiff,
                                ]);
                            }
                        } else {
                            if (abs($qtyDiff) > 0.0001) {
                                $this->stockService->moveStock(
                                    companyId: $companyId,
                                    itemId: $oldItemId,
                                    locationId: $oldLocationId,
                                    type: 'ADJUSTMENT',
                                    refType: GoodsReceipt::class,
                                    refId: $receipt->id,
                                    refNumber: $receipt->receipt_number,
                                    qty: $qtyDiff,
                                    notes: "Koreksi Dokumen {$receipt->receipt_number}: Qty disesuaikan dari " . number_format($oldQty, 2, ',', '.') . " ke " . number_format($newQty, 2, ',', '.'),
                                    userId: $userId
                                );

                                if ($batch) {
                                    $batch->update([
                                        'qty_initial' => $newQty,
                                        'qty_remaining' => (float) $batch->qty_remaining + $qtyDiff,
                                    ]);
                                }
                            }
                        }

                        $existingItem->update([
                            'warehouse_location_id' => $newLocationId,
                            'qty' => $newQty,
                            'total_price' => round($unitPrice * $newQty, 2),
                            'condition' => $condition,
                            'notes' => $notes,
                        ]);
                    }
                } else {
                    // Added a new item row
                    $unitPrice = isset($row['unit_price']) ? max(0, (float) $row['unit_price']) : (float) ($itemModel->purchase_price ?? 0);
                    $totalPrice = round($unitPrice * $newQty, 2);

                    GoodsReceiptItem::create([
                        'goods_receipt_id' => $receipt->id,
                        'item_id' => $newItemId,
                        'warehouse_location_id' => $newLocationId,
                        'qty' => $newQty,
                        'unit_price' => $unitPrice,
                        'total_price' => $totalPrice,
                        'condition' => $condition,
                        'notes' => $notes,
                    ]);

                    $originalReceivedAt = $receipt->received_date ? \Carbon\Carbon::parse($receipt->received_date) : now();
                    $batchNumber = sprintf('BATCH-GR-%06d-%03d', $receipt->id, $idx + 1);
                    \App\Models\InventoryBatch::create([
                        'batch_number' => $batchNumber,
                        'item_id' => $newItemId,
                        'company_id' => $companyId,
                        'warehouse_location_id' => $newLocationId,
                        'qty_initial' => $newQty,
                        'qty_remaining' => $newQty,
                        'unit_price' => $unitPrice,
                        'received_at' => $originalReceivedAt, // PRESERVE FIFO!
                        'source_type' => GoodsReceipt::class,
                        'source_id' => $receipt->id,
                        'notes' => "Penerimaan No: {$receipt->receipt_number} (Item Tambahan)",
                    ]);

                    $this->stockService->moveStock(
                        companyId: $companyId,
                        itemId: $newItemId,
                        locationId: $newLocationId,
                        type: 'ADJUSTMENT',
                        refType: GoodsReceipt::class,
                        refId: $receipt->id,
                        refNumber: $receipt->receipt_number,
                        qty: $newQty,
                        notes: "Koreksi Dokumen {$receipt->receipt_number}: Penambahan item baru",
                        userId: $userId
                    );
                }
            }

            // Remove any items that were removed
            foreach ($existingItems as $existingId => $existingItem) {
                if (!in_array($existingId, $processedItemIds)) {
                    $batch = \App\Models\InventoryBatch::where('source_type', GoodsReceipt::class)
                        ->where('source_id', $receipt->id)
                        ->where('item_id', $existingItem->item_id)
                        ->lockForUpdate()
                        ->first();

                    $qtyUsed = 0.0;
                    if ($batch) {
                        $qtyUsed = max(0, (float) $batch->qty_initial - (float) $batch->qty_remaining);
                    }

                    if ($qtyUsed > 0) {
                        throw new \RuntimeException(
                            "Item '{$existingItem->item?->name}' tidak dapat dihapus karena sudah ada " . number_format($qtyUsed, 2, ',', '.') . " yang keluar ke produksi."
                        );
                    }

                    $this->stockService->moveStock(
                        companyId: $companyId,
                        itemId: $existingItem->item_id,
                        locationId: $existingItem->warehouse_location_id,
                        type: 'ADJUSTMENT',
                        refType: GoodsReceipt::class,
                        refId: $receipt->id,
                        refNumber: $receipt->receipt_number,
                        qty: -(float) $existingItem->qty,
                        notes: "Koreksi Dokumen {$receipt->receipt_number}: Hapus item salah input",
                        userId: $userId
                    );

                    if ($batch) {
                        $batch->delete();
                    }

                    $existingItem->delete();
                }
            }

            AuditLog::record('GOODS_RECEIPT_CORRECTION', GoodsReceipt::class, $receipt->id, $oldReceiptData, $receipt->fresh('items')->toArray(), $userId);

            return $receipt->fresh(['items.item', 'items.location', 'company', 'supplier', 'warehouse', 'receivedBy', 'attachments']);
        });
    }
}
