<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature\Dashboard;

use Tsitsishvili\ElasticAudit\Dashboard\Dashboard;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\FakeLogElasticsearchClient;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class DashboardTest extends TestCase
{
    private FakeLogElasticsearchClient $fake;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // The shared header links to the activity dashboard, so its routes must be registered.
        $app['config']->set('activity_logs.dashboard.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeLogElasticsearchClient();
        $this->app->instance(LogElasticsearchClientInterface::class, $this->fake);

        Dashboard::auth(fn () => true);
    }

    protected function tearDown(): void
    {
        Dashboard::auth(null);

        parent::tearDown();
    }

    public function test_overview_renders_when_authorized(): void
    {
        $this->fake->searchResponse = [
            'hits'         => ['total' => ['value' => 3]],
            'aggregations' => [
                'by_status_class' => ['buckets' => [['key' => '2xx', 'doc_count' => 3]]],
                'success'         => ['buckets' => [['key' => 1, 'key_as_string' => 'true', 'doc_count' => 3]]],
                'over_time'       => ['buckets' => []],
            ],
        ];

        $this->get(route('http-logs.overview', [], false))
            ->assertOk()
            ->assertSee('Overview')
            ->assertSee('Success rate');
    }

    public function test_overview_forbidden_when_auth_denies(): void
    {
        Dashboard::auth(fn () => false);

        $this->get(route('http-logs.overview', [], false))->assertForbidden();
    }

    public function test_overview_applies_range_and_interval_to_metrics_query(): void
    {
        $this->fake->searchResponse = [
            'hits'         => ['total' => ['value' => 0]],
            'aggregations' => ['over_time' => ['buckets' => []]],
        ];

        $this->get(route('http-logs.overview', ['range' => '7d', 'interval' => '1d'], false))->assertOk();

        $body = $this->fake->lastSearch()['body'];

        $this->assertSame('1d', $body['aggs']['over_time']['date_histogram']['calendar_interval']);
        $this->assertArrayHasKey('time_zone', $body['aggs']['over_time']['date_histogram']);

        $hasRange = collect($body['query']['bool']['filter'])
            ->contains(fn ($clause) => isset($clause['range']['@timestamp']['gte'], $clause['range']['@timestamp']['lte']));

        $this->assertTrue($hasRange, 'Expected a @timestamp range clause derived from the selected window.');
    }

    public function test_overview_falls_back_to_defaults_for_unknown_range_and_interval(): void
    {
        $this->fake->searchResponse = [
            'hits'         => ['total' => ['value' => 0]],
            'aggregations' => ['over_time' => ['buckets' => []]],
        ];

        $this->get(route('http-logs.overview', ['range' => 'bogus', 'interval' => 'bogus'], false))->assertOk();

        $body = $this->fake->lastSearch()['body'];

        $this->assertSame('1h', $body['aggs']['over_time']['date_histogram']['calendar_interval']);
    }

    public function test_overview_custom_range_uses_explicit_from_and_to(): void
    {
        $this->fake->searchResponse = [
            'hits'         => ['total' => ['value' => 0]],
            'aggregations' => ['over_time' => ['buckets' => []]],
        ];

        $this->get(route('http-logs.overview', ['range' => 'custom', 'interval' => '1h', 'from' => '2026-01-01T00:00', 'to' => '2026-02-01T00:00'], false))->assertOk();

        $filter = $this->fake->lastSearch()['body']['query']['bool']['filter'];

        $this->assertContains(
            ['range' => ['@timestamp' => ['gte' => '2026-01-01T00:00', 'lte' => '2026-02-01T00:00', 'time_zone' => 'UTC']]],
            $filter,
        );
    }

    public function test_logs_index_renders_rows(): void
    {
        $this->fake->searchResponse = [
            'hits' => [
                'total' => ['value' => 1],
                'hits'  => [[
                    '_id'     => 'd1',
                    '_source' => [
                        'event_id'   => 'evt-1',
                        '@timestamp' => '2026-06-03T10:00:00Z',
                        'provider'   => 'delivery',
                        'event_type' => 'order_create',
                        'direction'  => 'outgoing',
                        'success'    => true,
                        'http'       => ['method' => 'POST', 'path' => '/orders', 'status_code' => 200, 'status_class' => '2xx', 'latency_ms' => 120],
                        'entity'     => ['type' => 'order', 'id' => '42'],
                    ],
                ]],
            ],
        ];

        $this->get(route('http-logs.logs.index', [], false))
            ->assertOk()
            ->assertSee('delivery')
            ->assertSee('/orders');
    }

    public function test_logs_show_renders_found_log(): void
    {
        $this->fake->searchResponse = [
            'hits' => ['hits' => [[
                '_id'     => 'd1',
                '_source' => [
                    'event_id'   => 'evt-1',
                    '@timestamp' => '2026-06-03T10:00:00Z',
                    'provider'   => 'payment',
                    'event_type' => 'payment_callback',
                    'direction'  => 'incoming',
                    'success'    => true,
                    'http'       => ['method' => 'POST', 'host' => 'pay.example', 'path' => '/cb', 'status_code' => 200, 'status_class' => '2xx', 'latency_ms' => 5],
                    'entity'     => ['type' => 'payment', 'id' => '7'],
                    'request'    => ['headers' => ['x' => 'y'], 'body_preview' => '{"a":1}', 'body_hash' => 'h', 'body_truncated' => false],
                    'response'   => ['headers' => [], 'body_preview' => '', 'body_hash' => null, 'body_truncated' => false],
                ],
            ]]],
        ];

        $this->get(route('http-logs.logs.show', 'evt-1', false))
            ->assertOk()
            ->assertSee('payment_callback')
            ->assertSee('pay.example');
    }

    public function test_logs_show_returns_404_when_missing(): void
    {
        $this->fake->searchResponse = ['hits' => ['hits' => []]];

        $this->get(route('http-logs.logs.show', 'missing', false))->assertNotFound();
    }

    public function test_header_links_to_activity_dashboard_when_enabled(): void
    {
        $this->fake->searchResponse = [
            'hits'         => ['total' => ['value' => 0]],
            'aggregations' => ['over_time' => ['buckets' => []]],
        ];

        $this->get(route('http-logs.overview', [], false))
            ->assertOk()
            ->assertSee('Activity Logs')
            ->assertSee(route('activity-logs.overview', [], false), false);
    }
}
