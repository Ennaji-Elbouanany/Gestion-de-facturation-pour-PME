<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ArchivesDrafts;
use App\Models\Quote;
use App\Services\QuoteConversionService;
use App\Services\QuoteService;
use App\Support\CompanyCache;
use App\Support\InvoiceView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuoteController extends Controller
{
    use ArchivesDrafts;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Quote::class);
        $companyId = $request->user()->company_id;

        return response()->json(CompanyCache::remember(CompanyCache::key($companyId, CompanyCache::QUOTES), now()->addMinutes(2),
            fn () => Quote::where('company_id', $companyId)->with(['client:id,name,deleted_at', 'items', 'invoice'])->latest()->get()));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Quote::class);
        $this->guardConverted($request, new Quote(['company_id' => $request->user()->company_id]));

        return response()->json(app(QuoteService::class)->create($request->user(), $request->all()), 201);
    }

    public function show(Request $request, Quote $quote): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $quote);
        $this->authorize('view', $quote);

        return response()->json($quote->load(['client:id,name,email,phone,address,city,country,deleted_at', 'items', 'invoice']));
    }

    public function update(Request $request, Quote $quote): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $quote);
        $this->authorize('update', $quote);
        $this->guardConverted($request, $quote);

        return response()->json(app(QuoteService::class)->update($request->user(), $quote->id, $request->all()));
    }

    public function destroy(Request $request, Quote $quote): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $quote);
        $this->authorize('view', $quote);

        return response()->json(['message' => 'Ce document doit être conservé. Vous pouvez archiver un brouillon.'], 409);
    }

    public function archive(Request $request, Quote $quote): JsonResponse
    {
        return $this->changeDraftArchive($request, $quote, true);
    }

    public function restore(Request $request, Quote $quote): JsonResponse
    {
        return $this->changeDraftArchive($request, $quote, false);
    }

    public function updateStatus(Request $request, Quote $quote): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $quote);
        $this->authorize('updateStatus', $quote);
        $this->guardConverted($request, $quote);
        $data = $request->validate(['status' => ['required', 'in:draft,sent,accepted,rejected,expired']]);

        return response()->json(app(QuoteService::class)->transition($request->user(), $quote->id, $data['status']));
    }

    public function convertToInvoice(Request $request, Quote $quote): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $quote);
        $this->authorize('convert', $quote);
        [$invoice, $created] = app(QuoteConversionService::class)->convert($request->user(), $quote->id);

        return response()->json(InvoiceView::one($invoice, $request->user()), $created ? 201 : 200);
    }

    private function guardConverted(Request $request, Quote $quote): void
    {
        if ($request->input('status') === 'converted') {
            $this->authorize('convert', $quote);
            abort(422, 'Le statut converti est réservé à la conversion métier.');
        }
    }

    private function ensureBelongsToCompany(Request $request, Quote $quote): void
    {
        abort_unless($quote->company_id === $request->user()->company_id, 404);
    }
}
