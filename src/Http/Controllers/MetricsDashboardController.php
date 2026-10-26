<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;
use Tsitsishvili\ElasticAudit\Dashboard\ActivityDashboardQuery;
use Tsitsishvili\ElasticAudit\Dashboard\DashboardAccess;
use Tsitsishvili\ElasticAudit\Dashboard\HttpLogDashboardQuery;
use Tsitsishvili\ElasticAudit\Dashboard\MetricsDashboardQuery;
use Tsitsishvili\ElasticAudit\Dashboard\TraceTimeline;

final class MetricsDashboardController
{
    private const RANGES = [
        '24h' => ['label' => 'Last 24 hours', 'sub' => 'last 24h', 'since' => '-24 hours'],
        '7d'  => ['label' => 'Last 7 days', 'sub' => 'last 7d', 'since' => '-7 days'],
        '30d' => ['label' => 'Last 30 days', 'sub' => 'last 30d', 'since' => '-30 days'],
        '90d' => ['label' => 'Last 90 days', 'sub' => 'last 90d', 'since' => '-90 days'],
    ];

    private const INTERVALS = ['1h' => 'Per hour', '1d' => 'Per day'];

    private const PER_PAGE_OPTIONS = [25, 50, 100];

    /** Both queries clamp page size to 200; a trace with more rows from one source is truncated with a notice. */
    private const AUDIT_ROWS_PER_SOURCE = 200;

    /** How the function list can be ranked, mirroring what each column means. */
    private const SORTS = [
        MetricsDashboardQuery::SORT_TOTAL   => 'total time',
        MetricsDashboardQuery::SORT_SELF    => 'total self time',
        MetricsDashboardQuery::SORT_AVERAGE => 'average duration',
        MetricsDashboardQuery::SORT_P95     => 'p95 duration',
        MetricsDashboardQuery::SORT_CALLS   => 'call count',
    ];

    /** Sortable transaction columns, mapped from query value to the Elasticsearch field. */
    private const TRANSACTION_SORTS = [
        'time'     => '@timestamp',
        'name'     => 'name',
        'type'     => 'type',
        'outcome'  => 'outcome',
        'duration' => 'duration_ms',
        'spans'    => 'transaction.span_count',
    ];

    public function __construct(
        private readonly MetricsDashboardQuery $query,
    ) {}

    public function overview(Request $request): View
    {
        [$range, $interval, $filters] = $this->window($request);

        $error     = null;
        $data      = ['total' => 0, 'aggs' => []];
        $functions = [];

        try {
            $data      = $this->query->overview($filters);
            $functions = $this->query->functions($filters);
        } catch (Throwable $exception) {
            $error = $this->queryError($exception);
        }

        return view('elastic-audit::metrics.overview', compact('data', 'filters', 'range', 'interval', 'error', 'functions') + [
            'ranges'    => self::RANGES,
            'intervals' => self::INTERVALS,
            'timezone'  => $filters['timezone'],
        ]);
    }

    public function functions(Request $request): View
    {
        [$range, $interval, $filters] = $this->window($request);

        // Total time is the default rather than self time: self time arrives with
        // metrics schema v4, so ranking by it would show a page of zeroes until
        // an upgraded index has accumulated data.
        $sort  = (string) $request->query('sort', MetricsDashboardQuery::SORT_TOTAL);
        $sort  = isset(self::SORTS[$sort]) ? $sort : MetricsDashboardQuery::SORT_TOTAL;
        $error = null;
        $rows  = [];
        $name  = $request->query('name');

        if (is_string($name) && $name !== '') {
            $filters['name'] = $name;
        }

        try {
            $rows = $this->query->functionStatistics($filters, sort: $sort);
        } catch (Throwable $exception) {
            $error = $this->queryError($exception);
        }

        return view('elastic-audit::metrics.functions', compact('rows', 'filters', 'range', 'interval', 'error', 'sort') + [
            'ranges'    => self::RANGES,
            'intervals' => self::INTERVALS,
            'sorts'     => self::SORTS,
            'timezone'  => $filters['timezone'],
        ]);
    }

    public function transactions(Request $request): View
    {
        $filters = $this->filters($request);
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = (int) $request->query('per_page', config('elastic_audit_metrics.dashboard.per_page', 25));
        $perPage = in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : 25;
        $sort    = (string) $request->query('sort', 'time');
        $sort    = isset(self::TRANSACTION_SORTS[$sort]) ? $sort : 'time';
        $dir     = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $error   = null;
        $data    = ['hits' => [], 'total' => 0];
        $options = ['types' => [], 'services' => []];

        try {
            $data = $this->query->transactions(
                [...$filters, 'timezone' => $this->timezone()],
                $page,
                $perPage,
                self::TRANSACTION_SORTS[$sort],
                $dir,
            );
            $options = $this->query->filterOptions();
        } catch (Throwable $exception) {
            $error = $this->queryError($exception);
        }

        return view('elastic-audit::metrics.transactions', compact('data', 'filters', 'page', 'perPage', 'sort', 'dir', 'options', 'error') + [
            'timezone'       => $this->timezone(),
            'perPageOptions' => self::PER_PAGE_OPTIONS,
        ]);
    }

    public function trace(
        Request $request,
        string $traceId,
        HttpLogDashboardQuery $http,
        ActivityDashboardQuery $activity,
        DashboardAccess $access,
    ): View {
        abort_unless(preg_match('/^[a-f0-9]{32}$/', $traceId) === 1, 404);

        $sourceErrors = [];
        $truncated    = [];
        $auditHidden  = false;
        $metrics      = [];
        $audit        = [DashboardAccess::HTTP => [], DashboardAccess::ACTIVITY => []];

        try {
            $metrics = $this->query->trace($traceId);
        } catch (Throwable $exception) {
            $sourceErrors[DashboardAccess::METRICS] = $this->queryError($exception, ['source' => DashboardAccess::METRICS, 'trace_id' => $traceId]);
        }

        foreach ([DashboardAccess::HTTP => $http, DashboardAccess::ACTIVITY => $activity] as $source => $query) {
            if (! $access->enabled($source)) {
                continue;
            }

            // Never query a source the viewer cannot open on its own dashboard.
            if (! $access->allows($request, $source)) {
                $auditHidden = true;

                continue;
            }

            try {
                $result         = $query->search(['trace_id' => $traceId], 1, self::AUDIT_ROWS_PER_SOURCE, '@timestamp', 'asc');
                $audit[$source] = $result['hits'];

                if ($result['total'] > self::AUDIT_ROWS_PER_SOURCE) {
                    $truncated[$source] = $result['total'];
                }
            } catch (Throwable $exception) {
                $sourceErrors[$source] = $this->queryError($exception, ['source' => $source, 'trace_id' => $traceId]);
            }
        }

        $timeline = TraceTimeline::build($metrics, $audit[DashboardAccess::HTTP], $audit[DashboardAccess::ACTIVITY]);

        abort_if($timeline['rows'] === [] && $timeline['profiled'] === [] && $sourceErrors === [], 404);

        return view('elastic-audit::metrics.trace', compact('timeline', 'sourceErrors', 'auditHidden', 'truncated', 'traceId') + [
            'timezone' => $this->timezone(),
            'auditCap' => self::AUDIT_ROWS_PER_SOURCE,
        ]);
    }

    public function profile(string $profileId): View
    {
        $error   = null;
        $profile = null;

        try {
            $profile = $this->query->profile($profileId);
        } catch (Throwable $exception) {
            $error = $this->queryError($exception);
        }

        abort_if($profile === null && $error === null, 404);

        return view('elastic-audit::metrics.profile', compact('profile', 'error') + [
            'timezone' => $this->timezone(),
        ]);
    }

    /**
     * Resolve the shared range/interval/window selection.
     *
     * @return array{string, string, array<string, string>}
     */
    private function window(Request $request): array
    {
        $range    = (string) $request->query('range', '24h');
        $isCustom = $range === 'custom';
        $range    = ($isCustom || isset(self::RANGES[$range])) ? $range : '24h';

        $interval = (string) $request->query('interval', '');
        $interval = isset(self::INTERVALS[$interval]) ? $interval : ($range === '24h' ? '1h' : '1d');

        $filters = [
            'interval' => $interval,
            'timezone' => $this->timezone(),
        ];

        if ($isCustom) {
            foreach (['from', 'to'] as $key) {
                $value = $request->query($key);

                if (is_string($value) && $value !== '') {
                    $filters[$key] = $value;
                }
            }
        } else {
            $now             = Carbon::now();
            $filters['from'] = $now->copy()->modify(self::RANGES[$range]['since'])->toIso8601String();
            $filters['to']   = $now->toIso8601String();
        }

        return [$range, $interval, $filters];
    }

    private function timezone(): string
    {
        return (string) (config('app.timezone') ?: 'UTC');
    }

    /** @return array<string, string> */
    private function filters(Request $request): array
    {
        $filters = [];

        foreach (['name', 'type', 'outcome', 'service', 'min_duration', 'trace_id', 'from', 'to'] as $key) {
            $value = $request->query($key);

            if (is_string($value) && $value !== '') {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    /** @param array<string, string> $context */
    private function queryError(Throwable $exception, array $context = []): string
    {
        Log::error('Elastic Audit metrics dashboard query failed', $context + ['error' => $exception->getMessage()]);

        return 'Failed to query Elasticsearch. Check the application log for details.';
    }
}
