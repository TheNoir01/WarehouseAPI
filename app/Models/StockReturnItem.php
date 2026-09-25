<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockReturnItem extends Model
{
    protected $fillable = [
        'stock_return_id',
        'stock_issue_item_id',
        'item_id',
        'warehouse_location_id',
        'qty_returned',
        'return_status',
        'condition',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'qty_returned' => 'decimal:2',
        ];
    }

    public function stockReturn(): BelongsTo
    {
        return $this->belongsTo(StockReturn::class);
    }

    public function stockIssueItem(): BelongsTo
    {
        return $this->belongsTo(StockIssueItem::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'warehouse_location_id');
    }

    public function remnants(): HasMany
    {
        return $this->hasMany(MaterialRemnant::class);
    }
}
