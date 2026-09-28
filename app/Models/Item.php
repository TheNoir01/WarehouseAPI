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
        'po_number',
        'purchase_price',
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
            'purchase_price' => 'decimal:2',
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

    /**
     * Scope for blazing fast, multi-keyword and typo-tolerant search across all inventory items.
     */
    public function scopeFuzzySearch($query, string $search)
    {
        $search = trim($search);
        if ($search === '') {
            return $query;
        }

        $knownCorrections = [
            'glavanis' => 'galvanis',
            'glvanis' => 'galvanis',
            'galfanis' => 'galvanis',
            'grinda' => 'gerinda',
            'gerrenda' => 'gerinda',
            'fiting' => 'fitting',
            'kones' => 'cones',
            'spandeck' => 'spandek',
            'dopp' => 'dop',
            'nepel' => 'napel',
            'naple' => 'napel',
            'isolasi' => 'solasi',
            'solder' => 'tinol',
            'v-lock' => 'vlok',
            'vlock' => 'vlok',
            'reduser' => 'reducer',
            'las' => 'welding',
        ];

        $tokens = preg_split('/\s+/', strtolower($search));

        return $query->where(function ($masterQ) use ($tokens, $knownCorrections) {
            foreach ($tokens as $token) {
                $token = trim($token);
                if ($token === '') continue;

                $variants = [$token];

                // Convert dot to comma and comma to dot (e.g. 0.8 <-> 0,8)
                if (strpos($token, '.') !== false) {
                    $variants[] = str_replace('.', ',', $token);
                } elseif (strpos($token, ',') !== false) {
                    $variants[] = str_replace(',', '.', $token);
                }

                if (isset($knownCorrections[$token])) {
                    $variants[] = $knownCorrections[$token];
                }
                foreach ($knownCorrections as $typo => $correct) {
                    if ($token === $correct && !in_array($typo, $variants, true)) {
                        $variants[] = $typo;
                    }
                }

                if (count($variants) === 1 && strlen($token) >= 4) {
                    $targetWords = [
                        'galvanis', 'gerinda', 'fitting', 'cutting', 'wheel', 'baut',
                        'socket', 'flange', 'elbow', 'nipple', 'napel', 'gasket',
                        'spandek', 'bearing', 'kabel', 'pipa', 'plat', 'grating', 'conduit'
                    ];
                    foreach ($targetWords as $tw) {
                        if (levenshtein($token, $tw) <= 2) {
                            $variants[] = $tw;
                        }
                    }
                }

                $variants = array_values(array_unique($variants));

                $masterQ->where(function ($tokenQ) use ($variants) {
                    foreach ($variants as $v) {
                        $tokenQ->orWhere('name', 'LIKE', "%{$v}%")
                               ->orWhere('item_code', 'LIKE', "%{$v}%")
                               ->orWhere('po_number', 'LIKE', "%{$v}%")
                               ->orWhere('specification', 'LIKE', "%{$v}%")
                               ->orWhere('barcode', 'LIKE', "%{$v}%")
                               ->orWhere('qr_code', 'LIKE', "%{$v}%");
                    }
                });
            }
        });
    }
}
