<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Dashboard;

use DateTimeImmutable;
use Exception;
use Tsitsishvili\ElasticAudit\DataTransferObjects\MetricData;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;

final class MetricsDashboardQuery
{
    public const SORT_SELF = 'self';

    public const SORT_TOTAL = 'total';

    public const SORT_AVERAGE = 'average';

    public const SORT_P95 = 'p95';

    public const SORT_CALLS = 'calls';

    private const MAX_PER_PAGE = 200;

    /** Aggregation each sort orders the terms bucket by. */
    private const SORT_ORDERS = [
        self::SORT_SELF    => ['self_duration' => 'desc'],
        self::SORT_TOTAL   => ['total_duration' => 'desc'],
        self::SORT_AVERAGE => ['avg_duration' => 'desc'],
        self::SORT_P95     => ['p95.95' => 'desc'],
        self::SORT_CALLS   => ['_count' => 'desc'],
    ];

    /**
     * Elasticsearch refuses a search where `from + size` exceeds
     * `index.max_result_window` (10000 by default). Deep pages are clamped to
     * the last reachable one so browsing far into a large result set stops
     * paging instead of raising a query error.
     */
    private const MAX_RESULT_WINDOW = 10000;

    public function __construct(
        private readonly LogElasticsearchClientInterface $client,
        private readonly string $metricsAlias,
        private readonly string $profilesAlias,
    ) {}

    /**
     * @param  array<string, string>  $filters
     * @return array{total: int, aggs: array<string, mixed>}
     */
    public function overview(array $filters): array
    {
        $histogram = [
            'field'             => '@timestamp',
            'calendar_interval' => $filters['interval'] ?? '1h',
            'min_doc_count'     => 0,
            'time_zone'         => $filters['timezone'] ?? 'UTC',
        ];

        if (isset($filters['from'], $filters['to'])) {
            $histogram['extended_bounds'] = ['min' => $filters['from'], 'max' => $filters['to']];
        }

        $result = $this->client->search([
            'index' => $this->metricsAlias,
            'body'  => [
                'size'             => 0,
                'track_total_hits' => true,
                'query'            => ['bool' => ['filter' => [
                    ['term' => ['kind' => 'transaction']],
                    ...$this->filters($filters),
                ]]],
                'aggs' => [
                    'duration' => [
                        'avg' => ['field' => 'duration_ms'],
                    ],
                    'latency' => [
                        'percentiles' => ['field' => 'duration_ms', 'percents' => [50, 95, 99]],
                    ],
                    'failures' => [
                        'filter' => ['term' => ['outcome' => 'failure']],
                    ],
                    'profiled' => [
                        'filter' => ['exists' => ['field' => 'transaction.profile_id']],
                    ],
                    'by_type' => [
                        'terms' => ['field' => 'type', 'size' => 20],
                    ],
                    'slowest' => [
                        'terms' => ['field' => 'name', 'size' => 10, 'order' => ['avg_duration' => 'desc']],
                        'aggs'  => ['avg_duration' => ['avg' => ['field' => 'duration_ms']]],
                    ],
                    'throughput' => [
                        'date_histogram' => $histogram,
                        'aggs'           => [
                            'failures' => ['filter' => ['term' => ['outcome' => 'failure']]],
                            'p95'      => ['percentiles' => ['field' => 'duration_ms', 'percents' => [95]]],
                        ],
                    ],
                ],
            ],
        ]);

        return [
            'total' => (int) data_get($result, 'hits.total.value', 0),
            'aggs'  => is_array($result['aggregations'] ?? null) ? $result['aggregations'] : [],
        ];
    }

    /**
     * Slowest explicitly measured application code in the window.
     *
     * These are spans rather than transactions, so they need their own search;
     * the overview aggregation only looks at roots.
     *
     * @param  array<string, string>  $filters
     * @return list<array{key: string, count: int, avg_ms: float, max_ms: float, total_ms: float, exact: bool}>
     */
    public function functions(array $filters, int $size = 10): array
    {
        $result = $this->client->search([
            'index' => $this->metricsAlias,
            'body'  => [
                'size'  => 0,
                'query' => ['bool' => ['filter' => [
                    ['term' => ['kind' => 'span']],
                    ['terms' => ['type' => [MetricData::TYPE_APP_FUNCTION, MetricData::TYPE_APP_FUNCTION_PROFILED]]],
                    ...$this->filters($filters),
                ]]],
                'aggs' => [
                    'functions' => [
                        'terms' => ['field' => 'name', 'size' => $size, 'order' => ['total_duration' => 'desc']],
                        'aggs'  => [
                            'avg_duration'   => ['avg' => ['field' => 'duration_ms']],
                            'max_duration'   => ['max' => ['field' => 'duration_ms']],
                            'total_duration' => ['sum' => ['field' => 'duration_ms']],
                            'sources'        => ['terms' => ['field' => 'type', 'size' => 2]],
                        ],
                    ],
                ],
            ],
        ]);

        return array_map(static function (array $bucket): array {
            $types = array_column($bucket['sources']['buckets'] ?? [], 'key');

            return [
                'key'      => (string) ($bucket['key'] ?? ''),
                'count'    => (int) ($bucket['doc_count'] ?? 0),
                'avg_ms'   => (float) ($bucket['avg_duration']['value'] ?? 0),
                'max_ms'   => (float) ($bucket['max_duration']['value'] ?? 0),
                'total_ms' => (float) ($bucket['total_duration']['value'] ?? 0),
                // Exact only when every document came from an explicit measure().
                'exact' => $types === [MetricData::TYPE_APP_FUNCTION],
            ];
        }, data_get($result, 'aggregations.functions.buckets', []));
    }

    /**
     * Per-function statistics for a window, compared against the window of the
     * same length immediately before it.
     *
     * The comparison is what makes the numbers actionable: an average on its own
     * says nothing about whether a function just got slower. Elasticsearch has
     * no cross-window aggregation, so both windows are aggregated separately and
     * matched by name here.
     *
     * @param  array<string, string>  $filters
     * @return list<array{
     *     key: string, count: int, avg_ms: float, p75_ms: float, p95_ms: float, max_ms: float,
     *     total_ms: float, self_ms: float, trend: list<array{at: string, p75_ms: float, count: int}>,
     *     exact: bool, baseline_count: int, baseline_avg_ms: ?float, delta_ms: ?float,
     *     delta_pct: ?float, status: string
     * }>
     */
    public function functionStatistics(
        array $filters,
        int $size = 50,
        int $minimumCalls = 3,
        string $sort = self::SORT_SELF,
    ): array {
        $current  = $this->functionAggregation($filters, $size, $sort);
        $baseline = $this->functionAggregation($this->precedingWindow($filters), $size, $sort);
        $rows     = [];

        foreach ($current as $name => $stats) {
            $before = $baseline[$name] ?? null;

            // A handful of calls on either side is noise, not a trend.
            $comparable = $before !== null
                && $before['count'] >= $minimumCalls
                && $stats['count'] >= $minimumCalls
                && $before['avg_ms'] > 0;

            $deltaMs  = $comparable ? $stats['avg_ms'] - $before['avg_ms'] : null;
            $deltaPct = $comparable && $deltaMs !== null ? ($deltaMs / $before['avg_ms']) * 100 : null;

            $rows[] = [
                'key'             => $name,
                'count'           => $stats['count'],
                'avg_ms'          => $stats['avg_ms'],
                'p75_ms'          => $stats['p75_ms'],
                'p95_ms'          => $stats['p95_ms'],
                'max_ms'          => $stats['max_ms'],
                'total_ms'        => $stats['total_ms'],
                'self_ms'         => $stats['self_ms'],
                'trend'           => $stats['trend'],
                'exact'           => $stats['exact'],
                'baseline_count'  => $before['count'] ?? 0,
                'baseline_avg_ms' => $before['avg_ms'] ?? null,
                'delta_ms'        => $deltaMs,
                'delta_pct'       => $deltaPct,
                'status'          => match (true) {
                    $before === null   => 'new',
                    ! $comparable      => 'unknown',
                    $deltaPct >= 10.0  => 'regressed',
                    $deltaPct <= -10.0 => 'improved',
                    default            => 'stable',
                },
            ];
        }

        // Functions that ran before and have since stopped appearing.
        foreach ($baseline as $name => $before) {
            if (isset($current[$name]) || $before['count'] < $minimumCalls) {
                continue;
            }

            $rows[] = [
                'key'             => $name,
                'count'           => 0,
                'avg_ms'          => 0.0,
                'p75_ms'          => 0.0,
                'p95_ms'          => 0.0,
                'max_ms'          => 0.0,
                'total_ms'        => 0.0,
                'self_ms'         => 0.0,
                'trend'           => [],
                'exact'           => $before['exact'],
                'baseline_count'  => $before['count'],
                'baseline_avg_ms' => $before['avg_ms'],
                'delta_ms'        => null,
                'delta_pct'       => null,
                'status'          => 'gone',
            ];
        }

        $key = match ($sort) {
            self::SORT_TOTAL   => 'total_ms',
            self::SORT_AVERAGE => 'avg_ms',
            self::SORT_P95     => 'p95_ms',
            self::SORT_CALLS   => 'count',
            default            => 'self_ms',
        };

        usort($rows, static fn (array $a, array $b): int => $b[$key] <=> $a[$key]);

        return $rows;
    }

    /**
     * @param  array<string, string>  $filters
     * @return array{hits: list<array<string, mixed>>, total: int}
     */
    public function transactions(array $filters, int $page, int $perPage): array
    {
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $result  = $this->client->search([
            'index' => $this->metricsAlias,
            'body'  => [
                'track_total_hits' => true,
                'from'             => ($this->offsetPage($page, $perPage) - 1) * $perPage,
                'size'             => $perPage,
                'sort'             => [['@timestamp' => ['order' => 'desc']]],
                'query'            => ['bool' => ['filter' => [
                    ['term' => ['kind' => 'transaction']],
                    ...$this->filters($filters),
                ]]],
            ],
        ]);

        return $this->hits($result);
    }

    /** @return list<array<string, mixed>> */
    public function trace(string $traceId): array
    {
        $result = $this->client->search([
            'index' => $this->metricsAlias,
            'body'  => [
                'size'  => 1000,
                'sort'  => [['@timestamp' => ['order' => 'asc']]],
                'query' => ['term' => ['trace.id' => $traceId]],
            ],
        ]);

        return $this->hits($result)['hits'];
    }

    /** @return array<string, mixed>|null */
    public function profile(string $profileId): ?array
    {
        $result = $this->client->search([
            'index' => $this->profilesAlias,
            'body'  => [
                'size'  => 1,
                'query' => ['term' => ['profile_id' => $profileId]],
            ],
        ]);

        $hit = $result['hits']['hits'][0] ?? null;

        return is_array($hit) ? (($hit['_source'] ?? []) + ['_id' => $hit['_id'] ?? null]) : null;
    }

    /**
     * The window of equal length ending where the requested one begins.
     *
     * @param  array<string, string>  $filters
     * @return array<string, string>
     */
    private function precedingWindow(array $filters): array
    {
        if (! isset($filters['from'], $filters['to'])) {
            return $filters;
        }

        try {
            $from = new DateTimeImmutable($filters['from']);
            $to   = new DateTimeImmutable($filters['to']);
        } catch (Exception) {
            return $filters;
        }

        $length = $to->getTimestamp() - $from->getTimestamp();

        if ($length <= 0) {
            return $filters;
        }

        return [
            ...$filters,
            'from' => $from->modify("-{$length} seconds")->format(DATE_ATOM),
            'to'   => $from->format(DATE_ATOM),
        ];
    }

    /**
     * @param  array<string, string>  $filters
     * @return array<string, array{count: int, avg_ms: float, p75_ms: float, p95_ms: float, max_ms: float, total_ms: float, self_ms: float, exact: bool, trend: list<array{at: string, p75_ms: float, count: int}>}>
     */
    private function functionAggregation(array $filters, int $size, string $sort = self::SORT_SELF): array
    {
        $result = $this->client->search([
            'index' => $this->metricsAlias,
            'body'  => [
                'size'  => 0,
                'query' => ['bool' => ['filter' => [
                    ['term' => ['kind' => 'span']],
                    ['terms' => ['type' => [MetricData::TYPE_APP_FUNCTION, MetricData::TYPE_APP_FUNCTION_PROFILED]]],
                    ...$this->filters($filters),
                ]]],
                'aggs' => [
                    'functions' => [
                        'terms' => [
                            'field' => 'name',
                            'size'  => $size,
                            'order' => self::SORT_ORDERS[$sort] ?? self::SORT_ORDERS[self::SORT_SELF],
                        ],
                        'aggs' => [
                            'avg_duration'   => ['avg' => ['field' => 'duration_ms']],
                            'max_duration'   => ['max' => ['field' => 'duration_ms']],
                            'total_duration' => ['sum' => ['field' => 'duration_ms']],
                            'self_duration'  => ['sum' => ['field' => 'code.self_ms']],
                            'p95'            => ['percentiles' => ['field' => 'duration_ms', 'percents' => [75, 95]]],
                            'sources'        => ['terms' => ['field' => 'type', 'size' => 2]],
                            'trend'          => [
                                'date_histogram' => [
                                    'field'             => '@timestamp',
                                    'calendar_interval' => $filters['interval'] ?? '1h',
                                    'min_doc_count'     => 0,
                                    'time_zone'         => $filters['timezone'] ?? 'UTC',
                                ] + (isset($filters['from'], $filters['to'])
                                    ? ['extended_bounds' => ['min' => $filters['from'], 'max' => $filters['to']]]
                                    : []),
                                'aggs' => ['p75' => ['percentiles' => ['field' => 'duration_ms', 'percents' => [75]]]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $rows = [];

        foreach (data_get($result, 'aggregations.functions.buckets', []) as $bucket) {
            $types = array_column($bucket['sources']['buckets'] ?? [], 'key');

            $trend = [];

            foreach ($bucket['trend']['buckets'] ?? [] as $point) {
                $trend[] = [
                    'at'     => (string) ($point['key_as_string'] ?? ''),
                    'p75_ms' => round((float) ($point['p75']['values']['75.0'] ?? $point['p75']['values']['75'] ?? 0), 3),
                    'count'  => (int) ($point['doc_count'] ?? 0),
                ];
            }

            $rows[(string) ($bucket['key'] ?? '')] = [
                'count'    => (int) ($bucket['doc_count'] ?? 0),
                'avg_ms'   => (float) ($bucket['avg_duration']['value'] ?? 0),
                'p75_ms'   => (float) ($bucket['p95']['values']['75.0'] ?? $bucket['p95']['values']['75'] ?? 0),
                'p95_ms'   => (float) ($bucket['p95']['values']['95.0'] ?? $bucket['p95']['values']['95'] ?? 0),
                'max_ms'   => (float) ($bucket['max_duration']['value'] ?? 0),
                'total_ms' => (float) ($bucket['total_duration']['value'] ?? 0),
                'self_ms'  => (float) ($bucket['self_duration']['value'] ?? 0),
                'exact'    => $types === [MetricData::TYPE_APP_FUNCTION],
                'trend'    => $trend,
            ];
        }

        return $rows;
    }

    /** @param array<string, string> $filters */
    private function filters(array $filters): array
    {
        $clauses = [];

        foreach (['type' => 'type', 'outcome' => 'outcome', 'service' => 'service.name', 'trace_id' => 'trace.id'] as $key => $field) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $clauses[] = ['term' => [$field => $filters[$key]]];
            }
        }

        if (isset($filters['from']) || isset($filters['to'])) {
            $range = [];

            if (isset($filters['from'])) {
                $range['gte'] = $filters['from'];
            }

            if (isset($filters['to'])) {
                $range['lte'] = $filters['to'];
            }

            if (isset($filters['timezone'])) {
                $range['time_zone'] = $filters['timezone'];
            }

            $clauses[] = ['range' => ['@timestamp' => $range]];
        }

        return $clauses;
    }

    /** @return array{hits: list<array<string, mixed>>, total: int} */
    private function hits(array $result): array
    {
        return [
            'hits' => array_map(
                static fn (array $hit): array => ($hit['_source'] ?? []) + ['_id' => $hit['_id'] ?? null],
                $result['hits']['hits'] ?? [],
            ),
            'total' => (int) data_get($result, 'hits.total.value', 0),
        ];
    }

    /**
     * The highest page whose window Elasticsearch will still serve.
     */
    private function offsetPage(int $page, int $perPage): int
    {
        return max(1, min(max(1, $page), intdiv(self::MAX_RESULT_WINDOW, max(1, $perPage))));
    }
}
