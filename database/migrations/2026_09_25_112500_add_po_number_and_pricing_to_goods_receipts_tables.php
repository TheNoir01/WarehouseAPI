<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add po_number to goods_receipts
        Schema::table('goods_receipts', function (Blueprint $table) {
            if (!Schema::hasColumn('goods_receipts', 'po_number')) {
                $table->string('po_number', 100)->nullable()->after('delivery_order_number');
            }
        });

        // 2. Add unit_price and total_price to goods_receipt_items
        Schema::table('goods_receipt_items', function (Blueprint $table) {
            if (!Schema::hasColumn('goods_receipt_items', 'unit_price')) {
                $table->decimal('unit_price', 14, 2)->default(0)->after('qty');
            }
            if (!Schema::hasColumn('goods_receipt_items', 'total_price')) {
                $table->decimal('total_price', 14, 2)->default(0)->after('unit_price');
            }
        });

        // 3. Add unit_price to inventory_batches
        Schema::table('inventory_batches', function (Blueprint $table) {
            if (!Schema::hasColumn('inventory_batches', 'unit_price')) {
                $table->decimal('unit_price', 14, 2)->default(0)->after('qty_remaining');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory_batches', function (Blueprint $table) {
            if (Schema::hasColumn('inventory_batches', 'unit_price')) {
                $table->dropColumn('unit_price');
            }
        });

        Schema::table('goods_receipt_items', function (Blueprint $table) {
            if (Schema::hasColumn('goods_receipt_items', 'total_price')) {
                $table->dropColumn('total_price');
            }
            if (Schema::hasColumn('goods_receipt_items', 'unit_price')) {
                $table->dropColumn('unit_price');
            }
        });

        Schema::table('goods_receipts', function (Blueprint $table) {
            if (Schema::hasColumn('goods_receipts', 'po_number')) {
                $table->dropColumn('po_number');
            }
        });
    }
};
