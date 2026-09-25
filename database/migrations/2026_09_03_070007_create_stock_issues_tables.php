<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_issues', function (Blueprint $table) {
            $table->id();
            $table->string('issue_number', 50)->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('project_name', 150); // Tujuan / Proyek
            $table->string('requester_name', 100); // Peminta
            $table->string('recipient_name', 100); // Penerima
            $table->date('issued_date');
            $table->foreignId('issued_by')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['open', 'partially_returned', 'fully_returned', 'closed'])->default('open');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('stock_issue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_issue_id')->constrained('stock_issues')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('warehouse_location_id')->constrained('warehouse_locations')->cascadeOnDelete();
            $table->decimal('qty_issued', 12, 2);
            $table->decimal('qty_used', 12, 2)->default(0.00);
            $table->decimal('qty_returned', 12, 2)->default(0.00);
            $table->decimal('qty_lost', 12, 2)->default(0.00);
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_issue_items');
        Schema::dropIfExists('stock_issues');
    }
};
