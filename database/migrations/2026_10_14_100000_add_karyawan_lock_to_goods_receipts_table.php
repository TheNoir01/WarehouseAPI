<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->unsignedInteger('karyawan_edit_count')->default(0)->after('purchasing_unlocked_by');
            $table->boolean('is_karyawan_locked')->default(false)->after('karyawan_edit_count');
            $table->timestamp('karyawan_locked_at')->nullable()->after('is_karyawan_locked');
            $table->timestamp('karyawan_unlocked_at')->nullable()->after('karyawan_locked_at');
            $table->foreignId('karyawan_unlocked_by')->nullable()->after('karyawan_unlocked_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropForeign(['karyawan_unlocked_by']);
            $table->dropColumn([
                'karyawan_edit_count',
                'is_karyawan_locked',
                'karyawan_locked_at',
                'karyawan_unlocked_at',
                'karyawan_unlocked_by',
            ]);
        });
    }
};
