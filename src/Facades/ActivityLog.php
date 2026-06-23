<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Facades;

use Illuminate\Support\Facades\Facade;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogContext;
use Tsitsishvili\ElasticAudit\Services\ActivityLogger;

/**
 * @method static void record(string $action, ActivityLogContext $context, array $changes = [], array $metadata = [], bool $success = true, ?string $errorClass = null, ?string $errorMessage = null)
 *
 * @see ActivityLogger
 */
class ActivityLog extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ActivityLogger::class;
    }
}
