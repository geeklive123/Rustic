<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CashShiftController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\KitchenController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->json(['data' => ['status' => 'ok']]));
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    Route::middleware(['auth:sanctum', 'api.token', 'throttle:120,1,authenticated-'])->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::middleware('permission:catalog.view')->group(function (): void {
            Route::get('/catalog', [CatalogController::class, 'catalog']);
            foreach (['categories', 'extras', 'options'] as $type) {
                Route::get('/'.$type, [CatalogController::class, 'index'])->defaults('type', $type);
            }
        });
        Route::middleware('permission:menu.manage')->group(function (): void {
            Route::post('/admin/catalog/{type}', [CatalogController::class, 'write'])->whereIn('type', ['categories', 'products', 'extras', 'options']);
            Route::patch('/admin/catalog/{type}/{id}', [CatalogController::class, 'write'])->whereIn('type', ['categories', 'products', 'extras', 'options'])->whereNumber('id');
        });
        Route::post('/orders', [OrderController::class, 'store'])->middleware('permission:orders.create');
        Route::get('/kitchen/orders', [KitchenController::class, 'index'])->middleware('permission:kitchen.view');
        Route::get('/kitchen/orders/{uuid}', [KitchenController::class, 'show'])->whereUuid('uuid')->middleware('permission:kitchen.view');
        Route::patch('/kitchen/orders/{uuid}/status', [KitchenController::class, 'update'])->whereUuid('uuid')->middleware('permission:orders.view');
        Route::get('/orders/{uuid}/status', [KitchenController::class, 'status'])->whereUuid('uuid')->middleware('permission:orders.view');
        Route::middleware(['permission:reports.view', 'throttle:60,1'])->prefix('reports')->group(function (): void {
            Route::get('/summary', [ReportController::class, 'summary']);
            Route::get('/sales', [ReportController::class, 'sales']);
            Route::get('/products', [ReportController::class, 'products']);
            Route::get('/payments', [ReportController::class, 'payments']);
        });
        Route::middleware(['permission:cash.manage', 'throttle:60,1'])->prefix('cash-shifts')->group(function (): void {
            Route::post('/open', [CashShiftController::class, 'open']);
            Route::get('/current', [CashShiftController::class, 'current']);
            Route::post('/{id}/close', [CashShiftController::class, 'close'])->whereNumber('id');
            Route::get('/', [CashShiftController::class, 'index']);
        });
        Route::middleware('permission:orders.view')->group(function (): void {
            Route::get('/orders', [OrderController::class, 'index']);
            Route::get('/orders/{uuid}/sync-status', [OrderController::class, 'syncStatus'])->whereUuid('uuid');
            Route::get('/orders/{uuid}', [OrderController::class, 'show'])->whereUuid('uuid');
        });
        Route::middleware('permission:users.manage')->group(function (): void {
            Route::get('/users', [UserController::class, 'index']);
            Route::post('/users', [UserController::class, 'store']);
            Route::get('/users/{user}', [UserController::class, 'show']);
            Route::patch('/users/{user}', [UserController::class, 'update']);
        });
    });
});
