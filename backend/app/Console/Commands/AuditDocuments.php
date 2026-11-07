<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Quote;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditDocuments extends Command
{
    protected $signature = 'documents:audit';

    protected $description = 'Audit en lecture seule des références, compteurs et conversions ; aucune réparation';

    public function handle(): int
    {
        $issues = 0;
        $maxima = [];
        $linked = Schema::hasColumn('invoices', 'source_quote_id');
        foreach (['invoices' => 'invoice_number', 'quotes' => 'quote_number'] as $table => $column) {
            $kind = Schema::hasIndex($table, ['company_id', $column], 'unique') ? 'entreprise' : (Schema::hasIndex($table, [$column], 'unique') ? 'globale historique' : 'absente');
            $this->line($table.' : unicité '.$kind);
            if ($kind === 'absente') {
                $issues++;
            }
        }
        if ($linked && DB::table('invoices')->join('quotes', 'invoices.source_quote_id', '=', 'quotes.id')->whereColumn('invoices.company_id', '!=', 'quotes.company_id')->exists()) {
            $this->warn('Lien de conversion hors entreprise : revue nécessaire.');
            $issues++;
        }
        foreach (['invoice' => Invoice::class, 'quote' => Quote::class] as $type => $model) {
            foreach ($model::orderBy('id')->lazyById(200) as $document) {
                $number = $document->{$type.'_number'};
                if (! preg_match('/(\d{4})-(\d+)$/D', $number, $match) || strlen(ltrim($match[2], '0')) > 18) {
                    $this->warn($type.' ID '.$document->id.' : référence historique à examiner.');
                    $issues++;
                } else {
                    $key = $document->company_id.':'.$type.':'.$match[1];
                    $maxima[$key] = max($maxima[$key] ?? 0, (int) $match[2]);
                }
                if ($type === 'quote' && $document->status === 'converted' && (! $linked || ! $document->invoice()->exists())) {
                    $this->warn('Devis ID '.$document->id.' : conversion ancienne sans lien fiable.');
                    $issues++;
                }
            }
        }
        if (Schema::hasTable('document_sequences')) {
            foreach (DB::table('document_sequences')->orderBy('id')->lazyById(200) as $counter) {
                $key = $counter->company_id.':'.$counter->document_type.':'.$counter->year;
                if ($counter->last_value < ($maxima[$key] ?? 0)) {
                    $this->warn('Compteur ID '.$counter->id.' : inférieur à l’historique, revue nécessaire.');
                    $issues++;
                }
            }
        }
        $this->info($issues.' anomalies. Aucun numéro, lien, montant ou statut modifié.');

        return $issues ? self::FAILURE : self::SUCCESS;
    }
}
