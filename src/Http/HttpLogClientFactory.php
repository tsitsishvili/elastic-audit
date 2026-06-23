<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Http;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Tsitsishvili\ElasticAudit\Contracts\EventTypeContract;
use Tsitsishvili\ElasticAudit\Contracts\ProviderContract;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogContext;
use Tsitsishvili\ElasticAudit\Services\Redactors\PaymentRedactor;
use Tsitsishvili\ElasticAudit\Services\Redactors\SensitiveDataRedactor;

class HttpLogClientFactory
{
    public function __construct(
        private readonly HttpFactory $httpFactory,
        private readonly PaymentRedactor $paymentRedactor,
        private readonly SensitiveDataRedactor $sensitiveDataRedactor,
    ) {}

    /**
     * Build a Laravel HTTP client (PendingRequest) with outgoing-request logging
     * attached as a Guzzle middleware. Callers use the full native fluent API; every
     * request made through it is logged for the given provider/event/context.
     */
    public function make(
        ProviderContract $provider,
        EventTypeContract $eventType,
        HttpLogContext $context,
    ): PendingRequest {
        $paymentProviderValues = config('http_logs.payment_provider_values', []);

        $redactor = in_array($provider->getValue(), $paymentProviderValues, true)
            ? $this->paymentRedactor
            : $this->sensitiveDataRedactor;

        return $this->httpFactory
            ->createPendingRequest()
            ->withMiddleware(new OutgoingHttpLogMiddleware($provider, $eventType, $context, $redactor));
    }
}
