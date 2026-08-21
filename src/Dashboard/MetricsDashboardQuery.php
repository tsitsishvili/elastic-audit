<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Dashboard;

use Tsitsishvili\ElasticAudit\DataTransferObjects\MetricData;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;

final class MetricsDashboardQuery
{
    private const MAX_PER_PAGE = 200;

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
     * @return list<array{key: string, count: int, avg_ms: float, max_ms: float, total_ms: float}>
     */
    public function functions(array $filters, int $size = 10): array
    {
        $result = $this->client->search([
            'index' => $this->metricsAlias,
            'body'  => [
                'size'  => 0,
                'query' => ['bool' => ['filter' => [
                    ['term' => ['kind' => 'span']],
                    ['term' => ['type' => MetricData::TYPE_APP_FUNCTION]],
                    ...$this->filters($filters),
                ]]],
                'aggs' => [
                    'functions' => [
                        'terms' => ['field' => 'name', 'size' => $size, 'order' => ['total_duration' => 'desc']],
                        'aggs'  => [
                            'avg_duration'   => ['avg' => ['field' => 'duration_ms']],
                            'max_duration'   => ['max' => ['field' => 'duration_ms']],
                            'total_duration' => ['sum' => ['field' => 'duration_ms']],
                        ],
                    ],
                ],
            ],
        ]);

        return array_map(static fn (array $bucket): array => [
            'key'      => (string) ($bucket['key'] ?? ''),
            'count'    => (int) ($bucket['doc_count'] ?? 0),
            'avg_ms'   => (float) ($bucket['avg_duration']['value'] ?? 0),
            'max_ms'   => (float) ($bucket['max_duration']['value'] ?? 0),
            'total_ms' => (float) ($bucket['total_duration']['value'] ?? 0),
        ], data_get($result, 'aggregations.functions.buckets', []));
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
