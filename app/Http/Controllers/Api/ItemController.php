<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Item;
use App\Services\ItemService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected ItemService $itemService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Item::with(['company', 'category', 'type', 'unit', 'stockBalances']);

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('type_id')) {
            $query->where('type_id', $request->type_id);
        }

        if ($request->filled('search')) {
            $query->fuzzySearch($request->search);
            $query->orderBy('item_code', 'ASC')->orderBy('id', 'ASC');
        } else {
            $query->orderByRaw("SUBSTRING(COALESCE(item_code, ''), 1, 3) ASC, LENGTH(COALESCE(item_code, '')) ASC, item_code ASC, id ASC");
        }

        if ($request->boolean('all') || $request->per_page === 'all' || (int)$request->per_page === -1) {
            $items = $query->get();

            if ($request->filled('stock_status')) {
                $status = strtoupper($request->stock_status);
                $items = $items->filter(function ($item) use ($status) {
                    return $item->stock_status === $status;
                })->values();
            }

            return $this->successResponse($items, 'Daftar seluruh barang berhasil diambil.', 200, [
                'current_page' => 1,
                'per_page' => $items->count(),
                'total' => $items->count(),
                'last_page' => 1,
            ]);
        }

        $perPage = (int) ($request->per_page ?: 15);
        $items = $query->paginate($perPage);

        // Filter stock status if requested
        if ($request->filled('stock_status')) {
            $status = strtoupper($request->stock_status);
            $filteredCollection = $items->getCollection()->filter(function ($item) use ($status) {
                return $item->stock_status === $status;
            })->values();

            $items->setCollection($filteredCollection);
        }

        return $this->successResponse($items->items(), 'Daftar barang berhasil diambil.', 200, [
            'current_page' => $items->currentPage(),
            'per_page' => $items->perPage(),
            'total' => $items->total(),
            'last_page' => $items->lastPage(),
        ]);
    }

    public function checkDuplicate(Request $request): JsonResponse
    {
        $name = $request->query('name', '');
        if (strlen(trim($name)) < 2) {
            return $this->successResponse([], 'Nama terlalu pendek.');
        }

        $similar = $this->itemService->findSimilarItems($name);

        $results = $similar->map(function ($item) {
            return [
                'id' => $item->id,
                'item_code' => $item->item_code,
                'name' => $item->name,
                'company_id' => $item->company_id,
                'company_name' => $item->company?->name,
                'company_code' => $item->company?->code,
                'total_stock' => $item->total_stock,
                'unit' => $item->unit?->code,
            ];
        });

        return $this->successResponse($results, 'Pemeriksaan barang mirip selesai.');
    }

    public function searchQuick(Request $request): JsonResponse
    {
        $term = $request->query('term', '');
        $companyId = $request->query('company_id') ? (int) $request->query('company_id') : null;

        if (empty($term)) {
            return $this->successResponse([], 'Pencarian kosong.');
        }

        $items = $this->itemService->searchItem($term, $companyId);
        return $this->successResponse($items, 'Hasil pencarian barang.');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'name' => 'required|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'category_name' => 'nullable|string|max:100',
            'type_id' => 'nullable|exists:types,id',
            'type_name' => 'nullable|string|max:100',
            'unit_id' => 'nullable|exists:units,id',
            'unit_code' => 'nullable|string|max:20',
            'unit_name' => 'nullable|string|max:50',
            'item_code' => 'nullable|string|max:50|unique:items,item_code',
            'barcode' => 'nullable|string|max:100',
            'qr_code' => 'nullable|string|max:100|unique:items,qr_code',
            'minimum_stock' => 'nullable|numeric|min:0',
            'specification' => 'nullable|string',
            'description' => 'nullable|string',
            'generate_qr' => 'nullable|boolean',
        ]);

        $item = $this->itemService->createItem($validated, $request->user()?->id);

        return $this->successResponse($item, 'Barang baru berhasil ditambahkan.', 201);
    }

    public function show(Item $item): JsonResponse
    {
        $item->load([
            'company',
            'category',
            'type',
            'unit',
            'stockBalances.location.warehouse',
            'stockMovements.user',
            'parentItem',
            'materialRemnants',
            'priceHistories.user',
        ]);

        return $this->successResponse($item, 'Detail barang berhasil diambil.');
    }

    public function updatePurchasing(Request $request, Item $item): JsonResponse
    {
        $user = $request->user();
        $roleName = is_string($user?->role) ? $user->role : ($user?->role?->name ?? '');

        if ($roleName !== 'purchasing') {
            return $this->errorResponse('Akses ditolak: Hanya role Purchasing yang berhak menginput atau mengubah harga barang.', 403);
        }

        $request->validate([
            'purchase_price' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:255',
        ]);

        $oldPrice = (float) ($item->purchase_price ?? 0);
        $newPrice = max(0, (float) $request->input('purchase_price', 0));
        $notes = trim($request->input('notes', ''));

        $item->update([
            'purchase_price' => $newPrice,
        ]);

        // Sync unit_price on existing inventory batches for this item if price specified
        if ($newPrice > 0) {
            \App\Models\InventoryBatch::where('item_id', $item->id)
                ->where('unit_price', '<=', 0)
                ->update(['unit_price' => $newPrice]);
        }

        // Record history log in item_price_histories
        \App\Models\ItemPriceHistory::create([
            'item_id' => $item->id,
            'user_id' => $request->user()?->id,
            'old_price' => $oldPrice,
            'new_price' => $newPrice,
            'notes' => $notes ?: 'Pembaruan harga oleh Purchasing',
        ]);

        AuditLog::record('ITEM_PRICE_UPDATE', Item::class, $item->id, [
            'purchase_price' => $oldPrice,
        ], [
            'purchase_price' => $newPrice,
            'notes' => $notes,
        ], $request->user()?->id);

        return $this->successResponse($item->fresh(['company', 'category', 'type', 'unit', 'priceHistories.user']), 'Harga barang berhasil diperbarui.');
    }

    public function priceHistories(Request $request): JsonResponse
    {
        $query = \App\Models\ItemPriceHistory::with(['item.company', 'item.unit', 'user'])
            ->orderByDesc('created_at');

        if ($request->filled('item_id')) {
            $query->where('item_id', $request->item_id);
        }

        $perPage = (int) ($request->per_page ?: 25);
        $histories = $query->paginate($perPage);

        return $this->successResponse($histories->items(), 'Riwayat perubahan harga berhasil diambil.', 200, [
            'current_page' => $histories->currentPage(),
            'per_page' => $histories->perPage(),
            'total' => $histories->total(),
            'last_page' => $histories->lastPage(),
        ]);
    }

    public function update(Request $request, Item $item): JsonResponse
    {
        $user = $request->user();
        $roleName = is_string($user?->role) ? $user->role : ($user?->role?->name ?? '');

        if ($roleName === 'purchasing') {
            return $this->errorResponse('Akses ditolak: Role Purchasing tidak memiliki izin mengubah data master barang.', 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'type_id' => 'nullable|exists:types,id',
            'unit_id' => 'nullable|exists:units,id',
            'barcode' => 'nullable|string|max:100',
            'qr_code' => 'nullable|string|max:100|unique:items,qr_code,' . $item->id,
            'minimum_stock' => 'nullable|numeric|min:0',
            'specification' => 'nullable|string',
            'description' => 'nullable|string',
        ]);

        $old = $item->toArray();
        $item->update($validated);
        AuditLog::record('UPDATE_ITEM', Item::class, $item->id, $old, $item->toArray(), $request->user()?->id);

        return $this->successResponse($item->fresh(['company', 'category', 'type', 'unit']), 'Data barang berhasil diperbarui.');
    }

    public function destroy(Item $item, Request $request): JsonResponse
    {
        $old = $item->toArray();
        $item->delete();
        AuditLog::record('DELETE_ITEM', Item::class, $item->id, $old, null, $request->user()?->id);

        return $this->successResponse(null, 'Barang berhasil dihapus.');
    }
}
