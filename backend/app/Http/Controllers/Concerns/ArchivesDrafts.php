<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Invoice;
use App\Models\Quote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

trait ArchivesDrafts
{
    private function changeDraftArchive(Request $request, Invoice|Quote $document, bool $archive): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $document);
        $document = DB::transaction(function () use ($document, $archive) {
            $locked = $document->newQuery()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'draft', 409, 'Seul un brouillon peut être archivé ou restauré.');
            if ($locked instanceof Invoice) {
                abort_if($locked->payments()->exists(), 409, 'Une facture avec des règlements ne peut pas être archivée.');
            }
            $locked->archived_at = $archive ? ($locked->archived_at ?? now()) : null;
            $locked->save();

            return $locked->load(['client:id,name,deleted_at', 'items']);
        });
        $this->forgetCompanyCaches($request->user()->company_id);

        return response()->json($document);
    }
}
