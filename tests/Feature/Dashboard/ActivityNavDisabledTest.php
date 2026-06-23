<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature\Dashboard;

use Tsitsishvili\ElasticAudit\Dashboard\Dashboard;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\FakeLogElasticsearchClient;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class ActivityNavDisabledTest extends TestCase
{
    private FakeLogElasticsearchClient $fake;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // HTTP dashboard off: its routes are never registered.
        $app['config']->set('http_logs.dashboard.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeLogElasticsearchClient();
        $this->fake->searchResponse = [
            'hits'         => ['total' => ['value' => 0], 'hits' => []],
            'aggregations' => [
                'by_action' => ['buckets' => []],
                'by_actor'  => ['buckets' => []],
                'success'   => ['buckets' => []],
                'over_time' => ['buckets' => []],
            ],
        ];
        $this->app->instance(LogElasticsearchClientInterface::class, $this->fake);

        Dashboard::auth(fn () => true);
    }

    protected function tearDown(): void
    {
        Dashboard::auth(null);
        parent::tearDown();
    }

    public function test_switcher_hides_disabled_http_dashboard(): void
    {
        $this->get(route('activity-logs.overview', [], false))
            ->assertOk()
            ->assertDontSee('HTTP Logs');
    }
}
