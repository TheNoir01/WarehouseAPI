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
            $table->foreignId('supplier_id')->nullable()->change();
            $table->string('supplier_name', 150)->nullable()->after('supplier_id');
            $table->string('delivery_order_number', 100)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->string('delivery_order_number', 100)->nullable(false)->change();
            $table->dropColumn('supplier_name');
            $table->foreignId('supplier_id')->nullable(false)->change();
        });
    }
};
