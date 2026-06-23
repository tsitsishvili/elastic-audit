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
}
