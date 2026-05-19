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
        $this->postWebhook($this->makeEvent('invoice.paid',      ['status' => 'paid',      'amount_paid_sats' => '100000']))->assertOk();

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
    public static ?string $throwOnEvent = null;

    private function record(string $name): void
    {
        if (self::$throwOnEvent === "invoice.{$name}") {
            self::$calls[] = $name;
            throw new \RuntimeException('boom');
        }
        self::$calls[] = $name;
    }

    public function paid(SatlaneInvoice $invoice): void { $this->record('paid'); }
    public function underpaid(SatlaneInvoice $invoice): void { $this->record('underpaid'); }
    public function latePaid(SatlaneInvoice $invoice): void { $this->record('late_paid'); }
}
