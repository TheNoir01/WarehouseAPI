<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'item_code',
        'company_id',
        'category_id',
        'type_id',
        'unit_id',
        'name',
        'barcode',
        'qr_code',
        'minimum_stock',
        'is_remnant',
        'parent_item_id',
        'specification',
        'description',
    ];

    protected $appends = [
        'total_stock',
        'stock_status',
        'suppliers_summary',
    ];

    protected function casts(): array
    {
        return [
            'minimum_stock' => 'decimal:2',
            'is_remnant' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ItemType::class, 'type_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function parentItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'parent_item_id');
    }

    public function childRemnantItems(): HasMany
    {
        return $this->hasMany(Item::class, 'parent_item_id');
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(ItemIdentifier::class);
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function materialRemnants(): HasMany
    {
        return $this->hasMany(MaterialRemnant::class, 'parent_item_id');
    }

    public function getTotalStockAttribute(): float
    {
        if ($this->relationLoaded('stockBalances')) {
            return (float) ($this->stockBalances->sum('qty') ?? 0);
        }
        return (float) ($this->stockBalances()->sum('qty') ?? 0);
    }

    public function getStockStatusAttribute(): string
    {
        $stock = $this->total_stock;
        $min = (float) $this->minimum_stock;

        if ($stock <= 0) {
            return 'HABIS';
        }
        if ($min > 0 && $stock <= $min) {
            return 'MENIPIS';
        }
        return 'TERSEDIA';
    }

    public function getSuppliersListAttribute(): array
    {
        $suppliers = [];
        $desc = (string) ($this->description ?? '');
        if (!empty($desc)) {
            if (preg_match('/Supplier\s*:\s*([^|\n]+)/i', $desc, $matches)) {
                $rawParts = explode(',', $matches[1]);
                foreach ($rawParts as $p) {
                    $clean = trim($p);
                    if ($clean !== '' && !in_array($clean, $suppliers, true)) {
                        $suppliers[] = $clean;
                    }
                }
            }
        }
        return $suppliers;
    }

    public function getSuppliersSummaryAttribute(): string
    {
        $list = $this->suppliers_list;
        return !empty($list) ? implode(', ', $list) : '-';
    }
}
