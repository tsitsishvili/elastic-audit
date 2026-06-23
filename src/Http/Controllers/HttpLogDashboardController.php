<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;
use Tsitsishvili\ElasticAudit\Dashboard\HttpLogDashboardQuery;

class HttpLogDashboardController
{
    /** Selectable time windows for the overview, keyed by query value. */
    private const RANGES = [
        '24h' => ['label' => 'Last 24 hours', 'sub' => 'last 24h', 'since' => '-24 hours'],
        '7d'  => ['label' => 'Last 7 days',   'sub' => 'last 7d',  'since' => '-7 days'],
        '30d' => ['label' => 'Last 30 days',  'sub' => 'last 30d', 'since' => '-30 days'],
        '90d' => ['label' => 'Last 90 days',  'sub' => 'last 90d', 'since' => '-90 days'],
    ];

    /** Selectable histogram bucket sizes for the throughput chart. */
    private const INTERVALS = ['1h' => 'Per hour', '1d' => 'Per day'];

    /** Sortable log columns, mapped from query value to the Elasticsearch field. */
    private const SORTS = ['time' => '@timestamp', 'latency' => 'http.latency_ms'];

    /** Selectable page sizes for the log list. */
    private const PER_PAGE_OPTIONS = [25, 50, 100];

    public function __construct(
        private readonly HttpLogDashboardQuery $query,
    ) {}

    /**
     * Metrics overview — the dashboard landing page.
     */
    public function overview(Request $request): View
    {
        $range    = (string) $request->query('range', '24h');
        $isCustom = $range === 'custom';
        $range    = ($isCustom || isset(self::RANGES[$range])) ? $range : '24h';

        $interval = (string) $request->query('interval', '');
        $interval = isset(self::INTERVALS[$interval]) ? $interval : ($range === '24h' ? '1h' : '1d');

        // Presets compute a rolling window; "custom" keeps the explicit from/to
        // already pulled off the query string by filters() (may be empty = all time).
        $filters = $this->filters($request);
        $filters['interval'] = $interval;
        $filters['timezone'] = (string) (config('app.timezone') ?: 'UTC');

        if (! $isCustom) {
            $now = Carbon::now();
            $filters['from'] = $now->copy()->modify(self::RANGES[$range]['since'])->toIso8601String();
            $filters['to']   = $now->toIso8601String();
        }

        $error   = null;
        $metrics = ['total' => 0, 'aggs' => []];

        try {
            $metrics = $this->query->metrics($filters);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return view('elastic-audit::dashboard.overview', [
            'filters'   => $filters,
            'metrics'   => $metrics,
            'timezone'  => $filters['timezone'],
            'error'     => $error,
            'range'     => $range,
            'interval'  => $interval,
            'ranges'    => self::RANGES,
            'intervals' => self::INTERVALS,
        ]);
    }

    /**
     * Paginated, filterable log list.
     */
    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $page    = max(1, (int) $request->query('page', 1));

        $sort = (string) $request->query('sort', 'time');
        $sort = isset(self::SORTS[$sort]) ? $sort : 'time';
        $dir  = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $defaultPerPage = (int) config('http_logs.dashboard.per_page', 25);
        $perPage        = (int) $request->query('per_page', $defaultPerPage);
        $perPage        = in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : $defaultPerPage;

        $timezone = (string) (config('app.timezone') ?: 'UTC');

        $error   = null;
        $logs    = [];
        $total   = 0;
        $options = ['providers' => [], 'event_types' => []];

        try {
            // Pass the timezone to the query only (so zone-less from/to are read in app
            // time); keep it out of $filters, which drives the view's chips and links.
            $result  = $this->query->search([...$filters, 'timezone' => $timezone], $page, $perPage, self::SORTS[$sort], $dir);
            $logs    = $result['hits'];
            $total   = $result['total'];
            $options = $this->query->filterOptions();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return view('elastic-audit::dashboard.logs.index', [
            'filters'        => $filters,
            'logs'           => $logs,
            'total'          => $total,
            'page'           => $page,
            'perPage'        => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'sort'           => $sort,
            'dir'            => $dir,
            'timezone'       => $timezone,
            'options'        => $options,
            'error'          => $error,
        ]);
    }

    /**
     * Single log detail.
     */
    public function show(Request $request, string $eventId): View
    {
        $timezone = (string) (config('app.timezone') ?: 'UTC');

        try {
            $log = $this->query->find($eventId);
        } catch (Throwable $e) {
            return view('elastic-audit::dashboard.logs.show', [
                'log'      => null,
                'timezone' => $timezone,
                'error'    => $e->getMessage(),
            ]);
        }

        abort_if($log === null, 404);

        return view('elastic-audit::dashboard.logs.show', [
            'log'      => $log,
            'timezone' => $timezone,
            'error'    => null,
        ]);
    }

    /**
     * Extract recognized filter values from the request query string.
     *
     * @return array<string, string>
     */
    private function filters(Request $request): array
    {
        $keys = [
            'provider', 'event_type', 'direction', 'status_class',
            'success', 'timeout', 'entity_id', 'request_id', 'external_id', 'from', 'to',
        ];

        $filters = [];

        foreach ($keys as $key) {
            $value = $request->query($key);

            if ($value !== null && $value !== '') {
                $filters[$key] = (string) $value;
            }
        }

        return $filters;
    }
}
