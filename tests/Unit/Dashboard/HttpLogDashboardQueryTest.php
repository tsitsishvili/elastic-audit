<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit\Dashboard;

use Tsitsishvili\ElasticAudit\Dashboard\HttpLogDashboardQuery;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\FakeLogElasticsearchClient;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class HttpLogDashboardQueryTest extends TestCase
{
    private function query(FakeLogElasticsearchClient $client): HttpLogDashboardQuery
    {
        return new HttpLogDashboardQuery($client, 'test_alias');
    }

    public function test_search_builds_pagination_and_sort(): void
    {
        $client = new FakeLogElasticsearchClient();
        $client->searchResponse = ['hits' => ['total' => ['value' => 42], 'hits' => []]];

        $result = $this->query($client)->search([], page: 2, perPage: 10);

        $body = $client->lastSearch()['body'];

        $this->assertSame('test_alias', $client->lastSearch()['index']);
        $this->assertSame(10, $body['from']);
        $this->assertSame(10, $body['size']);
        $this->assertSame([['@timestamp' => ['order' => 'desc']]], $body['sort']);
        $this->assertSame(42, $result['total']);
    }

    public function test_search_translates_filters_into_term_clauses(): void
    {
        $client = new FakeLogElasticsearchClient();

        $this->query($client)->search([
            'provider'     => 'delivery',
            'direction'    => 'outgoing',
            'status_class' => '5xx',
            'success'      => 'false',
            'entity_id'    => '42',
        ]);

        $filter = $client->lastSearch()['body']['query']['bool']['filter'];

        $this->assertContains(['term' => ['provider' => 'delivery']], $filter);
        $this->assertContains(['term' => ['direction' => 'outgoing']], $filter);
        $this->assertContains(['term' => ['http.status_class' => '5xx']], $filter);
        $this->assertContains(['term' => ['success' => false]], $filter);
        $this->assertContains(['term' => ['entity.id' => '42']], $filter);
    }

    public function test_search_translates_timeout_filter_into_term_clause(): void
    {
        $client = new FakeLogElasticsearchClient();

        $this->query($client)->search(['timeout' => '1']);

        $filter = $client->lastSearch()['body']['query']['bool']['filter'];

        $this->assertContains(['term' => ['http.timed_out' => true]], $filter);
    }

    public function test_search_ignores_falsey_timeout_filter(): void
    {
        $client = new FakeLogElasticsearchClient();

        $this->query($client)->search(['timeout' => '0']);

        $filter = $client->lastSearch()['body']['query']['bool']['filter'];

        $this->assertNotContains(['term' => ['http.timed_out' => true]], $filter);
    }

    public function test_search_builds_timestamp_range_from_dates(): void
    {
        $client = new FakeLogElasticsearchClient();

        $this->query($client)->search(['from' => '2026-01-01T00:00', 'to' => '2026-02-01T00:00']);

        $filter = $client->lastSearch()['body']['query']['bool']['filter'];

        $this->assertContains(
            ['range' => ['@timestamp' => ['gte' => '2026-01-01T00:00', 'lte' => '2026-02-01T00:00']]],
            $filter,
        );
    }

    public function test_search_returns_sources_with_id(): void
    {
        $client = new FakeLogElasticsearchClient();
        $client->searchResponse = [
            'hits' => [
                'total' => ['value' => 1],
                'hits'  => [['_id' => 'abc', '_source' => ['event_id' => 'evt-1', 'provider' => 'payment']]],
            ],
        ];

        $result = $this->query($client)->search([]);

        $this->assertCount(1, $result['hits']);
        $this->assertSame('evt-1', $result['hits'][0]['event_id']);
        $this->assertSame('abc', $result['hits'][0]['_id']);
    }

    public function test_find_queries_by_event_id_and_returns_source(): void
    {
        $client = new FakeLogElasticsearchClient();
        $client->searchResponse = [
            'hits' => ['hits' => [['_id' => 'doc-1', '_source' => ['event_id' => 'evt-9']]]],
        ];

        $log = $this->query($client)->find('evt-9');

        $this->assertSame(['term' => ['event_id' => 'evt-9']], $client->lastSearch()['body']['query']);
        $this->assertSame('evt-9', $log['event_id']);
    }

    public function test_find_returns_null_when_missing(): void
    {
        $client = new FakeLogElasticsearchClient();
        $client->searchResponse = ['hits' => ['hits' => []]];

        $this->assertNull($this->query($client)->find('missing'));
    }

    public function test_metrics_requests_aggregations(): void
    {
        $client = new FakeLogElasticsearchClient();
        $client->searchResponse = [
            'hits'         => ['total' => ['value' => 5]],
            'aggregations' => ['by_provider' => ['buckets' => []]],
        ];

        $metrics = $this->query($client)->metrics([]);

        $body = $client->lastSearch()['body'];
        $this->assertSame(0, $body['size']);
        $this->assertArrayHasKey('by_status_class', $body['aggs']);
        $this->assertArrayHasKey('over_time', $body['aggs']);
        $this->assertSame(['filter' => ['term' => ['http.timed_out' => true]]], $body['aggs']['timeouts']);
        $this->assertSame(5, $metrics['total']);
    }

    public function test_filter_options_extracts_bucket_keys(): void
    {
        $client = new FakeLogElasticsearchClient();
        $client->searchResponse = [
            'aggregations' => [
                'providers'   => ['buckets' => [['key' => 'delivery'], ['key' => 'payment']]],
                'event_types' => ['buckets' => [['key' => 'order_create']]],
            ],
        ];

        $options = $this->query($client)->filterOptions();

        $this->assertSame(['delivery', 'payment'], $options['providers']);
        $this->assertSame(['order_create'], $options['event_types']);
    }
}
