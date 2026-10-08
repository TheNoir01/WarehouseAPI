<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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

        $loginInput = trim((string) $request->email);
        $password = (string) $request->password;

        // 1. Cari user berdasarkan email atau username
        $user = User::with(['role', 'company'])
            ->where('email', $loginInput)
            ->orWhere('username', $loginInput)
            ->first();

        // 2. Jika email / username tidak ditemukan -> JANGAN dihitung count false input
        if (!$user) {
            return $this->errorResponse('Email tidak terdaftar.', null, 401);
        }

        // 3. Jika akun sudah dinonaktifkan / diblokir
        if (!$user->is_active) {
            return $this->errorResponse('Akun Anda dinonaktifkan atau diblokir. Silakan hubungi admin.', null, 403);
        }

        // 4. Role Maintenance & Admin: TIDAK memakai sistem count false input dan bebas dari pemblokiran
        $isPrivileged = in_array($user->role?->name, ['maintenance', 'admin']);
        if ($isPrivileged) {
            if (!Hash::check($password, $user->password)) {
                AuditLog::record('LOGIN_FAILED', User::class, $user->id, null, [
                    'input' => $loginInput,
                    'ip' => $request->ip(),
                    'role' => $user->role?->name,
                ], $user->id);

                return $this->errorResponse('Password yang Anda masukkan salah.', null, 401);
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

        // 5. Untuk Non-Admin: Sistem 3 kali percobaan -> jeda 1 menit -> 3 kali percobaan lagi -> langsung blokir
        $attemptsKey = "login:attempts:user:{$user->id}";
        $cooldownKey = "login:cooldown:user:{$user->id}";

        // Cek apakah sedang dalam masa jeda 1 menit
        if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            $seconds = RateLimiter::availableIn($cooldownKey);
            AuditLog::record('LOGIN_COOLDOWN', User::class, $user->id, null, [
                'input' => $loginInput,
                'ip' => $request->ip(),
                'seconds_remaining' => $seconds,
            ], $user->id);

            return $this->errorResponse(
                "Terlalu banyak percobaan login yang gagal. Akun dikunci sementara selama 1 menit. Silakan coba lagi dalam {$seconds} detik.",
                [
                    'seconds_remaining' => $seconds,
                ],
                429
            );
        }

        // Cek kecocokan password
        if (!Hash::check($password, $user->password)) {
            RateLimiter::hit($attemptsKey, 86400); // Catatan percobaan disimpan
            $attempts = RateLimiter::attempts($attemptsKey);

            AuditLog::record('LOGIN_FAILED', User::class, $user->id, null, [
                'input' => $loginInput,
                'ip' => $request->ip(),
                'attempt' => $attempts,
            ], $user->id);

            // Tahap 1: Percobaan 1 & 2
            if ($attempts < 3) {
                $remaining = 3 - $attempts;
                $msg = "Password salah. (Percobaan ke-{$attempts} dari 3, sisa {$remaining} kesempatan lagi).";
                return $this->errorResponse($msg, [
                    'phase' => 1,
                    'attempts' => $attempts,
                    'remaining' => $remaining,
                ], 401);
            }

            // Tahap 1 Selesai: Percobaan ke-3 salah -> Aktifkan jeda 1 menit
            if ($attempts === 3) {
                RateLimiter::hit($cooldownKey, 60); // Kunci sementara 60 detik (1 menit)
                $msg = "Password salah sebanyak 3 kali. Akun dikunci sementara, silakan coba lagi dalam 60 detik.";
                return $this->errorResponse($msg, [
                    'phase' => 1,
                    'cooldown' => 60,
                    'seconds_remaining' => 60,
                ], 429);
            }

            // Tahap 2: Diberi 3 kali lagi setelah masa jeda (Percobaan ke-4 dan ke-5)
            if ($attempts < 6) {
                $phase2Attempt = $attempts - 3;
                $remaining = 6 - $attempts;
                $msg = "Password salah. (Tahap kedua: percobaan ke-{$phase2Attempt} dari 3, sisa {$remaining} kesempatan lagi sebelum akun diblokir).";
                return $this->errorResponse($msg, [
                    'phase' => 2,
                    'attempts' => $phase2Attempt,
                    'remaining' => $remaining,
                ], 401);
            }

            // Tahap 2 Selesai: Percobaan ke-6 (3 kali salah setelah jeda) -> LANGSUNG BLOKIR PERMANEN
            $user->is_active = false;
            $user->save();

            RateLimiter::clear($attemptsKey);
            RateLimiter::clear($cooldownKey);

            AuditLog::record('USER_BLOCKED', User::class, $user->id, null, [
                'reason' => 'Salah memasukkan password 3 kali setelah jeda 1 menit (Total 6 kali salah)',
                'ip' => $request->ip(),
            ], $user->id);

            return $this->errorResponse(
                "Akun Anda telah diblokir karena salah memasukkan password sebanyak 3 kali setelah jeda. Silakan hubungi admin untuk membuka blokir akun Anda.",
                [
                    'blocked' => true,
                ],
                403
            );
        }

        // Password BENAR: Reset semua counter
        RateLimiter::clear($attemptsKey);
        RateLimiter::clear($cooldownKey);

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
