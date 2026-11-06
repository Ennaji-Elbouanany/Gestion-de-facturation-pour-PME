<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Quote;
use App\Support\CompanyCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', \App\Models\Company::class);
        $companyId = $request->user()->company_id;

        $summary = CompanyCache::remember(
            CompanyCache::key($companyId, CompanyCache::DASHBOARD),
            now()->addSeconds(60),
            fn () => [
                'clients_count' => Client::where('company_id', $companyId)->count(),
                'products_count' => Product::where('company_id', $companyId)->count(),
                'invoices_count' => Invoice::where('company_id', $companyId)->whereNull('archived_at')->count(),
                'quotes_count' => Quote::where('company_id', $companyId)->whereNull('archived_at')->count(),
                'payments_count' => Payment::whereHas(
                    'invoice',
                    fn ($query) => $query->where('company_id', $companyId)
                )->count(),
            ]
        );

        if (! $request->user()->can('viewFinancialStatistics', \App\Models\Company::class)) {
            $summary = array_intersect_key($summary, array_flip(['clients_count', 'products_count', 'invoices_count', 'quotes_count']));
        }

        return response()->json($summary);
    }
}
