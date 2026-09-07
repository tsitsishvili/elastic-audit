<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;
use Tsitsishvili\ElasticAudit\Contracts\FunctionProfiler;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ApplicationMetricContext;
use Tsitsishvili\ElasticAudit\DataTransferObjects\AuditSource;
use Tsitsishvili\ElasticAudit\DataTransferObjects\CapturedProfile;
use Tsitsishvili\ElasticAudit\DataTransferObjects\MetricData;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ProfileData;
use Tsitsishvili\ElasticAudit\Jobs\LogMetricBatchJob;
use Tsitsishvili\ElasticAudit\Jobs\LogProfileJob;
use Tsitsishvili\ElasticAudit\Support\ApplicationFrames;
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

    /**
     * Set while a long-running daemon command (queue:work, schedule:work,
     * octane:start, ...) owns the process. Such a daemon performs its own
     * bookkeeping between units of work — queue polling, cache reads, restart
     * checks — and on the database queue/cache drivers that bookkeeping is
     * itself caused by delivering metrics. Recording it would make the
     * telemetry self-feeding, so context-less spans are dropped for the
     * daemon's lifetime. Work inside a job or scheduled task still runs under
     * its own root transaction and is captured normally.
     */
    private bool $daemonProcess = false;

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

    public function enterDaemonProcess(): void
    {
        $this->daemonProcess = true;
    }

    public function leaveDaemonProcess(): void
    {
        $this->daemonProcess = false;
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
        string $kind = MetricData::KIND_TRANSACTION,
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
                // A root is its own transaction; a measured block belongs to the
                // transaction it runs inside, and to none when there is none.
                transactionId: $kind === MetricData::KIND_TRANSACTION
                    ? $spanId
                    : $parent?->transactionId,
                kind: $kind,
                startedAt: hrtime(true),
                startedTimestamp: Carbon::now()->toIso8601ZuluString('millisecond'),
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
            $keepRoot        = $context->sampled && $durationMs >= $this->minimumDuration($context->category);

            if ($context->ownsProfiler) {
                $capture         = $this->profiler?->stop();
                $profilerStopped = true;

                if ($capture !== null && $keepRoot) {
                    $profile            = ProfileData::fromCapture($context, $capture, $durationMs);
                    $context->profileId = $profile->profileId;
                    $this->flushProfile($profile);
                    $this->recordProfiledFunctions($context, $capture, $durationMs);
                }
            }

            // A root below its own threshold is never indexed. Its spans carry
            // that root's transaction id, so keeping them would leave documents
            // pointing at a transaction that does not exist — invisible to the
            // dashboards, which only list transactions, but still stored.
            if (! $keepRoot) {
                $context->spans = [];
            }

            if ($keepRoot) {
                $isTransaction = $context->kind === MetricData::KIND_TRANSACTION;
                $spanCount     = $isTransaction ? count(array_filter(
                    $context->spans,
                    static fn (MetricData $metric): bool => $metric->kind === MetricData::KIND_SPAN,
                )) : 0;
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
                    kind: $context->kind,
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
     * Time a callable and record it as a span inside the current trace.
     *
     * The callback's return value is passed through and its exceptions are
     * rethrown unchanged, so wrapping a call can never alter behaviour. Nested
     * calls nest as spans, and spans opened outside any transaction are still
     * recorded with their own trace.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function measure(string $name, Closure $callback, string $type = MetricData::TYPE_APP_FUNCTION): mixed
    {
        $token = $this->beginMeasure($name, $type);

        if ($token === null) {
            return $callback();
        }

        try {
            $result = $callback();
        } catch (Throwable $exception) {
            $this->endMeasure($token, $name, MetricData::OUTCOME_FAILURE);

            throw $exception;
        }

        $this->endMeasure($token, $name, MetricData::OUTCOME_SUCCESS);

        return $result;
    }

    /**
     * Open a measured span by hand. Pair with endMeasure(); prefer measure()
     * unless the start and end genuinely cannot share a scope.
     */
    public function beginMeasure(string $name, string $type = MetricData::TYPE_APP_FUNCTION): ?string
    {
        return $this->begin(
            category: 'functions',
            type: $type,
            name: $name,
            kind: MetricData::KIND_SPAN,
        );
    }

    public function endMeasure(?string $token, string $name, string $outcome = MetricData::OUTCOME_SUCCESS): void
    {
        $this->finish(token: $token, name: $name, outcome: $outcome);
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

            // A span with no root inside a daemon is the daemon's own loop.
            if ($context === null && $this->daemonProcess) {
                return;
            }

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
                timestamp: Carbon::now()->subMicroseconds((int) round(max(0.0, $durationMs) * 1000))->toIso8601ZuluString('millisecond'),
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

    /**
     * Turn the application frames of a captured profile into function spans.
     *
     * This is what makes function timing automatic: the profiler already knows
     * every method that ran and what it cost, so the application does not have
     * to wrap anything by hand. Durations from a sampling driver are estimates,
     * which is why these carry their own type and never claim a position on the
     * trace timeline — a sample says how much time a frame accounted for, not
     * when it started.
     */
    private function recordProfiledFunctions(
        ApplicationMetricContext $context,
        CapturedProfile $capture,
        float $durationMs,
    ): void {
        if (! (bool) $this->setting('capture.functions.enabled', true)
            || ! (bool) $this->setting('capture.functions.automatic', false)
            || $capture->functionTimings === []) {
            return;
        }

        $prefixes = ApplicationFrames::prefixes();

        if ($prefixes === []) {
            return;
        }

        $limit   = max(1, (int) $this->setting('capture.functions.automatic_limit', 20));
        $minimum = max(0.0, (float) $this->setting('capture.functions.automatic_min_duration_ms', 1.0));
        $spans   = [];

        foreach ($capture->functionTimings as $timing) {
            if (count($spans) >= $limit) {
                break;
            }

            $function = $timing['function'];
            $totalMs  = $timing['total_ms'];
            $selfMs   = $timing['self_ms'];

            if ($function === ''
                || $totalMs < $minimum
                || ! ApplicationFrames::isApplicationFrame($function, $prefixes)) {
                continue;
            }

            $spans[] = MetricData::make(
                type: MetricData::TYPE_APP_FUNCTION_PROFILED,
                name: $function,
                outcome: MetricData::OUTCOME_SUCCESS,
                // A frame cannot have cost more than the root it ran inside.
                durationMs: min($totalMs, $durationMs),
                traceId: $context->traceId,
                parentSpanId: $context->spanId,
                source: $context->source,
                transactionId: $context->transactionId,
                timestamp: $context->startedTimestamp,
                code: ['self_ms' => round(min($selfMs, $durationMs), 3)],
            );
        }

        if ($spans !== []) {
            $context->spans = array_merge($context->spans, $spans);
        }
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
