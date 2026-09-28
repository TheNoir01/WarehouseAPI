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
            'po_number' => 'nullable|string|max:100',
            'received_date' => 'nullable|date',
            'receipt_number' => 'nullable|string|max:50|unique:goods_receipts,receipt_number',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'nullable|exists:items,id',
            'items.*.warehouse_location_id' => 'nullable|exists:warehouse_locations,id',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.total_price' => 'nullable|numeric|min:0',
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

    public function update(Request $request, GoodsReceipt $goodsReceipt): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'nullable|exists:suppliers,id',
            'supplier_name' => 'nullable|string|max:150',
            'delivery_order_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.id' => 'nullable|integer',
            'items.*.item_id' => 'required|exists:items,id',
            'items.*.warehouse_location_id' => 'nullable|exists:warehouse_locations,id',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.condition' => 'nullable|in:good,damaged,other',
            'items.*.notes' => 'nullable|string|max:255',
        ]);

        try {
            $receipt = $this->goodsReceiptService->correctReceipt(
                receipt: $goodsReceipt,
                data: [
                    'supplier_id' => $validated['supplier_id'] ?? null,
                    'supplier_name' => $validated['supplier_name'] ?? null,
                    'delivery_order_number' => $validated['delivery_order_number'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                ],
                itemsData: $validated['items'],
                userId: $request->user()?->id
            );

            return $this->successResponse($receipt, 'Penerimaan barang berhasil dikoreksi dan stok diperbarui tanpa mengubah antrean FIFO.');
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

        $batches = \App\Models\InventoryBatch::where('source_type', GoodsReceipt::class)
            ->where('source_id', $goodsReceipt->id)
            ->get()
            ->keyBy('item_id');

        $goodsReceipt->items->each(function ($item) use ($batches) {
            $batch = $batches->get($item->item_id);
            $item->batch = $batch ? [
                'id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'qty_initial' => (float) $batch->qty_initial,
                'qty_remaining' => (float) $batch->qty_remaining,
                'qty_used' => max(0, round((float) $batch->qty_initial - (float) $batch->qty_remaining, 4)),
                'received_at' => $batch->received_at,
            ] : null;
        });

        return $this->successResponse($goodsReceipt, 'Detail penerimaan barang berhasil diambil.');
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
