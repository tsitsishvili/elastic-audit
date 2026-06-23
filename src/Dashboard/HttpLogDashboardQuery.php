<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Dashboard;

use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;

/**
 * Read-side queries for the dashboard. Builds Elasticsearch requests against the
 * read alias and normalizes the responses into plain arrays for the views.
 */
class HttpLogDashboardQuery
{
    /** Hard cap on page size to protect Elasticsearch from large deep pages. */
    private const MAX_PER_PAGE = 200;

    public function __construct(
        private readonly LogElasticsearchClientInterface $client,
        private readonly string $readAlias,
    ) {}

    /**
     * Paginated list of logs matching the given filters, newest first.
     *
     * @param  array<string, mixed>  $filters
     * @return array{hits: list<array<string, mixed>>, total: int}
     */
    public function search(
        array $filters,
        int $page = 1,
        int $perPage = 25,
        string $sortField = '@timestamp',
        string $sortDir = 'desc',
    ): array {
        $page    = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        $sortField = in_array($sortField, ['@timestamp', 'http.latency_ms'], true) ? $sortField : '@timestamp';
        $sortDir   = in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : 'desc';

        $result = $this->client->search([
            'index' => $this->readAlias,
            'body'  => [
                'track_total_hits' => true,
                'from'             => ($page - 1) * $perPage,
                'size'             => $perPage,
                'sort'             => [[$sortField => ['order' => $sortDir]]],
                'query'            => ['bool' => ['filter' => $this->filterClauses($filters)]],
            ],
        ]);

        return [
            'hits'  => array_map(
                static fn (array $hit): array => ($hit['_source'] ?? []) + ['_id' => $hit['_id'] ?? null],
                $result['hits']['hits'] ?? [],
            ),
            'total' => (int) ($result['hits']['total']['value'] ?? 0),
        ];
    }

    /**
     * Fetch a single log document by its unique event id.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $eventId): ?array
    {
        $result = $this->client->search([
            'index' => $this->readAlias,
            'body'  => [
                'size'  => 1,
                'query' => ['term' => ['event_id' => $eventId]],
            ],
        ]);

        $hit = $result['hits']['hits'][0] ?? null;

        if ($hit === null) {
            return null;
        }

        return ($hit['_source'] ?? []) + ['_id' => $hit['_id'] ?? null];
    }

    /**
     * Aggregated metrics for the overview page.
     *
     * @param  array<string, mixed>  $filters
     * @return array{total: int, aggs: array<string, mixed>}
     */
    public function metrics(array $filters): array
    {
        // `calendar_interval` (with `time_zone`) aligns buckets to local wall-clock
        // hour/day boundaries, so the chart axis and tooltip match the app timezone.
        $dateHistogram = [
            'field'             => '@timestamp',
            'calendar_interval' => in_array($filters['interval'] ?? null, ['1h', '1d'], true) ? $filters['interval'] : '1h',
            'min_doc_count'     => 0,
            'time_zone'         => $filters['timezone'] ?? 'UTC',
        ];

        // Stretch the histogram to the full selected window so dates before the first
        // matching document render as zeros instead of being omitted. `min_doc_count: 0`
        // alone only fills gaps between the first and last matching document.
        if (! empty($filters['from']) && ! empty($filters['to'])) {
            $dateHistogram['extended_bounds'] = [
                'min' => $filters['from'],
                'max' => $filters['to'],
            ];
        }

        $result = $this->client->search([
            'index' => $this->readAlias,
            'body'  => [
                'size'             => 0,
                'track_total_hits' => true,
                'query'            => ['bool' => ['filter' => $this->filterClauses($filters)]],
                'aggs'             => [
                    'by_status_class' => ['terms' => ['field' => 'http.status_class', 'size' => 10]],
                    'by_provider'     => [
                        'terms' => ['field' => 'provider', 'size' => 20],
                        'aggs'  => ['latency_avg' => ['avg' => ['field' => 'http.latency_ms']]],
                    ],
                    'by_direction'    => ['terms' => ['field' => 'direction', 'size' => 5]],
                    'success'         => ['terms' => ['field' => 'success', 'size' => 2]],
                    'timeouts'        => ['filter' => ['term' => ['http.timed_out' => true]]],
                    'latency'         => ['stats' => ['field' => 'http.latency_ms']],
                    'latency_pct'     => ['percentiles' => ['field' => 'http.latency_ms', 'percents' => [50, 95, 99]]],
                    'over_time'       => [
                        'date_histogram' => $dateHistogram,
                        'aggs'           => [
                            'by_status_class' => ['terms' => ['field' => 'http.status_class', 'size' => 10]],
                            'latency_avg'     => ['avg' => ['field' => 'http.latency_ms']],
                            'latency_p95'     => ['percentiles' => ['field' => 'http.latency_ms', 'percents' => [95]]],
                        ],
                    ],
                ],
            ],
        ]);

        return [
            'total' => (int) ($result['hits']['total']['value'] ?? 0),
            'aggs'  => $result['aggregations'] ?? [],
        ];
    }

    /**
     * Distinct provider and event-type values present in the index, for filter dropdowns.
     *
     * @return array{providers: list<string>, event_types: list<string>}
     */
    public function filterOptions(): array
    {
        $result = $this->client->search([
            'index' => $this->readAlias,
            'body'  => [
                'size' => 0,
                'aggs' => [
                    'providers'   => ['terms' => ['field' => 'provider', 'size' => 50]],
                    'event_types' => ['terms' => ['field' => 'event_type', 'size' => 100]],
                ],
            ],
        ]);

        $aggs = $result['aggregations'] ?? [];

        return [
            'providers'   => array_column($aggs['providers']['buckets'] ?? [], 'key'),
            'event_types' => array_column($aggs['event_types']['buckets'] ?? [], 'key'),
        ];
    }

    /**
     * Translate dashboard filters into Elasticsearch bool `filter` clauses.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function filterClauses(array $filters): array
    {
        $clauses = [];

        $termFields = [
            'provider'     => 'provider',
            'event_type'   => 'event_type',
            'direction'    => 'direction',
            'status_class' => 'http.status_class',
            'entity_id'    => 'entity.id',
            'request_id'   => 'request_id',
            'external_id'  => 'external.id',
        ];

        foreach ($termFields as $key => $field) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $clauses[] = ['term' => [$field => (string) $filters[$key]]];
            }
        }

        if (isset($filters['success']) && $filters['success'] !== '') {
            $clauses[] = ['term' => ['success' => filter_var($filters['success'], FILTER_VALIDATE_BOOL)]];
        }

        // Timeout is a one-way filter: a truthy value narrows to timed-out requests;
        // an absent/empty value leaves the full result set unfiltered.
        if (isset($filters['timeout']) && filter_var($filters['timeout'], FILTER_VALIDATE_BOOL)) {
            $clauses[] = ['term' => ['http.timed_out' => true]];
        }

        $range = [];

        if (! empty($filters['from'])) {
            $range['gte'] = $filters['from'];
        }

        if (! empty($filters['to'])) {
            $range['lte'] = $filters['to'];
        }

        if ($range !== []) {
            // Interpret zone-less datetimes (e.g. from the `datetime-local` filter inputs)
            // in the app timezone. Values carrying an explicit offset are used as-is by ES.
            if (! empty($filters['timezone'])) {
                $range['time_zone'] = $filters['timezone'];
            }

            $clauses[] = ['range' => ['@timestamp' => $range]];
        }

        return $clauses;
    }
}
