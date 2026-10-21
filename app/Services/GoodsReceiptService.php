<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Company;
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

    public function generateReceiptNumber(?int $companyId = null, ?string $date = null): string
    {
        $romanMonths = [
            1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI',
            7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII',
        ];

        // 1. Resolve company code (e.g. KJG, LNP)
        $companyCode = 'KJG';
        if ($companyId) {
            $company = Company::find($companyId);
            if ($company && !empty($company->code)) {
                $companyCode = strtoupper(trim($company->code));
            }
        } else {
            $firstCompany = Company::first();
            if ($firstCompany && !empty($firstCompany->code)) {
                $companyCode = strtoupper(trim($firstCompany->code));
            }
        }
        $companyCode = preg_replace('/[^A-Z0-9]/', '', $companyCode);
        if ($companyCode === '') {
            $companyCode = 'KJG';
        }

        // 2. Resolve month in Roman numerals and 4-digit year from received_date (or current date)
        $timestamp = !empty($date) ? strtotime($date) : time();
        if (!$timestamp) {
            $timestamp = time();
        }
        $monthNum = (int) date('n', $timestamp);
        $year = date('Y', $timestamp);
        $romanMonth = $romanMonths[$monthNum] ?? 'I';

        // 3. Format prefix: [KODE_PT]-[BULAN_ROMAWI]-[TAHUN]- (e.g. KJG-IX-2026- or KJG-X-2026-)
        $prefix = "{$companyCode}-{$romanMonth}-{$year}-";

        // 4. Find the highest sequence number for this PT and month/year
        $maxSeq = 0;
        $regex = '/^' . preg_quote($prefix, '/') . '(\d+)$/';

        $existingNumbers = GoodsReceipt::withTrashed()
            ->where('receipt_number', 'LIKE', "{$prefix}%")
            ->pluck('receipt_number');

        foreach ($existingNumbers as $num) {
            if (preg_match($regex, $num, $matches)) {
                $seq = (int) $matches[1];
                if ($seq > $maxSeq) {
                    $maxSeq = $seq;
                }
            }
        }

        $nextSeq = $maxSeq + 1;
        return $prefix . $nextSeq;
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
            $receiptNumber = !empty($data['receipt_number'])
                ? $data['receipt_number']
                : $this->generateReceiptNumber(
                    companyId: !empty($data['company_id']) ? (int) $data['company_id'] : null,
                    date: $data['received_date'] ?? null
                );
            $warehouseId = !empty($data['warehouse_id']) ? $data['warehouse_id'] : (Warehouse::first()?->id ?? 1);

            $receipt = GoodsReceipt::create([
                'receipt_number' => $receiptNumber,
                'company_id' => $data['company_id'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'supplier_name' => $data['supplier_name'] ?? null,
                'warehouse_id' => $warehouseId,
                'delivery_order_number' => $data['delivery_order_number'] ?? null,
                'po_number' => !empty($data['po_number']) ? trim($data['po_number']) : null,
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

    /**
     * Update/correct an existing Goods Receipt (header and items).
     * Rule: If updated by role 'karyawan', increment karyawan_edit_count.
     * When karyawan_edit_count reaches 3, lock document (is_karyawan_locked = true).
     * Once locked, role 'karyawan' cannot update until unlocked by kepala_gudang, admin, or maintenance.
     */
    public function updateReceipt(GoodsReceipt $receipt, array $data, array $items, ?int $userId = null): GoodsReceipt
    {
        $userId = $userId ?? auth('sanctum')->id() ?? auth()->id() ?? 1;
        $user = \App\Models\User::with('role')->find($userId);
        $roleName = $user?->role?->name ?? '';
        $isKaryawan = ($roleName === 'karyawan');

        // Check if locked for karyawan
        if ($isKaryawan && ($receipt->is_karyawan_locked || (int) $receipt->karyawan_edit_count >= 3)) {
            throw new \Exception("Dokumen penerimaan [{$receipt->receipt_number}] telah TERKUNCI karena telah diubah sebanyak 3 kali oleh role Karyawan. Dokumen ini hanya bisa dibuka kembali oleh Kepala Gudang atau Admin.");
        }

        return DB::transaction(function () use ($receipt, $data, $items, $userId, $isKaryawan) {
            $updateReceiptPayload = [];

            if (array_key_exists('supplier_name', $data)) {
                $updateReceiptPayload['supplier_name'] = trim((string) $data['supplier_name']);
            }
            if (array_key_exists('supplier_id', $data)) {
                $updateReceiptPayload['supplier_id'] = $data['supplier_id'];
            }
            if (array_key_exists('delivery_order_number', $data)) {
                $updateReceiptPayload['delivery_order_number'] = trim((string) $data['delivery_order_number']);
            }
            if (array_key_exists('po_number', $data)) {
                $updateReceiptPayload['po_number'] = trim((string) $data['po_number']) ?: null;
            }
            if (array_key_exists('notes', $data)) {
                $updateReceiptPayload['notes'] = trim((string) $data['notes']);
            }

            // Karyawan edit counter
            if ($isKaryawan) {
                $newCount = ((int) $receipt->karyawan_edit_count) + 1;
                $updateReceiptPayload['karyawan_edit_count'] = $newCount;
                if ($newCount >= 3) {
                    $updateReceiptPayload['is_karyawan_locked'] = true;
                    $updateReceiptPayload['karyawan_locked_at'] = now();
                }
            }

            if (!empty($updateReceiptPayload)) {
                $receipt->update($updateReceiptPayload);
            }

            // Handle items correction
            $existingItems = $receipt->items()->with('item')->get()->keyBy('id');
            $submittedItemIds = [];
            $oldAuditItems = [];
            $newAuditItems = [];

            foreach ($items as $idx => $row) {
                $rowId = !empty($row['id']) ? (int) $row['id'] : null;
                $itemId = !empty($row['item_id']) ? (int) $row['item_id'] : null;
                $newQty = (float) ($row['qty'] ?? 0);
                if ($newQty <= 0) {
                    throw new InvalidArgumentException("Kuantitas barang harus lebih dari 0.");
                }
                $locationId = !empty($row['warehouse_location_id']) ? (int) $row['warehouse_location_id'] : (\App\Models\WarehouseLocation::first()?->id ?? 1);
                $condition = $row['condition'] ?? 'good';
                $notes = $row['notes'] ?? null;

                if ($rowId && $existingItems->has($rowId)) {
                    // Updating an existing line
                    $submittedItemIds[] = $rowId;
                    $receiptItem = $existingItems->get($rowId);
                    $oldQty = (float) $receiptItem->qty;
                    $oldLocationId = (int) $receiptItem->warehouse_location_id;
                    $oldCondition = $receiptItem->condition;

                    // Find corresponding InventoryBatch
                    $batch = \App\Models\InventoryBatch::where('source_type', GoodsReceipt::class)
                        ->where('source_id', $receipt->id)
                        ->where('item_id', $receiptItem->item_id)
                        ->first();

                    $qtyUsed = 0.0;
                    if ($batch) {
                        $qtyUsed = max(0, (float) $batch->qty_initial - (float) $batch->qty_remaining);
                    }

                    if ($newQty < $qtyUsed - 0.0001) {
                        $itemName = $receiptItem->item?->name ?? "Item #{$receiptItem->item_id}";
                        throw new InvalidArgumentException("Kuantitas barang [{$itemName}] tidak boleh kurang dari {$qtyUsed} karena {$qtyUsed} telah dikeluarkan pada transaksi keluar.");
                    }

                    $qtyDiff = $newQty - $oldQty;

                    if (abs($qtyDiff) > 0.0001 || $oldLocationId !== $locationId) {
                        if ($oldLocationId !== $locationId) {
                            // Reverse from old location
                            $this->stockService->moveStock(
                                companyId: $receipt->company_id,
                                itemId: $receiptItem->item_id,
                                locationId: $oldLocationId,
                                type: 'ADJUSTMENT',
                                refType: GoodsReceipt::class,
                                refId: $receipt->id,
                                refNumber: $receipt->receipt_number,
                                qty: -$oldQty,
                                notes: "Relokasi Koreksi Penerimaan No: {$receipt->receipt_number}",
                                userId: $userId
                            );
                            // Add to new location
                            $this->stockService->moveStock(
                                companyId: $receipt->company_id,
                                itemId: $receiptItem->item_id,
                                locationId: $locationId,
                                type: 'ADJUSTMENT',
                                refType: GoodsReceipt::class,
                                refId: $receipt->id,
                                refNumber: $receipt->receipt_number,
                                qty: $newQty,
                                notes: "Relokasi Koreksi Penerimaan No: {$receipt->receipt_number}",
                                userId: $userId
                            );
                        } else {
                            // Same location, adjust delta
                            $this->stockService->moveStock(
                                companyId: $receipt->company_id,
                                itemId: $receiptItem->item_id,
                                locationId: $locationId,
                                type: 'ADJUSTMENT',
                                refType: GoodsReceipt::class,
                                refId: $receipt->id,
                                refNumber: $receipt->receipt_number,
                                qty: $qtyDiff,
                                notes: "Koreksi Qty Penerimaan No: {$receipt->receipt_number}",
                                userId: $userId
                            );
                        }

                        // Update batch
                        if ($batch) {
                            $batch->warehouse_location_id = $locationId;
                            $batch->qty_initial = $newQty;
                            $batch->qty_remaining = max(0, (float) $batch->qty_remaining + $qtyDiff);
                            $batch->save();
                        }
                    }

                    // Calculate total price if unit price exists
                    $unitPrice = (float) $receiptItem->unit_price;
                    $totalPrice = round($unitPrice * $newQty, 2);

                    $receiptItem->update([
                        'qty' => $newQty,
                        'warehouse_location_id' => $locationId,
                        'condition' => $condition,
                        'notes' => $notes,
                        'total_price' => $totalPrice,
                    ]);

                    $oldAuditItems[] = [
                        'id' => $receiptItem->id,
                        'item_id' => $receiptItem->item_id,
                        'qty' => $oldQty,
                        'location_id' => $oldLocationId,
                    ];
                    $newAuditItems[] = [
                        'id' => $receiptItem->id,
                        'item_id' => $receiptItem->item_id,
                        'qty' => $newQty,
                        'location_id' => $locationId,
                    ];
                } else {
                    // New item added during edit
                    if (empty($itemId)) {
                        continue;
                    }

                    $receiptItem = GoodsReceiptItem::create([
                        'goods_receipt_id' => $receipt->id,
                        'item_id' => $itemId,
                        'warehouse_location_id' => $locationId,
                        'qty' => $newQty,
                        'condition' => $condition,
                        'notes' => $notes,
                    ]);

                    $batchNumber = sprintf('BATCH-GR-%06d-%03d', $receipt->id, $receipt->items()->count());
                    \App\Models\InventoryBatch::create([
                        'batch_number' => $batchNumber,
                        'item_id' => $itemId,
                        'company_id' => $receipt->company_id,
                        'warehouse_location_id' => $locationId,
                        'qty_initial' => $newQty,
                        'qty_remaining' => $newQty,
                        'received_at' => $receipt->received_date ? \Carbon\Carbon::parse($receipt->received_date) : now(),
                        'source_type' => GoodsReceipt::class,
                        'source_id' => $receipt->id,
                        'notes' => "Penerimaan No: {$receipt->receipt_number} (Tambahan)",
                    ]);

                    $this->stockService->moveStock(
                        companyId: $receipt->company_id,
                        itemId: $itemId,
                        locationId: $locationId,
                        type: 'IN',
                        refType: GoodsReceipt::class,
                        refId: $receipt->id,
                        refNumber: $receipt->receipt_number,
                        qty: $newQty,
                        notes: "Tambahan Item Koreksi No: {$receipt->receipt_number}",
                        userId: $userId
                    );

                    $newAuditItems[] = [
                        'id' => $receiptItem->id,
                        'item_id' => $itemId,
                        'qty' => $newQty,
                        'location_id' => $locationId,
                        'action' => 'added',
                    ];
                }
            }

            // Check for deleted items (in DB but not in submittedItemIds)
            foreach ($existingItems as $dbItemId => $existingItem) {
                if (!in_array($dbItemId, $submittedItemIds)) {
                    $batch = \App\Models\InventoryBatch::where('source_type', GoodsReceipt::class)
                        ->where('source_id', $receipt->id)
                        ->where('item_id', $existingItem->item_id)
                        ->first();

                    $qtyUsed = 0.0;
                    if ($batch) {
                        $qtyUsed = max(0, (float) $batch->qty_initial - (float) $batch->qty_remaining);
                    }

                    if ($qtyUsed > 0.0001) {
                        $itemName = $existingItem->item?->name ?? "Item #{$existingItem->item_id}";
                        throw new InvalidArgumentException("Barang [{$itemName}] tidak dapat dihapus dari penerimaan ini karena sudah digunakan/dikeluarkan sebanyak {$qtyUsed} pada transaksi barang keluar.");
                    }

                    $this->stockService->moveStock(
                        companyId: $receipt->company_id,
                        itemId: $existingItem->item_id,
                        locationId: $existingItem->warehouse_location_id,
                        type: 'ADJUSTMENT',
                        refType: GoodsReceipt::class,
                        refId: $receipt->id,
                        refNumber: $receipt->receipt_number,
                        qty: -((float) $existingItem->qty),
                        notes: "Hapus item pada Koreksi Penerimaan No: {$receipt->receipt_number}",
                        userId: $userId
                    );

                    if ($batch) {
                        $batch->delete();
                    }

                    $oldAuditItems[] = [
                        'id' => $existingItem->id,
                        'item_id' => $existingItem->item_id,
                        'qty' => (float) $existingItem->qty,
                        'action' => 'deleted',
                    ];

                    $existingItem->delete();
                }
            }

            AuditLog::record('GOODS_RECEIPT_CORRECTION', GoodsReceipt::class, $receipt->id, [
                'items' => $oldAuditItems,
            ], [
                'items' => $newAuditItems,
                'updated_by' => $userId,
                'karyawan_edit_count' => $receipt->karyawan_edit_count,
                'is_karyawan_locked' => $receipt->is_karyawan_locked,
            ], $userId);

            return $receipt->fresh()->load(['items.item.unit', 'items.location', 'company', 'supplier', 'warehouse', 'receivedBy', 'karyawanUnlockedBy', 'attachments']);
        });
    }

    /**
     * Unlock Karyawan edit for a Goods Receipt (Kepala Gudang / Admin / Maintenance action).
     */
    public function unlockKaryawanReceipt(GoodsReceipt $receipt, int $unlockedByUserId): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $unlockedByUserId) {
            $oldCount = (int) $receipt->karyawan_edit_count;
            $wasLocked = (bool) $receipt->is_karyawan_locked;

            $receipt->update([
                'is_karyawan_locked' => false,
                'karyawan_edit_count' => 0,
                'karyawan_unlocked_at' => now(),
                'karyawan_unlocked_by' => $unlockedByUserId,
            ]);

            AuditLog::record('GOODS_RECEIPT_UNLOCK', GoodsReceipt::class, $receipt->id, [
                'previous_edit_count' => $oldCount,
                'was_locked' => $wasLocked,
            ], [
                'is_karyawan_locked' => false,
                'karyawan_edit_count' => 0,
                'unlocked_by' => $unlockedByUserId,
            ], $unlockedByUserId);

            return $receipt->fresh()->load(['items.item.unit', 'items.location', 'company', 'supplier', 'warehouse', 'receivedBy', 'karyawanUnlockedBy', 'attachments']);
        });
    }
}
