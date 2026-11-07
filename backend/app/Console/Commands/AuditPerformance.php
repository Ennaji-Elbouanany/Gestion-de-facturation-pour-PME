<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditPerformance extends Command
{
    protected $signature = 'performance:audit {--company=} {--explain}';

    protected $description = 'Audit en lecture seule des index et cardinalités d’une entreprise ; aucune DDL';

    public function handle(): int
    {
        $company = filter_var($this->option('company'), FILTER_VALIDATE_INT);
        if (! $company || $company < 1 || ! DB::table('companies')->where('id', $company)->exists()) {
            $this->error('Précisez --company avec une entreprise existante.');

            return self::FAILURE;
        }
        foreach (['clients', 'products', 'invoices', 'quotes'] as $table) {
            $this->line($table.' : '.DB::table($table)->where('company_id', $company)->count().' lignes');
            foreach (Schema::getIndexes($table) as $index) {
                $this->line('  '.$index['name'].' ('.implode(', ', $index['columns']).')'.($index['unique'] ? ' UNIQUE' : ''));
            }
        }
        $this->line('Paiements : '.DB::table('payments')->join('invoices', 'invoices.id', '=', 'payments.invoice_id')->where('invoices.company_id', $company)->count());
        if ($this->option('explain')) {
            $prefix = DB::getDriverName() === 'sqlite' ? 'EXPLAIN QUERY PLAN ' : 'EXPLAIN ';
            foreach (['SELECT * FROM clients WHERE company_id=? AND deleted_at IS NULL ORDER BY created_at DESC,id DESC LIMIT 25',
                'SELECT * FROM invoices WHERE company_id=? AND archived_at IS NULL ORDER BY created_at DESC,id DESC LIMIT 25',
                'SELECT * FROM invoices WHERE company_id=? AND invoice_date>=\'2026-01-01\' AND invoice_date<\'2027-01-01\''] as $sql) {
                foreach (DB::select($prefix.$sql, [$company]) as $row) {
                    $this->line(json_encode($row, JSON_UNESCAPED_UNICODE));
                }
            }
        }
        $this->info('Lecture seule : aucun index, montant, état, rôle ou numéro modifié.');

        return self::SUCCESS;
    }
}
