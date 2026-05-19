<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $table = config('satlane.invoices_table', 'satlane_invoices');
        $connection = config('satlane.connection');
        $schema = $connection ? Schema::connection($connection) : Schema::getFacadeRoot();

        if ($schema->hasTable($table)) {
            return; // re-runnable: don't blow up on partial installs
        }

        $schema->create($table, function (Blueprint $t) {
            // SatLane's UUID is the primary key — vendors can join
            // directly without an extra surrogate id.
            $t->uuid('id')->primary();

            // Indexed for `Order::satlaneInvoice` style lookups.
            $t->string('order_ref', 255)->nullable()->index();

            $t->string('status', 32)->index();
            $t->string('environment', 16)->index();

            $t->string('address', 100);
            $t->unsignedBigInteger('amount_sats');
            $t->unsignedBigInteger('amount_paid_sats')->default(0);
            $t->unsignedBigInteger('amount_tolerance_sats')->default(0);
            $t->decimal('amount_fiat', 18, 2)->nullable();
            $t->string('fiat_currency', 8)->nullable();
            $t->decimal('btc_usd_rate', 18, 2)->nullable();

            $t->timestamp('expires_at')->nullable();
            $t->timestamp('paid_at')->nullable();

            $t->string('buyer_email', 255)->nullable();
            $t->string('callback_url', 500)->nullable();
            $t->string('success_url', 500)->nullable();
            $t->string('hosted_checkout_url', 500)->nullable();
            $t->string('payment_uri', 500)->nullable();

            // Full payload from the most recent webhook so devs can
            // grep metadata + edge cases without round-tripping to us.
            $t->json('raw');

            $t->timestamp('first_seen_at')->useCurrent();
            $t->timestamp('last_updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        $table = config('satlane.invoices_table', 'satlane_invoices');
        $connection = config('satlane.connection');
        $schema = $connection ? Schema::connection($connection) : Schema::getFacadeRoot();
        $schema->dropIfExists($table);
    }
};
