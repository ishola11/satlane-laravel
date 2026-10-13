<?php

declare(strict_types=1);

namespace Satlane\Laravel\Models;

use Illuminate\Database\Eloquent\Model;

class SatlaneInvoice extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    // Use first_seen_at / last_updated_at instead of Laravel's defaults.
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'amount_sats'           => 'integer',
        'amount_paid_sats'      => 'integer',
        'amount_tolerance_sats' => 'integer',
        'amount_fiat'           => 'decimal:2',
        'btc_usd_rate'          => 'decimal:2',
        'expires_at'            => 'datetime',
        'paid_at'               => 'datetime',
        'first_seen_at'         => 'datetime',
        'last_updated_at'       => 'datetime',
        'raw'                   => 'array',
        'last_event_at' => 'datetime',
        'last_event_unix_ms' => 'integer',
        'payment_summary' => 'array',
        'checkout_urls' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('satlane.invoices_table', 'satlane_invoices'));
        $connection = config('satlane.connection');
        if ($connection) {
            $this->setConnection($connection);
        }
    }

    public function events()
    {
        return $this->hasMany(SatlaneEvent::class, 'invoice_id', 'id');
    }

    // Status sugar — same predicates the dev would write themselves.
    public function isPaid(): bool
    {
        return in_array($this->status, ['paid', 'late_paid'], true);
    }
    public function isConfirming(): bool
    {
        return $this->payment_phase === 'confirming';
    }
    public function checkoutUrl(): ?string
    {
        return $this->checkout_urls['branded'] ?? $this->hosted_checkout_url;
    }
    public function isUnderpaid(): bool
    {
        return $this->status === 'underpaid';
    }
    public function isOverpaid(): bool
    {
        return $this->status === 'overpaid';
    }
    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }
    public function isReverted(): bool
    {
        return $this->status === 'reverted';
    }
    public function needsReview(): bool
    {
        return $this->status === 'requires_review';
    }

    /** Sats still owed (only meaningful while underpaid). */
    public function remainingSats(): int
    {
        $owed = (int) $this->amount_sats - (int) $this->amount_paid_sats;
        return max(0, $owed);
    }
}
