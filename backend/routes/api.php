<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\QuoteItemController;
use App\Http\Controllers\SettingsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

Route::middleware('auth:sanctum')->apiResource('clients', ClientController::class);
Route::middleware('auth:sanctum')->apiResource('products', ProductController::class);
Route::middleware('auth:sanctum')->apiResource('invoices', InvoiceController::class);
Route::middleware('auth:sanctum')->patch('/invoices/{invoice}/status', [InvoiceController::class, 'updateStatus']);
Route::middleware('auth:sanctum')->apiResource('invoices.payments', PaymentController::class)->scoped()->parameters(['payments' => 'payment']);
Route::middleware('auth:sanctum')->get('/payments', [PaymentController::class, 'all']);
Route::middleware('auth:sanctum')->apiResource('quotes', QuoteController::class);
Route::middleware('auth:sanctum')->patch('/quotes/{quote}/status', [QuoteController::class, 'updateStatus']);
Route::middleware('auth:sanctum')->post('/quotes/{quote}/convert', [QuoteController::class, 'convertToInvoice']);
Route::middleware('auth:sanctum')->apiResource('quotes.items', QuoteItemController::class)->scoped(['item' => 'quoteItem'])->parameters(['items' => 'quoteItem']);

Route::middleware('auth:sanctum')->get('/settings', [SettingsController::class, 'show']);
Route::middleware('auth:sanctum')->patch('/settings/profile', [SettingsController::class, 'updateProfile']);
Route::middleware('auth:sanctum')->patch('/settings/company', [SettingsController::class, 'updateCompany']);
Route::middleware('auth:sanctum')->patch('/settings/billing', [SettingsController::class, 'updateBilling']);
Route::middleware('auth:sanctum')->patch('/settings/documents', [SettingsController::class, 'updateDocuments']);
Route::middleware('auth:sanctum')->patch('/settings/notifications', [SettingsController::class, 'updateNotifications']);
Route::middleware('auth:sanctum')->patch('/settings/security', [SettingsController::class, 'updateSecurity']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
