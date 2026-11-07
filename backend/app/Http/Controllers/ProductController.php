<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Product;
use App\Services\FinancialTransaction;
use App\Services\MoneyCalculator;
use App\Support\BusinessValidation;
use App\Support\CompanyCache;
use App\Support\ListQuery;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);
        $companyId = $request->user()->company_id;
        if (ListQuery::requested($request)) {
            $f = ListQuery::filters($request, 'products');
            $query = Product::where('company_id', $companyId);
            ListQuery::search($query, $f, ['products.name']);
            ListQuery::dates($query, $f, 'products.created_at');
            if (! empty($f['lookup'])) {
                $query->select(['id', 'name', 'unit_price', 'tax_rate', 'created_at']);
            }

            return response()->json(ListQuery::page($query, $request, $f, 'products'));
        }
        $products = CompanyCache::remember(
            CompanyCache::key($companyId, CompanyCache::PRODUCTS),
            now()->addMinutes(10),
            fn () => Product::where('company_id', $companyId)->latest()->get()
        );

        return response()->json($products);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Product::class);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'unit_price' => ['required', ...MoneyCalculator::rules()],
            'tax_rate' => ['nullable', ...MoneyCalculator::rules(), 'max:100'],
        ], BusinessValidation::messages(), BusinessValidation::attributes());

        $product = $this->write($request, fn () => Product::create([
            ...$data, 'tax_rate' => $data['tax_rate'] ?? '0', 'company_id' => $request->user()->company_id,
        ]));

        return response()->json($product, 201);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $product);
        $this->authorize('view', $product);

        return response()->json($product);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $product);
        $this->authorize('update', $product);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'unit_price' => ['sometimes', 'required', ...MoneyCalculator::rules()],
            'tax_rate' => ['sometimes', 'nullable', ...MoneyCalculator::rules(), 'max:100'],
        ], BusinessValidation::messages(), BusinessValidation::attributes());

        if (array_key_exists('tax_rate', $data) && $data['tax_rate'] === null) {
            $data['tax_rate'] = '0';
        }
        $product = $this->write($request, function () use ($product, $data): Product {
            $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $locked->update($data);

            return $locked;
        });

        return response()->json($product->fresh());
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $product);
        $this->authorize('delete', $product);
        try {
            $this->write($request, function () use ($product): Product {
                $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
                abort_if($locked->invoiceItems()->exists() || $locked->quoteItems()->exists(), 409, 'Ce produit est utilisé par un document et doit être conservé.');
                $locked->delete();

                return $locked;
            });
        } catch (QueryException $exception) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [1451, 787], true)) {
                abort(409, 'Ce produit est utilisé par un document et doit être conservé.');
            }
            throw $exception;
        }

        return response()->json([
            'message' => 'Produit supprimé avec succès.',
        ]);
    }

    private function ensureBelongsToCompany(Request $request, Product $product): void
    {
        abort_unless(
            $product->company_id === $request->user()->company_id,
            404
        );
    }

    private function write(Request $request, \Closure $work): Product
    {
        return FinancialTransaction::run(function () use ($request, $work): Product {
            Company::whereKey($request->user()->company_id)->lockForUpdate()->firstOrFail();
            $product = $work();
            FinancialTransaction::invalidate($product->company_id, CompanyCache::PRODUCTS);

            return $product;
        });
    }
}
