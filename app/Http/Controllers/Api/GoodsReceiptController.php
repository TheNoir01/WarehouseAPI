<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Services\GoodsReceiptService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoodsReceiptController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected GoodsReceiptService $goodsReceiptService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = GoodsReceipt::with(['company', 'supplier', 'warehouse', 'receivedBy'])
            ->withCount('items')
            ->orderBy('id', 'desc');

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('received_date', [$request->start_date, $request->end_date]);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('receipt_number', 'LIKE', "%{$search}%")
                  ->orWhere('po_number', 'LIKE', "%{$search}%")
                  ->orWhere('delivery_order_number', 'LIKE', "%{$search}%")
                  ->orWhere('supplier_name', 'LIKE', "%{$search}%")
                  ->orWhereHas('supplier', function ($sq) use ($search) {
                      $sq->where('name', 'LIKE', "%{$search}%");
                  });
            });
        }

        $perPage = (int) ($request->per_page ?: 15);
        $receipts = $query->paginate($perPage);

        return $this->successResponse($receipts->items(), 'Daftar penerimaan barang berhasil diambil.', 200, [
            'current_page' => $receipts->currentPage(),
            'per_page' => $receipts->perPage(),
            'total' => $receipts->total(),
            'last_page' => $receipts->lastPage(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'supplier_name' => 'nullable|string|max:150',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'delivery_order_number' => 'nullable|string|max:100',
            'received_date' => 'nullable|date',
            'receipt_number' => 'nullable|string|max:50|unique:goods_receipts,receipt_number',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'nullable|exists:items,id',
            'items.*.warehouse_location_id' => 'nullable|exists:warehouse_locations,id',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.condition' => 'nullable|in:good,damaged,other',
            'items.*.notes' => 'nullable|string|max:255',
            'items.*.new_item' => 'nullable|array',
            'attachments' => 'nullable|array',
        ]);

        try {
            $receipt = $this->goodsReceiptService->createReceipt(
                data: $validated,
                items: $validated['items'],
                attachments: $validated['attachments'] ?? [],
                userId: $request->user()?->id
            );

            return $this->successResponse($receipt, 'Penerimaan barang berhasil disimpan dan stok telah bertambah.', 201);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), null, 422);
        }
    }

    public function updatePurchasing(Request $request, GoodsReceipt $goodsReceipt): JsonResponse
    {
        $validated = $request->validate([
            'po_number' => 'nullable|string|max:100',
            'items' => 'nullable|array',
            'items.*.id' => 'nullable|integer',
            'items.*.item_id' => 'nullable|integer',
            'items.*.unit_price' => 'required_with:items|numeric|min:0',
            'items.*.total_price' => 'nullable|numeric|min:0',
        ]);

        try {
            $receipt = $this->goodsReceiptService->updatePurchasingInfo(
                receipt: $goodsReceipt,
                data: $validated,
                userId: $request->user()?->id
            );

            return $this->successResponse($receipt, 'Nomor PO dan harga barang berhasil diperbarui.');
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), null, 422);
        }
    }

    public function show(GoodsReceipt $goodsReceipt): JsonResponse
    {
        $goodsReceipt->load([
            'company',
            'supplier',
            'warehouse',
            'receivedBy',
            'items.item.unit',
            'items.location',
            'attachments',
        ]);

        // Load purchasing audit logs for this specific goods receipt
        $purchasingLogs = \App\Models\AuditLog::with('user')
            ->where('entity_type', GoodsReceipt::class)
            ->where('entity_id', $goodsReceipt->id)
            ->where('action', 'PURCHASING_UPDATE')
            ->orderBy('id', 'desc')
            ->get();
        $goodsReceipt->purchasing_logs = $purchasingLogs;

        return $this->successResponse($goodsReceipt, 'Detail penerimaan barang berhasil diambil.');
    }

    public function purchasingHistory(Request $request): JsonResponse
    {
        $query = \App\Models\AuditLog::with(['user'])
            ->whereIn('action', ['PURCHASING_UPDATE', 'ITEM_PURCHASING_UPDATE'])
            ->orderBy('id', 'desc');

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('created_at', [$request->start_date . ' 00:00:00', $request->end_date . ' 23:59:59']);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('old_values', 'LIKE', "%{$search}%")
                  ->orWhere('new_values', 'LIKE', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('username', 'LIKE', "%{$search}%");
                  });
            });
        }

        $perPage = (int) ($request->per_page ?: 20);
        $paginated = $query->paginate($perPage);

        $receiptIds = [];
        $itemIds = [];
        foreach ($paginated->items() as $log) {
            if ($log->entity_type === GoodsReceipt::class || $log->entity_type === 'App\Models\GoodsReceipt') {
                $receiptIds[] = $log->entity_id;
            } elseif ($log->entity_type === \App\Models\Item::class || $log->entity_type === 'App\Models\Item') {
                $itemIds[] = $log->entity_id;
            }
        }

        $receipts = GoodsReceipt::with(['company', 'supplier'])->whereIn('id', array_unique($receiptIds))->get()->keyBy('id');
        $items = \App\Models\Item::with(['company', 'unit'])->whereIn('id', array_unique($itemIds))->get()->keyBy('id');

        $enriched = collect($paginated->items())->map(function ($log) use ($receipts, $items) {
            $entityData = null;
            if ($log->entity_type === GoodsReceipt::class || $log->entity_type === 'App\Models\GoodsReceipt') {
                $r = $receipts->get($log->entity_id);
                $entityData = [
                    'type' => 'goods_receipt',
                    'id' => $log->entity_id,
                    'receipt_number' => $r?->receipt_number,
                    'supplier_name' => $r?->supplier?->name ?? $r?->supplier_name ?? '-',
                    'company_code' => $r?->company?->code ?? '-',
                ];
            } elseif ($log->entity_type === \App\Models\Item::class || $log->entity_type === 'App\Models\Item') {
                $it = $items->get($log->entity_id);
                $entityData = [
                    'type' => 'item',
                    'id' => $log->entity_id,
                    'item_code' => $it?->item_code,
                    'name' => $it?->name,
                    'unit_code' => $it?->unit?->code ?? '-',
                    'company_code' => $it?->company?->code ?? '-',
                ];
            }

            return [
                'id' => $log->id,
                'action' => $log->action,
                'entity' => $entityData,
                'user' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'username' => $log->user->username,
                ] : null,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toISOString() ?? (string) $log->created_at,
            ];
        });

        return $this->successResponse($enriched, 'Riwayat perubahan purchasing berhasil diambil.', 200, [
            'current_page' => $paginated->currentPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
            'last_page' => $paginated->lastPage(),
        ]);
    }

    public function exportData(Request $request): JsonResponse
    {
        $query = GoodsReceipt::with([
            'company',
            'supplier',
            'warehouse',
            'receivedBy',
            'items.item.unit',
            'items.location',
        ])->orderBy('received_date', 'asc')->orderBy('id', 'asc');

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('received_date', [$request->start_date, $request->end_date]);
        }

        $receipts = $query->get();

        return $this->successResponse($receipts, 'Data ekspor penerimaan barang berhasil diambil.');
    }
}
