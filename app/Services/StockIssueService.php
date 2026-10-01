<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\StockIssue;
use App\Models\StockIssueItem;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockIssueService
{
    public function __construct(
        protected StockService $stockService
    ) {}

    public function generateIssueNumber(): string
    {
        $year = date('Y');
        $last = StockIssue::withTrashed()
            ->where('issue_number', 'LIKE', "OUT-{$year}-%")
            ->orderByDesc('id')
            ->first();

        $next = 1;
        if ($last && preg_match('/OUT-\d+-(\d+)$/', $last->issue_number, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        while (StockIssue::withTrashed()->where('issue_number', sprintf('OUT-%s-%06d', $year, $next))->exists()) {
            $next++;
        }

        return sprintf('OUT-%s-%06d', $year, $next);
    }

    /**
     * Process an outgoing stock issue.
     */
    public function createIssue(array $data, array $items, array $attachments = [], ?int $userId = null): StockIssue
    {
        if (empty($items)) {
            throw new InvalidArgumentException('Transaksi pengeluaran barang wajib memiliki minimal 1 item.');
        }

        $userId = $userId ?? auth('sanctum')->id() ?? auth()->id() ?? 1;

        return DB::transaction(function () use ($data, $items, $attachments, $userId) {
            $issueNumber = !empty($data['issue_number']) ? $data['issue_number'] : $this->generateIssueNumber();
            $companyId = !empty($data['company_id']) ? (int) $data['company_id'] : null;

            $issue = StockIssue::create([
                'issue_number' => $issueNumber,
                'company_id' => $companyId,
                'project_name' => $data['project_name'],
                'requester_name' => $data['requester_name'],
                'recipient_name' => $data['recipient_name'],
                'issued_date' => $data['issued_date'] ?? now()->toDateString(),
                'issued_by' => $userId,
                'status' => 'open',
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($items as $itemRow) {
                $itemId = (int) $itemRow['item_id'];
                $locationId = (int) $itemRow['warehouse_location_id'];
                $qtyRequested = (float) $itemRow['qty_issued'];

                if ($qtyRequested <= 0) {
                    throw new InvalidArgumentException("Qty pengeluaran harus lebih dari 0.");
                }

                $selectedItem = \App\Models\Item::findOrFail($itemId);

                // Find all matching item IDs across companies (for identical items in shared warehouse, e.g. same name)
                $normalizedName = strtolower(trim($selectedItem->name));
                $matchingItemIds = \App\Models\Item::whereRaw('LOWER(TRIM(name)) = ?', [$normalizedName])
                    ->pluck('id')
                    ->toArray();
                if (!in_array($itemId, $matchingItemIds)) {
                    $matchingItemIds[] = $itemId;
                }

                // Query inventory batches ordered by pure FIFO (received_at ASC, id ASC)
                $batchQuery = \App\Models\InventoryBatch::with(['company', 'item'])
                    ->whereIn('item_id', $matchingItemIds)
                    ->where('warehouse_location_id', $locationId)
                    ->where('qty_remaining', '>', 0);

                if ($companyId) {
                    $batchQuery->where('company_id', $companyId);
                }

                // Row-level lock to prevent race condition during deduction
                $batches = $batchQuery
                    ->orderBy('received_at', 'ASC')
                    ->orderBy('id', 'ASC')
                    ->lockForUpdate()
                    ->get();

                $totalAvailable = (float) $batches->sum('qty_remaining');
                if ($totalAvailable < $qtyRequested) {
                    throw new \RuntimeException(
                        "Stok fisik di rak ini tidak mencukupi untuk '{$selectedItem->name}'. Tersedia: " . number_format($totalAvailable, 2, ',', '.') . ", dibutuhkan: " . number_format($qtyRequested, 2, ',', '.')
                    );
                }

                $issueItem = StockIssueItem::create([
                    'stock_issue_id' => $issue->id,
                    'item_id' => $itemId,
                    'warehouse_location_id' => $locationId,
                    'qty_issued' => $qtyRequested,
                    'qty_used' => 0.00,
                    'qty_returned' => 0.00,
                    'qty_lost' => 0.00,
                    'notes' => $itemRow['notes'] ?? null,
                ]);

                // Greedily deduct from oldest batches (FIFO)
                $remainingNeeded = $qtyRequested;
                foreach ($batches as $batch) {
                    if ($remainingNeeded <= 0) {
                        break;
                    }

                    $availableInBatch = (float) $batch->qty_remaining;
                    $qtyToDeduct = min($remainingNeeded, $availableInBatch);
                    $newRemaining = $availableInBatch - $qtyToDeduct;

                    $batch->update([
                        'qty_remaining' => $newRemaining,
                    ]);

                    // Record allocation log for PT quota audit
                    \App\Models\StockIssueAllocation::create([
                        'stock_issue_id' => $issue->id,
                        'stock_issue_item_id' => $issueItem->id,
                        'batch_id' => $batch->id,
                        'item_id' => $batch->item_id,
                        'company_id' => $batch->company_id,
                        'warehouse_location_id' => $locationId,
                        'qty_deducted' => $qtyToDeduct,
                    ]);

                    // Deduct stock balance for this specific batch's company and item
                    $this->stockService->moveStock(
                        companyId: $batch->company_id,
                        itemId: $batch->item_id,
                        locationId: $locationId,
                        type: 'OUT',
                        refType: StockIssue::class,
                        refId: $issue->id,
                        refNumber: $issue->issue_number,
                        qty: -$qtyToDeduct,
                        notes: "Pengeluaran FIFO No: {$issue->issue_number}, Proyek: {$issue->project_name} [Batch: {$batch->batch_number}]",
                        userId: $userId
                    );

                    $remainingNeeded -= $qtyToDeduct;
                }
            }

            // Save attachments (photos of items, handover, recipient)
            foreach ($attachments as $attachment) {
                Attachment::create([
                    'entity_type' => StockIssue::class,
                    'entity_id' => $issue->id,
                    'file_path' => $attachment['file_path'],
                    'file_name' => $attachment['file_name'],
                    'mime_type' => $attachment['mime_type'],
                    'file_size' => $attachment['file_size'],
                    'attachment_type' => $attachment['attachment_type'] ?? 'photo_handover',
                    'uploaded_by' => $userId,
                ]);
            }

            AuditLog::record('GOODS_ISSUE', StockIssue::class, $issue->id, null, [
                'issue_number' => $issue->issue_number,
                'project_name' => $issue->project_name,
                'recipient_name' => $issue->recipient_name,
                'item_count' => count($items),
            ], $userId);

            return $issue->load([
                'items.item',
                'items.location',
                'company',
                'issuedBy',
                'attachments',
                'allocations.company',
                'allocations.batch',
                'allocations.item.unit',
            ]);
        });
    }
}
