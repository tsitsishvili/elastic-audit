<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\DataTransferObjects;

use Illuminate\Support\Str;
use Tsitsishvili\ElasticAudit\Support\PackageConfig;
use Tsitsishvili\ElasticAudit\Support\RetentionDays;

final readonly class ProfileData
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{function: string, file: ?string, line: ?int, self_samples: int, total_samples: int}>  $hotFrames
     */
    public function __construct(
        public string $profileId,
        public string $timestamp,
        public string $traceId,
        public string $transactionId,
        public string $transactionName,
        public string $transactionType,
        public float $durationMs,
        public string $driver,
        public string $mode,
        public string $format,
        public ?float $sampleRateHz,
        public int $sampleCount,
        public bool $truncated,
        public array $payload,
        public array $hotFrames,
        public int $retentionDays,
        public AuditSource $source,
    ) {}

    public static function fromCapture(
        ApplicationMetricContext $context,
        CapturedProfile $capture,
        float $durationMs,
    ): self {
        return new self(
            profileId: (string) Str::ulid(),
            timestamp: $context->startedTimestamp,
            traceId: $context->traceId,
            transactionId: $context->transactionId,
            transactionName: $context->name,
            transactionType: $context->type,
            durationMs: max(0.0, $durationMs),
            driver: $capture->driver,
            mode: $capture->mode,
            format: $capture->format,
            sampleRateHz: $capture->sampleRateHz,
            sampleCount: max(0, $capture->sampleCount),
            truncated: $capture->truncated,
            payload: $capture->payload,
            hotFrames: $capture->hotFrames,
            retentionDays: RetentionDays::fromConfig(
                PackageConfig::get('elastic_audit_metrics.profiles.retention_days', 7),
            ),
            source: $context->source,
        );
    }
}
