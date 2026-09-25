<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MaterialRemnant;
use App\Services\StockReturnService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaterialRemnantController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected StockReturnService $stockReturnService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = MaterialRemnant::with(['company', 'parentItem.unit', 'location.warehouse', 'stockIssue', 'attachments'])
            ->orderBy('id', 'desc');

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->filled('parent_item_id')) {
            $query->where('parent_item_id', $request->parent_item_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('remnant_code', 'LIKE', "%{$search}%")
                  ->orWhere('shape_condition', 'LIKE', "%{$search}%")
                  ->orWhere('dimension_description', 'LIKE', "%{$search}%")
                  ->orWhereHas('parentItem', function ($pq) use ($search) {
                      $pq->where('name', 'LIKE', "%{$search}%");
                  });
            });
        }

        $perPage = (int) ($request->per_page ?: 15);
        $remnants = $query->paginate($perPage);

        return $this->successResponse($remnants->items(), 'Daftar sisa material berhasil diambil.', 200, [
            'current_page' => $remnants->currentPage(),
            'per_page' => $remnants->perPage(),
            'total' => $remnants->total(),
            'last_page' => $remnants->lastPage(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'parent_item_id' => 'required|exists:items,id',
            'warehouse_location_id' => 'required|exists:warehouse_locations,id',
            'shape_condition' => 'required|string|max:100', // Tidak Beraturan, dll
            'dimension_description' => 'required|string|max:255', // 1200 x 800 mm
            'estimated_area' => 'nullable|numeric|min:0',
            'estimated_weight' => 'nullable|numeric|min:0',
            'qty' => 'nullable|numeric|min:0.01',
            'unit_id' => 'required|exists:units,id',
            'stock_issue_id' => 'nullable|exists:stock_issues,id',
            'notes' => 'nullable|string',
            'remnant_code' => 'nullable|string|max:50|unique:material_remnants,remnant_code',
        ]);

        if (empty($validated['remnant_code'])) {
            $parent = \App\Models\Item::findOrFail($validated['parent_item_id']);
            $validated['remnant_code'] = $this->stockReturnService->generateRemnantCode($parent->item_code);
        }

        $validated['created_by'] = $request->user()?->id ?? 1;
        $remnant = MaterialRemnant::create($validated);

        AuditLog::record('CREATE_REMNANT', MaterialRemnant::class, $remnant->id, null, $remnant->toArray(), $request->user()?->id);

        return $this->successResponse($remnant->load(['parentItem', 'location', 'company', 'unit']), 'Sisa material berhasil dicatat.', 201);
    }

    public function show(MaterialRemnant $materialRemnant): JsonResponse
    {
        $materialRemnant->load([
            'company',
            'parentItem.unit',
            'location.warehouse',
            'stockIssue.issuedBy',
            'stockReturnItem.stockReturn',
            'unit',
            'createdBy',
            'attachments',
        ]);

        // Synthesize direct traceability answers
        $traceability = [
            'remnant_code' => $materialRemnant->remnant_code,
            'origin_item_name' => $materialRemnant->parentItem?->name,
            'origin_item_code' => $materialRemnant->parentItem?->item_code,
            'issue_number' => $materialRemnant->stockIssue?->issue_number ?? 'N/A',
            'project_name' => $materialRemnant->stockIssue?->project_name ?? 'N/A',
            'carried_by' => $materialRemnant->stockIssue?->recipient_name ?? 'N/A',
            'return_date' => $materialRemnant->stockReturnItem?->stockReturn?->returned_date 
                ?? $materialRemnant->created_at->toDateString(),
            'storage_location' => $materialRemnant->location?->code ?? 'N/A',
            'shape' => $materialRemnant->shape_condition,
            'dimensions' => $materialRemnant->dimension_description,
            'estimated_area' => $materialRemnant->estimated_area ? "{$materialRemnant->estimated_area} m2" : null,
            'estimated_weight' => $materialRemnant->estimated_weight ? "{$materialRemnant->estimated_weight} kg" : null,
        ];

        return $this->successResponse([
            'details' => $materialRemnant,
            'traceability' => $traceability,
        ], 'Detail material sisa berhasil diambil.');
    }

    public function update(Request $request, MaterialRemnant $materialRemnant): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|in:available,used,scrapped',
            'warehouse_location_id' => 'nullable|exists:warehouse_locations,id',
            'notes' => 'nullable|string',
        ]);

        $old = $materialRemnant->toArray();
        $materialRemnant->update($validated);
        AuditLog::record('UPDATE_REMNANT', MaterialRemnant::class, $materialRemnant->id, $old, $materialRemnant->toArray(), $request->user()?->id);

        return $this->successResponse($materialRemnant->fresh(), 'Status material sisa berhasil diperbarui.');
    }
}
