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
            $table->unsignedInteger('purchasing_edit_count')->default(0)->after('po_number');
            $table->boolean('is_purchasing_locked')->default(false)->after('purchasing_edit_count');
            $table->timestamp('purchasing_locked_at')->nullable()->after('is_purchasing_locked');
            $table->timestamp('purchasing_unlocked_at')->nullable()->after('purchasing_locked_at');
            $table->foreignId('purchasing_unlocked_by')->nullable()->after('purchasing_unlocked_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropForeign(['purchasing_unlocked_by']);
            $table->dropColumn([
                'purchasing_edit_count',
                'is_purchasing_locked',
                'purchasing_locked_at',
                'purchasing_unlocked_at',
                'purchasing_unlocked_by',
            ]);
        });
    }
};
