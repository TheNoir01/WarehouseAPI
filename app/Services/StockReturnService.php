<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\MaterialRemnant;
use App\Models\StockIssue;
use App\Models\StockIssueItem;
use App\Models\StockReturn;
use App\Models\StockReturnItem;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockReturnService
{
    public function __construct(
        protected StockService $stockService
    ) {}

    public function generateReturnNumber(?string $date = null): string
    {
        $romanMonths = [
            1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI',
            7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII',
        ];

        $timestamp = !empty($date) ? strtotime($date) : time();
        if (!$timestamp) {
            $timestamp = time();
        }
        $monthNum = (int) date('n', $timestamp);
        $year = date('Y', $timestamp);
        $romanMonth = $romanMonths[$monthNum] ?? 'I';

        $prefix = "RET-{$romanMonth}-{$year}-";

        $maxSeq = 0;
        $regex = '/^' . preg_quote($prefix, '/') . '(\d+)$/';

        $existingNumbers = StockReturn::withTrashed()
            ->where('return_number', 'LIKE', "{$prefix}%")
            ->pluck('return_number');

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

    public function generateRemnantCode(string $parentCode): string
    {
        $lastRemnant = MaterialRemnant::where('remnant_code', 'LIKE', "{$parentCode}-S%")
            ->orderByDesc('id')
            ->first();

        $next = 1;
        if ($lastRemnant && preg_match('/-S(\d+)$/', $lastRemnant->remnant_code, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        return sprintf('%s-S%02d', $parentCode, $next);
    }

    /**
     * Process a return referencing an original StockIssue.
     */
    public function createReturn(array $data, array $items, array $attachments = [], ?int $userId = null): StockReturn
    {
        if (empty($items)) {
            throw new InvalidArgumentException('Transaksi pengembalian wajib memiliki minimal 1 item.');
        }

        $userId = $userId ?? auth('sanctum')->id() ?? auth()->id() ?? 1;

        return DB::transaction(function () use ($data, $items, $attachments, $userId) {
            $stockIssue = StockIssue::with('items.item')->findOrFail($data['stock_issue_id']);
            $returnNumber = !empty($data['return_number'])
                ? $data['return_number']
                : $this->generateReturnNumber($data['returned_date'] ?? null);

            $firstIssueItem = StockIssueItem::with('item')->find($items[0]['stock_issue_item_id'] ?? null);
            $firstAllocation = $firstIssueItem 
                ? \App\Models\StockIssueAllocation::where('stock_issue_item_id', $firstIssueItem->id)->first() 
                : null;

            $effectiveCompanyId = $stockIssue->company_id 
                ?: ($firstAllocation?->company_id ?? ($firstIssueItem?->item?->company_id ?? null));

            $return = StockReturn::create([
                'return_number' => $returnNumber,
                'stock_issue_id' => $stockIssue->id,
                'company_id' => $effectiveCompanyId,
                'returned_date' => $data['returned_date'] ?? now()->toDateString(),
                'returned_by_name' => $data['returned_by_name'],
                'received_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($items as $itemRow) {
                $issueItem = StockIssueItem::with('item')->findOrFail($itemRow['stock_issue_item_id']);
                
                if ($issueItem->stock_issue_id !== $stockIssue->id) {
                    throw new InvalidArgumentException("Item ID {$issueItem->id} tidak termasuk dalam pengeluaran {$stockIssue->issue_number}.");
                }

                $qtyReturned = (float) $itemRow['qty_returned'];
                if ($qtyReturned <= 0) {
                    throw new InvalidArgumentException("Qty pengembalian harus lebih dari 0.");
                }

                $remaining = $issueItem->remaining_qty;
                if ($qtyReturned > $remaining + 0.0001) {
                    throw new InvalidArgumentException(
                        "Qty kembali ({$qtyReturned}) melebihi sisa qty yang belum kembali ({$remaining}) untuk item '{$issueItem->item->name}'."
                    );
                }

                $locationId = $itemRow['warehouse_location_id'] ?? $issueItem->warehouse_location_id;
                $returnStatus = $itemRow['return_status'] ?? 'sisa';
                $condition = $itemRow['condition'] ?? 'good';

                // Cari alokasi pemotongan stok keluar (FIFO allocation) untuk mengetahui pemilik dan item fisik asalnya
                $allocations = \App\Models\StockIssueAllocation::where('stock_issue_item_id', $issueItem->id)
                    ->orderByDesc('id')
                    ->get();
                $firstAlloc = $allocations->first();

                $itemCompanyId = (int) ($firstAlloc?->company_id ?: ($issueItem->item->company_id ?: ($stockIssue->company_id ?: 5)));
                $actualItem = ($firstAlloc && $firstAlloc->item_id) ? \App\Models\Item::find($firstAlloc->item_id) : $issueItem->item;
                $parentItem = $actualItem ?: $issueItem->item;

                $returnItem = StockReturnItem::create([
                    'stock_return_id' => $return->id,
                    'stock_issue_item_id' => $issueItem->id,
                    'item_id' => $parentItem->id,
                    'warehouse_location_id' => $locationId,
                    'qty_returned' => $qtyReturned,
                    'return_status' => $returnStatus,
                    'condition' => $condition,
                    'notes' => $itemRow['notes'] ?? null,
                ]);

                // Update issue item tracking
                $newQtyReturned = (float)$issueItem->qty_returned + $qtyReturned;
                $qtyUsed = isset($itemRow['qty_used']) ? (float)$itemRow['qty_used'] : (float)$issueItem->qty_used;
                $qtyLost = isset($itemRow['qty_lost']) ? (float)$itemRow['qty_lost'] : (float)$issueItem->qty_lost;

                $issueItem->update([
                    'qty_returned' => $newQtyReturned,
                    'qty_used' => $qtyUsed,
                    'qty_lost' => $qtyLost,
                ]);

                $isRemnantOrScrap = ($returnStatus === 'sisa_material')
                    || in_array(strtolower($condition), ['scrap', 'potongan', 'damaged', 'rusak']);

                // If sisa material/potongan/scrap, create MaterialRemnant record
                if ($isRemnantOrScrap || !empty($itemRow['material_remnant'])) {
                    $remnantData = $itemRow['material_remnant'] ?? [];
                    $remnantCode = !empty($remnantData['remnant_code']) 
                        ? $remnantData['remnant_code'] 
                        : $this->generateRemnantCode($parentItem->item_code);

                    MaterialRemnant::create([
                        'remnant_code' => $remnantCode,
                        'company_id' => $itemCompanyId,
                        'parent_item_id' => $parentItem->id,
                        'derived_item_id' => null,
                        'stock_issue_id' => $stockIssue->id,
                        'stock_return_item_id' => $returnItem->id,
                        'warehouse_location_id' => $locationId,
                        'shape_condition' => $remnantData['shape_condition'] ?? 'Sisa Potongan',
                        'dimension_description' => !empty($remnantData['dimension_description']) ? $remnantData['dimension_description'] : (!empty($itemRow['notes']) ? $itemRow['notes'] : 'Sisa Potongan Lapangan'),
                        'estimated_area' => $remnantData['estimated_area'] ?? null,
                        'estimated_weight' => $remnantData['estimated_weight'] ?? null,
                        'qty' => $qtyReturned,
                        'unit_id' => $parentItem->unit_id,
                        'status' => 'available',
                        'notes' => $remnantData['notes'] ?? $itemRow['notes'] ?? null,
                        'created_by' => $userId,
                    ]);
                }

                // HANYA barang yang kembali UTUH (kondisi baik / tidak jadi terpakai) yang menambah saldo stok gudang utama
                if (!$isRemnantOrScrap) {
                    $returnRemaining = $qtyReturned;
                    foreach ($allocations as $alloc) {
                        if ($returnRemaining <= 0) break;
                        $batch = \App\Models\InventoryBatch::find($alloc->batch_id);
                        if ($batch) {
                            $canRestore = min($returnRemaining, (float)$alloc->qty_deducted);
                            $batch->increment('qty_remaining', $canRestore);

                            // Increment stock balance directly for this batch's company & item
                            $this->stockService->moveStock(
                                companyId: $batch->company_id,
                                itemId: $batch->item_id,
                                locationId: $locationId,
                                type: 'RETURN',
                                refType: StockReturn::class,
                                refId: $return->id,
                                refNumber: $return->return_number,
                                qty: $canRestore,
                                notes: "Pengembalian No: {$return->return_number} ex OUT: {$stockIssue->issue_number} ({$returnStatus}) [Batch: {$batch->batch_number}]",
                                userId: $userId
                            );

                            $returnRemaining -= $canRestore;
                        }
                    }

                    if ($returnRemaining > 0) {
                        $firstBatch = \App\Models\InventoryBatch::where('item_id', $parentItem->id)
                            ->where('company_id', $itemCompanyId)
                            ->latest('id')
                            ->first();
                        if ($firstBatch) {
                            $firstBatch->increment('qty_remaining', $returnRemaining);
                        }
                        $this->stockService->moveStock(
                            companyId: $itemCompanyId,
                            itemId: $parentItem->id,
                            locationId: $locationId,
                            type: 'RETURN',
                            refType: StockReturn::class,
                            refId: $return->id,
                            refNumber: $return->return_number,
                            qty: $returnRemaining,
                            notes: "Pengembalian No: {$return->return_number} ex OUT: {$stockIssue->issue_number} ({$returnStatus})",
                            userId: $userId
                        );
                    }
                }
            }

            // Check if all items in the issue are fully settled
            $allSettled = true;
            $hasAnyReturn = false;
            foreach ($stockIssue->fresh()->items as $iItem) {
                if ($iItem->remaining_qty > 0.0001) {
                    $allSettled = false;
                }
                if ($iItem->qty_returned > 0) {
                    $hasAnyReturn = true;
                }
            }

            if ($allSettled) {
                $stockIssue->update(['status' => 'fully_returned']);
            } elseif ($hasAnyReturn) {
                $stockIssue->update(['status' => 'partially_returned']);
            }

            // Save attachments (photos)
            foreach ($attachments as $attachment) {
                Attachment::create([
                    'entity_type' => StockReturn::class,
                    'entity_id' => $return->id,
                    'file_path' => $attachment['file_path'],
                    'file_name' => $attachment['file_name'],
                    'mime_type' => $attachment['mime_type'],
                    'file_size' => $attachment['file_size'],
                    'attachment_type' => $attachment['attachment_type'] ?? 'photo_return',
                    'uploaded_by' => $userId,
                ]);
            }

            AuditLog::record('STOCK_RETURN', StockReturn::class, $return->id, null, [
                'return_number' => $return->return_number,
                'stock_issue' => $stockIssue->issue_number,
                'item_count' => count($items),
            ], $userId);

            return $return->load(['items.item', 'items.location', 'stockIssue', 'company', 'receivedBy', 'attachments']);
        });
    }
}
