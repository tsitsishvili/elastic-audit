<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\DataTransferObjects;

use Illuminate\Support\Str;
use Tsitsishvili\ElasticAudit\Contracts\EntityTypeContract;
use Tsitsishvili\ElasticAudit\Support\PackageConfig;
use Tsitsishvili\ElasticAudit\Support\RetentionDays;

final readonly class HttpLogContext
{
    public function __construct(
        public EntityTypeContract $entityType,
        public string $entityId,
        public ?string $externalId,
        public int|string|null $userId,
        public string $requestId,
        public ?int $retentionDays,
        public ?string $traceId = null,
        public ?string $spanId = null,
        public ?string $traceParent = null,
        public ?ExecutionOrigin $executionOrigin = null,
    ) {
        if ($this->retentionDays !== null) {
            RetentionDays::validate($this->retentionDays);
        }
    }

    public static function forEntity(
        EntityTypeContract $entityType,
        string $entityId,
        ?string $externalId = null,
        int|string|null $userId = null,
        ?int $retentionDays = null,
        ?string $requestId = null,
        ?string $traceId = null,
        ?string $spanId = null,
        ?string $traceParent = null,
        bool $retainForever = false,
        ?ExecutionOrigin $executionOrigin = null,
    ): self {
        return new self(
            entityType: $entityType,
            entityId: $entityId,
            externalId: $externalId,
            userId: $userId,
            requestId: $requestId ?? (string) Str::ulid(),
            retentionDays: RetentionDays::resolve(
                days: $retentionDays,
                retainForever: $retainForever,
                configuredDays: PackageConfig::get('http_logs.retention_days', 360),
                configuredRetainForever: (bool) PackageConfig::get('http_logs.retain_forever', false),
            ),
            traceId: $traceId,
            spanId: $spanId,
            traceParent: $traceParent,
            executionOrigin: $executionOrigin,
        );
    }
}
