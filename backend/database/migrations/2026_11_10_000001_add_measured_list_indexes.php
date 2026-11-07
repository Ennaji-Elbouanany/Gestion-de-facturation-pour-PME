<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $plan = ['clients' => ['clients_list_scope' => ['company_id', 'deleted_at', 'created_at', 'id']],
            'invoices' => ['invoices_list_scope' => ['company_id', 'archived_at', 'created_at', 'id'], 'invoices_period_scope' => ['company_id', 'invoice_date', 'id']]];
        foreach ($plan as $name => $indexes) {
            foreach ($indexes as $index => $columns) {
                if (Schema::hasIndex($name, $index) || Schema::hasIndex($name, $columns)) {
                    throw new RuntimeException('Index inattendu ou migration partielle : '.$index.'. Audit requis.');
                }
                foreach ($columns as $column) {
                    if (! Schema::hasColumn($name, $column)) {
                        throw new RuntimeException('Colonne absente : '.$name.'.'.$column);
                    }
                }
            }
        }
        foreach ($plan as $name => $indexes) {
            Schema::table($name, function (Blueprint $table) use ($indexes) {
                foreach ($indexes as $index => $columns) {
                    $table->index($columns, $index);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropIndex('clients_list_scope'));
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_list_scope');
            $table->dropIndex('invoices_period_scope');
        });
    }
};
