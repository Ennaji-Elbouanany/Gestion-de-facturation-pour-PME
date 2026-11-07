<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Quote;
use Illuminate\Support\Facades\DB;

final class DocumentNumberService
{
    public static function company(int $companyId): Company
    {
        abort_unless(DB::transactionLevel() > 0, 500, 'Document allocation requires a transaction.');

        return Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
    }

    public static function next(int $companyId, string $type): string
    {
        $company = self::company($companyId);
        abort_unless(in_array($type, ['invoice', 'quote'], true), 422, 'Unknown document type.');
        $year = (int) now()->format('Y');
        $scope = ['company_id' => $companyId, 'document_type' => $type, 'year' => $year];
        $query = DB::table('document_sequences')->where($scope);
        $counter = $query->lockForUpdate()->first();
        if (! $counter) {
            $max = 0;
            $model = $type === 'invoice' ? Invoice::class : Quote::class;
            $column = $type.'_number';
            // One bootstrap scan per tenant/type/year; all prefixes and archives are included.
            foreach ($model::where('company_id', $companyId)->select('id', $column)->orderBy('id')->lazyById(200) as $document) {
                if (preg_match('/'.preg_quote((string) $year, '/').'-(\d+)$/D', $document->$column, $match)) {
                    $digits = ltrim($match[1], '0') ?: '0';
                    abort_if(strlen($digits) > 18, 409, 'Historical sequence exceeds supported bounds; audit required.');
                    $max = max($max, (int) $digits);
                }
            }
            DB::table('document_sequences')->insert([...$scope, 'last_value' => $max]);
            $counter = $query->lockForUpdate()->first();
        }
        abort_if($counter->last_value < 0 || $counter->last_value >= 999999999999999999, 409, 'Document sequence exhausted or invalid.');
        $next = (int) $counter->last_value + 1;
        $query->update(['last_value' => $next]);
        $prefix = $type === 'invoice' ? (trim($company->invoice_prefix ?? '') ?: 'FAC-') : 'DEV-';

        return $prefix.$year.'-'.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }
}
