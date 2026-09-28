<?php

namespace Database\Seeders;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Item;
use App\Models\ItemType;
use App\Models\MaterialRemnant;
use App\Models\Role;
use App\Models\StockIssue;
use App\Models\StockIssueItem;
use App\Models\StockReturn;
use App\Models\StockReturnItem;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\StockService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $stockService = app(StockService::class);

        // 1. ROLES
        $adminRole = Role::create([
            'name' => 'admin',
            'label' => 'Administrator',
            'description' => 'Akses penuh ke seluruh sistem dan konfigurasi',
        ]);

        $kepalaRole = Role::create([
            'name' => 'kepala_gudang',
            'label' => 'Kepala Gudang',
            'description' => 'Mengelola inventaris, barang masuk, persetujuan dan master data',
        ]);

        $karyawanRole = Role::create([
            'name' => 'karyawan',
            'label' => 'Karyawan Operasional Gudang',
            'description' => 'Operator mobile untuk scan, cek stok, barang keluar, dan pengembalian',
        ]);

        $purchasingRole = Role::create([
            'name' => 'purchasing',
            'label' => 'Purchasing',
            'description' => 'Mengelola nomor PO dan harga barang masuk',
        ]);

        // 2. COMPANIES (2 PT dalam 1 gudang)
        $ptA = Company::create([
            'code' => 'KJG',
            'name' => 'PT Karunia Jaya Global',
            'status' => 'active',
            'address' => 'Kawasan Industri Cikarang',
            'phone' => '021-8901234',
        ]);

        $ptB = Company::create([
            'code' => 'LNP',
            'name' => 'PT LNP MINING INDONESIA',
            'status' => 'active',
            'address' => 'Kawasan Industri Jababeka',
            'phone' => '021-8912345',
        ]);

        // 3. USERS (3 roles)
        $adminUser = User::create([
            'role_id' => $adminRole->id,
            'company_id' => null, // Multi-company administrator
            'name' => 'Super Administrator',
            'email' => 'admin@warehouse.test',
            'username' => 'admin',
            'password' => Hash::make('password'),
            'phone' => '081234567890',
            'is_active' => true,
        ]);

        $kepalaUser = User::create([
            'role_id' => $kepalaRole->id,
            'company_id' => null, // Supervises physical warehouse for both PTs
            'name' => 'Budi Santoso (Kepala Gudang)',
            'email' => 'kepala@warehouse.test',
            'username' => 'kepalagudang',
            'password' => Hash::make('password'),
            'phone' => '081234567891',
            'is_active' => true,
        ]);

        $karyawanUser = User::create([
            'role_id' => $karyawanRole->id,
            'company_id' => $ptA->id,
            'name' => 'Andi Wijaya (Operator)',
            'email' => 'karyawan@warehouse.test',
            'username' => 'karyawan',
            'password' => Hash::make('password'),
            'phone' => '081234567892',
            'is_active' => true,
        ]);

        // 4. CATEGORIES & TYPES
        $catPlat = Category::create(['name' => 'Plat', 'description' => 'Material plat baja dan lembaran']);
        $catPipa = Category::create(['name' => 'Pipa', 'description' => 'Pipa carbon steel, stainless, spiral']);
        $catBesi = Category::create(['name' => 'Besi', 'description' => 'Besi beton, UNP, WF, H-Beam']);
        $catSparepart = Category::create(['name' => 'Sparepart', 'description' => 'Komponen mesin, bearing, seal']);
        $catConsumable = Category::create(['name' => 'Consumable', 'description' => 'Baut, mur, elektroda las, WD40']);
        $catAlatKerja = Category::create(['name' => 'Alat Kerja', 'description' => 'Gerinda, meteran, kunci ring']);
        $catLainnya = Category::create(['name' => 'Lainnya', 'description' => 'Barang kebutuhan umum']);

        $typePlatBesi = ItemType::create(['category_id' => $catPlat->id, 'name' => 'Plat Besi']);
        $typePlatSS = ItemType::create(['category_id' => $catPlat->id, 'name' => 'Plat Stainless']);
        $typePipaCS = ItemType::create(['category_id' => $catPipa->id, 'name' => 'Pipa Carbon Steel']);
        $typeBearing = ItemType::create(['category_id' => $catSparepart->id, 'name' => 'Bearing']);
        $typeBaut = ItemType::create(['category_id' => $catConsumable->id, 'name' => 'Baut & Mur']);
        $typeKabel = ItemType::create(['category_id' => $catLainnya->id, 'name' => 'Kabel']);

        // 5. UNITS
        $unitLbr = Unit::create(['code' => 'LBR', 'name' => 'Lembar']);
        $unitBtg = Unit::create(['code' => 'BTG', 'name' => 'Batang']);
        $unitPcs = Unit::create(['code' => 'PCS', 'name' => 'Pieces']);
        $unitKg = Unit::create(['code' => 'KG', 'name' => 'Kilogram']);
        $unitMtr = Unit::create(['code' => 'MTR', 'name' => 'Meter']);
        $unitRoll = Unit::create(['code' => 'ROLL', 'name' => 'Roll']);
        $unitSet = Unit::create(['code' => 'SET', 'name' => 'Set']);

        // 6. SUPPLIERS
        $sup1 = Supplier::create([
            'code' => 'SUP-001',
            'name' => 'PT Mitra Baja Nusantara',
            'phone' => '021-5551234',
            'email' => 'sales@mitrabaja.co.id',
            'address' => 'Jl. Krakatau Steel Indah Kav 10, Cilegon',
        ]);
        $sup2 = Supplier::create([
            'code' => 'SUP-002',
            'name' => 'CV Logam Jaya Abadi',
            'phone' => '021-5555678',
            'email' => 'order@logamjaya.com',
            'address' => 'Komp. Pergudangan Daan Mogot KM 19',
        ]);
        $sup3 = Supplier::create([
            'code' => 'SUP-003',
            'name' => 'PT Perkasa Fastener Indo',
            'phone' => '021-5559012',
            'email' => 'info@perkasafastener.com',
            'address' => 'Jl. Raya Narogong KM 12, Bekasi',
        ]);

        // 7. WAREHOUSE & LOCATIONS
        $warehouse = Warehouse::create([
            'code' => 'GDG-PUSAT',
            'name' => 'Gudang Utama Bersama',
            'address' => 'Kawasan Pergudangan Terpadu No. 1, Cikarang',
        ]);

        $locA1 = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'A-01-01', 'zone' => 'Zona A', 'rack' => 'Rak 01', 'shelf' => 'Tingkat 1']);
        $locA2 = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'A-01-02', 'zone' => 'Zona A', 'rack' => 'Rak 01', 'shelf' => 'Tingkat 2']);
        $locA3 = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'A-01-03', 'zone' => 'Zona A', 'rack' => 'Rak 01', 'shelf' => 'Tingkat 3']);
        $locB1 = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'B-01-01', 'zone' => 'Zona B', 'rack' => 'Rak 01', 'shelf' => 'Tingkat 1']);
        $locB2 = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'B-02-01', 'zone' => 'Zona B', 'rack' => 'Rak 02', 'shelf' => 'Tingkat 1']);
        $locSisa = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'ZONE-SISA', 'zone' => 'Area Sisa Material', 'rack' => 'Palet 01', 'shelf' => 'Lantai']);

        // 8. ITEMS (PT A and PT B separation demonstration)
        // PT A - Plat SS400 10mm 1500x3000 (With QR)
        $itemA_Plat = Item::create([
            'item_code' => 'PLT-000001',
            'company_id' => $ptA->id,
            'category_id' => $catPlat->id,
            'type_id' => $typePlatBesi->id,
            'unit_id' => $unitLbr->id,
            'name' => 'Plat SS400 10mm 1500x3000',
            'barcode' => '899123400001',
            'qr_code' => 'QR-PLT-000001-PTA',
            'minimum_stock' => 5.00,
            'specification' => 'Thick 10mm, Width 1500mm, Length 3000mm JIS G3101 SS400',
        ]);

        // PT B - Plat SS400 10mm 1500x3000 (Identical specification, owned by PT B! With QR)
        $itemB_Plat = Item::create([
            'item_code' => 'PLT-000002',
            'company_id' => $ptB->id,
            'category_id' => $catPlat->id,
            'type_id' => $typePlatBesi->id,
            'unit_id' => $unitLbr->id,
            'name' => 'Plat SS400 10mm 1500x3000',
            'barcode' => '899123400002',
            'qr_code' => 'QR-PLT-000002-PTB',
            'minimum_stock' => 5.00,
            'specification' => 'Thick 10mm, Width 1500mm, Length 3000mm JIS G3101 SS400',
        ]);

        // PT A - Pipa CS 2" SCH40 (With QR)
        $itemA_Pipa = Item::create([
            'item_code' => 'PIP-000001',
            'company_id' => $ptA->id,
            'category_id' => $catPipa->id,
            'type_id' => $typePipaCS->id,
            'unit_id' => $unitBtg->id,
            'name' => 'Pipa CS 2" SCH40 x 6M',
            'barcode' => '899123400003',
            'qr_code' => 'QR-PIP-000001-PTA',
            'minimum_stock' => 10.00,
            'specification' => 'Seamless Carbon Steel Pipe ASTM A106 Gr. B',
        ]);

        // PT B - Pipa CS 2" SCH40 (With QR)
        $itemB_Pipa = Item::create([
            'item_code' => 'PIP-000002',
            'company_id' => $ptB->id,
            'category_id' => $catPipa->id,
            'type_id' => $typePipaCS->id,
            'unit_id' => $unitBtg->id,
            'name' => 'Pipa CS 2" SCH40 x 6M',
            'barcode' => '899123400004',
            'qr_code' => 'QR-PIP-000002-PTB',
            'minimum_stock' => 10.00,
            'specification' => 'Seamless Carbon Steel Pipe ASTM A106 Gr. B',
        ]);

        // PT A - Baut M12 x 50 (Tanpa QR / Non-QR!)
        $itemA_Baut = Item::create([
            'item_code' => 'BRG-000001',
            'company_id' => $ptA->id,
            'category_id' => $catConsumable->id,
            'type_id' => $typeBaut->id,
            'unit_id' => $unitPcs->id,
            'name' => 'Baut M12 x 50 Hex Bolt Grade 8.8',
            'barcode' => null,
            'qr_code' => null, // Completely without QR/barcode as requested in Section 9
            'minimum_stock' => 50.00,
            'specification' => 'Full thread hexagon bolt high tensile',
        ]);

        // PT B - Baut M12 x 50 (Tanpa QR / Non-QR!)
        $itemB_Baut = Item::create([
            'item_code' => 'BRG-000002',
            'company_id' => $ptB->id,
            'category_id' => $catConsumable->id,
            'type_id' => $typeBaut->id,
            'unit_id' => $unitPcs->id,
            'name' => 'Baut M12 x 50 Hex Bolt Grade 8.8',
            'barcode' => null,
            'qr_code' => null,
            'minimum_stock' => 50.00,
            'specification' => 'Full thread hexagon bolt high tensile',
        ]);

        // PT A - Bearing SKF 6205 (With Barcode only)
        $itemA_Bearing = Item::create([
            'item_code' => 'SPR-000001',
            'company_id' => $ptA->id,
            'category_id' => $catSparepart->id,
            'type_id' => $typeBearing->id,
            'unit_id' => $unitPcs->id,
            'name' => 'Deep Groove Ball Bearing SKF 6205-2RS',
            'barcode' => '7316570517891',
            'qr_code' => null,
            'minimum_stock' => 4.00,
            'specification' => 'Rubber seal both sides, inner dia 25mm, outer dia 52mm',
        ]);

        // PT A - Kabel Power NYY 4x6mm (With QR)
        $itemA_Kabel = Item::create([
            'item_code' => 'ELC-000001',
            'company_id' => $ptA->id,
            'category_id' => $catLainnya->id,
            'type_id' => $typeKabel->id,
            'unit_id' => $unitMtr->id,
            'name' => 'Kabel Power NYY 4 x 6 mm2 Supreme',
            'barcode' => null,
            'qr_code' => 'QR-ELC-000001-PTA',
            'minimum_stock' => 100.00,
            'specification' => 'Tegangan 0.6/1 kV Cu/PVC/PVC',
        ]);

        // PT B - Plat Stainless SUS304 3mm (With QR)
        $itemB_PlatSS = Item::create([
            'item_code' => 'PLT-000003',
            'company_id' => $ptB->id,
            'category_id' => $catPlat->id,
            'type_id' => $typePlatSS->id,
            'unit_id' => $unitLbr->id,
            'name' => 'Plat Stainless Steel SUS 304 3mm 4x8',
            'barcode' => '899123400010',
            'qr_code' => 'QR-PLT-000003-PTB',
            'minimum_stock' => 2.00,
            'specification' => 'Finish 2B, 1219 x 2438 mm',
        ]);

        // PT B - Elektroda Las LB-52 3.2mm (Without QR)
        $itemB_Elektroda = Item::create([
            'item_code' => 'CSM-000001',
            'company_id' => $ptB->id,
            'category_id' => $catConsumable->id,
            'type_id' => $typeBaut->id,
            'unit_id' => $unitKg->id,
            'name' => 'Kawat Las Kobelco LB-52 3.2 mm',
            'barcode' => null,
            'qr_code' => null,
            'minimum_stock' => 20.00,
            'specification' => 'Low hydrogen type for mild and 490MPa steel',
        ]);

        // 9. INITIAL GOODS RECEIPTS (Multi-Item Receiving Transactions)
        // Receipt PT A: 25 Lembar Plat SS400, 30 Batang Pipa, 200 PCS Baut
        $grA = GoodsReceipt::create([
            'receipt_number' => 'GR-2026-000001',
            'company_id' => $ptA->id,
            'supplier_id' => $sup1->id,
            'warehouse_id' => $warehouse->id,
            'delivery_order_number' => 'SJ-MBN-9921',
            'received_date' => now()->subDays(5)->toDateString(),
            'received_by' => $kepalaUser->id,
            'status' => 'completed',
            'notes' => 'Penerimaan batch awal material proyek PT A',
        ]);

        GoodsReceiptItem::create(['goods_receipt_id' => $grA->id, 'item_id' => $itemA_Plat->id, 'warehouse_location_id' => $locA1->id, 'qty' => 25.00]);
        GoodsReceiptItem::create(['goods_receipt_id' => $grA->id, 'item_id' => $itemA_Pipa->id, 'warehouse_location_id' => $locA2->id, 'qty' => 30.00]);
        GoodsReceiptItem::create(['goods_receipt_id' => $grA->id, 'item_id' => $itemA_Baut->id, 'warehouse_location_id' => $locB1->id, 'qty' => 200.00]);

        $stockService->moveStock($ptA->id, $itemA_Plat->id, $locA1->id, 'IN', GoodsReceipt::class, $grA->id, $grA->receipt_number, 25.00, 'Penerimaan awal', $kepalaUser->id);
        $stockService->moveStock($ptA->id, $itemA_Pipa->id, $locA2->id, 'IN', GoodsReceipt::class, $grA->id, $grA->receipt_number, 30.00, 'Penerimaan awal', $kepalaUser->id);
        $stockService->moveStock($ptA->id, $itemA_Baut->id, $locB1->id, 'IN', GoodsReceipt::class, $grA->id, $grA->receipt_number, 200.00, 'Penerimaan awal', $kepalaUser->id);

        // Receipt PT B: 15 Lembar Plat SS400, 20 Batang Pipa, 150 PCS Baut
        $grB = GoodsReceipt::create([
            'receipt_number' => 'GR-2026-000002',
            'company_id' => $ptB->id,
            'supplier_id' => $sup2->id,
            'warehouse_id' => $warehouse->id,
            'delivery_order_number' => 'SJ-LJA-4410',
            'received_date' => now()->subDays(4)->toDateString(),
            'received_by' => $kepalaUser->id,
            'status' => 'completed',
            'notes' => 'Penerimaan batch awal material proyek PT B',
        ]);

        GoodsReceiptItem::create(['goods_receipt_id' => $grB->id, 'item_id' => $itemB_Plat->id, 'warehouse_location_id' => $locA3->id, 'qty' => 15.00]);
        GoodsReceiptItem::create(['goods_receipt_id' => $grB->id, 'item_id' => $itemB_Pipa->id, 'warehouse_location_id' => $locB2->id, 'qty' => 20.00]);
        GoodsReceiptItem::create(['goods_receipt_id' => $grB->id, 'item_id' => $itemB_Baut->id, 'warehouse_location_id' => $locB1->id, 'qty' => 150.00]);

        $stockService->moveStock($ptB->id, $itemB_Plat->id, $locA3->id, 'IN', GoodsReceipt::class, $grB->id, $grB->receipt_number, 15.00, 'Penerimaan awal PT B', $kepalaUser->id);
        $stockService->moveStock($ptB->id, $itemB_Pipa->id, $locB2->id, 'IN', GoodsReceipt::class, $grB->id, $grB->receipt_number, 20.00, 'Penerimaan awal PT B', $kepalaUser->id);
        $stockService->moveStock($ptB->id, $itemB_Baut->id, $locB1->id, 'IN', GoodsReceipt::class, $grB->id, $grB->receipt_number, 150.00, 'Penerimaan awal PT B', $kepalaUser->id);

        // 10. STOCK ISSUE SAMPLE (Barang Keluar ke Lapangan)
        // Keluar PT A: 5 Lembar Plat, 100 PCS Baut ke Proyek Tangki Oleo
        $outA = StockIssue::create([
            'issue_number' => 'OUT-2026-000001',
            'company_id' => $ptA->id,
            'project_name' => 'Proyek Fabrikasi Tangki Oleo Cilegon',
            'requester_name' => 'Deni Setiawan (Site Manager)',
            'recipient_name' => 'Andi Wijaya (Driver/Teknisi)',
            'issued_date' => now()->subDays(2)->toDateString(),
            'issued_by' => $karyawanUser->id,
            'status' => 'partially_returned',
            'notes' => 'Pengeluaran material untuk fabrikasi shell tangki',
        ]);

        $outA_itemPlat = StockIssueItem::create([
            'stock_issue_id' => $outA->id,
            'item_id' => $itemA_Plat->id,
            'warehouse_location_id' => $locA1->id,
            'qty_issued' => 5.00,
            'qty_used' => 3.00,
            'qty_returned' => 2.00,
            'qty_lost' => 0.00,
            'notes' => '3 lembar dipakai penuh, 1 lembar utuh kembali, 1 lembar dipotong menghasilkan sisa',
        ]);

        $outA_itemBaut = StockIssueItem::create([
            'stock_issue_id' => $outA->id,
            'item_id' => $itemA_Baut->id,
            'warehouse_location_id' => $locB1->id,
            'qty_issued' => 100.00,
            'qty_used' => 80.00,
            'qty_returned' => 20.00,
            'qty_lost' => 0.00,
            'notes' => '20 pcs kelebihan pasang dikembalikan',
        ]);

        // Reduce stock via OUT
        $stockService->moveStock($ptA->id, $itemA_Plat->id, $locA1->id, 'OUT', StockIssue::class, $outA->id, $outA->issue_number, -5.00, 'Pengeluaran ke proyek tangki', $karyawanUser->id);
        $stockService->moveStock($ptA->id, $itemA_Baut->id, $locB1->id, 'OUT', StockIssue::class, $outA->id, $outA->issue_number, -100.00, 'Pengeluaran baut proyek tangki', $karyawanUser->id);

        // Attachment sample for OUT
        Attachment::create([
            'entity_type' => StockIssue::class,
            'entity_id' => $outA->id,
            'file_path' => 'attachments/sample_handover_out.jpg',
            'file_name' => 'foto_serah_terima_out001.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 245100,
            'attachment_type' => 'photo_handover',
            'uploaded_by' => $karyawanUser->id,
        ]);

        // 11. STOCK RETURN & REMNANT SAMPLE
        // Return 1: Baut kelebihan 20 PCS dan Plat kembali 2 Lembar (1 utuh kelebihan + 1 sisa material terpotong)
        $retA = StockReturn::create([
            'return_number' => 'RET-2026-000001',
            'stock_issue_id' => $outA->id,
            'company_id' => $ptA->id,
            'returned_date' => now()->subDay()->toDateString(),
            'returned_by_name' => 'Andi Wijaya',
            'received_by' => $kepalaUser->id,
            'notes' => 'Pengembalian sisa pengerjaan tangki batch 1',
        ]);

        $retA_itemBaut = StockReturnItem::create([
            'stock_return_id' => $retA->id,
            'stock_issue_item_id' => $outA_itemBaut->id,
            'item_id' => $itemA_Baut->id,
            'warehouse_location_id' => $locB1->id,
            'qty_returned' => 20.00,
            'return_status' => 'kelebihan',
            'condition' => 'good',
            'notes' => 'Kelebihan belum dibuka, kondisi sangat baik',
        ]);

        $retA_itemPlatUtuh = StockReturnItem::create([
            'stock_return_id' => $retA->id,
            'stock_issue_item_id' => $outA_itemPlat->id,
            'item_id' => $itemA_Plat->id,
            'warehouse_location_id' => $locA1->id,
            'qty_returned' => 1.00,
            'return_status' => 'tidak_terpakai',
            'condition' => 'good',
            'notes' => '1 lembar utuh tidak sempat dipotong',
        ]);

        $retA_itemPlatSisa = StockReturnItem::create([
            'stock_return_id' => $retA->id,
            'stock_issue_item_id' => $outA_itemPlat->id,
            'item_id' => $itemA_Plat->id,
            'warehouse_location_id' => $locSisa->id,
            'qty_returned' => 1.00,
            'return_status' => 'sisa_material',
            'condition' => 'good',
            'notes' => 'Sisa potongan plat tidak beraturan',
        ]);

        // Stock additions via RETURN
        $stockService->moveStock($ptA->id, $itemA_Baut->id, $locB1->id, 'RETURN', StockReturn::class, $retA->id, $retA->return_number, 20.00, 'Pengembalian kelebihan baut', $kepalaUser->id);
        $stockService->moveStock($ptA->id, $itemA_Plat->id, $locA1->id, 'RETURN', StockReturn::class, $retA->id, $retA->return_number, 1.00, 'Pengembalian plat utuh', $kepalaUser->id);
        $stockService->moveStock($ptA->id, $itemA_Plat->id, $locSisa->id, 'RETURN', StockReturn::class, $retA->id, $retA->return_number, 1.00, 'Pengembalian plat sisa potongan', $kepalaUser->id);

        // 12. MATERIAL REMNANT RECORD (Traceable to parent item & transaction)
        MaterialRemnant::create([
            'remnant_code' => 'PLT-000001-S01',
            'company_id' => $ptA->id,
            'parent_item_id' => $itemA_Plat->id,
            'derived_item_id' => null,
            'stock_issue_id' => $outA->id,
            'stock_return_item_id' => $retA_itemPlatSisa->id,
            'warehouse_location_id' => $locSisa->id,
            'shape_condition' => 'Tidak Beraturan (Potongan L-Shape)',
            'dimension_description' => '1200 x 800 mm (Tebal 10mm)',
            'estimated_area' => 0.7500,
            'estimated_weight' => 15.5000,
            'qty' => 1.00,
            'unit_id' => $unitLbr->id,
            'status' => 'available',
            'notes' => 'Berasal dari potongan shell tangki proyek Oleo Cilegon',
            'created_by' => $kepalaUser->id,
        ]);

        // Resulting balances:
        // PT A Plat SS400: Received 25 - Out 5 + Returned 2 = 22 Lembar (Loc A-01-01: 21, Loc ZONE-SISA: 1)
        // PT B Plat SS400: Received 15 = 15 Lembar (Loc A-01-03: 15) -> Demonstrates clear separation of 2 PTs!

        AuditLog::record('SYSTEM_SEED', null, null, null, ['status' => 'Initial seed complete']);
    }
}
