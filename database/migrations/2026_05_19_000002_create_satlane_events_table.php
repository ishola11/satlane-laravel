<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $table = config('satlane.events_table', 'satlane_events');
        $connection = config('satlane.connection');
        $schema = $connection ? Schema::connection($connection) : Schema::getFacadeRoot();

        if ($schema->hasTable($table)) {
            return;
        }

        $schema->create($table, function (Blueprint $t) {
            $t->bigIncrements('id');

            // Idempotency: every retry of the same event carries this id.
            $t->string('event_id', 64)->unique();

            $t->string('event_type', 64)->index();
            $t->uuid('invoice_id')->nullable()->index();
            $t->string('order_ref', 255)->nullable()->index();

            $t->boolean('signature_verified')->default(false);

            // Full event body so devs can replay or debug without
            // asking SatLane.
            $t->json('payload');

            $t->timestamp('received_at')->useCurrent();
            $t->timestamp('processed_at')->nullable();

            // If a handler throws, capture so devs can grep for
            // failures (and the replay command can target them).
            $t->text('handler_error')->nullable();
            $t->integer('handler_attempts')->default(0);
        });
    }

    public function down(): void
    {
        $table = config('satlane.events_table', 'satlane_events');
        $connection = config('satlane.connection');
        $schema = $connection ? Schema::connection($connection) : Schema::getFacadeRoot();
        $schema->dropIfExists($table);
    }
};
