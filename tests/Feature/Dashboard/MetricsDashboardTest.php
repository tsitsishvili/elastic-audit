<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature\Dashboard;

use Tsitsishvili\ElasticAudit\Dashboard\Dashboard;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\FakeLogElasticsearchClient;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

final class MetricsDashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Dashboard::auth(fn () => true);
    }

    protected function tearDown(): void
    {
        Dashboard::auth(null);
        parent::tearDown();
    }

    public function test_overview_and_transactions_render(): void
    {
        $this->get(route('elastic-audit-metrics.overview'))
            ->assertOk()
            ->assertSee('Application performance')
            ->assertSee('p95 latency');

        $this->get(route('elastic-audit-metrics.transactions'))
            ->assertOk()
            ->assertSee('Transactions');
    }

    public function test_transactions_pass_sort_and_search_filters_to_the_query(): void
    {
        /** @var FakeLogElasticsearchClient $fake */
        $fake = $this->app->make(LogElasticsearchClientInterface::class);

        $this->get(route('elastic-audit-metrics.transactions', ['sort' => 'duration', 'dir' => 'asc', 'name' => 'orders']))
            ->assertOk()
            ->assertSee('Name:', false)
            ->assertSee('aria-sort="ascending"', false);

        $body = $fake->searchCalls[0]['body'];

        $this->assertSame(['order' => 'asc', 'unmapped_type' => 'double'], $body['sort'][0]['duration_ms']);
        $this->assertContains(['wildcard' => ['name' => ['value' => '*orders*', 'case_insensitive' => true]]], $body['query']['bool']['filter']);
    }

    public function test_functions_search_narrows_every_window_by_name(): void
    {
        /** @var FakeLogElasticsearchClient $fake */
        $fake = $this->app->make(LogElasticsearchClientInterface::class);

        $this->get(route('elastic-audit-metrics.functions', ['name' => 'Checkout']))
            ->assertOk()
            ->assertSee('No functions match');

        $this->assertNotEmpty($fake->searchCalls);

        foreach ($fake->searchCalls as $call) {
            $this->assertContains(
                ['wildcard' => ['name' => ['value' => '*Checkout*', 'case_insensitive' => true]]],
                $call['body']['query']['bool']['filter'],
            );
        }
    }

    public function test_trace_and_profile_render_linked_data(): void
    {
        /** @var FakeLogElasticsearchClient $fake */
        $fake                 = $this->app->make(LogElasticsearchClientInterface::class);
        $fake->searchResolver = static function (array $params): array {
            if (str_contains((string) $params['index'], 'profiles')) {
                return ['hits' => ['total' => ['value' => 1], 'hits' => [[
                    '_id'     => 'profile-1',
                    '_source' => [
                        'profile_id'   => 'profile-1',
                        'driver'       => 'excimer',
                        'mode'         => 'sampling',
                        'format'       => 'speedscope',
                        'sample_count' => 5,
                        'trace'        => ['id' => str_repeat('a', 32)],
                        'transaction'  => ['name' => 'GET orders.index'],
                        'hot_frames'   => [[
                            'function'      => 'App\\Services\\Orders::index',
                            'self_samples'  => 3,
                            'total_samples' => 5,
                        ]],
                        'payload' => ['profiles' => []],
                    ],
                ]]]];
            }

            if (str_contains((string) $params['index'], 'http_logs') || str_contains((string) $params['index'], 'activity_logs')) {
                return ['hits' => ['total' => ['value' => 0], 'hits' => []]];
            }

            return ['hits' => ['total' => ['value' => 1], 'hits' => [[
                '_id'     => 'event-1',
                '_source' => [
                    'kind'        => 'transaction',
                    'type'        => 'http.server',
                    'name'        => 'GET orders.index',
                    'outcome'     => 'success',
                    'duration_ms' => 25,
                    'trace'       => ['id' => str_repeat('a', 32)],
                    'transaction' => ['profile_id' => 'profile-1'],
                ],
            ]]]];
        };

        $this->get(route('elastic-audit-metrics.traces.show', str_repeat('a', 32)))
            ->assertOk()
            ->assertSee('GET orders.index')
            ->assertSee('Open sampled profile');
        $this->get(route('elastic-audit-metrics.profiles.show', 'profile-1'))
            ->assertOk()
            ->assertSee('excimer')
            ->assertSee('App\\Services\\Orders::index');
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('elastic_audit_metrics.dashboard.enabled', true);
        $app['config']->set('elastic_audit_metrics.dashboard.path', 'metrics');
        $app['config']->set('elastic_audit_metrics.dashboard.middleware', ['web']);

        $fake                 = new FakeLogElasticsearchClient;
        $fake->searchResponse = [
            'hits'         => ['total' => ['value' => 0], 'hits' => []],
            'aggregations' => [],
        ];
        $app->instance(LogElasticsearchClientInterface::class, $fake);
    }
}
