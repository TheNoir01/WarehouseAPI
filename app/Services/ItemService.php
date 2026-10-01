<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Item;
use App\Models\ItemType;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class ItemService
{
    /**
     * Check for similar item names to prevent unintended duplicates.
     */
    public function findSimilarItems(string $name, ?int $excludeItemId = null): Collection
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $name));
        $words = explode(' ', $normalized);

        $query = Item::with(['company', 'category', 'type', 'unit', 'stockBalances.location'])
            ->where(function ($q) use ($normalized, $words) {
                $q->where('name', 'LIKE', "%{$normalized}%");
                // Match first couple of key words
                if (count($words) >= 2) {
                    $prefix = $words[0] . ' ' . $words[1];
                    $q->orWhere('name', 'LIKE', "%{$prefix}%");
                }
            });

        if ($excludeItemId) {
            $query->where('id', '!=', $excludeItemId);
        }

        return $query->limit(10)->get();
    }

    /**
     * Generate next stable unique item code (e.g. KJG3343, LNP905, or BRG-000001).
     */
    public function generateItemCode(?string $prefix = 'BRG'): string
    {
        $prefix = strtoupper(trim($prefix ?: 'BRG'));
        $lastItem = Item::withTrashed()
            ->where('item_code', 'LIKE', "{$prefix}%")
            ->orderByDesc('id')
            ->first();

        $nextNumber = 1;
        if ($lastItem && preg_match('/(\d+)$/', $lastItem->item_code, $matches)) {
            $nextNumber = ((int) $matches[1]) + 1;
        }

        $format = in_array($prefix, ['KJG', 'LNP', 'LMP']) ? '%s%03d' : '%s-%06d';
        while (Item::withTrashed()->where('item_code', sprintf($format, $prefix, $nextNumber))->exists()) {
            $nextNumber++;
        }

        return sprintf($format, $prefix, $nextNumber);
    }

    /**
     * Resolve or create Category, Type, and Unit dynamically.
     */
    public function resolveDynamicAttributes(array $data): array
    {
        // Category
        if (!empty($data['category_name']) && empty($data['category_id'])) {
            $category = Category::firstOrCreate(['name' => trim($data['category_name'])]);
            $data['category_id'] = $category->id;
        }

        // Type
        if (!empty($data['type_name']) && empty($data['type_id']) && !empty($data['category_id'])) {
            $type = ItemType::firstOrCreate([
                'category_id' => $data['category_id'],
                'name' => trim($data['type_name']),
            ]);
            $data['type_id'] = $type->id;
        }

        // Unit
        if (!empty($data['unit_code']) && empty($data['unit_id'])) {
            $code = strtoupper(trim($data['unit_code']));
            $name = !empty($data['unit_name']) ? trim($data['unit_name']) : $code;
            $unit = Unit::firstOrCreate(
                ['code' => $code],
                ['name' => $name]
            );
            $data['unit_id'] = $unit->id;
        }

        return $data;
    }

    /**
     * Create a new Item with full validation & auto-code.
     */
    public function createItem(array $data, ?int $userId = null): Item
    {
        $data = $this->resolveDynamicAttributes($data);

        if (empty($data['item_code'])) {
            $prefix = 'BRG';
            if (!empty($data['company_id'])) {
                $comp = \App\Models\Company::find($data['company_id']);
                if ($comp && !empty($comp->code)) {
                    $prefix = strtoupper(trim($comp->code));
                }
            } elseif (!empty($data['category_id'])) {
                $cat = Category::find($data['category_id']);
                if ($cat) {
                    $prefix = substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $cat->name)), 0, 3) ?: 'BRG';
                }
            }
            $data['item_code'] = $this->generateItemCode($prefix);
        }

        // Generate QR code identifier if not provided or requested
        if (empty($data['qr_code']) && !empty($data['generate_qr'])) {
            $data['qr_code'] = 'QR-' . strtoupper(Str::random(8)) . '-' . $data['item_code'];
        }

        $item = Item::create([
            'item_code' => $data['item_code'],
            'company_id' => $data['company_id'],
            'category_id' => $data['category_id'] ?? null,
            'type_id' => $data['type_id'] ?? null,
            'unit_id' => $data['unit_id'] ?? null,
            'name' => trim($data['name']),
            'barcode' => !empty($data['barcode']) ? trim($data['barcode']) : null,
            'qr_code' => !empty($data['qr_code']) ? trim($data['qr_code']) : null,
            'minimum_stock' => $data['minimum_stock'] ?? 0.00,
            'is_remnant' => $data['is_remnant'] ?? false,
            'parent_item_id' => $data['parent_item_id'] ?? null,
            'specification' => $data['specification'] ?? null,
            'description' => $data['description'] ?? null,
        ]);

        AuditLog::record('CREATE_ITEM', Item::class, $item->id, null, $item->toArray(), $userId);

        return $item->load(['company', 'category', 'type', 'unit']);
    }

    /**
     * Search item by QR, Barcode, Item Code, or Name.
     */
    public function searchItem(string $term, ?int $companyId = null)
    {
        $term = trim($term);

        $query = Item::with(['company', 'category', 'type', 'unit', 'stockBalances.location'])
            ->where(function ($q) use ($term) {
                $q->where('qr_code', $term)
                  ->orWhere('barcode', $term)
                  ->orWhere('item_code', $term)
                  ->orWhere('name', 'LIKE', "%{$term}%");
            });

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        return $query->get();
    }
}
