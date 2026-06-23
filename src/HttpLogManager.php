<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tsitsishvili\ElasticAudit\Contracts\EventTypeContract;
use Tsitsishvili\ElasticAudit\Contracts\ProviderContract;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogContext;
use Tsitsishvili\ElasticAudit\Http\HttpLogClientFactory;
use Tsitsishvili\ElasticAudit\Services\HttpLogger;

class HttpLogManager
{
    public function __construct(
        private readonly HttpLogClientFactory $factory,
        private readonly HttpLogger $logger,
    ) {}

    public function make(
        ProviderContract $provider,
        EventTypeContract $eventType,
        HttpLogContext $context,
    ): PendingRequest {
        return $this->factory->make($provider, $eventType, $context);
    }

    public function logIncoming(
        Request $request,
        ProviderContract $provider,
        EventTypeContract $eventType,
        HttpLogContext $context,
        int $latencyMs = 0,
        int $httpStatusCode = 200,
        bool $success = true,
        ?Response $response = null,
    ): void {
        $this->logger->logIncoming($request, $provider, $eventType, $context, $latencyMs, $httpStatusCode, $success, $response);
    }
}
