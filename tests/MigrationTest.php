<?php

declare(strict_types=1);

namespace Satlane\Laravel\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MigrationTest extends TestCase
{
    public function testUpgradePreservesExistingRowsAndRollbackPreservesSandboxRows(): void
    {
        $migration = require __DIR__ . '/../database/migrations/2026_10_12_000003_harden_satlane_webhook_processing.php';
        $migration->down();
        Schema::table('satlane_invoices', static fn (\Illuminate\Database\Schema\Blueprint $table) => $table->string('address', 100)->nullable(false)->change());
        DB::table('satlane_invoices')->insert([
            'id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'status' => 'paid', 'environment' => 'live', 'address' => 'bc1qfixture',
            'amount_sats' => 1000, 'amount_paid_sats' => 1000, 'raw' => '{}',
        ]);
        DB::table('satlane_events')->insert([
            'event_id' => 'evt_existing', 'event_type' => 'invoice.paid',
            'payload' => '{}', 'handler_error' => 'previous failure',
        ]);
        $migration->up();
        self::assertTrue(Schema::hasTable('satlane_invoice_locks'));
        $this->assertDatabaseHas('satlane_invoices', ['address' => 'bc1qfixture', 'amount_paid_sats' => 1000]);
        $this->assertDatabaseHas('satlane_events', ['event_id' => 'evt_existing', 'handler_error' => 'previous failure']);
        DB::table('satlane_invoices')->where('id', 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa')->update(['address' => null, 'environment' => 'test']);
        $migration->down();
        self::assertFalse(Schema::hasTable('satlane_invoice_locks'));
        $this->assertDatabaseHas('satlane_invoices', ['address' => null, 'environment' => 'test']);
        $migration->up();
    }
}
