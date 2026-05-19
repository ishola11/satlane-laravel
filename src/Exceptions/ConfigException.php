<?php

declare(strict_types=1);

namespace Satlane\Laravel\Exceptions;

use Satlane\Exceptions\SatlaneException;

/**
 * Thrown when the Laravel integration is misconfigured (missing env
 * var, invalid handler class, wrong DB connection name, etc.). Always
 * actionable — the message tells you exactly what to fix.
 */
class ConfigException extends SatlaneException
{
}
