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
        /**
         * The transaction this context belongs to. A root owns its own id; a
         * measured block nested inside one inherits it, and null means the block
         * ran outside any transaction.
         */
        public readonly ?string $transactionId,
        public readonly int $startedAt,
        public readonly string $startedTimestamp,
        public readonly bool $sampled,
        public readonly ?string $traceState,
        public AuditSource $source,
        /** Whether finishing emits a transaction document or a span document. */
        public readonly string $kind = MetricData::KIND_TRANSACTION,
    ) {}
}
