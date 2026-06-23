<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\DataTransferObjects;

use Illuminate\Support\Str;
use Tsitsishvili\ElasticAudit\Contracts\EventTypeContract;
use Tsitsishvili\ElasticAudit\Contracts\ProviderContract;
use Tsitsishvili\ElasticAudit\Enums\HttpDirection;

final readonly class HttpLogData
{
    public const SCHEMA_VERSION = 2;

    public function __construct(
        public string $eventId,
        public string $timestamp,
        public string $requestId,
        public ProviderContract $provider,
        public EventTypeContract $eventType,
        public HttpDirection $direction,
        public string $httpMethod,
        public string $httpUrl,
        public string $httpHost,
        public string $httpPath,
        public ?int $httpStatusCode,
        public ?string $httpStatusClass,
        public int $latencyMs,
        public string $entityType,
        public string $entityId,
        public ?string $externalId,
        public ?int $userId,
        public int $attempt,
        public bool $success,
        public int $retentionDays,
        public RedactedHttpPayload $request,
        public RedactedHttpPayload $response,
        public ?string $errorClass,
        public ?string $errorMessage,
        public bool $timedOut = false,
    ) {}

    public static function make(
        ProviderContract $provider,
        EventTypeContract $eventType,
        HttpDirection $direction,
        string $httpMethod,
        string $httpUrl,
        int $latencyMs,
        HttpLogContext $context,
        RedactedHttpPayload $request,
        RedactedHttpPayload $response,
        ?int $httpStatusCode = null,
        bool $success = true,
        int $attempt = 1,
        ?string $errorClass = null,
        ?string $errorMessage = null,
        bool $timedOut = false,
    ): self {
        $parsed = parse_url($httpUrl);
        $host   = $parsed['host'] ?? $httpUrl;
        $path   = $parsed['path'] ?? '/';

        $statusClass = $httpStatusCode !== null
            ? (string) (intdiv($httpStatusCode, 100)) . 'xx'
            : null;

        return new self(
            eventId: (string) Str::ulid(),
            timestamp: now()->toIso8601ZuluString(),
            requestId: $context->requestId,
            provider: $provider,
            eventType: $eventType,
            direction: $direction,
            httpMethod: strtoupper($httpMethod),
            httpUrl: $httpUrl,
            httpHost: $host,
            httpPath: $path,
            httpStatusCode: $httpStatusCode,
            httpStatusClass: $statusClass,
            latencyMs: $latencyMs,
            entityType: $context->entityType->getValue(),
            entityId: $context->entityId,
            externalId: $context->externalId,
            userId: $context->userId,
            attempt: $attempt,
            success: $success,
            retentionDays: $context->retentionDays,
            request: $request,
            response: $response,
            errorClass: $errorClass,
            errorMessage: $errorMessage,
            timedOut: $timedOut,
        );
    }
}
