<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'role_id',
        'company_id',
        'name',
        'email',
        'username',
        'password',
        'phone',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isMaintenance(): bool
    {
        return $this->role?->name === 'maintenance';
    }

    public function isAdmin(): bool
    {
        return $this->role?->name === 'admin';
    }

    public function isKepalaGudang(): bool
    {
        return $this->role?->name === 'kepala_gudang';
    }

    public function isKaryawan(): bool
    {
        return $this->role?->name === 'karyawan';
    }

    public function isPurchasing(): bool
    {
        return $this->role?->name === 'purchasing';
    }

    public function hasRole(string|array $roles): bool
    {
        if (is_array($roles)) {
            return in_array($this->role?->name, $roles);
        }
        return $this->role?->name === $roles;
    }
}
