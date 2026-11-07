<?php

use App\Support\AccountCatalogAudit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit before any DDL. No merge, normalization or deletion of historical rows.
        if (AccountCatalogAudit::clientIssues() !== []) {
            throw new RuntimeException('E-mails clients incompatibles ; exécutez accounts-catalog:audit et faites valider une correction manuelle.');
        }
        foreach (Schema::getIndexes('clients') as $index) {
            if ($index['unique'] && in_array($index['columns'], [['email'], ['company_id', 'email']], true)) {
                throw new RuntimeException('Unicité e-mail inattendue ou migration partielle ; inspection requise.');
            }
        }
        Schema::table('clients', fn (Blueprint $table) => $table->unique(['company_id', 'email'], 'clients_company_email_unique'));
    }

    public function down(): void
    {
        // No data removed. Production must use forward corrections rather than downgrade protections.
        Schema::table('clients', function (Blueprint $table): void {
            if (! Schema::hasIndex('clients', ['company_id'])) {
                $table->index('company_id', 'clients_email_company_index');
            }
            $table->dropUnique('clients_company_email_unique');
        });
    }
};
