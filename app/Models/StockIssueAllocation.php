<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockIssueAllocation extends Model
{
    protected $fillable = [
        'stock_issue_id',
        'stock_issue_item_id',
        'batch_id',
        'item_id',
        'company_id',
        'warehouse_location_id',
        'qty_deducted',
    ];

    protected function casts(): array
    {
        return [
            'qty_deducted' => 'decimal:2',
        ];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(StockIssue::class, 'stock_issue_id');
    }

    public function issueItem(): BelongsTo
    {
        return $this->belongsTo(StockIssueItem::class, 'stock_issue_item_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'batch_id');
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
}
