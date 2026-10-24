<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('ice', 100)->nullable()->after('tax_number');
            $table->integer('payment_terms_days')->default(30)->after('ice');
            $table->decimal('default_tax_rate', 5, 2)->nullable()->after('payment_terms_days');
            $table->string('currency', 10)->default('MAD')->after('default_tax_rate');
            $table->string('invoice_prefix', 20)->default('FAC-')->after('currency');
            $table->text('invoice_footer')->nullable()->after('invoice_prefix');
            $table->boolean('notify_on_new_invoice')->default(true)->after('invoice_footer');
            $table->boolean('notify_on_payment')->default(true)->after('notify_on_new_invoice');
            $table->boolean('notify_on_quote')->default(true)->after('notify_on_payment');
            $table->boolean('notify_on_overdue')->default(true)->after('notify_on_quote');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropColumns('companies', [
            'ice',
            'payment_terms_days',
            'default_tax_rate',
            'currency',
            'invoice_prefix',
            'invoice_footer',
            'notify_on_new_invoice',
            'notify_on_payment',
            'notify_on_quote',
            'notify_on_overdue',
        ]);
    }
};