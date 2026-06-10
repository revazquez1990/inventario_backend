<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\MovementController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ['status' => 'ok', 'time' => now()->toIso8601String()]);

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/refresh', [AuthController::class, 'refresh']);

    Route::middleware(['auth:api', 'active_user'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

Route::middleware(['auth:api', 'active_user', 'role:admin'])->group(function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/users/{user}', [UserController::class, 'show']);
    Route::put('/users/{user}', [UserController::class, 'update']);
    Route::patch('/users/{user}/status', [UserController::class, 'updateStatus']);

    Route::post('/warehouses', [WarehouseController::class, 'store']);
    Route::get('/warehouses/{warehouse}', [WarehouseController::class, 'show']);
    Route::put('/warehouses/{warehouse}', [WarehouseController::class, 'update']);
    Route::delete('/warehouses/{warehouse}', [WarehouseController::class, 'delete']);
    Route::get('/warehouses/{warehouse}/products', [WarehouseController::class, 'products']);
    Route::put('/warehouses/{warehouse}/products/{product}/price', [WarehouseController::class, 'updateProductPrice']);
});

Route::middleware(['auth:api', 'active_user', 'warehouse'])->group(function () {
    Route::get('/warehouses', [WarehouseController::class, 'index']);

    Route::get('/categories', [CatalogController::class, 'categories']);
    Route::post('/categories', [CatalogController::class, 'storeCategory']);
    Route::get('/categories/{category}', fn (\App\Models\Category $category) => ['data' => $category]);
    Route::put('/categories/{category}', [CatalogController::class, 'updateCategory']);
    Route::delete('/categories/{category}', [CatalogController::class, 'deleteCategory'])->middleware('role:admin');

    Route::get('/units', [CatalogController::class, 'units']);
    Route::post('/units', [CatalogController::class, 'storeUnit'])->middleware('role:admin');
    Route::put('/units/{unit}', [CatalogController::class, 'updateUnit'])->middleware('role:admin');
    Route::delete('/units/{unit}', [CatalogController::class, 'deleteUnit'])->middleware('role:admin');

    Route::get('/attributes', [CatalogController::class, 'attributes']);
    Route::post('/attributes', [CatalogController::class, 'storeAttribute'])->middleware('role:admin');
    Route::put('/attributes/{attribute}', [CatalogController::class, 'updateAttribute'])->middleware('role:admin');
    Route::delete('/attributes/{attribute}', [CatalogController::class, 'deleteAttribute'])->middleware('role:admin');
    Route::get('/attributes/{attribute}/values', [CatalogController::class, 'attributeValues']);
    Route::post('/attributes/{attribute}/values', [CatalogController::class, 'storeAttributeValue'])->middleware('role:admin');
    Route::delete('/attributes/{attribute}/values/{value}', [CatalogController::class, 'deleteAttributeValue'])->middleware('role:admin');

    Route::get('/suppliers', [CatalogController::class, 'suppliers']);
    Route::post('/suppliers', [CatalogController::class, 'storeSupplier']);
    Route::get('/suppliers/{supplier}', [CatalogController::class, 'showSupplier']);
    Route::put('/suppliers/{supplier}', [CatalogController::class, 'updateSupplier']);
    Route::delete('/suppliers/{supplier}', [CatalogController::class, 'deleteSupplier'])->middleware('role:admin');

    Route::get('/products/import-template', [ProductController::class, 'template']);
    Route::post('/products/import/preview', [ProductController::class, 'importPreview']);
    Route::post('/products/import', [ProductController::class, 'import']);
    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
    Route::get('/products/{product}/movements', [ProductController::class, 'movements']);
    Route::put('/products/{product}', [ProductController::class, 'update']);
    Route::post('/products/{product}', [ProductController::class, 'update']);
    Route::delete('/products/{product}', [ProductController::class, 'delete'])->middleware('role:admin');

    Route::get('/exchange-rate/today', [ConfigController::class, 'todayRate']);
    Route::post('/exchange-rate', [ConfigController::class, 'saveRate']);
    Route::get('/exchange-rate', [ConfigController::class, 'rates']);
    Route::get('/settings/tax-rate', [ConfigController::class, 'taxRate']);
    Route::put('/settings/tax-rate', [ConfigController::class, 'updateTaxRate'])->middleware('role:admin');
    Route::get('/settings/business', [ConfigController::class, 'business']);
    Route::put('/settings/business', [ConfigController::class, 'updateBusiness'])->middleware('role:admin');

    Route::get('/movements', [MovementController::class, 'index']);
    Route::get('/movements/{movement}', [MovementController::class, 'show'])->whereNumber('movement');
    Route::post('/movements/entrada', [MovementController::class, 'entrada']);
    Route::post('/movements/salida', [MovementController::class, 'salida']);
    Route::post('/movements/venta', [MovementController::class, 'venta']);
    Route::post('/movements/ajuste', [MovementController::class, 'ajuste'])->middleware('role:admin');
    Route::post('/movements/transferencia', [MovementController::class, 'transferencia']);
    Route::post('/movements/{movement}/anular', [MovementController::class, 'anular'])->whereNumber('movement');

    Route::get('/dashboard/kpis', [ReportController::class, 'kpis']);
    Route::get('/dashboard/sales', [ReportController::class, 'sales']);
    Route::get('/reports/low-stock', [ReportController::class, 'lowStock']);
    Route::get('/reports/movements', [ReportController::class, 'movements']);
});
