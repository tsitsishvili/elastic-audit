<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Facades;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;
use Tsitsishvili\ElasticAudit\Contracts\EventTypeContract;
use Tsitsishvili\ElasticAudit\Contracts\ProviderContract;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogContext;
use Tsitsishvili\ElasticAudit\HttpLogManager;

/**
 * @method static PendingRequest make(ProviderContract $provider, EventTypeContract $eventType, HttpLogContext $context)
 * @method static void logIncoming(Request $request, ProviderContract $provider, EventTypeContract $eventType, HttpLogContext $context, int $latencyMs = 0, int $httpStatusCode = 200, bool $success = true, ?Response $response = null)
 *
 * @see HttpLogManager
 */
class HttpLog extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return HttpLogManager::class;
    }
}
