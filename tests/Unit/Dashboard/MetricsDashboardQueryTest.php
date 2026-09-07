<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use Tsitsishvili\ElasticAudit\Dashboard\MetricsDashboardQuery;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\FakeLogElasticsearchClient;

final class MetricsDashboardQueryTest extends TestCase
{
    private FakeLogElasticsearchClient $client;

    private MetricsDashboardQuery $query;

    protected function setUp(): void
    {
        $this->client = new FakeLogElasticsearchClient;
        $this->query  = new MetricsDashboardQuery($this->client, 'app_metrics', 'app_profiles');
    }

    public function test_transactions_query_only_root_documents_and_applies_filters(): void
    {
        $this->query->transactions(['type' => 'http.server', 'outcome' => 'failure'], 1, 25);
        $call    = $this->client->lastSearch();
        $filters = $call['body']['query']['bool']['filter'];

        $this->assertSame('app_metrics', $call['index']);
        $this->assertContains(['term' => ['kind' => 'transaction']], $filters);
        $this->assertContains(['term' => ['type' => 'http.server']], $filters);
        $this->assertContains(['term' => ['outcome' => 'failure']], $filters);
    }

    public function test_function_statistics_compare_the_window_against_the_one_before_it(): void
    {
        $bucket = static fn (string $name, int $count, float $avg, string $type): array => [
            'key'            => $name,
            'doc_count'      => $count,
            'avg_duration'   => ['value' => $avg],
            'max_duration'   => ['value' => $avg * 2],
            'total_duration' => ['value' => $avg * $count],
            'p95'            => ['values' => ['95.0' => $avg * 1.5]],
            'sources'        => ['buckets' => [['key' => $type]]],
        ];

        // The first search is the requested window, the second its predecessor.
        $responses = [
            ['aggregations' => ['functions' => ['buckets' => [
                $bucket('App\\Services\\Checkout::run', 10, 220.0, 'app.function'),
                $bucket('App\\Services\\Search::run', 10, 50.0, 'app.function'),
                $bucket('App\\Services\\Steady::run', 10, 100.0, 'app.function'),
                $bucket('App\\Services\\Fresh::run', 10, 10.0, 'app.function'),
                // Too few calls to trust either side.
                $bucket('App\\Services\\Rare::run', 1, 900.0, 'app.function'),
            ]]]],
            ['aggregations' => ['functions' => ['buckets' => [
                $bucket('App\\Services\\Checkout::run', 10, 100.0, 'app.function'),
                $bucket('App\\Services\\Search::run', 10, 100.0, 'app.function'),
                $bucket('App\\Services\\Steady::run', 10, 98.0, 'app.function'),
                $bucket('App\\Services\\Retired::run', 10, 40.0, 'app.function'),
                $bucket('App\\Services\\Rare::run', 1, 5.0, 'app.function'),
            ]]]],
        ];
        $this->client->searchResolver = static function () use (&$responses): array {
            return array_shift($responses) ?? ['aggregations' => ['functions' => ['buckets' => []]]];
        };

        $rows = collect($this->query->functionStatistics([
            'from' => '2026-08-21T12:00:00+00:00',
            'to'   => '2026-08-21T13:00:00+00:00',
        ]))->keyBy('key');

        // The baseline search must cover the hour immediately before.
        $baselineFilters = $this->client->searchCalls[1]['body']['query']['bool']['filter'];
        $range           = null;

        foreach ($baselineFilters as $filter) {
            if (isset($filter['range']['@timestamp'])) {
                $range = $filter['range']['@timestamp'];
            }
        }

        $this->assertNotNull($range);
        $this->assertSame('2026-08-21T11:00:00+00:00', $range['gte']);
        $this->assertSame('2026-08-21T12:00:00+00:00', $range['lte']);

        $this->assertSame('regressed', $rows['App\\Services\\Checkout::run']['status']);
        $this->assertSame(120.0, $rows['App\\Services\\Checkout::run']['delta_ms']);
        $this->assertSame(120.0, $rows['App\\Services\\Checkout::run']['delta_pct']);

        $this->assertSame('improved', $rows['App\\Services\\Search::run']['status']);
        $this->assertSame(-50.0, $rows['App\\Services\\Search::run']['delta_pct']);

        // Within the 10% band, so not called a movement either way.
        $this->assertSame('stable', $rows['App\\Services\\Steady::run']['status']);

        $this->assertSame('new', $rows['App\\Services\\Fresh::run']['status']);
        $this->assertNull($rows['App\\Services\\Fresh::run']['delta_pct']);

        // Present before, absent now.
        $this->assertSame('gone', $rows['App\\Services\\Retired::run']['status']);
        $this->assertSame(0, $rows['App\\Services\\Retired::run']['count']);

        // A single call on each side is noise, never a verdict.
        $this->assertSame('unknown', $rows['App\\Services\\Rare::run']['status']);
        $this->assertNull($rows['App\\Services\\Rare::run']['delta_pct']);
    }

    public function test_function_statistics_expose_self_time_and_a_per_function_trend(): void
    {
        $this->client->searchResponse = ['aggregations' => ['functions' => ['buckets' => [
            [
                'key'            => 'App\\Services\\Checkout::run', 'doc_count' => 4,
                'avg_duration'   => ['value' => 50.0], 'max_duration' => ['value' => 90.0],
                'total_duration' => ['value' => 200.0], 'self_duration' => ['value' => 30.0],
                'p95'            => ['values' => ['75.0' => 60.0, '95.0' => 85.0]],
                'sources'        => ['buckets' => [['key' => 'app.function']]],
                'trend'          => ['buckets' => [
                    ['key_as_string' => '2026-08-21T11:00:00Z', 'doc_count' => 2, 'p75' => ['values' => ['75.0' => 40.0]]],
                    ['key_as_string' => '2026-08-21T12:00:00Z', 'doc_count' => 2, 'p75' => ['values' => ['75.0' => 70.0]]],
                ]],
            ],
        ]]]];

        $row = collect($this->query->functionStatistics([]))->firstWhere('key', 'App\\Services\\Checkout::run');

        $this->assertSame(30.0, $row['self_ms']);
        $this->assertSame(60.0, $row['p75_ms']);
        $this->assertSame(85.0, $row['p95_ms']);
        $this->assertCount(2, $row['trend']);
        $this->assertSame(70.0, $row['trend'][1]['p75_ms']);
        $this->assertSame(2, $row['trend'][1]['count']);
    }

    public function test_function_statistics_order_by_the_requested_metric(): void
    {
        $this->query->functionStatistics([], sort: MetricsDashboardQuery::SORT_SELF);

        $this->assertSame(
            ['self_duration' => 'desc'],
            $this->client->searchCalls[0]['body']['aggs']['functions']['terms']['order'],
        );

        $this->query->functionStatistics([], sort: MetricsDashboardQuery::SORT_CALLS);

        $this->assertSame(
            ['_count' => 'desc'],
            $this->client->searchCalls[2]['body']['aggs']['functions']['terms']['order'],
        );
    }

    public function test_function_statistics_flag_profiler_derived_rows_as_estimates(): void
    {
        $this->client->searchResponse = ['aggregations' => ['functions' => ['buckets' => [
            [
                'key'            => 'App\\Services\\Sampled::run', 'doc_count' => 5,
                'avg_duration'   => ['value' => 10.0], 'max_duration' => ['value' => 20.0],
                'total_duration' => ['value' => 50.0], 'p95' => ['values' => ['95.0' => 15.0]],
                'sources'        => ['buckets' => [['key' => 'app.function.profiled']]],
            ],
        ]]]];

        $rows = collect($this->query->functionStatistics([]))->keyBy('key');

        $this->assertFalse($rows['App\\Services\\Sampled::run']['exact']);
    }

    public function test_profile_query_uses_separate_profiles_alias(): void
    {
        $this->query->profile('profile-1');

        $this->assertSame('app_profiles', $this->client->lastSearch()['index']);
        $this->assertSame(
            ['term' => ['profile_id' => 'profile-1']],
            $this->client->lastSearch()['body']['query'],
        );
    }
}
