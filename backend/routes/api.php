<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\QuoteItemController;
use App\Http\Controllers\SettingsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:login')->post('/register', [AuthController::class, 'register']);
Route::middleware('throttle:login')->post('/login', [AuthController::class, 'login']);
Route::middleware('throttle:login')->post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::middleware('throttle:login')->post('/reset-password', [AuthController::class, 'resetPassword']);

Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
    Route::apiResource('clients', ClientController::class);
    Route::apiResource('products', ProductController::class);
    Route::apiResource('invoices', InvoiceController::class);
    Route::patch('/invoices/{invoice}/status', [InvoiceController::class, 'updateStatus']);
    Route::apiResource('invoices.payments', PaymentController::class)->scoped()->parameters(['payments' => 'payment']);
    Route::get('/payments', [PaymentController::class, 'all']);
    Route::apiResource('quotes', QuoteController::class);
    Route::patch('/quotes/{quote}/status', [QuoteController::class, 'updateStatus']);
    Route::post('/quotes/{quote}/convert', [QuoteController::class, 'convertToInvoice']);
    Route::apiResource('quotes.items', QuoteItemController::class)->scoped(['item' => 'quoteItem'])->parameters(['items' => 'quoteItem']);

    Route::get('/settings', [SettingsController::class, 'show']);
    Route::patch('/settings/profile', [SettingsController::class, 'updateProfile']);
    Route::patch('/settings/company', [SettingsController::class, 'updateCompany']);
    Route::patch('/settings/billing', [SettingsController::class, 'updateBilling']);
    Route::patch('/settings/documents', [SettingsController::class, 'updateDocuments']);
    Route::patch('/settings/notifications', [SettingsController::class, 'updateNotifications']);
    Route::patch('/settings/security', [SettingsController::class, 'updateSecurity']);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
