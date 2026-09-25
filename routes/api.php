<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\GoodsReceiptController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\MasterController;
use App\Http\Controllers\Api\MaterialRemnantController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\StockIssueController;
use App\Http\Controllers\Api\StockReturnController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes: Warehouse Management System 2 PT
|--------------------------------------------------------------------------
*/

// Public Authentication
Route::post('/auth/login', [AuthController::class, 'login']);

// Protected Routes (Token Auth)
Route::middleware('auth:sanctum')->group(function () {
    // Current User & Logout
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Dashboard & Global Utilities
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::post('/uploads', [UploadController::class, 'upload']);
    Route::get('/scan/{code}', [StockController::class, 'scan']);

    // Master Data (Read: All Roles)
    Route::get('/companies', [CompanyController::class, 'index']);
    Route::get('/companies/{company}', [CompanyController::class, 'show']);
    Route::get('/categories', [MasterController::class, 'getCategories']);
    Route::get('/types', [MasterController::class, 'getTypes']);
    Route::get('/units', [MasterController::class, 'getUnits']);
    Route::get('/suppliers', [MasterController::class, 'getSuppliers']);
    Route::get('/warehouses', [MasterController::class, 'getWarehouses']);
    Route::get('/locations', [MasterController::class, 'getLocations']);

    // Items (Read: All Roles)
    Route::get('/items/check-duplicate', [ItemController::class, 'checkDuplicate']);
    Route::get('/items/search', [ItemController::class, 'searchQuick']);
    Route::get('/items', [ItemController::class, 'index']);
    Route::get('/items/{item}', [ItemController::class, 'show']);

    // Reports: Stock Balances & Movements (Restricted to Kepala Gudang & Admin)
    Route::middleware('role:kepala_gudang,admin')->group(function () {
        Route::get('/stock', [StockController::class, 'index']);
        Route::get('/stock/movements', [StockController::class, 'movements']);
    });

    // Stock & Inventory Balances
    Route::get('/stock/{item}', [StockController::class, 'showItemStock']);

    // Transactions: Read (All Roles)
    Route::get('/goods-receipts/export-data', [GoodsReceiptController::class, 'exportData']);
    Route::get('/goods-receipts', [GoodsReceiptController::class, 'index']);
    Route::get('/goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show']);
    Route::get('/goods-issues', [StockIssueController::class, 'index']);
    Route::get('/goods-issues/{stockIssue}', [StockIssueController::class, 'show']);
    Route::get('/returns', [StockReturnController::class, 'index']);
    Route::get('/returns/{stockReturn}', [StockReturnController::class, 'show']);
    Route::get('/remnants', [MaterialRemnantController::class, 'index']);
    Route::get('/remnants/{materialRemnant}', [MaterialRemnantController::class, 'show']);

    // Operational, Transactions, and Master Management (Karyawan, Kepala Gudang, and Admin)
    Route::middleware('role:karyawan,kepala_gudang,admin')->group(function () {
        // Transactions: Stock Issue & Returns
        Route::post('/goods-issues', [StockIssueController::class, 'store']);
        Route::post('/returns', [StockReturnController::class, 'store']);

        // Goods Receipts (Barang Masuk)
        Route::post('/goods-receipts', [GoodsReceiptController::class, 'store']);

        // Item Master Management
        Route::post('/items', [ItemController::class, 'store']);
        Route::put('/items/{item}', [ItemController::class, 'update']);
        Route::delete('/items/{item}', [ItemController::class, 'destroy']);

        // Material Remnants manual creation / update
        Route::post('/remnants', [MaterialRemnantController::class, 'store']);
        Route::put('/remnants/{materialRemnant}', [MaterialRemnantController::class, 'update']);

        // Dynamic Master Management
        Route::post('/categories', [MasterController::class, 'storeCategory']);
        Route::put('/categories/{category}', [MasterController::class, 'updateCategory']);
        Route::delete('/categories/{category}', [MasterController::class, 'destroyCategory']);

        Route::post('/types', [MasterController::class, 'storeType']);

        Route::post('/units', [MasterController::class, 'storeUnit']);
        Route::put('/units/{unit}', [MasterController::class, 'updateUnit']);
        Route::delete('/units/{unit}', [MasterController::class, 'destroyUnit']);

        Route::post('/suppliers', [MasterController::class, 'storeSupplier']);
        Route::put('/suppliers/{supplier}', [MasterController::class, 'updateSupplier']);
        Route::delete('/suppliers/{supplier}', [MasterController::class, 'destroySupplier']);

        Route::post('/warehouses', [MasterController::class, 'storeWarehouse']);
        Route::post('/locations', [MasterController::class, 'storeLocation']);
        Route::put('/locations/{location}', [MasterController::class, 'updateLocation']);
        Route::delete('/locations/{location}', [MasterController::class, 'destroyLocation']);

        // Multi-company Management
        Route::post('/companies', [CompanyController::class, 'store']);
        Route::put('/companies/{company}', [CompanyController::class, 'update']);
        Route::delete('/companies/{company}', [CompanyController::class, 'destroy']);
    });

    // User & Access Management (Restricted to Kepala Gudang & Admin)
    Route::middleware('role:kepala_gudang,admin')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::get('/roles', [UserController::class, 'roles']);
    });
});
