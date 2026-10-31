<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Quote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $summary = Cache::remember(
            "dashboard-summary-company-{$companyId}",
            now()->addSeconds(30),
            fn () => [
                'clients_count' => Client::where('company_id', $companyId)->count(),
                'products_count' => Product::where('company_id', $companyId)->count(),
                'invoices_count' => Invoice::where('company_id', $companyId)->count(),
                'quotes_count' => Quote::where('company_id', $companyId)->count(),
                'payments_count' => Payment::whereHas(
                    'invoice',
                    fn ($query) => $query->where('company_id', $companyId)
                )->count(),
            ]
        );

        return response()->json([
            ...$summary,
        ]);
    }
}
