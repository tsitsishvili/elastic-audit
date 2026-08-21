<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;
use Tsitsishvili\ElasticAudit\Contracts\FunctionProfiler;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ApplicationMetricContext;
use Tsitsishvili\ElasticAudit\DataTransferObjects\AuditSource;
use Tsitsishvili\ElasticAudit\DataTransferObjects\MetricData;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ProfileData;
use Tsitsishvili\ElasticAudit\Jobs\LogMetricBatchJob;
use Tsitsishvili\ElasticAudit\Jobs\LogProfileJob;
use Tsitsishvili\ElasticAudit\Support\AuditSourceResolver;
use Tsitsishvili\ElasticAudit\Support\ExecutionContextId;
use Tsitsishvili\ElasticAudit\Support\PackageConfig;
use Tsitsishvili\ElasticAudit\Support\TraceContext;

final class MetricsRecorder
{
    /** @var array<string, array<string, ApplicationMetricContext>> */
    private array $contexts = [];

    /** @var array<string, list<string>> */
    private array $stacks = [];

    /** @var array<string, int> */
    private array $suppressions = [];

    /** @param array<string, mixed>|null $config */
    public function __construct(
        private readonly ?array $config = null,
        private readonly ?FunctionProfiler $profiler = null,
        private readonly ?AuditSourceResolver $sourceResolver = null,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->setting('enabled', false);
    }

    public function isSuppressed(): bool
    {
        return ! $this->enabled() || ($this->suppressions[$this->slot()] ?? 0) > 0;
    }

    public function suppress(): void
    {
        $slot                      = $this->slot();
        $this->suppressions[$slot] = ($this->suppressions[$slot] ?? 0) + 1;
    }

    public function resume(): void
    {
        $slot                      = $this->slot();
        $this->suppressions[$slot] = max(0, ($this->suppressions[$slot] ?? 0) - 1);

        if ($this->suppressions[$slot] === 0) {
            unset($this->suppressions[$slot]);
        }
    }

    public function categoryEnabled(string $category): bool
    {
        return $this->enabled() && (bool) $this->setting("capture.{$category}.enabled", true);
    }

    public function begin(
        string $category,
        string $type,
        string $name,
        ?AuditSource $source = null,
        bool $independentRoot = false,
        ?TraceContext $upstream = null,
    ): ?string {
        if ($this->isSuppressed() || ! $this->categoryEnabled($category)) {
            return null;
        }

        try {
            $slot    = $this->slot();
            $active  = $this->current($slot);
            $parent  = $independentRoot ? null : $active;
            $sampled = $parent !== null ? $parent->sampled : $this->sampled($category);

            if ($parent === null
                && $upstream?->sampled === false
                && (bool) $this->setting('trace.honor_incoming_sampled', true)) {
                $sampled = false;
            }

            $token   = (string) Str::ulid();
            $spanId  = MetricData::randomId(8);
            $traceId = $parent !== null
                ? $parent->traceId
                : ($upstream !== null && $upstream->traceId !== null
                    ? $upstream->traceId
                    : MetricData::randomId(16));
            $context = new ApplicationMetricContext(
                token: $token,
                category: $category,
                type: $type,
                name: $name,
                traceId: $traceId,
                spanId: $spanId,
                parentSpanId: $parent !== null ? $parent->spanId : $upstream?->spanId,
                transactionId: $spanId,
                startedAt: hrtime(true),
                startedTimestamp: Carbon::now()->toIso8601ZuluString(),
                sampled: $sampled,
                traceState: $parent !== null ? $parent->traceState : $upstream?->traceState,
                source: $source ?? $this->resolver()->resolve(),
            );

            // Native profilers are process-global. Execution-local trace stacks
            // prevent attribution leaks; the driver also rejects overlapping runs.
            if ($sampled && $active === null && $this->shouldProfile()) {
                $context->ownsProfiler = $this->profiler?->start() ?? false;
            }

            $this->contexts[$slot][$token] = $context;
            $this->stacks[$slot][]         = $token;

            return $token;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, bool|float|int|string|null>|null  $http
     * @param  array<string, bool|float|int|string|null>|null  $queue
     * @param  array<string, bool|float|int|string|null>|null  $console
     * @param  array<string, bool|float|int|string|null>|null  $scheduler
     */
    public function finish(
        ?string $token,
        string $name,
        string $outcome,
        ?AuditSource $source = null,
        ?array $http = null,
        ?array $queue = null,
        ?array $console = null,
        ?array $scheduler = null,
    ): void {
        if ($token === null) {
            return;
        }

        $slot    = $this->slot();
        $context = $this->contexts[$slot][$token] ?? null;

        if ($context === null) {
            return;
        }

        $profilerStopped = false;

        try {
            $context->name   = $name;
            $context->source = $source ?? $context->source;
            $durationMs      = max(0.0, (hrtime(true) - $context->startedAt) / 1_000_000);

            if ($context->ownsProfiler) {
                $capture         = $this->profiler?->stop();
                $profilerStopped = true;

                if ($capture !== null
                    && $context->sampled
                    && $durationMs >= $this->minimumDuration($context->category)) {
                    $profile            = ProfileData::fromCapture($context, $capture, $durationMs);
                    $context->profileId = $profile->profileId;
                    $this->flushProfile($profile);
                }
            }

            if ($context->sampled && $durationMs >= $this->minimumDuration($context->category)) {
                $spanCount = count(array_filter(
                    $context->spans,
                    static fn (MetricData $metric): bool => $metric->kind === MetricData::KIND_SPAN,
                ));
                $context->spans[] = MetricData::make(
                    type: $context->type,
                    name: $name,
                    outcome: $outcome,
                    durationMs: $durationMs,
                    traceId: $context->traceId,
                    spanId: $context->spanId,
                    parentSpanId: $context->parentSpanId,
                    source: $context->source,
                    http: $http,
                    queue: $queue,
                    console: $console,
                    kind: MetricData::KIND_TRANSACTION,
                    transactionId: $context->transactionId,
                    sampled: true,
                    spanCount: $spanCount,
                    profileId: $context->profileId,
                    timestamp: $context->startedTimestamp,
                    scheduler: $scheduler,
                );
            }
        } catch (Throwable) {
            // Metrics and profiler failures cannot change application behavior.
        } finally {
            if ($context->ownsProfiler && ! $profilerStopped) {
                try {
                    $this->profiler?->stop();
                } catch (Throwable) {
                    // A custom profiler implementation must not affect the application.
                }
            }

            unset($this->contexts[$slot][$token]);
            $this->stacks[$slot] = array_values(array_filter(
                $this->stacks[$slot] ?? [],
                static fn (string $candidate): bool => $candidate !== $token,
            ));

            if (($this->contexts[$slot] ?? []) === []) {
                unset($this->contexts[$slot]);
            }

            if ($this->stacks[$slot] === []) {
                unset($this->stacks[$slot]);
            }
        }

        try {
            $parent = $this->current($slot);

            if ($parent !== null && $parent->traceId === $context->traceId) {
                $parent->spans = array_merge($parent->spans, $context->spans);

                return;
            }

            $this->flush($context->spans);
        } catch (Throwable) {
            // Metrics are best-effort.
        }
    }

    /**
     * @param  array<string, bool|float|int|string|null>|null  $http
     * @param  array<string, bool|float|int|string|null>|null  $db
     * @param  array<string, bool|float|int|string|null>|null  $queue
     * @param  array<string, bool|float|int|string|null>|null  $redis
     * @param  array<string, bool|float|int|string|null>|null  $cache
     * @param  array<string, bool|float|int|string|null>|null  $mail
     * @param  array<string, bool|float|int|string|null>|null  $notification
     */
    public function record(
        string $category,
        string $type,
        string $name,
        string $outcome,
        float $durationMs,
        ?AuditSource $source = null,
        ?array $http = null,
        ?array $db = null,
        ?array $queue = null,
        ?array $redis = null,
        ?array $cache = null,
        ?array $mail = null,
        ?array $notification = null,
        ?string $spanId = null,
    ): void {
        if ($this->isSuppressed()
            || ! $this->categoryEnabled($category)
            || $durationMs < $this->minimumDuration($category)) {
            return;
        }

        try {
            $context = $this->current();

            if (($context !== null && ! $context->sampled)
                || ($context === null && ! $this->sampled($category))
                || ($context !== null && ! $this->sampled($category))) {
                return;
            }

            $metric = MetricData::make(
                type: $type,
                name: $name,
                outcome: $outcome,
                durationMs: $durationMs,
                traceId: $context?->traceId,
                spanId: $spanId,
                parentSpanId: $context?->spanId,
                source: $source ?? ($context !== null ? $context->source : $this->resolver()->resolve()),
                http: $http,
                db: $db,
                queue: $queue,
                transactionId: $context?->transactionId,
                timestamp: Carbon::now()->subMicroseconds((int) round(max(0.0, $durationMs) * 1000))->toIso8601ZuluString(),
                redis: $redis,
                cache: $cache,
                mail: $mail,
                notification: $notification,
            );

            if ($context !== null) {
                $context->spans[] = $metric;

                return;
            }

            $this->flush([$metric]);
        } catch (Throwable) {
            // Metrics are best-effort.
        }
    }

    public function propagationContext(): ?TraceContext
    {
        if ($this->isSuppressed()) {
            return null;
        }

        $context = $this->current();

        return $context === null
            ? null
            : new TraceContext(
                traceId: $context->traceId,
                spanId: $context->spanId,
                sampled: $context->sampled,
                traceState: $context->traceState,
            );
    }

    /** @param list<MetricData> $metrics */
    private function flush(array $metrics): void
    {
        if ($metrics === []) {
            return;
        }

        $batchSize = max(1, (int) $this->setting('batch_size', 100));

        foreach (array_chunk($metrics, $batchSize) as $batch) {
            try {
                $this->suppress();
                LogMetricBatchJob::dispatch($batch);
            } catch (Throwable) {
                // Metrics are observational and must not alter application behavior.
            } finally {
                $this->resume();
            }
        }
    }

    private function flushProfile(ProfileData $profile): void
    {
        try {
            $this->suppress();
            LogProfileJob::dispatch($profile);
        } catch (Throwable) {
            // Profiles are observational and must not alter application behavior.
        } finally {
            $this->resume();
        }
    }

    private function current(?string $slot = null): ?ApplicationMetricContext
    {
        $slot ??= $this->slot();
        $stack = $this->stacks[$slot] ?? [];
        $token = end($stack);

        return is_string($token) ? ($this->contexts[$slot][$token] ?? null) : null;
    }

    private function shouldProfile(): bool
    {
        return (bool) $this->setting('profiles.enabled', true)
            && ($this->profiler?->available() ?? false)
            && $this->sampled('profiles');
    }

    private function sampled(string $category): bool
    {
        $key  = $category === 'profiles' ? 'profiles.sample_rate' : "capture.{$category}.sample_rate";
        $rate = max(0.0, min(1.0, (float) $this->setting($key, 1.0)));

        return $rate >= 1.0 || ($rate > 0.0 && lcg_value() < $rate);
    }

    private function minimumDuration(string $category): float
    {
        return max(0.0, (float) $this->setting("capture.{$category}.min_duration_ms", 0));
    }

    private function setting(string $key, mixed $default): mixed
    {
        if ($this->config === null) {
            return PackageConfig::get("elastic_audit_metrics.{$key}", $default);
        }

        $value = $this->config;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    private function resolver(): AuditSourceResolver
    {
        return $this->sourceResolver ?? AuditSourceResolver::fromContainer();
    }

    private function slot(): string
    {
        return ExecutionContextId::current();
    }
}
