<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\CompanyCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $products = CompanyCache::remember(
            CompanyCache::key($companyId, CompanyCache::PRODUCTS),
            now()->addMinutes(10),
            fn () => Product::where('company_id', $companyId)->latest()->get()
        );

        return response()->json($products);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $product = Product::create([
            ...$data,
            'company_id' => $request->user()->company_id,
        ]);

        $this->forgetCompanyCaches($request->user()->company_id);

        return response()->json($product, 201);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $product);

        return response()->json($product);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $product);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'unit_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'tax_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $product->update($data);
        $this->forgetCompanyCaches($request->user()->company_id);

        return response()->json($product->fresh());
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $product);
        $product->delete();
        $this->forgetCompanyCaches($request->user()->company_id);

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

    private function forgetCompanyCaches(int $companyId): void
    {
        CompanyCache::forget(
            $companyId,
            CompanyCache::PRODUCTS,
            CompanyCache::DASHBOARD,
        );
    }
}
