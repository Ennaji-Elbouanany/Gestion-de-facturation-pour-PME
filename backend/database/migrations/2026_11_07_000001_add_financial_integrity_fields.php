<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No backfill: legacy statuses/amounts are retained for a read-only audit.
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('document_status')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->json('issued_snapshot')->nullable();
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1);
            $table->uuid('idempotency_key')->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->unique(['invoice_id', 'idempotency_key']);
        });
        Schema::create('payment_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->text('reason');
            $table->json('before');
            $table->json('after');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (DB::table('payment_revisions')->exists() || DB::table('invoices')->whereNotNull('issued_snapshot')->exists()) {
            throw new RuntimeException('Le retour arrière détruirait des instantanés ou des corrections financières.');
        }
        // MySQL may replace its implicit invoice FK index with our composite unique index.
        // Preserve a standalone supporting index before removing that composite index.
        if (! Schema::hasIndex('payments', ['invoice_id'])) {
            Schema::table('payments', fn (Blueprint $table) => $table->index('invoice_id', 'payments_invoice_retention_index'));
        }
        Schema::dropIfExists('payment_revisions');
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['invoice_id', 'idempotency_key']);
            $table->dropColumn(['version', 'idempotency_key', 'request_hash']);
        });
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['document_status', 'issued_at', 'issued_snapshot']));
    }
};
