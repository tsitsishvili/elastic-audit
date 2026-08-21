<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\DataTransferObjects;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tsitsishvili\ElasticAudit\Support\AuditSourceResolver;
use Tsitsishvili\ElasticAudit\Support\PackageConfig;
use Tsitsishvili\ElasticAudit\Support\RetentionDays;

final readonly class MetricData
{
    public const SCHEMA_VERSION = 3;

    public const KIND_TRANSACTION = 'transaction';

    public const KIND_SPAN = 'span';

    public const TYPE_HTTP_SERVER = 'http.server';

    public const TYPE_DB_QUERY = 'db.query';

    public const TYPE_QUEUE_JOB = 'queue.job';

    public const TYPE_QUEUE_PUBLISH = 'queue.publish';

    public const TYPE_CONSOLE_COMMAND = 'console.command';

    public const TYPE_SCHEDULED_TASK = 'scheduled.task';

    public const TYPE_HTTP_CLIENT = 'http.client';

    public const TYPE_REDIS_COMMAND = 'redis.command';

    public const TYPE_CACHE_OPERATION = 'cache.operation';

    public const TYPE_MAIL_SEND = 'mail.send';

    public const TYPE_NOTIFICATION_SEND = 'notification.send';

    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_FAILURE = 'failure';

    public const OUTCOME_OBSERVED = 'observed';

    /**
     * Detail arrays have fixed fields and are serialized into strict mappings.
     * They intentionally cannot carry arbitrary payloads or application values.
     *
     * @param  array<string, bool|float|int|string|null>|null  $http
     * @param  array<string, bool|float|int|string|null>|null  $db
     * @param  array<string, bool|float|int|string|null>|null  $queue
     * @param  array<string, bool|float|int|string|null>|null  $console
     * @param  array<string, bool|float|int|string|null>|null  $redis
     * @param  array<string, bool|float|int|string|null>|null  $cache
     * @param  array<string, bool|float|int|string|null>|null  $mail
     * @param  array<string, bool|float|int|string|null>|null  $notification
     * @param  array<string, bool|float|int|string|null>|null  $scheduler
     */
    public function __construct(
        public string $eventId,
        public string $timestamp,
        public string $traceId,
        public string $spanId,
        public ?string $parentSpanId,
        public ?string $transactionId,
        public string $kind,
        public string $type,
        public string $name,
        public string $outcome,
        public float $durationMs,
        public bool $sampled,
        public int $spanCount,
        public ?string $profileId,
        public int $retentionDays,
        public ?AuditSource $source = null,
        public ?array $http = null,
        public ?array $db = null,
        public ?array $queue = null,
        public ?array $console = null,
        public ?array $redis = null,
        public ?array $cache = null,
        public ?array $mail = null,
        public ?array $notification = null,
        public ?array $scheduler = null,
    ) {}

    /**
     * @param  array<string, bool|float|int|string|null>|null  $http
     * @param  array<string, bool|float|int|string|null>|null  $db
     * @param  array<string, bool|float|int|string|null>|null  $queue
     * @param  array<string, bool|float|int|string|null>|null  $console
     * @param  array<string, bool|float|int|string|null>|null  $redis
     * @param  array<string, bool|float|int|string|null>|null  $cache
     * @param  array<string, bool|float|int|string|null>|null  $mail
     * @param  array<string, bool|float|int|string|null>|null  $notification
     * @param  array<string, bool|float|int|string|null>|null  $scheduler
     */
    public static function make(
        string $type,
        string $name,
        string $outcome,
        float $durationMs,
        ?string $traceId = null,
        ?string $spanId = null,
        ?string $parentSpanId = null,
        ?AuditSource $source = null,
        ?array $http = null,
        ?array $db = null,
        ?array $queue = null,
        ?array $console = null,
        string $kind = self::KIND_SPAN,
        ?string $transactionId = null,
        bool $sampled = true,
        int $spanCount = 0,
        ?string $profileId = null,
        ?string $timestamp = null,
        ?array $redis = null,
        ?array $cache = null,
        ?array $mail = null,
        ?array $notification = null,
        ?array $scheduler = null,
    ): self {
        $retentionDays = RetentionDays::fromConfig(PackageConfig::get('elastic_audit_metrics.retention_days', 30));
        $spanId ??= self::randomId(8);
        $kind = $kind === self::KIND_TRANSACTION ? self::KIND_TRANSACTION : self::KIND_SPAN;

        return new self(
            eventId: (string) Str::ulid(),
            timestamp: $timestamp ?? Carbon::now()->toIso8601ZuluString(),
            traceId: $traceId ?? self::randomId(16),
            spanId: $spanId,
            parentSpanId: $parentSpanId,
            transactionId: $transactionId ?? ($kind === self::KIND_TRANSACTION ? $spanId : null),
            kind: $kind,
            type: $type,
            name: $name,
            outcome: $outcome,
            durationMs: max(0.0, $durationMs),
            sampled: $sampled,
            spanCount: max(0, $spanCount),
            profileId: $profileId,
            retentionDays: $retentionDays,
            source: $source ?? AuditSourceResolver::fromContainer()->resolve(),
            http: $http,
            db: $db,
            queue: $queue,
            console: $console,
            redis: $redis,
            cache: $cache,
            mail: $mail,
            notification: $notification,
            scheduler: $scheduler,
        );
    }

    public static function randomId(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
