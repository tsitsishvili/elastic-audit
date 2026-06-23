<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use Tsitsishvili\ElasticAudit\Dashboard\ActivityDashboardQuery;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\FakeLogElasticsearchClient;

class ActivityDashboardQueryTest extends TestCase
{
    private const READ_ALIAS = 'app_activity_logs';

    private FakeLogElasticsearchClient $client;
    private ActivityDashboardQuery $query;

    protected function setUp(): void
    {
        $this->client = new FakeLogElasticsearchClient();
        $this->query  = new ActivityDashboardQuery($this->client, self::READ_ALIAS);
    }

    public function test_search_queries_correct_index(): void
    {
        $this->query->search([]);
        $this->assertSame(self::READ_ALIAS, $this->client->lastSearch()['index']);
    }

    public function test_search_applies_action_filter(): void
    {
        $this->query->search(['action' => 'order.updated']);

        $filter = $this->client->lastSearch()['body']['query']['bool']['filter'];
        $terms  = array_column($filter, 'term');

        $this->assertTrue(
            collect($terms)->contains(fn ($t) => ($t['action'] ?? null) === 'order.updated'),
        );
    }

    public function test_search_applies_actor_type_filter(): void
    {
        $this->query->search(['actor_type' => 'user']);

        $filter = $this->client->lastSearch()['body']['query']['bool']['filter'];
        $terms  = array_column($filter, 'term');

        $this->assertTrue(
            collect($terms)->contains(fn ($t) => ($t['actor.type'] ?? null) === 'user'),
        );
    }

    public function test_find_returns_null_when_no_hit(): void
    {
        $this->client->searchResponse = ['hits' => ['total' => ['value' => 0], 'hits' => []]];

        $result = $this->query->find('nonexistent-id');

        $this->assertNull($result);
    }

    public function test_find_returns_source_with_id(): void
    {
        $this->client->searchResponse = [
            'hits' => [
                'total' => ['value' => 1],
                'hits'  => [
                    ['_id' => 'doc-1', '_source' => ['event_id' => 'ev-1', 'action' => 'order.updated']],
                ],
            ],
        ];

        $result = $this->query->find('ev-1');

        $this->assertSame('ev-1', $result['event_id']);
        $this->assertSame('doc-1', $result['_id']);
    }

    public function test_filter_options_returns_actions_actor_types_entity_types(): void
    {
        $this->client->searchResponse = [
            'hits'         => ['total' => ['value' => 0], 'hits' => []],
            'aggregations' => [
                'actions'      => ['buckets' => [['key' => 'order.updated'], ['key' => 'order.created']]],
                'actor_types'  => ['buckets' => [['key' => 'user']]],
                'entity_types' => ['buckets' => [['key' => 'order']]],
            ],
        ];

        $options = $this->query->filterOptions();

        $this->assertSame(['order.updated', 'order.created'], $options['actions']);
        $this->assertSame(['user'], $options['actor_types']);
        $this->assertSame(['order'], $options['entity_types']);
    }
}
