<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\DataTransferObjects;

use Illuminate\Support\Str;
use Tsitsishvili\ElasticAudit\Contracts\EntityTypeContract;

final readonly class HttpLogContext
{
    public function __construct(
        public EntityTypeContract $entityType,
        public string $entityId,
        public ?string $externalId,
        public ?int $userId,
        public string $requestId,
        public int $retentionDays,
    ) {}

    public static function forEntity(
        EntityTypeContract $entityType,
        string $entityId,
        ?string $externalId = null,
        ?int $userId = null,
        int $retentionDays = 360,
        ?string $requestId = null,
    ): self {
        return new self(
            entityType: $entityType,
            entityId: $entityId,
            externalId: $externalId,
            userId: $userId,
            requestId: $requestId ?? (string) Str::ulid(),
            retentionDays: $retentionDays,
        );
    }
}
