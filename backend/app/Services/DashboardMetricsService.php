<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use App\Support\BusinessValidation;
use App\Support\ExactSql;
use App\Support\FinancialRead;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class DashboardMetricsService
{
    public function read(User $actor, Request $request): array
    {
        Gate::forUser($actor)->authorize('viewFinancialStatistics', Company::class);
        $f = $request->validate(['from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d'],
            'currency' => ['sometimes', 'in:MAD,EUR,USD,UNKNOWN'], 'company_id' => ['prohibited']], BusinessValidation::messages(), BusinessValidation::attributes());
        $from = CarbonImmutable::parse($f['from'] ?? today()->startOfMonth()->subMonths(11)->toDateString());
        $to = CarbonImmutable::parse($f['to'] ?? today()->toDateString());
        if ($to->lt($from) || $from->startOfMonth()->addMonths(24)->lte($to)) {
            throw ValidationException::withMessages(['to' => 'Choisissez une période ordonnée de 24 mois au maximum.']);
        }
        ExactSql::boot();

        // A short, read-only snapshot. No business row lock and no financial cache.
        return DB::transaction(fn () => $this->calculate($actor, $from, $to, $f['currency'] ?? null));
    }

    private function base(int $company): Builder
    {
        $snapshot = ExactSql::json('invoices.issued_snapshot', '$.total_amount');
        $valid = DB::getDriverName() === 'sqlite' ? "billing_valid(CAST($snapshot AS TEXT)) = 1" : "$snapshot REGEXP '^[0-9]{1,10}([.][0-9]{1,2})?$'";
        $number = DB::getDriverName() === 'sqlite' ? $snapshot : "CAST($snapshot AS DECIMAL(12,2))";
        $equal = ExactSql::compare('invoices.total_amount', $number);

        return FinancialRead::invoices($company)->leftJoin('clients', fn ($join) => $join->on('clients.id', '=', 'invoices.client_id')->on('clients.company_id', '=', 'invoices.company_id'))
            ->select(['invoices.id', 'invoices.client_id', 'invoices.invoice_number', 'invoices.invoice_date', 'invoices.due_date', 'invoices.total_amount', 'invoices.archived_at', 'invoices.created_at'])
            ->selectRaw(FinancialRead::state().' AS document_status, '.FinancialRead::status().' AS status, '.FinancialRead::paid().' AS paid, COALESCE(ledger.payment_count,0) AS payment_count, COALESCE(ledger.invalid_payment_count,0) AS invalid_payment_count, '.FinancialRead::currency().' AS currency, '.FinancialRead::clientName().' AS client_name')
            ->selectRaw("CASE WHEN invoices.issued_snapshot IS NULL THEN 1 WHEN $valid THEN CASE WHEN $equal = 0 THEN 0 ELSE 1 END ELSE 1 END AS snapshot_issue");
    }

    private function calculate(User $actor, CarbonImmutable $from, CarbonImmutable $to, ?string $selected): array
    {
        $company = $actor->company_id;
        $base = $this->base($company);
        $docs = fn () => DB::query()->fromSub(clone $base, 'd');
        $positive = ExactSql::compare('d.total_amount', 'd.paid').' > 0';
        $paidPositive = ExactSql::compare('d.paid', '0').' > 0';
        $balance = ExactSql::subtract('d.total_amount', 'd.paid');
        $issued = "d.document_status = 'issued'";
        $overdue = "$issued AND $positive AND d.due_date < '".today()->toDateString()."'";
        $aggregates = $docs()->select('d.currency')->groupBy('d.currency')
            ->selectRaw("COUNT(*) AS invoice_count, SUM(CASE WHEN $issued THEN 1 ELSE 0 END) AS issued_count, SUM(d.payment_count) AS payment_count")
            ->selectRaw(ExactSql::sum("CASE WHEN $issued THEN d.paid ELSE 0 END").' AS issued_collected_total, '.ExactSql::sum("CASE WHEN $issued THEN d.total_amount ELSE 0 END").' AS billed_total, '.ExactSql::sum('d.paid').' AS collected_total')
            ->selectRaw(ExactSql::sum("CASE WHEN $issued AND $positive THEN $balance ELSE 0 END").' AS outstanding_total, '.ExactSql::sum("CASE WHEN $overdue THEN $balance ELSE 0 END").' AS overdue_total')
            ->selectRaw("SUM(CASE WHEN $issued AND $positive THEN 1 ELSE 0 END) AS outstanding_count, SUM(CASE WHEN $overdue THEN 1 ELSE 0 END) AS overdue_count")
            ->selectRaw("SUM(CASE WHEN $issued AND ".ExactSql::compare('d.paid', '0').' = 0 AND '.ExactSql::compare('d.total_amount', '0').' > 0 THEN 1 ELSE 0 END) AS unpaid_count')
            ->selectRaw("SUM(CASE WHEN d.invalid_payment_count > 0 OR ($issued AND (d.snapshot_issue = 1 OR d.currency = 'UNKNOWN' OR d.archived_at IS NOT NULL)) OR ".ExactSql::compare('d.paid', 'd.total_amount')." > 0 OR (d.document_status <> 'issued' AND $paidPositive) THEN 1 ELSE 0 END) AS review_count")
            ->get();
        $available = $aggregates->pluck('currency')->sort()->values()->all();
        $selected ??= in_array('MAD', $available, true) ? 'MAD' : ($available[0] ?? 'MAD');
        $months = [];
        for ($month = $from->startOfMonth(); $month->lte($to); $month = $month->addMonth()) {
            $months[] = $month->format('Y-m');
        }
        $billMonths = $docs()->where('d.document_status', 'issued')->whereBetween('d.invoice_date', [$from->toDateString(), $to->toDateString()])
            ->select('d.currency')->selectRaw(ExactSql::month('d.invoice_date').' AS month, COUNT(*) AS count, '.ExactSql::sum('d.total_amount').' AS amount')
            ->groupBy('d.currency')->groupByRaw(ExactSql::month('d.invoice_date'))->get();
        $paymentBase = DB::table('payments')->join('invoices', 'invoices.id', '=', 'payments.invoice_id')->where('invoices.company_id', $company);
        $payMonths = (clone $paymentBase)->whereBetween('payments.payment_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw(FinancialRead::currency().' AS currency, '.ExactSql::month('payments.payment_date').' AS month, COUNT(*) AS count, '.ExactSql::sum('payments.amount').' AS amount')
            ->groupByRaw(FinancialRead::currency().', '.ExactSql::month('payments.payment_date'))->get();
        $methods = (clone $paymentBase)->select('payments.payment_method')->selectRaw(FinancialRead::currency().' AS currency, '.ExactSql::sum('payments.amount').' AS amount')->groupBy('payments.payment_method')->groupByRaw(FinancialRead::currency())->get();
        $statuses = $docs()->select('d.currency', 'd.status')->selectRaw('COUNT(*) AS count')->groupBy('d.currency', 'd.status')->get();
        $groups = [];
        foreach ($aggregates as $row) {
            $g = (array) $row;
            foreach (['billed_total', 'collected_total', 'outstanding_total', 'overdue_total', 'issued_collected_total'] as $field) {
                $g[$field] = MoneyCalculator::money($g[$field]);
            }
            foreach (['invoice_count', 'issued_count', 'payment_count', 'outstanding_count', 'overdue_count', 'unpaid_count', 'review_count'] as $field) {
                $g[$field] = (int) $g[$field];
            }
            $g['collection_average'] = $g['payment_count'] ? $this->divide($g['collected_total'], $g['payment_count']) : '0.00';
            $g['requires_review'] = $g['review_count'] > 0;
            $g['series'] = [];
            foreach ($months as $month) {
                $b = $billMonths->first(fn ($x) => $x->currency === $row->currency && $x->month === $month);
                $p = $payMonths->first(fn ($x) => $x->currency === $row->currency && $x->month === $month);
                $g['series'][] = ['month' => $month, 'billed' => MoneyCalculator::money($b->amount ?? '0'), 'collected' => MoneyCalculator::money($p->amount ?? '0'), 'invoice_count' => (int) ($b->count ?? 0), 'payment_count' => (int) ($p->count ?? 0)];
            }
            $max = '0';
            foreach ($g['series'] as $m) {
                foreach (['billed', 'collected'] as $key) {
                    if (MoneyCalculator::decimal($m[$key])->isGreaterThan($max)) {
                        $max = $m[$key];
                    }
                }
            }
            foreach ($g['series'] as &$m) {
                $m['billed_plot'] = $this->weight($m['billed'], $max);
                $m['collected_plot'] = $this->weight($m['collected'], $max);
            } unset($m);
            $g['axis_labels'] = [];
            foreach (['0', '0.25', '0.50', '0.75', '1'] as $fraction) {
                $g['axis_labels'][] = MoneyCalculator::money(MoneyCalculator::decimal($max)->multipliedBy($fraction));
            }
            $g['methods'] = $methods->where('currency', $row->currency)->map(fn ($m) => ['method' => $m->payment_method, 'amount' => MoneyCalculator::money($m->amount), 'weight' => $this->weight($m->amount, $g['collected_total'])])->values()->all();
            $g['statuses'] = $statuses->where('currency', $row->currency)->map(fn ($s) => ['status' => $s->status, 'count' => (int) $s->count])->values()->all();
            $last = $g['series'][count($g['series']) - 1] ?? null;
            $previous = $g['series'][count($g['series']) - 2] ?? null;
            $g['billed_change_pct'] = $this->change($last['billed'] ?? '0', $previous['billed'] ?? '0');
            $g['collected_change_pct'] = $this->change($last['collected'] ?? '0', $previous['collected'] ?? '0');
            $g['period_billed'] = MoneyCalculator::sum(array_column($g['series'], 'billed'));
            $g['period_collected'] = MoneyCalculator::sum(array_column($g['series'], 'collected'));
            // Same invoice cohort for the ratio, not unrelated period cash flows.
            $g['recovery_rate'] = $this->percent($g['issued_collected_total'], $g['billed_total']);
            $g['consolidated'] = $row->currency !== 'UNKNOWN';
            if (! $g['consolidated']) {
                foreach (['billed_total', 'collected_total', 'outstanding_total', 'overdue_total', 'issued_collected_total', 'collection_average', 'period_billed', 'period_collected', 'recovery_rate', 'billed_change_pct', 'collected_change_pct'] as $field) {
                    $g[$field] = null;
                }
                foreach ($g['series'] as &$month) {
                    $month['billed'] = null;
                    $month['collected'] = null;
                    $month['billed_plot'] = 0;
                    $month['collected_plot'] = 0;
                }unset($month);
                foreach ($g['methods'] as &$method) {
                    $method['amount'] = null;
                    $method['weight'] = 0;
                }unset($method);
                $g['axis_labels'] = array_fill(0, 5, null);
            }
            $groups[] = $g;
        }
        $recentInvoices = $docs()->where('d.currency', $selected)->orderByDesc('d.invoice_date')->orderByDesc('d.id')->limit(6)->get();
        $open = $docs()->where('d.currency', $selected)->where('d.document_status', 'issued')->whereRaw($positive)->select('d.*')->selectRaw($balance.' AS balance_due');
        $late = (clone $open)->where('d.due_date', '<', today()->toDateString())->orderBy('d.due_date')->orderBy('d.id')->limit(5)->get();
        $upcoming = (clone $open)->where('d.due_date', '>=', today()->toDateString())->orderBy('d.due_date')->orderBy('d.id')->limit(5)->get();
        $recentPayments = (clone $paymentBase)->leftJoin('clients', fn ($join) => $join->on('clients.id', '=', 'invoices.client_id')->on('clients.company_id', '=', 'invoices.company_id'))->whereRaw(FinancialRead::currency().' = ?', [$selected])
            ->select(['payments.id', 'payments.invoice_id', 'payments.payment_date', 'payments.payment_method', 'payments.amount', 'payments.created_at', 'invoices.invoice_number'])
            ->selectRaw(FinancialRead::clientName().' AS client_name')->orderByDesc('payments.payment_date')->orderByDesc('payments.id')->limit(6)->get();
        $quotes = DB::table('quotes')->where('quotes.company_id', $company)->whereNull('archived_at');
        $quoteCounts = (clone $quotes)->select('status')->selectRaw('COUNT(*) AS count')->groupBy('status')->pluck('count', 'status')->map(fn ($x) => (int) $x)->all();
        $recentQuotes = (clone $quotes)->leftJoin('clients', fn ($join) => $join->on('clients.id', '=', 'quotes.client_id')->on('clients.company_id', '=', 'quotes.company_id'))->select(['quotes.id', 'quotes.quote_number', 'quotes.quote_date', 'quotes.total_amount', 'quotes.status', 'quotes.created_at', 'clients.name as client_name'])->orderByDesc('quotes.quote_date')->orderByDesc('quotes.id')->limit(4)->get();
        $clients = $docs()->where('d.currency', $selected)->where('d.document_status', 'issued')->whereBetween('d.invoice_date', [$from->toDateString(), $to->toDateString()])
            ->select('d.client_id')->selectRaw('MAX(d.client_name) AS label, COUNT(*) AS count, '.ExactSql::sum('d.total_amount').' AS value')->groupBy('d.client_id')->orderByRaw(ExactSql::moneyOrder('value'))->orderBy('d.client_id')->limit(5)->get();
        // Product totals prefer issued snapshots, with explicit legacy fallback; no document discount allocation.
        $products = $this->products($company, $selected, $from, $to);
        $clientRows = $selected === 'UNKNOWN' ? [] : $this->rows($clients);
        $productRows = $selected === 'UNKNOWN' ? [] : $products;
        foreach ([&$clientRows, &$productRows] as &$ranking) {
            $max = $ranking[0]['value'] ?? '0';
            foreach ($ranking as &$item) {
                $item['plot'] = $this->weight($item['value'], $max);
            } unset($item);
        } unset($ranking);
        $clientSummary = DB::table('clients')->where('company_id', $company)->whereNull('deleted_at')->selectRaw('COUNT(*) AS total, SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS new_count', [today()->startOfMonth()->toDateTimeString()])->first();
        $documentCounts = [];
        foreach ($statuses as $status) {
            $documentCounts[$status->status] = ($documentCounts[$status->status] ?? 0) + (int) $status->count;
        }
        $summary = ['clients_count' => (int) $clientSummary->total, 'new_clients_this_month' => (int) $clientSummary->new_count, 'products_count' => DB::table('products')->where('company_id', $company)->count(), 'invoices_count' => array_sum($documentCounts)];

        return ['document_counts' => $documentCounts, 'summary' => $summary, 'calculated_at' => now()->toIso8601String(), 'as_of_date' => today()->toDateString(), 'timezone' => config('app.timezone'),
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'date_basis' => 'invoice_date/payment_date', 'cache' => 'disabled',
            'integrity_scope' => 'ledger_and_snapshot_totals; detailed_line_audit_remains_on_document',
            'selected_currency' => $selected, 'available_currencies' => $available, 'groups' => $groups,
            'quotes' => ['counts' => $quoteCounts, 'pending_count' => ($quoteCounts['sent'] ?? 0) + ($quoteCounts['accepted'] ?? 0), 'pending_total' => null, 'currency' => 'UNKNOWN'],
            'details' => ['recent_invoices' => $this->rows($recentInvoices), 'overdue_invoices' => $this->rows($late), 'upcoming_invoices' => $this->rows($upcoming),
                'recent_payments' => $this->rows($recentPayments), 'recent_quotes' => $this->rows($recentQuotes), 'top_clients' => $clientRows, 'top_products' => $productRows,
                'recent_clients' => DB::table('clients')->where('company_id', $company)->whereNull('deleted_at')->select('id', 'name', 'created_at')->orderByDesc('created_at')->orderByDesc('id')->limit(4)->get()->map(fn ($row) => (array) $row)->all()]];
    }

    private function products(int $company, string $currency, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $snapshot = DB::table('invoices');
        if (DB::getDriverName() === 'sqlite') {
            $snapshot->crossJoin(DB::raw("json_each(invoices.issued_snapshot, '$.items') AS line"));
            $product = ExactSql::json('line.value', '$.product_id');
            $description = ExactSql::json('line.value', '$.description');
            $amount = ExactSql::json('line.value', '$.total');
            $quantity = ExactSql::json('line.value', '$.quantity');
        } else {
            $snapshot->crossJoin(DB::raw("JSON_TABLE(invoices.issued_snapshot, '$.items[*]' COLUMNS(product_id BIGINT PATH '$.product_id' NULL ON EMPTY, description VARCHAR(255) PATH '$.description' NULL ON EMPTY, amount DECIMAL(12,2) PATH '$.total' NULL ON EMPTY, quantity DECIMAL(12,2) PATH '$.quantity' NULL ON EMPTY)) AS line"));
            $product = 'line.product_id';
            $description = 'line.description';
            $amount = 'line.amount';
            $quantity = 'line.quantity';
        }
        $snapshot->selectRaw("$product AS product_id, $description AS description, $amount AS amount, $quantity AS quantity");
        $legacy = DB::table('invoices')->join('invoice_items', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->whereRaw(ExactSql::json('invoices.issued_snapshot', '$.items').' IS NULL')->select(['invoice_items.product_id', 'invoice_items.description', 'invoice_items.total as amount', 'invoice_items.quantity']);
        foreach ([$snapshot, $legacy] as $query) {
            $query->where('invoices.company_id', $company)->whereRaw(FinancialRead::state()." = 'issued'")->whereRaw(FinancialRead::currency().' = ?', [$currency])->whereBetween('invoices.invoice_date', [$from->toDateString(), $to->toDateString()]);
        }
        $query = DB::query()->fromSub($snapshot->unionAll($legacy), 'items')->select('product_id', 'description')->selectRaw(ExactSql::sum('amount').' AS value, '.ExactSql::sum('quantity').' AS quantity')->groupBy('product_id', 'description');
        $query->orderByRaw(ExactSql::moneyOrder('value'))->orderBy('product_id')->orderBy('description')->limit(5);

        return $this->rows($query->get());
    }

    private function rows($rows): array
    {
        return $rows->map(function ($row) {
            $a = (array) $row;
            foreach (['total_amount', 'paid', 'balance_due', 'amount', 'value', 'quantity'] as $field) {
                if (isset($a[$field])) {
                    $a[$field] = MoneyCalculator::money($a[$field]);
                }
            }

            return $a;
        })->all();
    }

    private function divide(mixed $a, mixed $b, int $scale = 2): string
    {
        return (string) MoneyCalculator::decimal($a)->dividedBy((string) $b, $scale, RoundingMode::HalfUp);
    }

    private function weight(mixed $value, mixed $total): int
    {
        if (! MoneyCalculator::decimal($total)->isPositive() || ! MoneyCalculator::decimal($value)->isPositive()) {
            return 0;
        }

        return (int) $this->divide(MoneyCalculator::decimal($value)->multipliedBy('10000'), $total, 0);
    }

    private function percent(mixed $a, mixed $b): ?string
    {
        return MoneyCalculator::decimal($b)->isPositive() ? $this->divide(MoneyCalculator::decimal($a)->multipliedBy('100'), $b, 1) : null;
    }

    private function change(mixed $a, mixed $b): ?string
    {
        return $this->percent(MoneyCalculator::decimal($a)->minus((string) $b), $b);
    }
}
