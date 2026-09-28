<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryBatch extends Model
{
    protected $fillable = [
        'batch_number',
        'item_id',
        'company_id',
        'warehouse_location_id',
        'qty_initial',
        'qty_remaining',
        'unit_price',
        'received_at',
        'source_type',
        'source_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'qty_initial' => 'decimal:2',
            'qty_remaining' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'received_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'warehouse_location_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(StockIssueAllocation::class, 'batch_id');
    }
}
