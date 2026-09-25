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
        $query = Item::with(['company', 'category', 'type', 'unit', 'stockBalances.location'])
            ->orderByRaw("SUBSTRING(COALESCE(item_code, ''), 1, 3) ASC, LENGTH(COALESCE(item_code, '')) ASC, item_code ASC, id ASC");

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
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('item_code', 'LIKE', "%{$search}%")
                  ->orWhere('barcode', 'LIKE', "%{$search}%")
                  ->orWhere('qr_code', 'LIKE', "%{$search}%");
            });
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
        ]);

        return $this->successResponse($item, 'Detail barang berhasil diambil.');
    }

    public function update(Request $request, Item $item): JsonResponse
    {
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

        return $this->successResponse($item->fresh(['company', 'category', 'type', 'unit']), 'Barang berhasil diperbarui.');
    }

    public function destroy(Item $item, Request $request): JsonResponse
    {
        $old = $item->toArray();
        $item->delete();
        AuditLog::record('DELETE_ITEM', Item::class, $item->id, $old, null, $request->user()?->id);

        return $this->successResponse(null, 'Barang berhasil dihapus.');
    }
}
