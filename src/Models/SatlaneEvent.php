<?php

declare(strict_types=1);

namespace Satlane\Laravel\Models;

use Illuminate\Database\Eloquent\Model;

class SatlaneEvent extends Model
{
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'payload'             => 'array',
        'signature_verified'  => 'boolean',
        'received_at'         => 'datetime',
        'processed_at'        => 'datetime',
        'handler_attempts'    => 'integer',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('satlane.events_table', 'satlane_events'));
        $connection = config('satlane.connection');
        if ($connection) {
            $this->setConnection($connection);
        }
    }

    public function invoice()
    {
        return $this->belongsTo(SatlaneInvoice::class, 'invoice_id', 'id');
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null && $this->handler_error === null;
    }

    public function failed(): bool
    {
        return $this->handler_error !== null;
    }
}
