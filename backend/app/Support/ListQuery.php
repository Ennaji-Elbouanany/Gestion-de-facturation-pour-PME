<?php

namespace App\Support;

use App\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\Rule;

final class ListQuery
{
    public static function requested(Request $request): bool
    {
        return $request->hasAny(['per_page', 'page', 'pagination', 'q', 'from', 'to', 'created_from', 'created_to', 'client_id', 'status', 'document_status', 'archived', 'lookup', 'payment_method']);
    }

    public static function filters(Request $request, string $resource): array
    {
        $rules = ['per_page' => ['sometimes', 'integer', 'between:1,100'], 'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'pagination' => ['sometimes', 'in:page,cursor'], 'cursor' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'q' => ['sometimes', 'nullable', 'string', 'max:150'], 'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'created_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'created_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', ...($request->filled('created_from') ? ['after_or_equal:created_from'] : [])],
            'company_id' => ['prohibited'], 'lookup' => ['sometimes', 'boolean'], 'archived' => ['sometimes', Rule::in(['0', '1', 'all', 0, 1])],
            'client_id' => ['sometimes', 'nullable', 'integer'], 'status' => ['sometimes', 'nullable', 'string'], 'document_status' => ['sometimes', 'nullable', 'in:draft,issued,cancelled'],
            'payment_method' => ['sometimes', 'nullable', 'in:cash,bank_transfer,cheque,credit_card,digital_wallet,other']];
        if ($resource === 'invoices') {
            $rules['status'][] = Rule::in(['draft', 'sent', 'partial', 'paid', 'overdue', 'cancelled']);
        } elseif ($resource === 'quotes') {
            $rules['status'][] = Rule::in(['draft', 'sent', 'accepted', 'rejected', 'expired', 'converted']);
        } else {
            $rules['status'] = ['prohibited'];
        }
        if (! in_array($resource, ['invoices', 'quotes', 'payments'], true)) {
            $rules['client_id'] = ['prohibited'];
        }
        if (! in_array($resource, ['clients', 'invoices', 'quotes'], true)) {
            $rules['archived'] = ['prohibited'];
        }
        if ($resource !== 'invoices') {
            $rules['document_status'] = ['prohibited'];
        }
        if ($resource !== 'payments') {
            $rules['payment_method'] = ['prohibited'];
        }
        if (! in_array($resource, ['clients', 'products'], true)) {
            $rules['lookup'] = ['prohibited'];
        }
        $data = $request->validate($rules, BusinessValidation::messages(), BusinessValidation::attributes());
        if (! empty($data['client_id'])) {
            abort_unless(Client::withTrashed()->where('company_id', $request->user()->company_id)->whereKey($data['client_id'])->exists(), 404);
        }
        if (($data['pagination'] ?? 'page') === 'cursor' && ! empty($data['cursor'])) {
            try {
                $decoded = Cursor::fromEncoded($data['cursor']);
                $parameters = $decoded?->toArray() ?? [];
                if (! isset($parameters['_pointsToNextItems']) || ! is_bool($parameters['_pointsToNextItems'])) {
                    throw new \RuntimeException('Invalid direction');
                }
                unset($parameters['_pointsToNextItems']);
                $value = $parameters[$resource.'.id'] ?? null;
                if (count($parameters) !== 1 || filter_var($value, FILTER_VALIDATE_INT) === false || $value < 1) {
                    throw new \RuntimeException('Invalid cursor');
                }
            } catch (\Throwable $error) {
                abort(422, 'Curseur invalide.');
            }
        }

        return $data;
    }

    public static function dates($query, array $filters, string $column): void
    {
        if (! empty($filters['from'])) {
            $query->where($column, '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where($column, '<', CarbonImmutable::parse($filters['to'])->addDay()->toDateString());
        }
        $table = str_contains($column, '.') ? explode('.', $column)[0].'.' : '';
        if (! empty($filters['created_from'])) {
            $query->where($table.'created_at', '>=', $filters['created_from']);
        }
        if (! empty($filters['created_to'])) {
            $query->where($table.'created_at', '<', CarbonImmutable::parse($filters['created_to'])->addDay()->toDateString());
        }
    }

    public static function archive($query, array $filters, string $column): void
    {
        if (($filters['archived'] ?? 'all') !== 'all') {
            (string) $filters['archived'] === '1' ? $query->whereNotNull($column) : $query->whereNull($column);
        }
    }

    public static function search($query, array $filters, array $columns): void
    {
        if (! isset($filters['q']) || trim($filters['q']) === '') {
            return;
        }
        $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filters['q'])).'%';
        $query->where(function ($q) use ($needle, $columns) {
            foreach ($columns as $column) {
                $q->orWhereRaw("$column LIKE ? ESCAPE '!'", [$needle]);
            }
        });
    }

    public static function page($query, Request $request, array $filters, string $table)
    {
        $query->reorder();
        if (($filters['pagination'] ?? 'page') !== 'cursor') {
            $query->orderByDesc($table.'.created_at');
        }
        $query->orderByDesc($table.'.id');
        $size = $filters['per_page'] ?? 25;

        return (($filters['pagination'] ?? 'page') === 'cursor' ? $query->cursorPaginate($size) : $query->paginate($size))->withQueryString();
    }
}
