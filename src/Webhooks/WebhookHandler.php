<?php

declare(strict_types=1);

namespace Satlane\Laravel\Webhooks;

use Satlane\Laravel\Models\SatlaneInvoice;

/**
 * Abstract base for a webhook handler. Subclasses implement one method
 * per event_type they care about. Unimplemented methods are no-ops.
 *
 * Method names match the event_type with dots replaced by camel case:
 *
 *   invoice.created         → public function created(SatlaneInvoice $i)
 *   invoice.payment_seen    → public function paymentSeen(SatlaneInvoice $i)
 *   invoice.paid            → public function paid(SatlaneInvoice $i)
 *   invoice.late_paid       → public function latePaid(SatlaneInvoice $i)
 *   invoice.underpaid       → public function underpaid(SatlaneInvoice $i)
 *   invoice.overpaid        → public function overpaid(SatlaneInvoice $i)
 *   invoice.expired         → public function expired(SatlaneInvoice $i)
 *   invoice.payment_reverted → public function paymentReverted(SatlaneInvoice $i)
 *   invoice.requires_review → public function requiresReview(SatlaneInvoice $i)
 *   invoice.cancelled       → public function cancelled(SatlaneInvoice $i)
 *
 * Override `default()` to catch event types you didn't write a method
 * for. The SatlaneInvoice passed in is the mirrored row (always loaded
 * fresh, status reflects the just-arrived event).
 */
abstract class WebhookHandler
{
    /**
     * Override per-event in your subclass. Default no-op.
     */
    public function created(SatlaneInvoice $invoice): void {}
    public function paymentSeen(SatlaneInvoice $invoice): void {}
    public function paid(SatlaneInvoice $invoice): void {}
    public function latePaid(SatlaneInvoice $invoice): void {}
    public function underpaid(SatlaneInvoice $invoice): void {}
    public function overpaid(SatlaneInvoice $invoice): void {}
    public function expired(SatlaneInvoice $invoice): void {}
    public function paymentReverted(SatlaneInvoice $invoice): void {}
    public function requiresReview(SatlaneInvoice $invoice): void {}
    public function cancelled(SatlaneInvoice $invoice): void {}

    /**
     * Catch-all for event types your subclass didn't implement
     * explicitly. Default no-op so unknown future event types
     * don't crash your handler.
     */
    public function default(string $eventType, SatlaneInvoice $invoice): void {}

    /**
     * Dispatch by event_type. Called by the controller — you
     * usually don't override this.
     */
    public function dispatch(string $eventType, SatlaneInvoice $invoice): void
    {
        $method = match ($eventType) {
            'invoice.created'          => 'created',
            'invoice.payment_seen'     => 'paymentSeen',
            'invoice.paid'             => 'paid',
            'invoice.late_paid'        => 'latePaid',
            'invoice.underpaid'        => 'underpaid',
            'invoice.overpaid'         => 'overpaid',
            'invoice.expired'          => 'expired',
            'invoice.payment_reverted' => 'paymentReverted',
            'invoice.requires_review'  => 'requiresReview',
            'invoice.cancelled'        => 'cancelled',
            default                    => null,
        };

        if ($method === null) {
            $this->default($eventType, $invoice);
            return;
        }

        $this->{$method}($invoice);
    }
}
