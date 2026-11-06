<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\FinancialAudit;
use Illuminate\Console\Command;

class AuditInvoiceFinances extends Command
{
    protected $signature = 'billing:audit';

    protected $description = 'Audit financier en lecture seule, sans correction ni migration';

    public function handle(FinancialAudit $audit): int
    {
        $flagged = 0;
        $total = 0;
        Invoice::with(['items', 'payments'])->orderBy('id')->chunkById(100, function ($invoices) use ($audit, &$flagged, &$total): void {
            foreach ($invoices as $invoice) {
                $total++;
                $issues = $audit->inspect($invoice);
                if ($issues) {
                    $flagged++;
                    $this->warn('Facture ID '.$invoice->id.' / entreprise '.$invoice->company_id.' : '.implode(', ', $issues));
                }
            }
        });
        $this->info("{$total} factures examinées ; {$flagged} à vérifier. Aucune donnée modifiée.");

        return $flagged ? self::FAILURE : self::SUCCESS;
    }
}
