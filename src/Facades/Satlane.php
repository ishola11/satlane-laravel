<?php

declare(strict_types=1);

namespace Satlane\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Satlane\Client;

/**
 * @method static \Satlane\Resources\Invoices $invoices()
 * @method static \Satlane\Resources\Webhooks $webhooks()
 * @method static array request(string $method, string $path, ?array $body = null, array $headers = [])
 *
 * @see \Satlane\Client
 */
class Satlane extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
