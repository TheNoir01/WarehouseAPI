<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialRemnant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'remnant_code',
        'company_id',
        'parent_item_id',
        'derived_item_id',
        'stock_issue_id',
        'stock_return_item_id',
        'warehouse_location_id',
        'shape_condition',
        'dimension_description',
        'estimated_area',
        'estimated_weight',
        'qty',
        'unit_id',
        'status',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'estimated_area' => 'decimal:4',
            'estimated_weight' => 'decimal:4',
            'qty' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function parentItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'parent_item_id');
    }

    public function derivedItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'derived_item_id');
    }

    public function stockIssue(): BelongsTo
    {
        return $this->belongsTo(StockIssue::class);
    }

    public function stockReturnItem(): BelongsTo
    {
        return $this->belongsTo(StockReturnItem::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'warehouse_location_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class, 'entity_id')
            ->where('entity_type', MaterialRemnant::class);
    }
}
