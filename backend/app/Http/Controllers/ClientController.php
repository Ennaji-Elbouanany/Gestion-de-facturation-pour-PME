<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Company;
use App\Services\FinancialTransaction;
use App\Support\BusinessValidation;
use App\Support\CompanyCache;
use App\Support\ListQuery;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Client::class);
        $companyId = $request->user()->company_id;
        $query = Client::where('company_id', $companyId)->latest();
        if (ListQuery::requested($request)) {
            $f = ListQuery::filters($request, 'clients');
            $archived = (string) ($f['archived'] ?? '0');
            if ($archived === 'all') {
                $query->withTrashed();
            } elseif ($archived === '1') {
                $query->onlyTrashed();
            }
            ListQuery::search($query, $f, ['clients.name', 'clients.email']);
            ListQuery::dates($query, $f, 'clients.created_at');
            if (! empty($f['lookup'])) {
                $query->select(['id', 'name', 'deleted_at', 'created_at']);
            }

            return response()->json(ListQuery::page($query, $request, $f, 'clients'));
        }

        $clients = CompanyCache::remember(
            CompanyCache::key($companyId, CompanyCache::CLIENTS),
            now()->addMinutes(5),
            fn () => $query->get()
        );

        return response()->json($clients);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Client::class);
        BusinessValidation::normalizeEmail($request);
        $client = $this->write($request, function () use ($request): Client {
            return Client::create([...$this->validated($request), 'company_id' => $request->user()->company_id]);
        });

        return response()->json($client, 201);
    }

    public function show(Request $request, Client $client): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $client);
        $this->authorize('view', $client);

        return response()->json($client);
    }

    public function update(Request $request, Client $client): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $client);
        $this->authorize('update', $client);

        BusinessValidation::normalizeEmail($request);
        $client = $this->write($request, function () use ($request, $client): Client {
            $locked = Client::where('company_id', $request->user()->company_id)->whereKey($client->id)->lockForUpdate()->firstOrFail();
            $locked->update($this->validated($request, $locked));

            return $locked;
        });

        return response()->json($client->fresh());
    }

    public function destroy(Request $request, Client $client): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $client);
        $this->authorize('archive', $client);
        $this->write($request, function () use ($client): Client {
            $locked = Client::withTrashed()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            if (! $locked->trashed()) {
                $locked->delete();
            }

            return $locked;
        });

        return response()->json([
            'message' => 'Client archivé. Ses documents et règlements sont conservés.',
        ]);
    }

    public function restore(Request $request, Client $client): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $client);
        $this->authorize('restore', $client);
        $client = $this->write($request, function () use ($client): Client {
            $locked = Client::withTrashed()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            if ($locked->trashed()) {
                $locked->restore();
            }

            return $locked;
        });

        return response()->json(['message' => 'Client restauré.', 'client' => $client->fresh()]);
    }

    private function ensureBelongsToCompany(Request $request, Client $client): void
    {
        abort_unless(
            $client->company_id === $request->user()->company_id,
            404
        );
    }

    private function validated(Request $request, ?Client $client = null): array
    {
        $unique = Rule::unique('clients', 'email')->where('company_id', $request->user()->company_id);
        if ($client) {
            $unique->ignore($client->id);
        }
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', $unique],
            'phone' => BusinessValidation::phone(),
            'tax_number' => BusinessValidation::fiscal(),
            'address' => ['nullable', 'string', 'max:5000'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
        ];
        if ($client) {
            foreach ($rules as &$rule) {
                array_unshift($rule, 'sometimes');
            }
            unset($rule);
        }

        return $request->validate($rules, BusinessValidation::messages(), BusinessValidation::attributes());
    }

    private function write(Request $request, \Closure $work): Client
    {
        try {
            return FinancialTransaction::run(function () use ($request, $work): Client {
                Company::whereKey($request->user()->company_id)->lockForUpdate()->firstOrFail();
                $client = $work();
                FinancialTransaction::invalidate($client->company_id, CompanyCache::CLIENTS, CompanyCache::QUOTES);

                return $client;
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['email' => 'Cette adresse e-mail est déjà utilisée par un client de votre entreprise, y compris dans les archives.']);
        }
    }
}
