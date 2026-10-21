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

    public function goodsReceiptItems(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }

    public function priceHistories(): HasMany
    {
        return $this->hasMany(ItemPriceHistory::class)->orderByDesc('created_at');
    }

    public function getTotalStockAttribute(): float
    {
        if ($this->relationLoaded('stockBalances')) {
            return (float) ($this->stockBalances->sum('qty') ?? 0);
        }
        return (float) ($this->stockBalances()->sum('qty') ?? 0);
    }

    public function getCategoryMinimumStockAttribute(): float
    {
        if ($this->relationLoaded('category') && $this->category) {
            return (float) ($this->category->minimum_stock ?? 0);
        }
        if ($this->category_id) {
            return (float) ($this->category()->value('minimum_stock') ?? 0);
        }
        return (float) ($this->minimum_stock ?? 0);
    }

    public function getStockStatusAttribute(): string
    {
        $stock = (float) $this->total_stock;
        $min = (float) $this->category_minimum_stock;
        if ($min <= 0 && (float) $this->minimum_stock > 0) {
            $min = (float) $this->minimum_stock;
        }

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

        // 1. Ambil dari riwayat dokumen penerimaan barang masuk
        if ($this->relationLoaded('goodsReceiptItems')) {
            foreach ($this->goodsReceiptItems as $gri) {
                $sName = trim($gri->goodsReceipt?->supplier?->name ?? $gri->goodsReceipt?->supplier_name ?? '');
                if ($sName !== '' && !in_array($sName, $suppliers, true)) {
                    $suppliers[] = $sName;
                }
            }
        }

        // 2. Fallback dari description
        $desc = (string) ($this->description ?? '');
        if (!empty($desc) && preg_match('/Supplier\s*:\s*([^|\n]+)/i', $desc, $matches)) {
            $rawParts = explode(',', $matches[1]);
            foreach ($rawParts as $p) {
                $clean = trim($p);
                if ($clean !== '' && !in_array($clean, $suppliers, true)) {
                    $suppliers[] = $clean;
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

    /**
     * Scope for multi-word / tokenized flexible live search.
     * Matches every random keyword across item fields regardless of order.
     */
    public function scopeFuzzySearch($query, string $search)
    {
        $search = trim($search);
        if ($search === '') {
            return $query;
        }

        $tokens = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($tokens)) {
            return $query;
        }

        return $query->where(function ($q) use ($tokens, $search) {
            // Direct exact match on codes
            $q->where('item_code', $search)
              ->orWhere('barcode', $search)
              ->orWhere('qr_code', $search)
              // Multi-token match: all typed words must exist in the item attributes
              ->orWhere(function ($multiQ) use ($tokens) {
                  foreach ($tokens as $token) {
                      $multiQ->where(function ($tokenQ) use ($token) {
                          $tokenQ->where('name', 'LIKE', "%{$token}%")
                                 ->orWhere('item_code', 'LIKE', "%{$token}%")
                                 ->orWhere('barcode', 'LIKE', "%{$token}%")
                                 ->orWhere('qr_code', 'LIKE', "%{$token}%")
                                 ->orWhere('po_number', 'LIKE', "%{$token}%")
                                 ->orWhere('specification', 'LIKE', "%{$token}%")
                                 ->orWhereHas('category', function ($catQ) use ($token) {
                                     $catQ->where('name', 'LIKE', "%{$token}%");
                                 });
                      });
                  }
              });
        });
    }
}
