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
}
