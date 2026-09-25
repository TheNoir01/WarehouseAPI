<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockIssueItem extends Model
{
    protected $fillable = [
        'stock_issue_id',
        'item_id',
        'warehouse_location_id',
        'qty_issued',
        'qty_used',
        'qty_returned',
        'qty_lost',
        'notes',
    ];

    protected $appends = [
        'remaining_qty',
    ];

    protected function casts(): array
    {
        return [
            'qty_issued' => 'decimal:2',
            'qty_used' => 'decimal:2',
            'qty_returned' => 'decimal:2',
            'qty_lost' => 'decimal:2',
        ];
    }

    public function stockIssue(): BelongsTo
    {
        return $this->belongsTo(StockIssue::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'warehouse_location_id');
    }

    public function returnItems(): HasMany
    {
        return $this->hasMany(StockReturnItem::class);
    }

    public function getRemainingQtyAttribute(): float
    {
        return (float) ($this->qty_issued - ($this->qty_used + $this->qty_returned + $this->qty_lost));
    }
}
