<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\DataTransferObjects;

use Illuminate\Support\Str;

final readonly class ActivityLogData
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        public string $eventId,
        public string $timestamp,
        public string $requestId,
        public string $actorType,
        public ?int $actorId,
        public string $action,
        public string $entityType,
        public string $entityId,
        public array $changes,
        public array $metadata,
        public bool $success,
        public int $retentionDays,
        public ?string $errorClass,
        public ?string $errorMessage,
    ) {}

    public static function make(
        string $action,
        ActivityLogContext $context,
        array $changes = [],
        array $metadata = [],
        bool $success = true,
        ?string $errorClass = null,
        ?string $errorMessage = null,
    ): self {
        return new self(
            eventId: (string) Str::ulid(),
            timestamp: now()->toIso8601ZuluString(),
            requestId: $context->requestId,
            actorType: $context->actorType,
            actorId: $context->actorId,
            action: $action,
            entityType: $context->entityType,
            entityId: $context->entityId,
            changes: $changes,
            metadata: $metadata,
            success: $success,
            retentionDays: $context->retentionDays,
            errorClass: $errorClass,
            errorMessage: $errorMessage,
        );
    }
}
