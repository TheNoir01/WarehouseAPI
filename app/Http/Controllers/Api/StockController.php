<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockController extends Controller
{
    use ApiResponse;

    /**
     * Stock balances list with PT separation and location details.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Item::with(['company', 'unit', 'category', 'stockBalances.location.warehouse'])
            ->orderByRaw("SUBSTRING(COALESCE(item_code, ''), 1, 3) ASC, LENGTH(COALESCE(item_code, '')) ASC, item_code ASC, id ASC");

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('item_id')) {
            $query->where('id', $request->item_id);
        }

        if ($request->filled('warehouse_location_id')) {
            $query->whereHas('stockBalances', function ($q) use ($request) {
                $q->where('warehouse_location_id', $request->warehouse_location_id);
            });
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('item_code', 'LIKE', "%{$search}%")
                  ->orWhere('specification', 'LIKE', "%{$search}%");
            });
        }

        $mapItemToBalance = function ($item) {
            $firstBalance = $item->stockBalances->first();
            $totalStock = (float) $item->total_stock;
            $lastUpdated = $item->stockBalances->max('updated_at') ?? $item->updated_at;

            return [
                'id' => $firstBalance?->id ?? $item->id,
                'item_id' => $item->id,
                'company_id' => $item->company_id,
                'warehouse_location_id' => $firstBalance?->warehouse_location_id ?? 1,
                'qty' => $totalStock,
                'stock_status' => $item->stock_status,
                'last_movement_at' => $lastUpdated ? $lastUpdated->toISOString() : null,
                'updated_at' => $lastUpdated ? $lastUpdated->toISOString() : null,
                'created_at' => $item->created_at ? $item->created_at->toISOString() : null,
                'company' => $item->company,
                'item' => $item,
                'location' => $firstBalance?->location ?? [
                    'id' => 1,
                    'code' => '-',
                    'zone' => '-',
                    'rack' => '-',
                    'shelf' => '-',
                    'warehouse' => ['name' => 'Gudang Pusat'],
                ],
            ];
        };

        if ($request->boolean('all') || $request->per_page === 'all' || (int)$request->per_page === -1) {
            $items = $query->get()->map($mapItemToBalance);
            return $this->successResponse($items, 'Data saldo stok seluruh barang berhasil diambil.');
        }

        $perPage = (int) ($request->per_page ?: 20);
        $paginated = $query->paginate($perPage);
        $items = collect($paginated->items())->map($mapItemToBalance);

        return $this->successResponse($items, 'Data saldo stok berhasil diambil.', 200, [
            'current_page' => $paginated->currentPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
            'last_page' => $paginated->lastPage(),
        ]);
    }

    /**
     * Detailed stock for a single item across locations and PTs.
     */
    public function showItemStock(Item $item): JsonResponse
    {
        $item->load(['company', 'unit', 'category', 'type']);

        $balances = StockBalance::with(['location.warehouse', 'company'])
            ->where('item_id', $item->id)
            ->get();

        $movements = StockMovement::with(['location', 'user', 'company'])
            ->where('item_id', $item->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return $this->successResponse([
            'item' => $item,
            'total_stock' => $item->total_stock,
            'stock_status' => $item->stock_status,
            'balances' => $balances,
            'recent_movements' => $movements,
        ], 'Detail stok barang berhasil diambil.');
    }

    /**
     * Stock movements history / mutasi.
     */
    public function movements(Request $request): JsonResponse
    {
        $query = StockMovement::with(['company', 'item.unit', 'location', 'user'])
            ->orderByDesc('id');

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->filled('item_id')) {
            $query->where('item_id', $request->item_id);
        }

        if ($request->filled('warehouse_location_id')) {
            $query->where('warehouse_location_id', $request->warehouse_location_id);
        }

        if ($request->filled('movement_type')) {
            $query->where('movement_type', strtoupper($request->movement_type));
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('created_at', [$request->start_date . ' 00:00:00', $request->end_date . ' 23:59:59']);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('reference_number', 'LIKE', "%{$search}%")
                  ->orWhereHas('item', function ($iq) use ($search) {
                      $iq->where('name', 'LIKE', "%{$search}%")
                         ->orWhere('item_code', 'LIKE', "%{$search}%");
                  });
            });
        }

        if ($request->boolean('all') || $request->per_page === 'all' || (int)$request->per_page === -1) {
            $movements = $query->get();
            return $this->successResponse($movements, 'Data mutasi stok berhasil diambil.');
        }

        $perPage = (int) ($request->per_page ?: 20);
        $movements = $query->paginate($perPage);

        return $this->successResponse($movements->items(), 'Data mutasi stok berhasil diambil.', 200, [
            'current_page' => $movements->currentPage(),
            'per_page' => $movements->perPage(),
            'total' => $movements->total(),
            'last_page' => $movements->lastPage(),
        ]);
    }

    /**
     * Scan identifier (QR, Barcode, or Item Code).
     */
    public function scan(string $code, Request $request): JsonResponse
    {
        $code = trim($code);

        $query = Item::with(['company', 'category', 'type', 'unit', 'stockBalances.location.warehouse'])
            ->where(function ($q) use ($code) {
                $q->where('qr_code', $code)
                  ->orWhere('barcode', $code)
                  ->orWhere('item_code', $code);
            });

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        $items = $query->get();

        if ($items->isEmpty()) {
            return $this->errorResponse("Barang dengan kode scan '{$code}' tidak ditemukan.", null, 404);
        }

        // Return first match or list if multiple PTs have items with matching code
        $data = $items->map(function ($item) {
            return [
                'id' => $item->id,
                'item_code' => $item->item_code,
                'name' => $item->name,
                'barcode' => $item->barcode,
                'qr_code' => $item->qr_code,
                'company_id' => $item->company_id,
                'company_name' => $item->company?->name,
                'company_code' => $item->company?->code,
                'category_name' => $item->category?->name,
                'type_name' => $item->type?->name,
                'unit_code' => $item->unit?->code,
                'unit_name' => $item->unit?->name,
                'total_stock' => $item->total_stock,
                'stock_status' => $item->stock_status,
                'minimum_stock' => (float) $item->minimum_stock,
                'locations' => $item->stockBalances->map(function ($b) {
                    return [
                        'location_id' => $b->warehouse_location_id,
                        'location_code' => $b->location?->code,
                        'qty' => (float) $b->qty,
                    ];
                }),
            ];
        });

        return $this->successResponse(
            $items->count() === 1 ? $data->first() : $data,
            'Barang berhasil ditemukan melalui scan.'
        );
    }
}
