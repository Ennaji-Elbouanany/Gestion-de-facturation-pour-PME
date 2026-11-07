<?php

namespace App\Services;

use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\User;
use App\Support\CompanyCache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class QuoteService
{
    public static function locked(User $actor, int $id): Quote
    {
        abort_if($actor->company_id === null, 403);
        DocumentNumberService::company($actor->company_id);

        return Quote::where('company_id', $actor->company_id)->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public static function editable(Quote $quote): void
    {
        abort_unless($quote->status === 'draft' && $quote->archived_at === null, 409, 'Seul un brouillon actif peut être modifié.');
    }

    public function create(User $actor, array $data): Quote
    {
        Gate::forUser($actor)->authorize('create', Quote::class);

        return FinancialTransaction::run(function () use ($actor, $data) {
            DocumentNumberService::company($actor->company_id);
            $data = $this->validated($actor, $data);
            abort_unless(in_array($data['status'] ?? 'draft', ['draft', 'sent'], true), 409, 'Un nouveau devis doit être un brouillon ou être envoyé.');
            $quote = Quote::create(['company_id' => $actor->company_id, 'user_id' => $actor->id,
                'quote_number' => DocumentNumberService::next($actor->company_id, 'quote'),
                ...collect($data)->only(['client_id', 'quote_date', 'valid_until', 'notes'])->all(), 'status' => 'draft']);
            $this->replaceItems($quote, $data['items']);
            if (($data['status'] ?? 'draft') !== 'draft') {
                $this->changeState($quote, $data['status']);
            }
            FinancialTransaction::invalidate($quote->company_id, CompanyCache::QUOTES);

            return $quote->fresh(['client', 'items', 'invoice']);
        });
    }

    public function update(User $actor, int $id, array $data): Quote
    {
        return FinancialTransaction::run(function () use ($actor, $id, $data) {
            $quote = self::locked($actor, $id);
            Gate::forUser($actor)->authorize('update', $quote);
            self::editable($quote);
            $data = $this->validated($actor, $data, $quote);
            $quote->fill(collect($data)->only(['client_id', 'quote_date', 'valid_until', 'notes'])->all())->save();
            if (isset($data['items'])) {
                $this->replaceItems($quote, $data['items']);
            }
            if (isset($data['status'])) {
                Gate::forUser($actor)->authorize('updateStatus', $quote);
                $this->changeState($quote, $data['status']);
            }
            FinancialTransaction::invalidate($quote->company_id, CompanyCache::QUOTES);

            return $quote->fresh(['client', 'items', 'invoice']);
        });
    }

    public function transition(User $actor, int $id, string $target): Quote
    {
        return FinancialTransaction::run(function () use ($actor, $id, $target) {
            $quote = self::locked($actor, $id);
            Gate::forUser($actor)->authorize('updateStatus', $quote);
            $this->changeState($quote, $target);
            FinancialTransaction::invalidate($quote->company_id, CompanyCache::QUOTES);

            return $quote->fresh(['client', 'items', 'invoice']);
        });
    }

    private function changeState(Quote $quote, string $target): void
    {
        abort_if($target === 'converted', 422, 'Utilisez la conversion métier pour créer une facture.');
        abort_if($quote->archived_at !== null, 409, 'Restaurez le brouillon avant de changer son état.');
        if ($quote->status === $target) {
            return;
        }
        $allowed = ['draft' => ['sent'], 'sent' => ['accepted', 'rejected', 'expired']];
        abort_unless(in_array($target, $allowed[$quote->status] ?? [], true), 409, 'Transition commerciale interdite.');
        if ($target === 'expired') {
            abort_unless($quote->valid_until && $quote->valid_until->lt(today()), 409, 'Ce devis n’est pas expiré.');
        }
        if ($target === 'accepted') {
            abort_if($quote->valid_until && $quote->valid_until->lt(today()), 409, 'Un devis expiré ne peut pas être accepté.');
        }
        if ($target === 'sent') {
            self::assertCoherent($quote);
        }
        $quote->status = $target;
        $quote->save();
    }

    public static function assertCoherent(Quote $quote): void
    {
        abort_unless($quote->client()->where('company_id', $quote->company_id)->exists(), 409, 'Client historique hors entreprise : audit nécessaire.');
        $items = $quote->items()->orderBy('id')->lockForUpdate()->get();
        abort_if($items->isEmpty(), 409, 'Le devis doit contenir au moins une ligne.');
        foreach ($items as $item) {
            abort_if($item->product_id && ! $item->product()->where('company_id', $quote->company_id)->exists(), 409, 'Produit historique hors entreprise : audit nécessaire.');
            $valid = Validator::make($item->toArray(), ['quantity' => ['required', ...MoneyCalculator::rules(true)], 'unit_price' => ['required', ...MoneyCalculator::rules()], 'tax_rate' => ['nullable', ...MoneyCalculator::rules(), 'max:100']]);
            abort_if($valid->fails(), 409, 'Valeurs historiques incohérentes : audit nécessaire.');
            foreach (MoneyCalculator::line($item->toArray()) as $field => $amount) {
                abort_unless(MoneyCalculator::decimal($amount)->isEqualTo($item->$field), 409, 'Lignes historiques incohérentes : audit nécessaire.');
            }
        }
        foreach (MoneyCalculator::totals($items->toArray(), $quote->discount_amount) as $field => $amount) {
            abort_unless(MoneyCalculator::decimal($amount)->isEqualTo($quote->$field), 409, 'Totaux historiques incohérents : audit nécessaire.');
        }
        Validator::make(['quote_date' => $quote->quote_date?->toDateString(), 'valid_until' => $quote->valid_until?->toDateString()], [
            'quote_date' => ['required', 'date_format:Y-m-d'], 'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:quote_date'],
        ])->validate();
        $quote->setRelation('items', $items);
    }

    public function item(User $actor, int $quoteId, ?int $itemId, ?array $data): ?QuoteItem
    {
        return FinancialTransaction::run(function () use ($actor, $quoteId, $itemId, $data) {
            $quote = self::locked($actor, $quoteId);
            Gate::forUser($actor)->authorize('update', $quote);
            self::editable($quote);
            $item = $itemId ? $quote->items()->whereKey($itemId)->lockForUpdate()->firstOrFail() : null;
            if ($data === null) {
                $item->delete();
            } else {
                if (! $item) {
                    abort_if($quote->items()->count() >= 200, 422, 'Le devis contient déjà 200 lignes.');
                }
                $merged = array_merge($item?->only(['product_id', 'description', 'quantity', 'unit_price', 'tax_rate']) ?? [], $data);
                $validated = Validator::make(['items' => [$merged]], $this->itemRules($actor))->validate()['items'][0];
                $values = [...$validated, ...MoneyCalculator::line($validated)];
                $item ? $item->update($values) : $item = $quote->items()->create($values);
            }
            $quote->fill(MoneyCalculator::totals($quote->items()->get()->toArray(), $quote->discount_amount))->save();
            FinancialTransaction::invalidate($quote->company_id, CompanyCache::QUOTES);

            return $data === null ? null : $item->fresh(['product:id,name']);
        });
    }

    private function replaceItems(Quote $quote, array $items): void
    {
        self::editable($quote);
        $rows = array_map(fn ($item) => [...$item, ...MoneyCalculator::line($item)], $items);
        $totals = MoneyCalculator::totals($rows, $quote->discount_amount);
        $quote->items()->delete();
        foreach ($rows as $row) {
            $quote->items()->create($row);
        }
        $quote->fill($totals)->save();
    }

    private function itemRules(User $actor): array
    {
        return ['items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('company_id', $actor->company_id)],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', ...MoneyCalculator::rules(true)],
            'items.*.unit_price' => ['required', ...MoneyCalculator::rules()],
            'items.*.tax_rate' => ['nullable', ...MoneyCalculator::rules(), 'max:100']];
    }

    private function validated(User $actor, array $data, ?Quote $quote = null): array
    {
        $rules = ['client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where(function ($q) use ($actor, $quote) {
            $q->where('company_id', $actor->company_id)->where(fn ($clients) => $clients->whereNull('deleted_at')->orWhere('id', $quote?->client_id ?? 0));
        })], 'quote_date' => ['required', 'date_format:Y-m-d'], 'valid_until' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string'], 'status' => ['sometimes', 'in:draft,sent,accepted,rejected,expired'], ...$this->itemRules($actor)];
        if ($quote) {
            foreach ($rules as $field => &$rule) {
                if (! str_starts_with($field, 'items.*.')) {
                    $rule = ['sometimes', ...$rule];
                }
            }
            unset($rule);
        }
        $validated = Validator::make($data, $rules)->validate();
        Validator::make(array_merge(['quote_date' => $quote?->quote_date?->toDateString(), 'valid_until' => $quote?->valid_until?->toDateString()], $validated), [
            'quote_date' => ['required', 'date_format:Y-m-d'], 'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:quote_date'],
        ])->validate();

        return $validated;
    }
}
