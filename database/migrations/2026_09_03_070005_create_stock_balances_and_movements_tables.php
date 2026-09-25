<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('warehouse_location_id')->constrained('warehouse_locations')->cascadeOnDelete();
            $table->decimal('qty', 14, 2)->default(0.00);
            $table->decimal('reserved_qty', 14, 2)->default(0.00);
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'item_id', 'warehouse_location_id'], 'uq_stock_balance_comp_item_loc');
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('warehouse_location_id')->constrained('warehouse_locations')->cascadeOnDelete();
            $table->enum('movement_type', ['IN', 'OUT', 'RETURN', 'ADJUSTMENT', 'TRANSFER']);
            $table->string('reference_type', 100);
            $table->unsignedBigInteger('reference_id');
            $table->string('reference_number', 100)->nullable();
            $table->decimal('qty', 14, 2);
            $table->decimal('balance_before', 14, 2);
            $table->decimal('balance_after', 14, 2);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'item_id']);
            $table->index(['reference_type', 'reference_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_balances');
    }
};
