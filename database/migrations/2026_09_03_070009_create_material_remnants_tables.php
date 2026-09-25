<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_remnants', function (Blueprint $table) {
            $table->id();
            $table->string('remnant_code', 50)->unique(); // e.g. PLT-001-S01
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('parent_item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('derived_item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->foreignId('stock_issue_id')->nullable()->constrained('stock_issues')->nullOnDelete();
            $table->foreignId('stock_return_item_id')->nullable()->constrained('stock_return_items')->nullOnDelete();
            $table->foreignId('warehouse_location_id')->constrained('warehouse_locations')->cascadeOnDelete();
            $table->string('shape_condition', 100); // e.g. Tidak Beraturan, Segitiga, Potongan Sudut
            $table->string('dimension_description', 255); // e.g. 1200 x 800 mm
            $table->decimal('estimated_area', 10, 4)->nullable(); // m2
            $table->decimal('estimated_weight', 10, 4)->nullable(); // kg
            $table->decimal('qty', 12, 2)->default(1.00);
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
            $table->enum('status', ['available', 'used', 'scrapped'])->default('available');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'parent_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_remnants');
    }
};
