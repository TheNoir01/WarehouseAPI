<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\ItemType;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MasterController extends Controller
{
    use ApiResponse;

    // --- CATEGORIES ---
    public function getCategories(): JsonResponse
    {
        $categories = Category::with('types')->withCount('items')->get();
        return $this->successResponse($categories, 'Daftar kategori berhasil diambil.');
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:categories,name',
            'minimum_stock' => 'nullable|numeric|min:0',
        ]);

        $category = Category::create([
            'name' => trim($validated['name']),
            'minimum_stock' => isset($validated['minimum_stock']) ? max(0, (float) $validated['minimum_stock']) : 0.00,
            'description' => null,
        ]);
        AuditLog::record('CREATE_CATEGORY', Category::class, $category->id, null, $category->toArray());

        return $this->successResponse($category, 'Kategori berhasil ditambahkan.', 201);
    }

    public function updateCategory(Request $request, Category $category): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:categories,name,' . $category->id,
            'minimum_stock' => 'nullable|numeric|min:0',
        ]);

        $old = $category->toArray();
        $updateData = [
            'name' => trim($validated['name']),
        ];
        if ($request->has('minimum_stock')) {
            $updateData['minimum_stock'] = max(0, (float) ($validated['minimum_stock'] ?? 0));
        }

        $category->update($updateData);
        AuditLog::record('UPDATE_CATEGORY', Category::class, $category->id, $old, $category->toArray());

        return $this->successResponse($category, 'Kategori berhasil diperbarui.');
    }

    public function destroyCategory(Category $category): JsonResponse
    {
        if ($category->items()->count() > 0) {
            return $this->errorResponse('Kategori tidak dapat dihapus karena masih digunakan oleh data barang.', 422);
        }

        $old = $category->toArray();
        $category->types()->delete();
        $category->delete();
        AuditLog::record('DELETE_CATEGORY', Category::class, $category->id, $old, null);

        return $this->successResponse(null, 'Kategori berhasil dihapus.');
    }

    // --- TYPES ---
    public function getTypes(Request $request): JsonResponse
    {
        $query = ItemType::with('category');
        if ($request->category_id) {
            $query->where('category_id', $request->category_id);
        }
        return $this->successResponse($query->get(), 'Daftar jenis barang berhasil diambil.');
    }

    public function storeType(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
        ]);

        $type = ItemType::firstOrCreate([
            'category_id' => $validated['category_id'],
            'name' => $validated['name'],
        ], [
            'description' => $validated['description'] ?? null,
        ]);

        return $this->successResponse($type, 'Jenis barang berhasil disimpan.', 201);
    }

    // --- UNITS ---
    public function getUnits(): JsonResponse
    {
        return $this->successResponse(Unit::all(), 'Daftar satuan berhasil diambil.');
    }

    public function storeUnit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:20|unique:units,code',
            'name' => 'required|string|max:50',
        ]);

        $unit = Unit::create([
            'code' => strtoupper(trim($validated['code'])),
            'name' => trim($validated['name']),
        ]);

        return $this->successResponse($unit, 'Satuan berhasil ditambahkan.', 201);
    }

    public function updateUnit(Request $request, Unit $unit): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:20|unique:units,code,' . $unit->id,
            'name' => 'required|string|max:50',
        ]);

        $old = $unit->toArray();
        $unit->update([
            'code' => strtoupper(trim($validated['code'])),
            'name' => trim($validated['name']),
        ]);
        AuditLog::record('UPDATE_UNIT', Unit::class, $unit->id, $old, $unit->toArray());

        return $this->successResponse($unit, 'Satuan berhasil diperbarui.');
    }

    public function destroyUnit(Unit $unit): JsonResponse
    {
        if ($unit->items()->count() > 0) {
            return $this->errorResponse('Satuan tidak dapat dihapus karena masih digunakan oleh data barang.', 422);
        }

        $old = $unit->toArray();
        $unit->delete();
        AuditLog::record('DELETE_UNIT', Unit::class, $unit->id, $old, null);

        return $this->successResponse(null, 'Satuan berhasil dihapus.');
    }

    // --- SUPPLIERS ---
    public function getSuppliers(): JsonResponse
    {
        return $this->successResponse(Supplier::all(), 'Daftar supplier berhasil diambil.');
    }

    public function storeSupplier(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:suppliers,code',
            'name' => 'required|string|max:150',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:100',
            'address' => 'nullable|string',
        ]);

        $supplier = Supplier::create($validated);
        AuditLog::record('CREATE_SUPPLIER', Supplier::class, $supplier->id, null, $supplier->toArray());

        return $this->successResponse($supplier, 'Supplier berhasil ditambahkan.', 201);
    }

    public function updateSupplier(Request $request, Supplier $supplier): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:suppliers,code,' . $supplier->id,
            'name' => 'required|string|max:150',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:100',
            'address' => 'nullable|string',
        ]);

        $old = $supplier->toArray();
        $supplier->update($validated);
        AuditLog::record('UPDATE_SUPPLIER', Supplier::class, $supplier->id, $old, $supplier->toArray());

        return $this->successResponse($supplier, 'Supplier berhasil diperbarui.');
    }

    public function destroySupplier(Supplier $supplier): JsonResponse
    {
        if ($supplier->goodsReceipts()->count() > 0) {
            return $this->errorResponse('Supplier tidak dapat dihapus karena masih memiliki riwayat transaksi penerimaan barang.', 422);
        }

        $old = $supplier->toArray();
        $supplier->delete();
        AuditLog::record('DELETE_SUPPLIER', Supplier::class, $supplier->id, $old, null);

        return $this->successResponse(null, 'Supplier berhasil dihapus.');
    }

    // --- WAREHOUSES & LOCATIONS ---
    public function getWarehouses(): JsonResponse
    {
        $warehouses = Warehouse::with('locations')->get();
        return $this->successResponse($warehouses, 'Daftar gudang berhasil diambil.');
    }

    public function storeWarehouse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:30|unique:warehouses,code',
            'name' => 'required|string|max:100',
            'address' => 'nullable|string',
        ]);

        $warehouse = Warehouse::create($validated);
        return $this->successResponse($warehouse, 'Gudang berhasil ditambahkan.', 201);
    }

    public function getLocations(Request $request): JsonResponse
    {
        $query = WarehouseLocation::with('warehouse');
        if ($request->warehouse_id) {
            $query->where('warehouse_id', $request->warehouse_id);
        }
        return $this->successResponse($query->get(), 'Daftar lokasi rak berhasil diambil.');
    }

    public function storeLocation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'code' => 'required|string|max:50|unique:warehouse_locations,code',
            'zone' => 'nullable|string|max:50',
            'rack' => 'nullable|string|max:50',
            'shelf' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:255',
        ]);

        $location = WarehouseLocation::create($validated);
        return $this->successResponse($location, 'Lokasi rak berhasil ditambahkan.', 201);
    }

    public function updateLocation(Request $request, WarehouseLocation $location): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'code' => 'required|string|max:50|unique:warehouse_locations,code,' . $location->id,
            'zone' => 'nullable|string|max:50',
            'rack' => 'nullable|string|max:50',
            'shelf' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:255',
        ]);

        $location->update($validated);
        return $this->successResponse($location, 'Lokasi rak berhasil diperbarui.');
    }

    public function destroyLocation(WarehouseLocation $location): JsonResponse
    {
        if ($location->stockBalances()->where('qty', '>', 0)->count() > 0) {
            return $this->errorResponse('Lokasi rak tidak dapat dihapus karena masih terdapat stok barang di lokasi ini.', 422);
        }

        $location->delete();
        return $this->successResponse(null, 'Lokasi rak berhasil dihapus.');
    }
}
