<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create inventory_batches table
        Schema::create('inventory_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number', 100)->unique();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('warehouse_location_id')->constrained('warehouse_locations')->cascadeOnDelete();
            $table->decimal('qty_initial', 14, 2);
            $table->decimal('qty_remaining', 14, 2);
            $table->dateTime('received_at');
            $table->string('source_type', 100)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['item_id', 'warehouse_location_id', 'qty_remaining', 'received_at'], 'idx_fifo_item_loc_rem_date');
            $table->index(['company_id', 'qty_remaining'], 'idx_fifo_comp_rem');
        });

        // 2. Create stock_issue_allocations table (log pemotongan per batch & PT)
        Schema::create('stock_issue_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_issue_id')->constrained('stock_issues')->cascadeOnDelete();
            $table->foreignId('stock_issue_item_id')->nullable()->constrained('stock_issue_items')->nullOnDelete();
            $table->foreignId('batch_id')->constrained('inventory_batches')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('warehouse_location_id')->constrained('warehouse_locations')->cascadeOnDelete();
            $table->decimal('qty_deducted', 14, 2);
            $table->timestamps();

            $table->index(['stock_issue_id', 'company_id'], 'idx_alloc_issue_comp');
            $table->index(['batch_id'], 'idx_alloc_batch');
        });

        // 3. Make stock_issues.company_id nullable so multi-PT issues are permitted
        Schema::table('stock_issues', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->change();
        });

        // 4. Seed initial batches from existing positive stock balances
        $balances = DB::table('stock_balances')
            ->where('qty', '>', 0)
            ->get();

        foreach ($balances as $idx => $b) {
            $receivedAt = $b->last_movement_at ?? $b->created_at ?? now();
            DB::table('inventory_batches')->insert([
                'batch_number' => sprintf('BATCH-INIT-%06d', $idx + 1),
                'item_id' => $b->item_id,
                'company_id' => $b->company_id,
                'warehouse_location_id' => $b->warehouse_location_id,
                'qty_initial' => $b->qty,
                'qty_remaining' => $b->qty,
                'received_at' => $receivedAt,
                'source_type' => 'INITIAL_STOCK_BALANCE',
                'source_id' => $b->id,
                'notes' => 'Batch inisialisasi dari saldo stok sistem',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('stock_issues', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable(false)->change();
        });

        Schema::dropIfExists('stock_issue_allocations');
        Schema::dropIfExists('inventory_batches');
    }
};
