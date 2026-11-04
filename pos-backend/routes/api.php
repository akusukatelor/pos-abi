<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DiscountController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\OwnerDashboardController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseReceiptController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RestockRecommendationController;
use App\Http\Controllers\Api\RestockSettingController;
use App\Http\Controllers\Api\ServiceTransactionController;
use App\Http\Controllers\Api\ServiceTypeController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\StockMovementController;
use App\Http\Controllers\Api\StockOpnameController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\UnitController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
*/
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:5,1');

/*
|--------------------------------------------------------------------------
| Authenticated routes
|--------------------------------------------------------------------------
|
| Role mapping for the current database schema:
| - admin = Owner (terminology used by SRS v0.2)
| - kasir = Kasir
|
*/
Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);

    // Read-only data needed by both Owner and Kasir.
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('categories/{id}', [CategoryController::class, 'show']);
    Route::get('units', [UnitController::class, 'index']);
    Route::get('units/{id}', [UnitController::class, 'show']);
    Route::get('suppliers', [SupplierController::class, 'index']);
    Route::get('suppliers/{id}', [SupplierController::class, 'show']);
    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{id}', [ProductController::class, 'show']);

    // Cashier and Owner may process sales. The controller scopes Cashier history to their own sales.
    Route::get('transactions', [TransactionController::class, 'index']);
    Route::post('transactions', [TransactionController::class, 'store']);
    Route::get('transactions/{id}', [TransactionController::class, 'show']);

    // Stock history is visible internally; manual adjustments are Owner-only.
    Route::get('stock-movements', [StockMovementController::class, 'index']);

    // Owner and Kasir may view service types and record successful digital-service transactions.
    Route::get('service-types', [ServiceTypeController::class, 'index']);
    Route::get('service-types/{id}', [ServiceTypeController::class, 'show']);
    Route::get('service-transactions', [ServiceTransactionController::class, 'index']);
    Route::post('service-transactions', [ServiceTransactionController::class, 'store']);
    Route::get('service-transactions/{id}', [ServiceTransactionController::class, 'show']);

    // Owner and Kasir may record and confirm supplier receipts and submit stock counts.
    Route::middleware('role:admin,kasir')->group(function (): void {
        Route::get('purchase-receipts', [PurchaseReceiptController::class, 'index']);
        Route::post('purchase-receipts', [PurchaseReceiptController::class, 'store']);
        Route::get('purchase-receipts/{id}', [PurchaseReceiptController::class, 'show']);
        Route::post('purchase-receipts/{id}/confirm', [PurchaseReceiptController::class, 'confirm']);
        Route::delete('purchase-receipts/{id}', [PurchaseReceiptController::class, 'destroy']);

        Route::get('stock-opnames', [StockOpnameController::class, 'index']);
        Route::post('stock-opnames', [StockOpnameController::class, 'store']);
        Route::get('stock-opnames/{id}', [StockOpnameController::class, 'show']);
    });

    // Only Owner may authorize a stock correction after physical counting.
    Route::middleware('role:admin')->group(function (): void {
        Route::post('stock-opnames/{id}/approve', [StockOpnameController::class, 'approve']);
        Route::post('stock-opnames/{id}/reject', [StockOpnameController::class, 'reject']);
    });

    // Owner-only verification, cancellation, reporting, and master-data administration.
    Route::middleware('role:admin')->group(function (): void {
        Route::post('service-types', [ServiceTypeController::class, 'store']);
        Route::put('service-types/{id}', [ServiceTypeController::class, 'update']);
        Route::patch('service-types/{id}', [ServiceTypeController::class, 'update']);
        Route::delete('service-types/{id}', [ServiceTypeController::class, 'destroy']);

        Route::post('service-transactions/{id}/verify-margin', [ServiceTransactionController::class, 'verifyMargin']);
        Route::post('service-transactions/{id}/cancel', [ServiceTransactionController::class, 'cancel']);

        Route::get('reports/summary', [ReportController::class, 'summary']);
        Route::get('reports/sales', [ReportController::class, 'sales']);
        Route::get('reports/purchases', [ReportController::class, 'purchases']);
        Route::get('reports/inventory', [ReportController::class, 'inventory']);
        Route::get('reports/services', [ReportController::class, 'services']);

        Route::get('dashboard/owner', [OwnerDashboardController::class, 'show']);
        Route::get('restock-settings', [RestockSettingController::class, 'index']);
        Route::put('restock-settings/{productId}', [RestockSettingController::class, 'update'])->whereNumber('productId');
        Route::get('restock-recommendations', [RestockRecommendationController::class, 'index']);

        Route::apiResource('users', UserController::class);

        Route::post('categories', [CategoryController::class, 'store']);
        Route::put('categories/{id}', [CategoryController::class, 'update']);
        Route::patch('categories/{id}', [CategoryController::class, 'update']);
        Route::delete('categories/{id}', [CategoryController::class, 'destroy']);

        Route::post('units', [UnitController::class, 'store']);
        Route::put('units/{id}', [UnitController::class, 'update']);
        Route::patch('units/{id}', [UnitController::class, 'update']);
        Route::delete('units/{id}', [UnitController::class, 'destroy']);

        Route::post('suppliers', [SupplierController::class, 'store']);
        Route::put('suppliers/{id}', [SupplierController::class, 'update']);
        Route::patch('suppliers/{id}', [SupplierController::class, 'update']);
        Route::delete('suppliers/{id}', [SupplierController::class, 'destroy']);

        Route::apiResource('customers', CustomerController::class);
        Route::apiResource('expenses', ExpenseController::class);
        Route::apiResource('shifts', ShiftController::class);
        Route::apiResource('discounts', DiscountController::class);

        Route::post('stock-movements', [StockMovementController::class, 'store']);

        Route::get('products/{id}/price-history', [ProductController::class, 'priceHistory'])->whereNumber('id');
        Route::post('products', [ProductController::class, 'store']);
        Route::put('products/{id}', [ProductController::class, 'update']);
        Route::patch('products/{id}', [ProductController::class, 'update']);
        Route::delete('products/{id}', [ProductController::class, 'destroy']);
    });
});
