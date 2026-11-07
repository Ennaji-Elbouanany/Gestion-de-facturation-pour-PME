<?php

namespace App\Support;

use App\Services\MoneyCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class AccountCatalogAudit
{
    public static function clientIssues(): array
    {
        $issues = [];
        foreach (DB::table('clients')->orderBy('id')->lazyById(200) as $client) {
            if ($client->email !== null && ($client->email === '' || $client->email !== strtolower(trim($client->email)))) {
                $issues[] = 'Client ID '.$client->id.' : e-mail non normalisé, revue manuelle requise.';
            }
        }
        $duplicates = DB::table('clients')->whereNotNull('email')->selectRaw('company_id, MIN(id) AS first_id')
            ->groupBy('company_id')->groupByRaw('LOWER(TRIM(email))')->havingRaw('COUNT(*) > 1')->get();
        foreach ($duplicates as $row) {
            $issues[] = 'Entreprise ID '.$row->company_id.' : doublon e-mail client (premier ID '.$row->first_id.'), archives comprises.';
        }

        return $issues;
    }

    public static function catalogIssues(): array
    {
        $issues = [];
        foreach (DB::table('products')->orderBy('id')->lazyById(200) as $product) {
            if (Validator::make((array) $product, ['unit_price' => ['required', ...MoneyCalculator::rules()],
                'tax_rate' => ['required', ...MoneyCalculator::rules(), 'max:100']])->fails()) {
                $issues[] = 'Produit ID '.$product->id.' : prix ou taxe invalide.';
            }
        }
        foreach (['invoice_items', 'quote_items'] as $table) {
            if (DB::table($table)->whereNotNull('product_id')->whereNotIn('product_id', DB::table('products')->select('id'))->exists()) {
                $issues[] = $table.' : référence produit orpheline.';
            }
        }

        return $issues;
    }
}
