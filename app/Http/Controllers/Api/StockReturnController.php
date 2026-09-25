<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockReturn;
use App\Services\StockReturnService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockReturnController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected StockReturnService $stockReturnService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = StockReturn::with(['company', 'stockIssue', 'receivedBy'])
            ->withCount('items')
            ->orderBy('id', 'desc');

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->filled('stock_issue_id')) {
            $query->where('stock_issue_id', $request->stock_issue_id);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('return_number', 'LIKE', "%{$search}%")
                  ->orWhere('returned_by_name', 'LIKE', "%{$search}%")
                  ->orWhereHas('stockIssue', function ($sq) use ($search) {
                      $sq->where('issue_number', 'LIKE', "%{$search}%");
                  });
            });
        }

        $perPage = (int) ($request->per_page ?: 15);
        $returns = $query->paginate($perPage);

        return $this->successResponse($returns->items(), 'Daftar pengembalian barang berhasil diambil.', 200, [
            'current_page' => $returns->currentPage(),
            'per_page' => $returns->perPage(),
            'total' => $returns->total(),
            'last_page' => $returns->lastPage(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'stock_issue_id' => 'required|exists:stock_issues,id',
            'returned_by_name' => 'required|string|max:100',
            'returned_date' => 'nullable|date',
            'return_number' => 'nullable|string|max:50|unique:stock_returns,return_number',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.stock_issue_item_id' => 'required|exists:stock_issue_items,id',
            'items.*.warehouse_location_id' => 'nullable|exists:warehouse_locations,id',
            'items.*.qty_returned' => 'required|numeric|min:0.01',
            'items.*.qty_used' => 'nullable|numeric|min:0',
            'items.*.qty_lost' => 'nullable|numeric|min:0',
            'items.*.return_status' => 'required|in:sisa,kelebihan,tidak_terpakai,bekas,sisa_material,lainnya',
            'items.*.condition' => 'nullable|in:good,scrap,rework,damaged',
            'items.*.notes' => 'nullable|string|max:255',
            'items.*.material_remnant' => 'nullable|array',
            'attachments' => 'nullable|array',
        ]);

        try {
            $return = $this->stockReturnService->createReturn(
                data: $validated,
                items: $validated['items'],
                attachments: $validated['attachments'] ?? [],
                userId: $request->user()?->id
            );

            return $this->successResponse($return, 'Pengembalian barang berhasil diproses dan stok telah diperbarui.', 201);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), null, 422);
        }
    }

    public function show(StockReturn $stockReturn): JsonResponse
    {
        $stockReturn->load([
            'company',
            'stockIssue.items.item',
            'receivedBy',
            'items.item.unit',
            'items.location',
            'items.remnants',
            'attachments',
        ]);

        return $this->successResponse($stockReturn, 'Detail pengembalian barang berhasil diambil.');
    }
}
