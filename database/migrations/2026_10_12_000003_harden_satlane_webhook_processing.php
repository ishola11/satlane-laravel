<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        $schema = config('satlane.connection') ? Schema::connection(config('satlane.connection')) : Schema::getFacadeRoot();
        $schema->table(config('satlane.events_table', 'satlane_events'), function (Blueprint $t) {
            $t->string('handler_class', 255)->nullable();
            $t->string('processing_result', 32)->nullable();
        });
        $schema->table(config('satlane.invoices_table', 'satlane_invoices'), function (Blueprint $t) {
            $t->string('address', 100)->nullable()->change();
            $t->string('store_id', 64)->nullable()->index();
            $t->string('payment_phase', 32)->nullable();
            $t->json('payment_summary')->nullable();
            $t->json('checkout_urls')->nullable();
            $t->timestamp('last_event_at', 6)->nullable();
            $t->bigInteger('last_event_unix_ms')->nullable();
        });
        $schema->create(config('satlane.invoice_locks_table', 'satlane_invoice_locks'), function (Blueprint $t) {
            $t->string('invoice_id', 64)->primary();
        });
    }
    public function down(): void
    {
        $schema = config('satlane.connection') ? Schema::connection(config('satlane.connection')) : Schema::getFacadeRoot();
        $schema->dropIfExists(config('satlane.invoice_locks_table', 'satlane_invoice_locks'));
        $schema->table(config('satlane.events_table', 'satlane_events'), fn (Blueprint $t) => $t->dropColumn(['handler_class','processing_result']));
        $schema->table(config('satlane.invoices_table', 'satlane_invoices'), fn (Blueprint $t) => $t->dropIndex(['store_id']));
        $schema->table(config('satlane.invoices_table', 'satlane_invoices'), fn (Blueprint $t) => $t->dropColumn(['store_id','payment_phase','payment_summary','checkout_urls','last_event_at', 'last_event_unix_ms']));
        // Keep address nullable: a rollback must not destroy Sandbox records.
    }
};
