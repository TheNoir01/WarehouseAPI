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
        $requesterRole = $requester?->role?->name;
        $isRequesterMaintenance = ($requesterRole === 'maintenance');
        $maintenanceRole = Role::where('name', 'maintenance')->first();

        // 1. Role Maintenance hanya dapat dibuat oleh akun Maintenance
        if (!$isRequesterMaintenance && (int) $request->role_id === (int) ($maintenanceRole?->id)) {
            return $this->errorResponse('Akses ditolak: Hanya akun Maintenance yang dapat membuat pengguna dengan hak akses Maintenance.', 403);
        }

        // 2. Role Kepala Gudang hanya dapat membuat akun Karyawan Operasional Gudang
        if ($requesterRole === 'kepala_gudang') {
            $karyawanRole = Role::where('name', 'karyawan')->first();
            if ((int) $request->role_id !== (int) ($karyawanRole?->id)) {
                return $this->errorResponse('Akses ditolak: Kepala Gudang hanya dapat membuat pengguna dengan hak akses Karyawan Operasional Gudang.', 403);
            }
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
        $requesterRole = $requester?->role?->name;
        $targetRole = $user->role?->name;
        $maintenanceRole = Role::where('name', 'maintenance')->first();

        // 1. Akun Maintenance tidak bisa diubah oleh siapapun selain Maintenance
        if ($requesterRole !== 'maintenance' && $targetRole === 'maintenance') {
            return $this->errorResponse('Akses ditolak: Anda tidak diizinkan mengubah akun pengguna dengan hak akses Maintenance.', 403);
        }

        // 2. Hak akses Maintenance tidak bisa diberikan oleh selain Maintenance
        if ($requesterRole !== 'maintenance' && (int) $request->role_id === (int) ($maintenanceRole?->id)) {
            return $this->errorResponse('Akses ditolak: Anda tidak dapat memberikan hak akses Maintenance.', 403);
        }

        // 3. Kepala Gudang hanya bisa edit akun role sendiri dan karyawan
        if ($requesterRole === 'kepala_gudang') {
            $canEdit = ($user->id === $requester->id) || in_array($targetRole, ['karyawan', 'kepala_gudang']);
            if (!$canEdit) {
                return $this->errorResponse('Akses ditolak: Kepala Gudang hanya dapat mengubah akun dengan role Kepala Gudang atau Karyawan.', 403);
            }

            // Kepala Gudang hanya dapat memilih role Kepala Gudang atau Karyawan
            $allowedRoleIds = Role::whereIn('name', ['karyawan', 'kepala_gudang'])->pluck('id')->toArray();
            if (!in_array((int) $request->role_id, $allowedRoleIds, true)) {
                return $this->errorResponse('Akses ditolak: Kepala Gudang hanya dapat menetapkan role Kepala Gudang atau Karyawan Operasional Gudang.', 403);
            }
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

    public function destroy(Request $request, User $user): JsonResponse
    {
        $requester = $request->user();
        $requesterRole = $requester?->role?->name;
        $targetRole = $user->role?->name;

        // Tidak dapat menghapus akun sendiri
        if ((int) $requester->id === (int) $user->id) {
            return $this->errorResponse('Akses ditolak: Anda tidak dapat menghapus akun Anda sendiri.', 400);
        }

        // 1. Akun Maintenance hanya bisa dihapus oleh akun Maintenance
        if ($targetRole === 'maintenance' && $requesterRole !== 'maintenance') {
            return $this->errorResponse('Akses ditolak: Akun Maintenance (MT) tidak dapat dihapus oleh pengguna lain.', 403);
        }

        // 2. Kepala Gudang hanya dapat menghapus akun role Karyawan Operasional Gudang
        if ($requesterRole === 'kepala_gudang') {
            if ($targetRole !== 'karyawan') {
                return $this->errorResponse('Akses ditolak: Kepala Gudang hanya dapat menghapus akun dengan role Karyawan Operasional Gudang.', 403);
            }
        }

        // 3. Admin dapat menghapus akun semua role kecuali Maintenance
        if ($requesterRole === 'admin') {
            if ($targetRole === 'maintenance') {
                return $this->errorResponse('Akses ditolak: Admin tidak dapat menghapus akun Maintenance (MT).', 403);
            }
        }

        $old = $user->toArray();
        $userName = $user->name;
        $user->delete();

        AuditLog::record('DELETE_USER', User::class, $user->id, $old, null, $requester->id);

        return $this->successResponse(null, "Akun pengguna [{$userName}] berhasil dihapus dari sistem.");
    }
}
