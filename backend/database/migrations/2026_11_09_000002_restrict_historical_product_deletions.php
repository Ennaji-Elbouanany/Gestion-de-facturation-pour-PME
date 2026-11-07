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
        $this->replace('set null', 'restrict');
    }

    public function down(): void
    {
        $this->replace('restrict', 'set null');
    }

    private function replace(string $expected, string $action): void
    {
        $plan = [];
        foreach (['invoice_items', 'quote_items'] as $name) {
            $keys = array_values(array_filter(Schema::getForeignKeys($name), fn ($key) => $key['columns'] === ['product_id']));
            if (count($keys) !== 1 || $keys[0]['foreign_table'] !== 'products' || $keys[0]['foreign_columns'] !== ['id']
                || strtolower($keys[0]['on_delete']) !== $expected) {
                throw new RuntimeException('Contrainte produit inattendue ou migration partielle sur '.$name.'.');
            }
            if (DB::table($name)->whereNotNull('product_id')->whereNotIn('product_id', DB::table('products')->select('id'))->exists()) {
                throw new RuntimeException('Références produits orphelines sur '.$name.'.');
            }
            $plan[$name] = $keys[0];
        }
        $work = function () use ($plan, $action): void {
            foreach ($plan as $name => $key) {
                Schema::table($name, function (Blueprint $table) use ($key, $action): void {
                    $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['product_id'] : $key['name']);
                    $table->foreign('product_id')->references('id')->on('products')->onUpdate($key['on_update'])->onDelete($action);
                });
            }
        };
        if (DB::getDriverName() === 'sqlite') {
            Schema::withoutForeignKeyConstraints(fn () => DB::transaction(function () use ($work): void {
                $work();
                if (DB::select('PRAGMA foreign_key_check') !== []) {
                    throw new RuntimeException('échec du contr?le des références produits.');
                }
            }));
        } else {
            $work();
        }
    }
};
