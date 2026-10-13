<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
// Import Controller yang baru kita buat
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\UnitController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\StockMovementController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\DiscountController;
use App\Http\Controllers\Api\PaymentMethodController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\TransactionController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Rute Master Data (Sementara belum pakai otentikasi/login agar mudah ditesting)
Route::apiResource('categories', CategoryController::class);
Route::apiResource('units', UnitController::class);
Route::apiResource('suppliers', SupplierController::class);
Route::apiResource('customers', CustomerController::class);
Route::apiResource('stock-movements', StockMovementController::class);
Route::apiResource('expenses', ExpenseController::class);
Route::apiResource('shifts', ShiftController::class);
Route::apiResource('discounts', DiscountController::class);
Route::apiResource('payment-methods', PaymentMethodController::class);
Route::apiResource('products', ProductController::class);
Route::apiResource('transactions', TransactionController::class);