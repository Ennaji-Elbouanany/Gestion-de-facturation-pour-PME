<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SQLite must disable foreign keys outside a transaction when rebuilding parents.
    public $withinTransaction = false;

    private array $relations = [
        'clients' => ['company_id' => 'companies'],
        'invoices' => ['company_id' => 'companies', 'client_id' => 'clients'],
        'quotes' => ['company_id' => 'companies', 'client_id' => 'clients'],
        'invoice_items' => ['invoice_id' => 'invoices'],
        'quote_items' => ['quote_id' => 'quotes'],
        'payments' => ['invoice_id' => 'invoices'],
    ];

    public function up(): void
    {
        $this->replaceConstraints('restrict');
    }

    public function down(): void
    {
        $this->replaceConstraints('cascade');
    }

    private function replaceConstraints(string $action): void
    {
        $plan = [];
        // Inspect every relation before the first DDL statement; do not guess names.
        foreach ($this->relations as $table => $relations) {
            $keys = Schema::getForeignKeys($table);
            foreach ($relations as $column => $parent) {
                $matches = array_values(array_filter($keys, fn ($key) => $key['columns'] === [$column]));
                if (count($matches) !== 1 || $matches[0]['foreign_table'] !== $parent
                    || $matches[0]['foreign_columns'] !== ['id']) {
                    throw new RuntimeException("Contrainte inattendue sur {$table}.{$column}. Aucune modification effectuée.");
                }
                $key = $matches[0];
                if (DB::table($table)->whereNotNull($column)->whereNotIn($column, DB::table($parent)->select('id'))->exists()) {
                    throw new RuntimeException("Références orphelines sur {$table}.{$column}. Corrigez-les avant migration.");
                }
                $plan[$table][$column] = [$parent, $key];
            }
        }
        $apply = function () use ($plan, $action): void {
            foreach ($plan as $name => $relations) {
                Schema::table($name, function (Blueprint $table) use ($relations, $action): void {
                    foreach ($relations as $column => [$parent, $key]) {
                        if (DB::getDriverName() === 'sqlite') {
                            $table->dropForeign([$column]);
                        } else {
                            $table->dropForeign($key['name']);
                        }
                        $table->foreign($column)->references('id')->on($parent)
                            ->onUpdate($key['on_update'])->onDelete($action);
                    }
                });
            }
        };
        if (DB::getDriverName() === 'sqlite') {
            Schema::withoutForeignKeyConstraints(function () use ($apply): void {
                DB::transaction(function () use ($apply): void {
                    $apply();
                    if (DB::select('PRAGMA foreign_key_check') !== []) {
                        throw new RuntimeException('Échec du contrôle des clés étrangères après migration.');
                    }
                });
            });
        } else {
            $apply();
        }
    }
};
