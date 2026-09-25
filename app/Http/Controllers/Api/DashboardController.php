<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\MaterialRemnant;
use App\Models\StockBalance;
use App\Models\StockIssue;
use App\Models\StockMovement;
use App\Models\StockReturn;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id');

        // Companies overview
        $companies = Company::where('status', 'active')->get();

        $companiesStats = $companies->map(function ($comp) {
            $totalItems = Item::where('company_id', $comp->id)->count();
            $totalStock = (float) (StockBalance::where('company_id', $comp->id)->sum('qty') ?? 0);
            
            // Habis & Menipis
            $items = Item::with('stockBalances')->where('company_id', $comp->id)->get();
            $habis = 0;
            $menipis = 0;
            $tersedia = 0;

            foreach ($items as $item) {
                $status = $item->stock_status;
                if ($status === 'HABIS') $habis++;
                elseif ($status === 'MENIPIS') $menipis++;
                else $tersedia++;
            }

            return [
                'company_id' => $comp->id,
                'company_code' => $comp->code,
                'company_name' => $comp->name,
                'total_items' => $totalItems,
                'total_stock' => $totalStock,
                'status_counts' => [
                    'habis' => $habis,
                    'menipis' => $menipis,
                    'tersedia' => $tersedia,
                ],
            ];
        });

        // Summary queries
        $itemQuery = Item::query();
        $balanceQuery = StockBalance::query();
        $receiptQuery = GoodsReceipt::query();
        $issueQuery = StockIssue::query();
        $returnQuery = StockReturn::query();
        $remnantQuery = MaterialRemnant::query();

        if ($companyId) {
            $itemQuery->where('company_id', $companyId);
            $balanceQuery->where('company_id', $companyId);
            $receiptQuery->where('company_id', $companyId);
            $issueQuery->where('company_id', $companyId);
            $returnQuery->where('company_id', $companyId);
            $remnantQuery->where('company_id', $companyId);
        }

        // Low stock items list (Habis or Menipis)
        $allItems = (clone $itemQuery)->with(['company', 'unit', 'stockBalances'])->get();
        $alertItems = $allItems->filter(function ($i) {
            return in_array($i->stock_status, ['HABIS', 'MENIPIS']);
        })->values()->take(10)->map(function ($i) {
            return [
                'id' => $i->id,
                'item_code' => $i->item_code,
                'name' => $i->name,
                'company_code' => $i->company?->code,
                'total_stock' => $i->total_stock,
                'minimum_stock' => (float) $i->minimum_stock,
                'unit' => $i->unit?->code,
                'stock_status' => $i->stock_status,
            ];
        });

        // Recent transactions
        $recentReceipts = (clone $receiptQuery)->with(['company', 'supplier'])->orderByDesc('id')->limit(5)->get();
        $recentIssues = (clone $issueQuery)->with(['company'])->orderByDesc('id')->limit(5)->get();
        $recentReturns = (clone $returnQuery)->with(['company', 'stockIssue'])->orderByDesc('id')->limit(5)->get();
        $recentMovements = StockMovement::with(['company', 'item', 'location', 'user'])
            ->when($companyId, fn($q) => $q->where('company_id', $companyId))
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        return $this->successResponse([
            'overview' => [
                'total_items' => $itemQuery->count(),
                'total_stock_qty' => (float) ($balanceQuery->sum('qty') ?? 0),
                'total_receipts' => $receiptQuery->count(),
                'total_issues' => $issueQuery->count(),
                'total_returns' => $returnQuery->count(),
                'total_remnants' => $remnantQuery->count(),
            ],
            'companies_stats' => $companiesStats,
            'alert_items' => $alertItems,
            'recent_receipts' => $recentReceipts,
            'recent_issues' => $recentIssues,
            'recent_returns' => $recentReturns,
            'recent_movements' => $recentMovements,
        ], 'Data dashboard berhasil diambil.');
    }
}
