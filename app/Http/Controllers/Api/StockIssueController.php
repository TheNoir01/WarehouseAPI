<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockIssue;
use App\Services\StockIssueService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockIssueController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected StockIssueService $stockIssueService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = StockIssue::with(['company', 'issuedBy'])
            ->withCount('items')
            ->orderBy('id', 'desc');

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('issue_number', 'LIKE', "%{$search}%")
                  ->orWhere('project_name', 'LIKE', "%{$search}%")
                  ->orWhere('recipient_name', 'LIKE', "%{$search}%")
                  ->orWhere('requester_name', 'LIKE', "%{$search}%");
            });
        }

        // If karyawan role, can optionally filter to only their own or their PT
        if ($request->user()?->isKaryawan() && $request->filled('my_transactions')) {
            $query->where('issued_by', $request->user()->id);
        }

        $perPage = (int) ($request->per_page ?: 15);
        $issues = $query->paginate($perPage);

        return $this->successResponse($issues->items(), 'Daftar pengeluaran barang berhasil diambil.', 200, [
            'current_page' => $issues->currentPage(),
            'per_page' => $issues->perPage(),
            'total' => $issues->total(),
            'last_page' => $issues->lastPage(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => 'nullable|exists:companies,id',
            'project_name' => 'required|string|max:150',
            'requester_name' => 'required|string|max:100',
            'recipient_name' => 'required|string|max:100',
            'issued_date' => 'nullable|date',
            'issue_number' => 'nullable|string|max:50|unique:stock_issues,issue_number',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:items,id',
            'items.*.warehouse_location_id' => 'required|exists:warehouse_locations,id',
            'items.*.qty_issued' => 'required|numeric|min:0.01',
            'items.*.notes' => 'nullable|string|max:255',
            'attachments' => 'nullable|array',
        ]);

        try {
            $issue = $this->stockIssueService->createIssue(
                data: $validated,
                items: $validated['items'],
                attachments: $validated['attachments'] ?? [],
                userId: $request->user()?->id
            );

            return $this->successResponse($issue, 'Pengeluaran barang berhasil diproses dan stok telah berkurang.', 201);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), null, 422);
        }
    }

    public function show(StockIssue $stockIssue): JsonResponse
    {
        $stockIssue->load([
            'company',
            'issuedBy',
            'items.item.unit',
            'items.location',
            'returns.receivedBy',
            'attachments',
            'allocations.company',
            'allocations.batch',
            'allocations.item.unit',
        ]);

        return $this->successResponse($stockIssue, 'Detail pengeluaran barang berhasil diambil.');
    }
}
