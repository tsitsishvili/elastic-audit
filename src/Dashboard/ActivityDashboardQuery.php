<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Dashboard;

use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;

class ActivityDashboardQuery
{
    private const MAX_PER_PAGE = 200;

    public function __construct(
        private readonly LogElasticsearchClientInterface $client,
        private readonly string $readAlias,
    ) {}

    public function search(
        array $filters,
        int $page = 1,
        int $perPage = 25,
        string $sortField = '@timestamp',
        string $sortDir = 'desc',
    ): array {
        $page      = max(1, $page);
        $perPage   = max(1, min(self::MAX_PER_PAGE, $perPage));
        $sortField = $sortField === '@timestamp' ? $sortField : '@timestamp';
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

    public function metrics(array $filters): array
    {
        $dateHistogram = [
            'field'             => '@timestamp',
            'calendar_interval' => in_array($filters['interval'] ?? null, ['1h', '1d'], true) ? $filters['interval'] : '1h',
            'min_doc_count'     => 0,
            'time_zone'         => $filters['timezone'] ?? 'UTC',
        ];

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
                    'by_action'  => ['terms' => ['field' => 'action', 'size' => 20]],
                    'by_actor'   => ['terms' => ['field' => 'actor.type', 'size' => 10]],
                    'success'    => ['terms' => ['field' => 'success', 'size' => 2]],
                    'over_time'  => [
                        'date_histogram' => $dateHistogram,
                        'aggs'           => [
                            'success' => ['terms' => ['field' => 'success', 'size' => 2]],
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

    public function filterOptions(): array
    {
        $result = $this->client->search([
            'index' => $this->readAlias,
            'body'  => [
                'size' => 0,
                'aggs' => [
                    'actions'      => ['terms' => ['field' => 'action', 'size' => 100]],
                    'actor_types'  => ['terms' => ['field' => 'actor.type', 'size' => 10]],
                    'entity_types' => ['terms' => ['field' => 'entity.type', 'size' => 50]],
                ],
            ],
        ]);

        $aggs = $result['aggregations'] ?? [];

        return [
            'actions'      => array_column($aggs['actions']['buckets'] ?? [], 'key'),
            'actor_types'  => array_column($aggs['actor_types']['buckets'] ?? [], 'key'),
            'entity_types' => array_column($aggs['entity_types']['buckets'] ?? [], 'key'),
        ];
    }

    private function filterClauses(array $filters): array
    {
        $clauses = [];

        $termFields = [
            'action'      => 'action',
            'actor_type'  => 'actor.type',
            'actor_id'    => 'actor.id',
            'entity_type' => 'entity.type',
            'entity_id'   => 'entity.id',
            'request_id'  => 'request_id',
        ];

        foreach ($termFields as $key => $field) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $clauses[] = ['term' => [$field => (string) $filters[$key]]];
            }
        }

        if (isset($filters['success']) && $filters['success'] !== '') {
            $clauses[] = ['term' => ['success' => filter_var($filters['success'], FILTER_VALIDATE_BOOL)]];
        }

        $range = [];

        if (! empty($filters['from'])) {
            $range['gte'] = $filters['from'];
        }

        if (! empty($filters['to'])) {
            $range['lte'] = $filters['to'];
        }

        if ($range !== []) {
            if (! empty($filters['timezone'])) {
                $range['time_zone'] = $filters['timezone'];
            }

            $clauses[] = ['range' => ['@timestamp' => $range]];
        }

        return $clauses;
    }
}
