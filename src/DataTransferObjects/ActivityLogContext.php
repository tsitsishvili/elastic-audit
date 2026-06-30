<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\DataTransferObjects;

use Illuminate\Support\Str;

final readonly class ActivityLogContext
{
    public function __construct(
        public string $actorType,
        public ?int $actorId,
        public string $entityType,
        public string $entityId,
        public string $requestId,
        public int $retentionDays,
    ) {}

    public static function forActor(
        string $actorType,
        ?int $actorId,
        string $entityType,
        string $entityId,
        ?string $requestId = null,
        int $retentionDays = 360,
    ): self {
        return new self(
            actorType: $actorType,
            actorId: $actorId,
            entityType: $entityType,
            entityId: $entityId,
            requestId: $requestId ?? (string) Str::ulid(),
            retentionDays: $retentionDays,
        );
    }
}
