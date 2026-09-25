<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\StockBalance;
use App\Models\StockIssue;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Tests\TestCase;

class WarehouseWorkflowTest extends TestCase
{
    protected User $kepalaGudang;
    protected User $karyawan;
    protected Company $ptA;
    protected Company $ptB;
    protected Warehouse $warehouse;
    protected WarehouseLocation $location;
    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kepalaGudang = User::where('email', 'kepala@warehouse.test')->first();
        $this->karyawan = User::where('email', 'karyawan@warehouse.test')->first();
        $this->ptA = Company::where('code', 'PT-A')->first();
        $this->ptB = Company::where('code', 'PT-B')->first();
        $this->warehouse = Warehouse::first();
        $this->location = WarehouseLocation::first();
        $this->supplier = Supplier::first();
    }

    public function test_pt_separation_stocks_remain_independent_for_identical_items(): void
    {
        // Both PT A and PT B have 'Plat SS400 10mm 1500x3000'
        $itemA = Item::where('company_id', $this->ptA->id)
            ->where('name', 'Plat SS400 10mm 1500x3000')
            ->first();

        $itemB = Item::where('company_id', $this->ptB->id)
            ->where('name', 'Plat SS400 10mm 1500x3000')
            ->first();

        $this->assertNotNull($itemA);
        $this->assertNotNull($itemB);
        $this->assertNotEquals($itemA->id, $itemB->id);
        $this->assertEquals($this->ptA->id, $itemA->company_id);
        $this->assertEquals($this->ptB->id, $itemB->company_id);

        // Verify independent stock totals (PT A: 22, PT B: 15)
        $this->assertEquals(22.00, $itemA->total_stock);
        $this->assertEquals(15.00, $itemB->total_stock);
    }

    public function test_duplicate_prevention_warns_on_similar_name(): void
    {
        $response = $this->actingAs($this->kepalaGudang, 'sanctum')
            ->getJson('/api/items/check-duplicate?name=' . urlencode('Plat SS400 10mm'));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $this->assertTrue(count($data) >= 2); // Should find items from both PT A and PT B
    }

    public function test_create_item_with_dynamic_category_and_unit(): void
    {
        $response = $this->actingAs($this->kepalaGudang, 'sanctum')
            ->postJson('/api/items', [
                'company_id' => $this->ptA->id,
                'name' => 'Elbow 90 Derajat CS 2 Inch',
                'category_name' => 'Fitting', // New dynamic category
                'type_name' => 'Elbow CS',   // New dynamic type
                'unit_code' => 'PCS',
                'minimum_stock' => 5,
                'generate_qr' => true,
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Elbow 90 Derajat CS 2 Inch',
                ],
            ]);

        $this->assertDatabaseHas('categories', ['name' => 'Fitting']);
        $this->assertDatabaseHas('types', ['name' => 'Elbow CS']);
    }

    public function test_goods_receipt_increases_stock_and_records_movement(): void
    {
        $item = Item::where('company_id', $this->ptA->id)->first();
        $stockBefore = $item->total_stock;

        $response = $this->actingAs($this->kepalaGudang, 'sanctum')
            ->postJson('/api/goods-receipts', [
                'company_id' => $this->ptA->id,
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'delivery_order_number' => 'SJ-TEST-1001',
                'received_date' => now()->toDateString(),
                'items' => [
                    [
                        'item_id' => $item->id,
                        'warehouse_location_id' => $this->location->id,
                        'qty' => 10.00,
                        'condition' => 'good',
                    ],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        $item->refresh();
        $this->assertEquals($stockBefore + 10.00, $item->total_stock);

        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $item->id,
            'movement_type' => 'IN',
            'qty' => 10.00,
        ]);
    }

    public function test_goods_receipt_with_manual_supplier_and_without_delivery_order(): void
    {
        $item = Item::where('company_id', $this->ptA->id)->first();
        $stockBefore = $item->total_stock;

        $response = $this->actingAs($this->kepalaGudang, 'sanctum')
            ->postJson('/api/goods-receipts', [
                'company_id' => $this->ptA->id,
                'supplier_name' => 'Supplier Toko Jaya Abadi',
                'received_date' => now()->toDateString(),
                'items' => [
                    [
                        'item_id' => $item->id,
                        'warehouse_location_id' => $this->location->id,
                        'qty' => 5.00,
                        'condition' => 'good',
                    ],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        $item->refresh();
        $this->assertEquals($stockBefore + 5.00, $item->total_stock);

        $this->assertDatabaseHas('goods_receipts', [
            'supplier_name' => 'Supplier Toko Jaya Abadi',
            'supplier_id' => null,
            'delivery_order_number' => null,
        ]);
    }

    public function test_stock_issue_reduces_stock_successfully(): void
    {
        $item = Item::where('company_id', $this->ptA->id)
            ->where('name', 'Pipa CS 2" SCH40 x 6M')
            ->first();
        $stockBefore = $item->total_stock;

        $response = $this->actingAs($this->karyawan, 'sanctum')
            ->postJson('/api/goods-issues', [
                'company_id' => $this->ptA->id,
                'project_name' => 'Proyek Piping Line A',
                'requester_name' => 'Joko',
                'recipient_name' => 'Andi Wijaya',
                'items' => [
                    [
                        'item_id' => $item->id,
                        'warehouse_location_id' => WarehouseLocation::where('code', 'A-01-02')->first()->id,
                        'qty_issued' => 5.00,
                    ],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        $item->refresh();
        $this->assertEquals($stockBefore - 5.00, $item->total_stock);
    }

    public function test_stock_issue_cannot_cause_negative_stock(): void
    {
        $item = Item::where('company_id', $this->ptA->id)->first();
        $stockLoc = StockBalance::where('item_id', $item->id)->first();
        $excessiveQty = ($stockLoc ? (float) $stockLoc->qty : 0.0) + 9999.00;

        $response = $this->actingAs($this->karyawan, 'sanctum')
            ->postJson('/api/goods-issues', [
                'company_id' => $this->ptA->id,
                'project_name' => 'Proyek Gagal',
                'requester_name' => 'Joko',
                'recipient_name' => 'Andi',
                'items' => [
                    [
                        'item_id' => $item->id,
                        'warehouse_location_id' => $stockLoc->warehouse_location_id,
                        'qty_issued' => $excessiveQty,
                    ],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_stock_return_updates_issue_and_restores_stock(): void
    {
        $item = Item::where('company_id', $this->ptA->id)->first();
        $location = WarehouseLocation::where('code', 'A-01-01')->first();

        // Issue 3 items first
        $issueResponse = $this->actingAs($this->karyawan, 'sanctum')
            ->postJson('/api/goods-issues', [
                'company_id' => $this->ptA->id,
                'project_name' => 'Proyek Uji Return',
                'requester_name' => 'Mandor',
                'recipient_name' => 'Andi Wijaya',
                'items' => [
                    [
                        'item_id' => $item->id,
                        'warehouse_location_id' => $location->id,
                        'qty_issued' => 3.00,
                    ],
                ],
            ]);
        $issueResponse->assertStatus(201);
        $issueData = $issueResponse->json('data');
        $issueItemId = $issueData['items'][0]['id'];
        $stockBefore = $item->fresh()->total_stock;

        // Return 1 item with remnant
        $response = $this->actingAs($this->karyawan, 'sanctum')
            ->postJson('/api/returns', [
                'stock_issue_id' => $issueData['id'],
                'returned_by_name' => 'Andi Wijaya',
                'items' => [
                    [
                        'stock_issue_item_id' => $issueItemId,
                        'warehouse_location_id' => $location->id,
                        'qty_returned' => 1.00,
                        'return_status' => 'sisa_material',
                        'condition' => 'good',
                        'material_remnant' => [
                            'shape_condition' => 'Persegi Sisa Potong',
                            'dimension_description' => '500 x 500 mm',
                            'estimated_area' => 0.25,
                        ],
                    ],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        $this->assertEquals($stockBefore + 1.00, $item->fresh()->total_stock);

        $this->assertDatabaseHas('material_remnants', [
            'stock_issue_id' => $issueData['id'],
            'shape_condition' => 'Persegi Sisa Potong',
        ]);
    }

    public function test_karyawan_cannot_create_master_item(): void
    {
        $response = $this->actingAs($this->karyawan, 'sanctum')
            ->postJson('/api/items', [
                'company_id' => $this->ptA->id,
                'name' => 'Barang Ilegal Oleh Karyawan',
                'category_id' => 1,
                'type_id' => 1,
                'unit_id' => 1,
            ]);

        $response->assertStatus(403);
    }
}
