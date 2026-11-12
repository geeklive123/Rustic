<?php

use App\Http\Controllers\Admin\CashController;
use App\Http\Controllers\Admin\CashMovementController;
use App\Http\Controllers\Admin\CatalogWebController;
use App\Http\Controllers\Admin\ComboCatalogController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LoginController;
use App\Http\Controllers\Admin\OrderCorrectionController;
use App\Http\Controllers\Admin\OrderHistoryController;
use App\Http\Controllers\Admin\SpecialPriceController;
use App\Http\Controllers\DailyReportController;
use App\Http\Middleware\RestrictReportNetwork;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/reportes', DailyReportController::class)
    ->middleware(RestrictReportNetwork::class);

Route::prefix('admin')->group(function (): void {
    Route::get('/login', [LoginController::class, 'form'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:5,1');
    Route::middleware(['auth', 'permission:menu.manage'])->prefix('catalogo')->group(function (): void {
        Route::get('/products/{product}/composicion', [ComboCatalogController::class, 'index'])->name('combo.index');
        Route::get('/products/{product}/composicion/nuevo', [ComboCatalogController::class, 'create'])->name('combo.create');
        Route::post('/products/{product}/composicion', [ComboCatalogController::class, 'store'])->name('combo.store');
        Route::get('/products/{product}/composicion/{component}/editar', [ComboCatalogController::class, 'edit'])->name('combo.edit');
        Route::post('/products/{product}/composicion/{component}', [ComboCatalogController::class, 'update'])->name('combo.update');
        Route::get('/{type}', [CatalogWebController::class, 'index'])->name('catalog.index');
        Route::get('/{type}/nuevo', [CatalogWebController::class, 'create'])->name('catalog.create');
        Route::post('/{type}', [CatalogWebController::class, 'store'])->name('catalog.store');
        Route::get('/{type}/{id}/editar', [CatalogWebController::class, 'edit'])->whereNumber('id')->name('catalog.edit');
        Route::post('/{type}/{id}', [CatalogWebController::class, 'update'])->whereNumber('id')->name('catalog.update');
    });
    Route::middleware(['auth', 'permission:menu.manage'])->group(function (): void {
        Route::get('/precios-especiales', [SpecialPriceController::class, 'index'])->name('special-prices.index');
        Route::post('/precios-especiales', [SpecialPriceController::class, 'store'])->name('special-prices.store');
    });
    Route::middleware(['auth', 'permission:cash.manage'])->group(function (): void {
        Route::get('/', DashboardController::class)->name('admin.home');
        Route::get('/reportes', DailyReportController::class)->name('admin.reports');
        Route::get('/pedidos', [OrderHistoryController::class, 'all'])->name('admin.orders');
        Route::get('/ventas-sin-caja', [OrderHistoryController::class, 'unlinked'])->name('admin.unlinked');
        Route::get('/pedidos/{order}', [OrderHistoryController::class, 'show'])->name('admin.order');
        Route::post('/pedidos/{order}/observaciones', [OrderCorrectionController::class, 'update'])->name('admin.order.notes');
        Route::post('/pedidos/{order}/corregir-qr-simulado', [OrderCorrectionController::class, 'correctPayment'])->name('admin.order.payment-correction');
        Route::post('/logout', [LoginController::class, 'logout'])->name('admin.logout');
        Route::get('/caja', [CashController::class, 'current'])->name('cash.current');
        Route::get('/caja/abrir', [CashController::class, 'openForm'])->name('cash.open');
        Route::post('/caja/abrir', [CashController::class, 'open']);
        Route::get('/caja/historial', [CashController::class, 'history'])->name('cash.history');
        Route::get('/caja/{shift}/movimientos', [CashMovementController::class, 'index'])->name('cash.movements');
        Route::post('/caja/{shift}/movimientos', [CashMovementController::class, 'store'])->name('cash.movements.store');
        Route::post('/caja/{shift}/compensaciones', [CashMovementController::class, 'compensate'])->name('cash.movements.compensate');
        Route::get('/caja/{shift}', [CashController::class, 'show'])->name('cash.show');
        Route::get('/caja/{shift}/pedidos', [CashController::class, 'orders'])->name('cash.orders');
        Route::get('/caja/{shift}/cerrar', [CashController::class, 'closeForm'])->name('cash.close');
        Route::post('/caja/{shift}/cerrar', [CashController::class, 'close']);
    });
});
