<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class UserController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $users = User::with(['role', 'company'])->orderBy('id', 'asc')->get();
        return $this->successResponse($users, 'Daftar pengguna berhasil diambil.');
    }

    public function roles(): JsonResponse
    {
        return $this->successResponse(Role::all(), 'Daftar role berhasil diambil.');
    }

    public function store(Request $request): JsonResponse
    {
        $requester = $request->user();
        $isRequesterMaintenance = ($requester?->role?->name === 'maintenance');
        $maintenanceRole = Role::where('name', 'maintenance')->first();

        if (!$isRequesterMaintenance && (int) $request->role_id === (int) ($maintenanceRole?->id)) {
            return $this->errorResponse('Akses ditolak: Hanya akun Maintenance yang dapat membuat pengguna dengan hak akses Maintenance.', 403);
        }

        $validated = $request->validate([
            'role_id' => 'required|exists:roles,id',
            'company_id' => 'nullable|exists:companies,id',
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:100|unique:users,email',
            'username' => 'nullable|string|max:50|unique:users,username',
            'password' => 'required|string|min:6',
            'phone' => 'nullable|string|max:30',
        ]);

        $validated['password'] = Hash::make($validated['password']);
        $user = User::create($validated);

        AuditLog::record('CREATE_USER', User::class, $user->id, null, [
            'name' => $user->name,
            'email' => $user->email,
            'role_id' => $user->role_id,
        ]);

        return $this->successResponse($user->load(['role', 'company']), 'Pengguna berhasil ditambahkan.', 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $requester = $request->user();
        $isRequesterMaintenance = ($requester?->role?->name === 'maintenance');
        $targetIsMaintenance = ($user->role?->name === 'maintenance');
        $maintenanceRole = Role::where('name', 'maintenance')->first();

        if (!$isRequesterMaintenance && $targetIsMaintenance) {
            return $this->errorResponse('Akses ditolak: Anda tidak diizinkan mengubah akun pengguna dengan hak akses Maintenance.', 403);
        }

        if (!$isRequesterMaintenance && (int) $request->role_id === (int) ($maintenanceRole?->id)) {
            return $this->errorResponse('Akses ditolak: Anda tidak dapat memberikan hak akses Maintenance.', 403);
        }

        $validated = $request->validate([
            'role_id' => 'required|exists:roles,id',
            'company_id' => 'nullable|exists:companies,id',
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:100|unique:users,email,' . $user->id,
            'username' => 'nullable|string|max:50|unique:users,username,' . $user->id,
            'password' => 'nullable|string|min:6',
            'phone' => 'nullable|string|max:30',
            'is_active' => 'nullable|boolean',
        ]);

        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $old = $user->toArray();
        $user->update($validated);

        if (!empty($validated['is_active']) || !empty($validated['password'])) {
            RateLimiter::clear("login:attempts:user:{$user->id}");
            RateLimiter::clear("login:cooldown:user:{$user->id}");
        }

        AuditLog::record('UPDATE_USER', User::class, $user->id, $old, $user->toArray());

        return $this->successResponse($user->fresh(['role', 'company']), 'Data pengguna berhasil diperbarui.');
    }
}
