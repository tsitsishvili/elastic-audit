<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\DataTransferObjects;

final class ApplicationMetricContext
{
    /** @var list<MetricData> */
    public array $spans = [];

    public bool $ownsProfiler = false;

    public ?string $profileId = null;

    public function __construct(
        public readonly string $token,
        public readonly string $category,
        public readonly string $type,
        public string $name,
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly ?string $parentSpanId,
        public readonly string $transactionId,
        public readonly int $startedAt,
        public readonly string $startedTimestamp,
        public readonly bool $sampled,
        public readonly ?string $traceState,
        public AuditSource $source,
    ) {}
}
