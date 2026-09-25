<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Company;
use App\Models\Category;
use App\Models\ItemType;
use App\Models\Unit;
use App\Models\Item;
use App\Models\StockBalance;
use App\Models\WarehouseLocation;

class ImportDataBarang extends Command
{
    protected $signature = 'warehouse:import-data-barang';
    protected $description = 'Import 4,246 items from Data Barang.xlsx for PT KJG and PT LNP';

    public function handle()
    {
        $jsonPath = database_path('data_barang_import.json');
        if (!file_exists($jsonPath)) {
            $this->error("File {$jsonPath} not found! Please run generate_import_json.py first.");
            return 1;
        }

        $this->info("Loading data from {$jsonPath}...");
        $itemsData = json_decode(file_get_contents($jsonPath), true);
        if (!$itemsData) {
            $this->error("Invalid JSON data!");
            return 1;
        }

        $this->info("Found " . count($itemsData) . " items to import.");

        DB::beginTransaction();
        try {
            // 1. Setup 2 Companies: KJG & LNP
            $this->info("1. Setting up Companies: KJG and LNP...");
            $compKJG = Company::where('code', 'KJG')->first();
            if (!$compKJG) {
                $compKJG = Company::find(1) ?? new Company();
                $compKJG->code = 'KJG';
            }
            $compKJG->name = 'PT Karunia Jaya Global';
            $compKJG->status = 'active';
            $compKJG->address = 'Kawasan Industri Cikarang';
            $compKJG->phone = '021-8901234';
            $compKJG->save();

            $compLNP = Company::where('code', 'LNP')->first();
            if (!$compLNP) {
                $compLNP = Company::where('code', 'LMP')->first() ?? Company::find(2) ?? new Company();
                $compLNP->code = 'LNP';
            }
            $compLNP->name = 'PT LNP MINING INDONESIA';
            $compLNP->status = 'active';
            $compLNP->address = 'Kawasan Industri Jababeka';
            $compLNP->phone = '021-8912345';
            $compLNP->save();

            // Clean up any other companies
            Company::whereNotIn('id', [$compKJG->id, $compLNP->id])->delete();

            // Re-link users
            DB::table('users')->where('company_id', '!=', $compKJG->id)->where('company_id', '!=', $compLNP->id)->whereNotNull('company_id')->update(['company_id' => $compKJG->id]);

            // Delete old dummy items (not starting with KJG or LNP)
            Item::where('item_code', 'NOT LIKE', 'KJG%')->where('item_code', 'NOT LIKE', 'LNP%')->forceDelete();

            $compMap = [
                'KJG' => $compKJG->id,
                'LNP' => $compLNP->id,
                'LMP' => $compLNP->id,
            ];

            // 2. Setup Units
            $this->info("2. Setting up Units...");
            $unitsDef = [
                'PCS'  => 'Pieces / Satuan',
                'SET'  => 'Set',
                'BTG'  => 'Batang',
                'MTR'  => 'Meter',
                'LBR'  => 'Lembar',
                'UNIT' => 'Unit',
                'ROLL' => 'Roll',
                'KG'   => 'Kilogram',
                'PTG'  => 'Potong',
                'KLG'  => 'Kaleng',
                'GLN'  => 'Galon',
                'LTR'  => 'Liter',
                'TBG'  => 'Tabung',
                'DRM'  => 'Drum',
                'JRGN' => 'Jerigen',
                'BTL'  => 'Botol',
                'PAIL' => 'Pail',
                'BOX'  => 'Box / Dus',
                'PSG'  => 'Pasang',
            ];

            $unitModels = [];
            foreach ($unitsDef as $code => $name) {
                $u = Unit::firstOrCreate(['code' => $code], ['name' => $name]);
                $unitModels[$code] = $u->id;
            }

            // 3. Setup Categories & Types
            $this->info("3. Setting up Categories & Types...");
            $catNames = array_unique(array_column($itemsData, 'category'));
            $catModels = [];
            $typeModels = [];

            foreach ($catNames as $catName) {
                $c = Category::firstOrCreate(['name' => $catName]);
                $catModels[$catName] = $c->id;

                $t = ItemType::firstOrCreate(
                    ['category_id' => $c->id, 'name' => 'General'],
                    ['description' => 'Tipe umum ' . $catName]
                );
                $typeModels[$catName] = $t->id;
            }

            // 4. Default Warehouse Location
            $loc = WarehouseLocation::first();
            if (!$loc) {
                $loc = WarehouseLocation::create([
                    'warehouse_id' => 1,
                    'code' => 'A-01-01',
                    'zone' => 'Zona A',
                    'rack' => 'Rak 01',
                    'shelf' => 'Tingkat 1',
                ]);
            }
            $locationId = $loc->id;

            // 5. Bulk Upsert Items in Chunks of 500
            $this->info("4. Importing Items into database...");
            $now = now();
            $itemRows = [];

            foreach ($itemsData as $row) {
                $compCode = $row['company_code'];
                $companyId = $compMap[$compCode] ?? $compKJG->id;
                $catId = $catModels[$row['category']] ?? $catModels['Consumable'];
                $typeId = $typeModels[$row['category']] ?? $typeModels['Consumable'];
                $unitId = $unitModels[$row['unit']] ?? $unitModels['PCS'];

                $qrCode = 'QR-' . $row['item_code'];

                $itemRows[] = [
                    'item_code'      => $row['item_code'],
                    'company_id'     => $companyId,
                    'category_id'    => $catId,
                    'type_id'        => $typeId,
                    'unit_id'        => $unitId,
                    'name'           => $row['name'],
                    'qr_code'        => $qrCode,
                    'specification'  => $row['supplier'] ? ('Supplier: ' . $row['supplier']) : ($row['notes'] ?? null),
                    'minimum_stock'  => 0.00,
                    'is_remnant'     => 0,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
            }

            $itemChunks = array_chunk($itemRows, 500);
            foreach ($itemChunks as $chunk) {
                Item::upsert(
                    $chunk,
                    ['item_code'],
                    ['company_id', 'category_id', 'type_id', 'unit_id', 'name', 'qr_code', 'specification', 'minimum_stock', 'is_remnant', 'updated_at']
                );
            }

            // 6. Map item_code -> id for stock balances
            $this->info("5. Importing Initial Stock Balances...");
            $itemMap = Item::pluck('id', 'item_code')->toArray();

            $balanceRows = [];
            foreach ($itemsData as $row) {
                $stk = (float)($row['stock'] ?? 0);
                if ($stk > 0 && isset($itemMap[$row['item_code']])) {
                    $compCode = $row['company_code'];
                    $companyId = $compMap[$compCode] ?? $compKJG->id;
                    $itemId = $itemMap[$row['item_code']];

                    $balanceRows[] = [
                        'company_id'            => $companyId,
                        'item_id'               => $itemId,
                        'warehouse_location_id' => $locationId,
                        'qty'                   => $stk,
                        'reserved_qty'          => 0.00,
                        'last_movement_at'      => $now,
                        'created_at'            => $now,
                        'updated_at'            => $now,
                    ];
                }
            }

            $balanceChunks = array_chunk($balanceRows, 500);
            foreach ($balanceChunks as $chunk) {
                StockBalance::upsert(
                    $chunk,
                    ['company_id', 'item_id', 'warehouse_location_id'],
                    ['qty', 'reserved_qty', 'last_movement_at', 'updated_at']
                );
            }

            DB::commit();

            $this->newLine();
            $this->info("=== IMPORT BERHASIL 100% ===");
            $this->table(
                ['Entitas', 'Jumlah di Database'],
                [
                    ['Total Katalog Barang', Item::count()],
                    ['Barang PT Karunia Jaya Global (KJG)', Item::where('company_id', $compKJG->id)->count()],
                    ['Barang PT LNP MINING INDONESIA (LNP)', Item::where('company_id', $compLNP->id)->count()],
                    ['Kategori Master', Category::count()],
                    ['Satuan Master (Units)', Unit::count()],
                    ['Barang Memiliki Saldo Stok Fisik', StockBalance::where('qty', '>', 0)->count()],
                    ['Total Akumulasi Stok di Lokasi Rak', StockBalance::count()],
                ]
            );

            return 0;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Error importing data: " . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }
    }
}
