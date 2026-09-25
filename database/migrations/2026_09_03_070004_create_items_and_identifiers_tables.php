<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('item_code', 50)->unique(); // Internal unique & stable ID e.g. BRG-000001
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->foreignId('type_id')->constrained('types')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('barcode', 100)->nullable()->index();
            $table->string('qr_code', 100)->nullable()->unique();
            $table->decimal('minimum_stock', 12, 2)->default(0.00);
            $table->boolean('is_remnant')->default(false);
            $table->foreignId('parent_item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->text('specification')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'name']);
        });

        Schema::create('item_identifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->enum('identifier_type', ['qr', 'barcode', 'serial_number']);
            $table->string('identifier_value', 100);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['identifier_type', 'identifier_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_identifiers');
        Schema::dropIfExists('items');
    }
};
