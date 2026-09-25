<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBalance extends Model
{
    protected $fillable = [
        'company_id',
        'item_id',
        'warehouse_location_id',
        'qty',
        'reserved_qty',
        'last_movement_at',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:2',
            'reserved_qty' => 'decimal:2',
            'last_movement_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'warehouse_location_id');
    }
}
