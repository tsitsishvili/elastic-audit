<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature\Dashboard;

use Tsitsishvili\ElasticAudit\Dashboard\Dashboard;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\FakeLogElasticsearchClient;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class ActivityDashboardTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('activity_logs.dashboard.enabled', true);
        $app['config']->set('activity_logs.dashboard.path', 'activity');
        $app['config']->set('activity_logs.dashboard.middleware', ['web']);
        $app['config']->set('activity_logs.index_alias', 'app_activity_logs');
        $app['config']->set('activity_logs.index_alias_write', 'app_activity_logs_write');

        $fake = new FakeLogElasticsearchClient();
        $fake->searchResponse = [
            'hits'         => ['total' => ['value' => 0], 'hits' => []],
            'aggregations' => [
                'by_action'  => ['buckets' => []],
                'by_actor'   => ['buckets' => []],
                'success'    => ['buckets' => []],
                'over_time'  => ['buckets' => []],
            ],
        ];

        $app->instance(LogElasticsearchClientInterface::class, $fake);
    }

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

    public function test_overview_returns_200(): void
    {
        $this->get(route('activity-logs.overview'))->assertStatus(200);
    }

    public function test_list_returns_200(): void
    {
        $this->get(route('activity-logs.logs.index'))->assertStatus(200);
    }

    public function test_show_returns_404_for_unknown_event(): void
    {
        $this->get(route('activity-logs.logs.show', 'nonexistent'))->assertStatus(404);
    }

    public function test_dashboard_blocked_when_auth_fails(): void
    {
        Dashboard::auth(fn () => false);

        $this->get(route('activity-logs.overview'))->assertStatus(403);
    }

    public function test_header_links_to_http_dashboard_when_enabled(): void
    {
        $this->get(route('activity-logs.overview', [], false))
            ->assertOk()
            ->assertSee('HTTP Logs')
            ->assertSee(route('http-logs.overview', [], false), false);
    }

    public function test_overview_renders_activity_chart_when_data_present(): void
    {
        /** @var FakeLogElasticsearchClient $fake */
        $fake = $this->app->make(LogElasticsearchClientInterface::class);
        $fake->searchResponse = [
            'hits'         => ['total' => ['value' => 5], 'hits' => []],
            'aggregations' => [
                'by_action'  => ['buckets' => [['key' => 'user.login', 'doc_count' => 5]]],
                'by_actor'   => ['buckets' => [['key' => 'user', 'doc_count' => 5]]],
                'success'    => ['buckets' => [
                    ['key' => 1, 'key_as_string' => 'true', 'doc_count' => 4],
                    ['key' => 0, 'key_as_string' => 'false', 'doc_count' => 1],
                ]],
                'over_time'  => ['buckets' => [[
                    'key_as_string' => '2026-06-24T00:00:00.000Z',
                    'key'           => 1750723200000,
                    'doc_count'     => 5,
                    'success'       => ['buckets' => [
                        ['key' => 1, 'key_as_string' => 'true', 'doc_count' => 4],
                        ['key' => 0, 'key_as_string' => 'false', 'doc_count' => 1],
                    ]],
                ]]],
            ],
        ];

        $this->get(route('activity-logs.overview', [], false))
            ->assertOk()
            ->assertSee('Activity over time')
            ->assertSee('id="activityChart"', false)
            ->assertSee('Interval:')
            ->assertSee('Success Rate')
            ->assertSee('80%');
    }
}
