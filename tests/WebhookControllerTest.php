<?php

declare(strict_types=1);

namespace Satlane\Laravel\Tests;

use Illuminate\Support\Facades\Route;
use Satlane\Laravel\Models\SatlaneEvent;
use Satlane\Laravel\Models\SatlaneInvoice;
use Satlane\Laravel\Webhooks\WebhookHandler;
use Satlane\WebhookSignature;

final class WebhookControllerTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        Route::satlaneWebhook('/webhooks/test', RecordingHandler::class);
        RecordingHandler::$calls = [];
        RecordingHandler::$throwOnEvent = null;
    }

    private function makeEvent(string $type, array $invoiceOverrides = []): array
    {
        return [
            'event_id'   => 'evt_' . uniqid(),
            'event_type' => $type,
            'created_at' => now()->toIso8601String(),
            'livemode'   => false,
            'data' => [
                'invoice' => array_merge([
                    'id'                    => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                    'status'                => 'paid',
                    'environment'           => 'test',
                    'address'               => 'bc1qexample',
                    'amount_sats'           => '100000',
                    'amount_paid_sats'      => '100000',
                    'amount_tolerance_sats' => '25',
                    'order_ref'             => 'ORD-1',
                ], $invoiceOverrides),
            ],
        ];
    }

    private function postWebhook(array $event): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $header = WebhookSignature::sign($body, self::SECRET);
        return $this->call(
            method: 'POST',
            uri: '/webhooks/test',
            server: ['HTTP_X_SATLANE_SIGNATURE' => $header, 'CONTENT_TYPE' => 'application/json'],
            content: $body,
        );
    }

    public function testFailedHandlerRunsAgainOnDeliveryRetry(): void
    {
        $event = $this->makeEvent('invoice.paid');
        RecordingHandler::$throwOnEvent = 'invoice.paid';
        $this->postWebhook($event)->assertStatus(503);
        RecordingHandler::$throwOnEvent = null;
        $this->postWebhook($event)->assertOk();
        $this->assertSame(['paid','paid'], RecordingHandler::$calls);
        $this->assertTrue(SatlaneEvent::first()->isProcessed());
        $this->assertSame(2, SatlaneEvent::first()->handler_attempts);
    }
    public function testStorageFailureIsNotAcknowledged(): void
    {
        SatlaneEvent::creating(function () {
            throw new \RuntimeException('storage failed');
        });
        $this->postWebhook($this->makeEvent('invoice.paid'))->assertStatus(503);
        $this->assertDatabaseCount('satlane_events', 0);
    }
    public function testMirrorFailureRemainsRetryable(): void
    {
        $fail = true;
        SatlaneInvoice::creating(function () use (&$fail) {
            if ($fail) {
                throw new \RuntimeException('mirror failed');
            }
        });
        $event = $this->makeEvent('invoice.paid');
        $this->postWebhook($event)->assertStatus(503);
        $this->assertNull(SatlaneEvent::first()->processed_at);
        $fail = false;
        $this->postWebhook($event)->assertOk();
        $this->assertSame(['paid'], RecordingHandler::$calls);
    }
    public function testHandlerStillReceivesSnapshotWithoutMirroring(): void
    {
        config()->set('satlane.mirror_invoices', false);
        $this->postWebhook($this->makeEvent('invoice.paid'))->assertOk();
        $this->assertSame(['paid'], RecordingHandler::$calls);
        $this->assertDatabaseCount('satlane_invoices', 0);
    }
    public function testOlderEventDoesNotRegressInvoiceOrDispatchHandler(): void
    {
        $new = $this->makeEvent('invoice.paid');
        $new['created_at'] = '2026-10-12T12:00:01.000Z';
        $old = $this->makeEvent('invoice.underpaid', ['status' => 'underpaid']);
        $old['created_at'] = '2026-10-12T12:00:00.000Z';
        $this->postWebhook($new)->assertOk();
        $this->postWebhook($old)->assertOk();
        $this->assertSame('paid', SatlaneInvoice::first()->status);
        $this->assertSame(['paid'], RecordingHandler::$calls);
        $this->assertSame('stale', SatlaneEvent::where('event_id', $old['event_id'])->first()->processing_result);
    }
    public function testOrderingIsIndependentOfApplicationTimezone(): void
    {
        config()->set('app.timezone', 'Africa/Lagos');
        $new = $this->makeEvent('invoice.paid');
        $new['created_at'] = '2026-10-12T12:00:00.200Z';
        $old = $this->makeEvent('invoice.underpaid', ['status' => 'underpaid']);
        $old['created_at'] = '2026-10-12T13:00:00.100+01:00';
        $this->postWebhook($new)->assertOk();
        $this->postWebhook($old)->assertOk();
        $this->assertSame('paid', SatlaneInvoice::first()->status);
        $this->assertSame(['paid'], RecordingHandler::$calls);
        $this->assertSame('stale', SatlaneEvent::where('event_id', $old['event_id'])->first()->processing_result);
    }
    public function testSandboxDestinationAndLifecycleFieldsArePreserved(): void
    {
        $this->postWebhook($this->makeEvent('invoice.paid', ['address' => null,'payment_phase' => 'settled','payment_summary' => ['remaining_sats' => '0']]))->assertOk();
        $invoice = SatlaneInvoice::first();
        $this->assertNull($invoice->address);
        $this->assertSame('settled', $invoice->payment_phase);
        $this->assertSame('0', $invoice->payment_summary['remaining_sats']);
    }
    public function testConnectionTestWithoutInvoiceCanBeRecorded(): void
    {
        $event = ['event_id' => 'evt_connection','event_type' => 'webhook.test','data' => ['message' => 'test']];
        $this->postWebhook($event)->assertOk();
        $this->assertDatabaseCount('satlane_events', 1);
        $this->assertDatabaseCount('satlane_invoices', 0);
    }
    public function testReplayUsesSavedSnapshotAndDoesNotRegressNewerMirror(): void
    {
        $old = $this->makeEvent('invoice.underpaid', ['status' => 'underpaid']);
        $old['created_at'] = '2026-10-12T12:00:00.100Z';
        $new = $this->makeEvent('invoice.paid');
        $new['created_at'] = '2026-10-12T12:00:00.200Z';
        $this->postWebhook($old)->assertOk();
        $this->postWebhook($new)->assertOk();
        RecordingHandler::$statuses = [];
        $this->artisan('satlane:replay', ['event_id' => $old['event_id'],'--force' => true])->assertSuccessful();
        $this->assertSame(['underpaid'], RecordingHandler::$statuses);
        $this->assertSame('paid', SatlaneInvoice::first()->status);
    }
    public function testReplaySkipsCompletedEventUnlessForced(): void
    {
        $event = $this->makeEvent('invoice.paid');
        $this->postWebhook($event)->assertOk();
        $this->artisan('satlane:replay', ['event_id' => $event['event_id']])->assertSuccessful();
        $this->assertSame(['paid'], RecordingHandler::$calls);
    }
    public function testPruneRetainsFailuresAndPendingWork(): void
    {
        $event = $this->makeEvent('invoice.paid');
        RecordingHandler::$throwOnEvent = 'invoice.paid';
        $this->postWebhook($event)->assertStatus(503);
        SatlaneEvent::query()->update(['received_at' => now()->subDays(100)]);
        $this->artisan('satlane:prune')->assertSuccessful();
        $this->assertDatabaseCount('satlane_events', 1);
    }
    public function testInstallerReturnsFailureForMissingConfiguration(): void
    {
        config()->set('satlane.api_key', null);
        $this->artisan('satlane:install', ['--no-publish' => true,'--no-migrate' => true,'--no-ping' => true])->assertFailed();
    }
    public function testRouteSpecificSecretAndEnvironmentAreEnforced(): void
    {
        Route::satlaneWebhook('/webhooks/scoped', RecordingHandler::class, ['secret' => 'other-secret','environment' => 'live','store_id' => 'store-a']);
        $event = $this->makeEvent('invoice.paid', ['store_id' => 'store-a','environment' => 'test']);
        $body = json_encode($event);
        $headers = ['HTTP_X_SATLANE_SIGNATURE' => WebhookSignature::sign($body, 'other-secret'),'CONTENT_TYPE' => 'application/json'];
        $this->call('POST', '/webhooks/scoped', [], [], [], $headers, $body)->assertStatus(400);
        $this->assertDatabaseCount('satlane_events', 0);
        $event['data']['invoice']['environment'] = 'live';
        $body = json_encode($event);
        $headers['HTTP_X_SATLANE_SIGNATURE'] = WebhookSignature::sign($body, 'other-secret');
        $this->call('POST', '/webhooks/scoped', [], [], [], $headers, $body)->assertOk();
    }
    public function testReversionNewerThanSettlementStillDispatches(): void
    {
        $paid = $this->makeEvent('invoice.paid');
        $paid['created_at'] = '2026-10-12T12:00:00.100Z';
        $revert = $this->makeEvent('invoice.payment_reverted', ['status' => 'reverted']);
        $revert['created_at'] = '2026-10-12T12:00:00.200Z';
        $this->postWebhook($paid)->assertOk();
        $this->postWebhook($revert)->assertOk();
        $this->assertSame('reverted', SatlaneInvoice::first()->status);
    }
    public function testValidEventIsPersistedAndDispatched(): void
    {
        $event = $this->makeEvent('invoice.paid');
        $response = $this->postWebhook($event);

        $response->assertOk();
        $this->assertDatabaseHas('satlane_events', [
            'event_id'   => $event['event_id'],
            'event_type' => 'invoice.paid',
        ]);
        $this->assertDatabaseHas('satlane_invoices', [
            'id'        => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'status'    => 'paid',
            'order_ref' => 'ORD-1',
        ]);
        $this->assertSame(['paid'], RecordingHandler::$calls);
    }

    public function testInvalidSignatureReturns400(): void
    {
        $body = json_encode($this->makeEvent('invoice.paid'));
        $response = $this->call(
            method: 'POST',
            uri: '/webhooks/test',
            server: ['HTTP_X_SATLANE_SIGNATURE' => 't=1,v1=deadbeef', 'CONTENT_TYPE' => 'application/json'],
            content: $body,
        );
        $response->assertStatus(400);
        $this->assertDatabaseCount('satlane_events', 0);
    }

    public function testDuplicateEventReturns200AndDoesNotReDispatch(): void
    {
        $event = $this->makeEvent('invoice.paid');
        $this->postWebhook($event)->assertOk();
        $this->postWebhook($event)->assertOk(); // same event_id

        $this->assertDatabaseCount('satlane_events', 1);
        $this->assertCount(1, RecordingHandler::$calls);
    }

    public function testMalformedBodyReturns400(): void
    {
        $body = 'not even json';
        $header = WebhookSignature::sign($body, self::SECRET);
        $response = $this->call(
            method: 'POST',
            uri: '/webhooks/test',
            server: ['HTTP_X_SATLANE_SIGNATURE' => $header, 'CONTENT_TYPE' => 'application/json'],
            content: $body,
        );
        $response->assertStatus(400);
    }

    public function testHandlerThrowingRetryModeReturns503AndStoresError(): void
    {
        config()->set('satlane.on_handler_error', 'retry');
        RecordingHandler::$throwOnEvent = 'invoice.paid';

        $response = $this->postWebhook($this->makeEvent('invoice.paid'));
        $response->assertStatus(503);

        $row = SatlaneEvent::first();
        $this->assertNotNull($row->handler_error);
        $this->assertNull($row->processed_at);
    }

    public function testHandlerThrowingIgnoreModeReturns200(): void
    {
        config()->set('satlane.on_handler_error', 'ignore');
        RecordingHandler::$throwOnEvent = 'invoice.paid';

        $response = $this->postWebhook($this->makeEvent('invoice.paid'));
        $response->assertOk();

        $row = SatlaneEvent::first();
        $this->assertNotNull($row->handler_error);
    }

    public function testTopUpFiresUnderpaidTwiceThenPaid(): void
    {
        $this->postWebhook($this->makeEvent('invoice.underpaid', ['status' => 'underpaid', 'amount_paid_sats' => '60000']))->assertOk();
        $this->postWebhook($this->makeEvent('invoice.underpaid', ['status' => 'underpaid', 'amount_paid_sats' => '90000']))->assertOk();
        $this->postWebhook($this->makeEvent('invoice.paid', ['status' => 'paid',      'amount_paid_sats' => '100000']))->assertOk();

        // All three events recorded.
        $this->assertDatabaseCount('satlane_events', 3);
        // Invoice ended in paid state with the running total.
        $this->assertDatabaseHas('satlane_invoices', [
            'id'                => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'status'            => 'paid',
            'amount_paid_sats'  => 100000,
        ]);
        $this->assertSame(['underpaid', 'underpaid', 'paid'], RecordingHandler::$calls);
    }
}

class RecordingHandler extends WebhookHandler
{
    /** @var list<string> */
    public static array $calls = [];
    public static array $statuses = [];
    public static ?string $throwOnEvent = null;

    private function record(string $name): void
    {
        if (self::$throwOnEvent === "invoice.{$name}") {
            self::$calls[] = $name;
            throw new \RuntimeException('boom');
        }
        self::$calls[] = $name;
    }

    public function paid(SatlaneInvoice $invoice): void
    {
        $this->record('paid');
    }
    public function underpaid(SatlaneInvoice $invoice): void
    {
        self::$statuses[] = $invoice->status;
        $this->record('underpaid');
    }
    public function latePaid(SatlaneInvoice $invoice): void
    {
        $this->record('late_paid');
    }
}
