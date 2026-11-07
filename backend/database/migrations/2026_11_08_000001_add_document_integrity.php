<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $indexes = [];
        // Inspect the complete plan before the first DDL. Never renumber or infer historical links.
        foreach (['invoices' => 'invoice_number', 'quotes' => 'quote_number'] as $table => $number) {
            $matches = array_values(array_filter(Schema::getIndexes($table), fn ($index) => $index['unique'] && $index['columns'] === [$number]));
            if (count($matches) !== 1 || DB::table($table)->select('company_id', $number)->groupBy('company_id', $number)->havingRaw('COUNT(*) > 1')->exists()) {
                throw new RuntimeException('Document uniqueness differs from the expected schema; audit required.');
            }
            $indexes[$table] = $matches[0]['name'];
        }
        if (Schema::hasTable('document_sequences') || Schema::hasColumn('invoices', 'source_quote_id')) {
            throw new RuntimeException('Partial document migration detected; inspect before resuming.');
        }
        $this->schemaChange(function () use ($indexes): void {
            Schema::create('document_sequences', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained()->restrictOnDelete();
                $table->string('document_type', 10);
                $table->unsignedSmallInteger('year');
                $table->bigInteger('last_value');
                $table->unique(['company_id', 'document_type', 'year'], 'document_sequences_scope_unique');
            });
            Schema::table('quotes', function (Blueprint $table) use ($indexes): void {
                $table->unique(['company_id', 'id'], 'quotes_company_id_id_unique');
                $table->unique(['company_id', 'quote_number'], 'quotes_company_number_unique');
                $table->dropUnique($indexes['quotes']);
            });
            Schema::table('invoices', function (Blueprint $table) use ($indexes): void {
                $table->unsignedBigInteger('source_quote_id')->nullable();
                $table->unique('source_quote_id', 'invoices_source_quote_unique');
                $table->unique(['company_id', 'invoice_number'], 'invoices_company_number_unique');
                $table->foreign(['company_id', 'source_quote_id'], 'invoices_source_quote_tenant_foreign')
                    ->references(['company_id', 'id'])->on('quotes')->restrictOnDelete()->restrictOnUpdate();
                $table->dropUnique($indexes['invoices']);
            });
        });
    }

    public function down(): void
    {
        if (DB::table('document_sequences')->exists() || DB::table('invoices')->whereNotNull('source_quote_id')->exists()) {
            throw new RuntimeException('Document counters or conversion links are in use; forward correction required.');
        }
        foreach (['invoices' => 'invoice_number', 'quotes' => 'quote_number'] as $table => $column) {
            if (DB::table($table)->select($column)->groupBy($column)->havingRaw('COUNT(*) > 1')->exists()) {
                throw new RuntimeException('Global uniqueness cannot be restored without losing tenant document numbers.');
            }
        }
        $this->schemaChange(function (): void {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['company_id', 'source_quote_id'] : 'invoices_source_quote_tenant_foreign');
                $table->dropUnique('invoices_source_quote_unique');
                $table->dropColumn('source_quote_id');
                $table->unique('invoice_number');
                // Preserve a MySQL supporting index for the company FK.
                if (! Schema::hasIndex('invoices', ['company_id'])) {
                    $table->index('company_id', 'invoices_document_company_index');
                }
                $table->dropUnique('invoices_company_number_unique');
            });
            Schema::table('quotes', function (Blueprint $table): void {
                $table->unique('quote_number');
                if (! Schema::hasIndex('quotes', ['company_id'])) {
                    $table->index('company_id', 'quotes_document_company_index');
                }
                $table->dropUnique('quotes_company_number_unique');
                $table->dropUnique('quotes_company_id_id_unique');
            });
            Schema::drop('document_sequences');
        });
    }

    private function schemaChange(Closure $work): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::withoutForeignKeyConstraints(fn () => DB::transaction(function () use ($work): void {
                $work();
                if (DB::select('PRAGMA foreign_key_check') !== []) {
                    throw new RuntimeException('Foreign key verification failed.');
                }
            }));
        } else {
            $work();
        }
    }
};
