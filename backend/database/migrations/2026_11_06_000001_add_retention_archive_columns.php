<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->softDeletes());
        foreach (['invoices', 'quotes'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->timestamp('archived_at')->nullable()->index());
        }
    }

    public function down(): void
    {
        if (DB::table('clients')->whereNotNull('deleted_at')->exists()
            || DB::table('invoices')->whereNotNull('archived_at')->exists()
            || DB::table('quotes')->whereNotNull('archived_at')->exists()) {
            throw new RuntimeException('Restaurez les archives avant de retirer les colonnes de conservation.');
        }
        foreach (['invoices', 'quotes'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropIndex(['archived_at']);
                $table->dropColumn('archived_at');
            });
        }
        Schema::table('clients', fn (Blueprint $table) => $table->dropSoftDeletes());
    }
};
