<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\DataTransferObjects;

use Illuminate\Support\Str;
use Tsitsishvili\ElasticAudit\Contracts\EntityTypeContract;

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
        EntityTypeContract $entityType,
        string $entityId,
        ?string $requestId = null,
        int $retentionDays = 360,
    ): self {
        return new self(
            actorType: $actorType,
            actorId: $actorId,
            entityType: $entityType->getValue(),
            entityId: $entityId,
            requestId: $requestId ?? (string) Str::ulid(),
            retentionDays: $retentionDays,
        );
    }
}
