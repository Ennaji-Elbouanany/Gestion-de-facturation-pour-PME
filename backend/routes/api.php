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
use App\Http\Middleware\EnsureVerifiedAccount;
use App\Support\UserPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:login')->post('/register', [AuthController::class, 'register']);
Route::middleware('throttle:login')->post('/login', [AuthController::class, 'login']);
Route::middleware('throttle:login')->post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::middleware('throttle:login')->post('/reset-password', [AuthController::class, 'resetPassword']);

Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

Route::middleware(['auth:sanctum', EnsureVerifiedAccount::class])->group(function (): void {
    Route::get('/dashboard/financial', [DashboardController::class, 'financial']);
    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
    Route::patch('/clients/{client}/restore', [ClientController::class, 'restore'])->withTrashed();
    Route::apiResource('clients', ClientController::class)->withTrashed(['destroy']);
    Route::apiResource('products', ProductController::class);
    Route::apiResource('invoices', InvoiceController::class);
    Route::patch('/invoices/{invoice}/archive', [InvoiceController::class, 'archive']);
    Route::patch('/invoices/{invoice}/restore', [InvoiceController::class, 'restore']);
    Route::patch('/invoices/{invoice}/status', [InvoiceController::class, 'updateStatus']);
    Route::apiResource('invoices.payments', PaymentController::class)->scoped()->parameters(['payments' => 'payment']);
    Route::get('/payments', [PaymentController::class, 'all']);
    Route::apiResource('quotes', QuoteController::class);
    Route::patch('/quotes/{quote}/archive', [QuoteController::class, 'archive']);
    Route::patch('/quotes/{quote}/restore', [QuoteController::class, 'restore']);
    Route::patch('/quotes/{quote}/status', [QuoteController::class, 'updateStatus']);
    Route::post('/quotes/{quote}/convert', [QuoteController::class, 'convertToInvoice']);
    Route::apiResource('quotes.items', QuoteItemController::class)->scoped(['item' => 'quoteItem'])->parameters(['items' => 'quoteItem']);

});

Route::get('/user', function (Request $request) {
    $user = $request->user();

    return response()->json([...$user->toArray(), 'permissions' => UserPermissions::for($user)]);
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/settings', [SettingsController::class, 'show']);
    Route::patch('/settings/profile', [SettingsController::class, 'updateProfile'])->middleware('throttle:account-security');
    Route::patch('/settings/company', [SettingsController::class, 'updateCompany']);
    Route::patch('/settings/billing', [SettingsController::class, 'updateBilling']);
    Route::patch('/settings/documents', [SettingsController::class, 'updateDocuments']);
    Route::patch('/settings/notifications', [SettingsController::class, 'updateNotifications']);
    Route::patch('/settings/security', [SettingsController::class, 'updateSecurity'])->middleware('throttle:account-security');
    Route::post('/email/verification-notification', [SettingsController::class, 'resendVerification'])->middleware('throttle:account-verification');
});
