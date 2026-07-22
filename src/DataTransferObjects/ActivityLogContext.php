<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\DataTransferObjects;

use Illuminate\Support\Str;
use Tsitsishvili\ElasticAudit\Support\PackageConfig;
use Tsitsishvili\ElasticAudit\Support\RetentionDays;

final readonly class ActivityLogContext
{
    public function __construct(
        public string $actorType,
        public int|string|null $actorId,
        public string $entityType,
        public string $entityId,
        public string $requestId,
        public ?int $retentionDays,
        public ?string $traceId = null,
        public ?string $spanId = null,
        public ?string $traceParent = null,
    ) {
        if ($this->retentionDays !== null) {
            RetentionDays::validate($this->retentionDays);
        }
    }

    public static function forActor(
        string $actorType,
        int|string|null $actorId,
        string $entityType,
        string $entityId,
        ?string $requestId = null,
        ?int $retentionDays = null,
        ?string $traceId = null,
        ?string $spanId = null,
        ?string $traceParent = null,
        bool $retainForever = false,
    ): self {
        return new self(
            actorType: $actorType,
            actorId: $actorId,
            entityType: $entityType,
            entityId: $entityId,
            requestId: $requestId ?? (string) Str::ulid(),
            retentionDays: RetentionDays::resolve(
                days: $retentionDays,
                retainForever: $retainForever,
                configuredDays: PackageConfig::get('activity_logs.retention_days', 360),
                configuredRetainForever: (bool) PackageConfig::get('activity_logs.retain_forever', false),
            ),
            traceId: $traceId,
            spanId: $spanId,
            traceParent: $traceParent,
        );
    }
}
