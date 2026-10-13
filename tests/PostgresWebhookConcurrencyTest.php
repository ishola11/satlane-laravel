<?php

declare(strict_types=1);

namespace Satlane\Laravel\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Satlane\Laravel\Webhooks\EventProcessor;
use Satlane\Laravel\Webhooks\WebhookHandler;
use Satlane\Laravel\Models\SatlaneInvoice;
use Satlane\Laravel\Models\SatlaneEvent;

final class PostgresWebhookConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (!getenv('SATLANE_SDK_TEST_PG_PORT') || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('Run the isolated PostgreSQL SDK runner.');
        }
        parent::setUp();
        Schema::create('sdk_handler_calls', fn (Blueprint $t) => $t->string('status'));
    }
    protected function tearDown(): void
    {
        if (isset($this->app)) {
            Schema::dropIfExists('sdk_handler_calls');
        }
        parent::tearDown();
    }
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.connections.testing', [
            'driver' => 'pgsql','host' => '127.0.0.1','port' => getenv('SATLANE_SDK_TEST_PG_PORT'),
            'database' => 'satlane_sdk_disposable','username' => getenv('SATLANE_SDK_TEST_PG_USER') ?: getenv('USER'),'password' => getenv('SATLANE_SDK_TEST_PG_PASSWORD') ?: '','charset' => 'utf8','prefix' => '','schema' => 'public',
        ]);
    }
    private function event(string $id, string $type = 'invoice.paid', string $time = '2026-10-12T12:00:00.200Z'): array
    {
        return ['event_id' => $id,'event_type' => $type,'created_at' => $time,'data' => ['invoice' => ['id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa','status' => $type === 'invoice.paid' ? 'paid' : 'underpaid','environment' => 'test','amount_sats' => '1000','amount_paid_sats' => '1000']]];
    }
    private function parallel(array $events): void
    {
        $pids = [];
        DB::disconnect('testing');
        foreach ($events as $event) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new \RuntimeException('Could not fork isolated worker.');
            }
            if ($pid === 0) {
                try {
                    DB::purge('testing');
                    $p = new EventProcessor();
                    $p->process($p->receive($event, ConcurrencyHandler::class));
                    exit(0);
                } catch (\Throwable $e) {
                    fwrite(STDERR, 'SDK concurrency worker: '.$e->getMessage()."\n");
                    exit(1);
                }
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
    }
    public function testSimultaneousDeliveryAndReplayProcessOnce(): void
    {
        $event = $this->event('evt_same');
        (new EventProcessor())->receive($event, ConcurrencyHandler::class);
        $this->parallel([$event,$event]);
        $this->assertSame(1, DB::table('sdk_handler_calls')->count());
        $this->assertSame(1, SatlaneEvent::count());
        $this->assertTrue(SatlaneEvent::first()->isProcessed());
    }
    public function testDifferentEventsForOneInvoiceKeepNewestProjection(): void
    {
        $this->parallel([$this->event('evt_new'),$this->event('evt_old', 'invoice.underpaid', '2026-10-12T12:00:00.100Z')]);
        $this->assertSame('paid', SatlaneInvoice::first()->status);
        $this->assertSame(2, SatlaneEvent::whereNotNull('processed_at')->count());
    }
}
class ConcurrencyHandler extends WebhookHandler
{
    public function paid(SatlaneInvoice $invoice): void
    {
        usleep(100000);
        DB::table('sdk_handler_calls')->insert(['status' => $invoice->status]);
    }
    public function underpaid(SatlaneInvoice $invoice): void
    {
        usleep(100000);
        DB::table('sdk_handler_calls')->insert(['status' => $invoice->status]);
    }
}
