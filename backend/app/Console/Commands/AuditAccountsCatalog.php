<?php

namespace App\Console\Commands;

use App\Services\MoneyCalculator;
use App\Support\AccountCatalogAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class AuditAccountsCatalog extends Command
{
    protected $signature = 'accounts-catalog:audit';

    protected $description = 'Audit en lecture seule des comptes, clients, produits et paramètres ; aucune réparation';

    public function handle(): int
    {
        $issues = [...AccountCatalogAudit::clientIssues(), ...AccountCatalogAudit::catalogIssues()];
        $this->line('Comptes non vérifiés : '.DB::table('users')->whereNull('email_verified_at')->count());
        $this->line('Tokens sans expiration explicite : '.DB::table('personal_access_tokens')->whereNull('expires_at')->count());
        $this->line('Unicité clients par entreprise : '.(Schema::hasIndex('clients', ['company_id', 'email'], 'unique') ? 'présente' : 'à préparer'));
        foreach (['invoice_items', 'quote_items'] as $table) {
            $keys = array_values(array_filter(Schema::getForeignKeys($table), fn ($key) => $key['columns'] === ['product_id']));
            $this->line($table.' : suppression produit '.($keys[0]['on_delete'] ?? 'inconnue'));
        }
        foreach (DB::table('companies')->orderBy('id')->lazyById(200) as $company) {
            if (Validator::make((array) $company, [
                'invoice_prefix' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/D'],
                'currency' => ['required', 'in:MAD,EUR,USD'], 'payment_terms_days' => ['required', 'integer', 'between:0,365'],
                'default_tax_rate' => ['nullable', ...MoneyCalculator::rules(), 'max:100'],
            ])->fails()) {
                $issues[] = 'Entreprise ID '.$company->id.' : paramètres à examiner.';
            }
        }
        foreach ($issues as $issue) {
            $this->warn($issue);
        }
        $this->info(count($issues).' anomalies. Aucune donnée, session ou permission modifiée.');

        return $issues ? self::FAILURE : self::SUCCESS;
    }
}
