<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Ubah role id 1 yang sebelumnya 'admin' (Maintenance) menjadi name 'maintenance', label 'Maintenance'
        DB::table('roles')->where('id', 1)->update([
            'name' => 'maintenance',
            'label' => 'Maintenance',
            'description' => 'Akses penuh ke seluruh sistem dan konfigurasi',
            'updated_at' => now(),
        ]);

        // 2. Tambahkan role baru 'admin' dengan hak akses monitoring, laporan & audit, dan manajemen user
        $adminRole = DB::table('roles')->where('name', 'admin')->first();
        if (!$adminRole) {
            $adminRoleId = DB::table('roles')->insertGetId([
                'name' => 'admin',
                'label' => 'Admin',
                'description' => 'Monitoring sistem, melihat Laporan & Audit, serta CRUD Manajemen Pengguna & Hak Akses',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $adminRoleId = $adminRole->id;
        }

        // 3. Pastikan user admin@warehouse.test ada dan memiliki role 'admin'
        $adminUser = DB::table('users')->where('email', 'admin@warehouse.test')->first();
        if (!$adminUser) {
            DB::table('users')->insert([
                'role_id' => $adminRoleId,
                'name' => 'Administrator',
                'email' => 'admin@warehouse.test',
                'username' => 'adminuser',
                'password' => Hash::make('password'),
                'phone' => '081234567899',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('users')->where('id', $adminUser->id)->update([
                'role_id' => $adminRoleId,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('roles')->where('id', 1)->update([
            'name' => 'admin',
            'label' => 'Maintenance',
            'updated_at' => now(),
        ]);

        DB::table('roles')->where('name', 'admin')->delete();
    }
};
