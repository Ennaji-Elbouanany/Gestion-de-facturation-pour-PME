<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use App\Support\CompanyCache;
use Illuminate\Support\Facades\Gate;

final class QuoteConversionService
{
    /** @return array{0: Invoice, 1: bool} */
    public function convert(User $actor, int $id): array
    {
        return FinancialTransaction::run(function () use ($actor, $id) {
            $quote = QuoteService::locked($actor, $id);
            Gate::forUser($actor)->authorize('convert', $quote);
            abort_if($quote->archived_at !== null, 409, 'Un devis archivé ne peut pas être converti.');
            $existing = Invoice::where('company_id', $actor->company_id)->where('source_quote_id', $quote->id)->first();
            if ($existing) {
                abort_unless($quote->status === 'converted', 409, 'Lien de conversion incohérent : audit nécessaire.');

                return [$existing, false];
            }
            abort_if($quote->status === 'converted', 409, 'Ancienne conversion sans lien fiable : revue manuelle nécessaire.');
            abort_unless($quote->status === 'accepted', 409, 'Seul un devis accepté peut être converti.');
            // Global order: company, existing quote, counter, source lines.
            $number = DocumentNumberService::next($quote->company_id, 'invoice');
            QuoteService::assertCoherent($quote);
            $invoice = new Invoice(['company_id' => $quote->company_id, 'client_id' => $quote->client_id,
                'user_id' => $actor->id, 'invoice_number' => $number,
                'invoice_date' => today()->toDateString(), 'due_date' => today()->addDays(30)->toDateString(),
                ...$quote->only(['subtotal', 'tax_amount', 'discount_amount', 'total_amount', 'notes']), 'status' => 'draft']);
            $invoice->source_quote_id = $quote->id;
            $invoice->document_status = 'draft';
            $invoice->save();
            foreach ($quote->items as $item) {
                $invoice->items()->create($item->only(['product_id', 'description', 'quantity', 'unit_price', 'tax_rate', 'subtotal', 'total']));
            }
            $quote->status = 'converted';
            $quote->save();
            FinancialTransaction::invalidate($quote->company_id, CompanyCache::QUOTES);

            return [$invoice, true];
        });
    }
}
