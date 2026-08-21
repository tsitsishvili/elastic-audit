<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Support;

use Tsitsishvili\ElasticAudit\Jobs\LogActivityBatchJob;
use Tsitsishvili\ElasticAudit\Jobs\LogActivityJob;
use Tsitsishvili\ElasticAudit\Jobs\LogHttpRequestBatchJob;
use Tsitsishvili\ElasticAudit\Jobs\LogHttpRequestJob;
use Tsitsishvili\ElasticAudit\Jobs\LogMetricBatchJob;
use Tsitsishvili\ElasticAudit\Jobs\LogProfileJob;

/**
 * Decides what application performance monitoring must never observe.
 *
 * Two kinds of rule live here. The package's own delivery jobs and dashboards
 * are excluded unconditionally: measuring them describes the observability
 * pipeline rather than the application, and on the database queue and cache
 * drivers a job that records its own delivery makes the metrics queue feed
 * itself. Applications add their own patterns on top through configuration.
 */
final class MetricsExclusions
{
    /**
     * Queue jobs this package dispatches to deliver audit and telemetry
     * documents.
     *
     * @var list<class-string>
     */
    private const OWN_JOBS = [
        LogActivityJob::class,
        LogActivityBatchJob::class,
        LogHttpRequestJob::class,
        LogHttpRequestBatchJob::class,
        LogMetricBatchJob::class,
        LogProfileJob::class,
    ];

    /**
     * Build a dashboard route prefix from an optional shared group segment and
     * the dashboard's own subpath, tolerating empty/slash-padded values.
     *
     * @param  array<string, mixed>  $dashboard
     */
    public static function dashboardPrefix(array $dashboard, string $defaultPath): string
    {
        $prefix = trim((string) ($dashboard['prefix'] ?? ''), '/');
        $path   = trim((string) ($dashboard['path'] ?? $defaultPath), '/');

        return trim($prefix.'/'.$path, '/');
    }

    /**
     * Whether a queue job must not produce a transaction or a publish span.
     */
    public function job(string $class): bool
    {
        if (in_array($class, self::OWN_JOBS, true)) {
            return true;
        }

        return $this->matchesAny($class, 'elastic_audit_metrics.capture.jobs.exclude');
    }

    /**
     * Whether an inbound request path must not produce a transaction.
     *
     * The path is matched without its leading slash, so a configured pattern
     * looks like `health` or `admin/*` rather than `/admin/*`.
     */
    public function path(string $path): bool
    {
        $path = trim($path, '/');

        foreach ($this->ownPaths() as $pattern) {
            if (fnmatch($pattern, $path, FNM_NOESCAPE)) {
                return true;
            }
        }

        return $this->matchesAny($path, 'elastic_audit_metrics.capture.http.exclude_paths');
    }

    /**
     * Paths served by this package: the asset route and every enabled
     * dashboard, whatever prefix the application configured them under.
     *
     * @return list<string>
     */
    private function ownPaths(): array
    {
        $patterns = ['vendor/elastic-audit', 'vendor/elastic-audit/*'];

        foreach ([
            ['http_logs.dashboard', 'http-logs'],
            ['activity_logs.dashboard', 'activity'],
            ['elastic_audit_metrics.dashboard', 'metrics'],
        ] as [$configKey, $defaultPath]) {
            $prefix = self::dashboardPrefix((array) config($configKey, []), $defaultPath);

            if ($prefix === '') {
                continue;
            }

            $patterns[] = $prefix;
            $patterns[] = $prefix.'/*';
        }

        return $patterns;
    }

    /**
     * FNM_NOESCAPE keeps backslashes literal. Without it fnmatch() reads the
     * separators in a namespaced job class as escape sequences, so a pattern
     * like 'App\\Jobs\\Noisy*' would never match anything.
     */
    private function matchesAny(string $subject, string $configKey): bool
    {
        foreach ((array) config($configKey, []) as $pattern) {
            if (is_string($pattern) && $pattern !== '' && fnmatch($pattern, $subject, FNM_NOESCAPE)) {
                return true;
            }
        }

        return false;
    }
}
