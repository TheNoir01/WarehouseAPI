<?php

namespace App\Services;

use App\Models\Item;
use App\Models\StockBalance;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class StockService
{
    /**
     * Mutate stock balance with atomic movement log and negative balance protection.
     * 
     * @param int $companyId
     * @param int $itemId
     * @param int $locationId
     * @param string $type IN, OUT, RETURN, ADJUSTMENT, TRANSFER
     * @param string $refType
     * @param int $refId
     * @param string|null $refNumber
     * @param float $qty Positive for addition (IN, RETURN), negative for subtraction (OUT)
     * @param string|null $notes
     * @param int|null $userId
     * @return StockMovement
     * @throws RuntimeException
     */
    public function moveStock(
        int $companyId,
        int $itemId,
        int $locationId,
        string $type,
        string $refType,
        int $refId,
        ?string $refNumber,
        float $qty,
        ?string $notes = null,
        ?int $userId = null
    ): StockMovement {
        // Enforce company ownership integrity
        $item = Item::findOrFail($itemId);
        if ($item->company_id !== $companyId) {
            throw new InvalidArgumentException("Barang [{$item->item_code} - {$item->name}] bukan milik PT yang dipilih (Company ID mismatch).");
        }

        return DB::transaction(function () use (
            $companyId,
            $itemId,
            $locationId,
            $type,
            $refType,
            $refId,
            $refNumber,
            $qty,
            $notes,
            $userId,
            $item
        ) {
            // Lock balance row for update to prevent race conditions
            $balance = StockBalance::where('company_id', $companyId)
                ->where('item_id', $itemId)
                ->where('warehouse_location_id', $locationId)
                ->lockForUpdate()
                ->first();

            $before = $balance ? (float) $balance->qty : 0.0;
            $after = $before + $qty;

            // Strict check: Stock balance can never be negative
            if ($after < -0.00001) {
                throw new RuntimeException(
                    "Stok tidak mencukupi untuk barang '{$item->name}' di lokasi tersebut. Stok saat ini: {$before}, dibutuhkan: " . abs($qty)
                );
            }

            if (!$balance) {
                $balance = StockBalance::create([
                    'company_id' => $companyId,
                    'item_id' => $itemId,
                    'warehouse_location_id' => $locationId,
                    'qty' => $after,
                    'reserved_qty' => 0.00,
                    'last_movement_at' => now(),
                ]);
            } else {
                $balance->update([
                    'qty' => $after,
                    'last_movement_at' => now(),
                ]);
            }

            $currentUserId = $userId ?? auth('sanctum')->id() ?? auth()->id() ?? 1;

            $movement = StockMovement::create([
                'company_id' => $companyId,
                'item_id' => $itemId,
                'warehouse_location_id' => $locationId,
                'movement_type' => $type,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'reference_number' => $refNumber,
                'qty' => $qty,
                'balance_before' => $before,
                'balance_after' => $after,
                'user_id' => $currentUserId,
                'notes' => $notes,
                'created_at' => now(),
            ]);

            return $movement;
        });
    }

    /**
     * Get stock balances grouped by location for an item.
     */
    public function getStockDetails(int $itemId)
    {
        return StockBalance::with(['location', 'company'])
            ->where('item_id', $itemId)
            ->get();
    }

    /**
     * Check current stock of an item at a specific location
     */
    public function getAvailableStock(int $companyId, int $itemId, int $locationId): float
    {
        $balance = StockBalance::where('company_id', $companyId)
            ->where('item_id', $itemId)
            ->where('warehouse_location_id', $locationId)
            ->first();

        return $balance ? (float) $balance->qty : 0.0;
    }
}
