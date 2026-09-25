<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $companies = Company::withCount(['items', 'stockBalances'])->get();
        return $this->successResponse($companies, 'Daftar PT berhasil diambil.');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:20|unique:companies,code',
            'name' => 'required|string|max:150',
            'status' => 'nullable|in:active,inactive',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:30',
        ]);

        $company = Company::create($validated);
        AuditLog::record('CREATE_COMPANY', Company::class, $company->id, null, $company->toArray());

        return $this->successResponse($company, 'PT berhasil didaftarkan.', 201);
    }

    public function show(Company $company): JsonResponse
    {
        return $this->successResponse(
            $company->loadCount(['items', 'stockBalances', 'stockIssues', 'goodsReceipts']),
            'Detail PT berhasil diambil.'
        );
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:20|unique:companies,code,' . $company->id,
            'name' => 'required|string|max:150',
            'status' => 'nullable|in:active,inactive',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:30',
        ]);

        $old = $company->toArray();
        $company->update($validated);
        AuditLog::record('UPDATE_COMPANY', Company::class, $company->id, $old, $company->toArray());

        return $this->successResponse($company, 'Data PT berhasil diperbarui.');
    }

    public function destroy(Company $company): JsonResponse
    {
        $old = $company->toArray();
        $company->delete();
        AuditLog::record('DELETE_COMPANY', Company::class, $company->id, $old, null);

        return $this->successResponse(null, 'PT berhasil dinonaktifkan.');
    }
}
