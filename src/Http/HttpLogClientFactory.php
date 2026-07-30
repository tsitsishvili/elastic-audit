<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Http;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Tsitsishvili\ElasticAudit\Contracts\EventTypeContract;
use Tsitsishvili\ElasticAudit\Contracts\ProviderContract;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogContext;
use Tsitsishvili\ElasticAudit\Services\Redactors\HttpPayloadRedactorResolver;
use Tsitsishvili\ElasticAudit\Services\Redactors\PaymentRedactor;
use Tsitsishvili\ElasticAudit\Services\Redactors\SensitiveDataRedactor;
use Tsitsishvili\ElasticAudit\Support\AuditFailureReporter;
use Tsitsishvili\ElasticAudit\Support\AuditSourceResolver;
use Tsitsishvili\ElasticAudit\Support\CaptureSampling;

class HttpLogClientFactory
{
    private readonly HttpPayloadRedactorResolver $redactorResolver;

    private readonly AuditFailureReporter $failureReporter;

    private readonly AuditSourceResolver $sourceResolver;

    public function __construct(
        private readonly HttpFactory $httpFactory,
        PaymentRedactor $paymentRedactor,
        SensitiveDataRedactor $sensitiveDataRedactor,
        ?HttpPayloadRedactorResolver $redactorResolver = null,
        ?AuditFailureReporter $failureReporter = null,
        ?AuditSourceResolver $sourceResolver = null,
    ) {
        $this->redactorResolver = $redactorResolver
            ?? new HttpPayloadRedactorResolver($sensitiveDataRedactor, $paymentRedactor);
        $this->failureReporter = $failureReporter ?? new AuditFailureReporter($sensitiveDataRedactor);
        $this->sourceResolver  = $sourceResolver ?? AuditSourceResolver::fromContainer();
    }

    /**
     * Build a Laravel HTTP client (PendingRequest) with outgoing-request logging
     * attached as a Guzzle middleware. Fluent configuration and single-request
     * verbs are logged for the given provider/event/context; Laravel pool/batch
     * APIs create separate child requests and are not covered.
     */
    public function make(
        ProviderContract $provider,
        EventTypeContract $eventType,
        HttpLogContext $context,
    ): PendingRequest {
        return $this->httpFactory
            ->createPendingRequest()
            ->withMiddleware(new OutgoingHttpLogMiddleware(
                $provider,
                $eventType,
                $context,
                $this->redactorResolver->forProvider($provider),
                CaptureSampling::shouldCapture(),
                $this->failureReporter,
                $this->sourceResolver,
            ));
    }
}
