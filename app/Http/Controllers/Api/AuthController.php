<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use ApiResponse;

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|string',
            'password' => 'required|string',
            'device_name' => 'nullable|string',
        ]);

        $loginInput = $request->email;
        // Allow login by email or username
        $user = User::with(['role', 'company'])
            ->where('email', $loginInput)
            ->orWhere('username', $loginInput)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->errorResponse('Kredensial tidak cocok dengan data kami.', null, 401);
        }

        if (!$user->is_active) {
            return $this->errorResponse('Akun Anda dinonaktifkan. Silakan hubungi administrator.', null, 403);
        }

        $deviceName = $request->device_name ?: 'Web/Mobile Client';
        $token = $user->createToken($deviceName)->plainTextToken;

        AuditLog::record('LOGIN', User::class, $user->id, null, ['device' => $deviceName], $user->id);

        return $this->successResponse([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'phone' => $user->phone,
                'role' => $user->role?->name,
                'role_label' => $user->role?->label,
                'company_id' => $user->company_id,
                'company' => $user->company ? [
                    'id' => $user->company->id,
                    'code' => $user->company->code,
                    'name' => $user->company->name,
                ] : null,
            ],
        ], 'Login berhasil.');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['role', 'company']);

        return $this->successResponse([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'phone' => $user->phone,
            'role' => $user->role?->name,
            'role_label' => $user->role?->label,
            'company_id' => $user->company_id,
            'company' => $user->company ? [
                'id' => $user->company->id,
                'code' => $user->company->code,
                'name' => $user->company->name,
            ] : null,
        ], 'Data profil berhasil diambil.');
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user) {
            $user->currentAccessToken()?->delete();
            AuditLog::record('LOGOUT', User::class, $user->id, null, null, $user->id);
        }

        return $this->successResponse(null, 'Logout berhasil.');
    }
}
