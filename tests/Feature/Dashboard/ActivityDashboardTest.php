<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature\Dashboard;

use Illuminate\Support\Facades\Log;
use RuntimeException;
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

    public function test_show_renders_redacted_and_malformed_legacy_changes_without_crashing(): void
    {
        /** @var FakeLogElasticsearchClient $fake */
        $fake = $this->app->make(LogElasticsearchClientInterface::class);
        $fake->searchResponse = [
            'hits' => [
                'total' => ['value' => 1],
                'hits'  => [[
                    '_id'     => 'event-1',
                    '_source' => [
                        'event_id' => 'event-1',
                        'action'   => 'user.updated',
                        'success'  => true,
                        'changes'  => [
                            'password'     => '[REDACTED]',
                            'legacy_array' => ['unexpected' => ['nested' => true]],
                            'status'       => ['old' => 'pending', 'new' => 'active'],
                            'enabled'      => ['old' => false, 'new' => true],
                        ],
                    ],
                ]],
            ],
        ];

        $this->get(route('activity-logs.logs.show', 'event-1'))
            ->assertOk()
            ->assertSee('[REDACTED]')
            ->assertSee('legacy_array')
            ->assertSee('{&quot;unexpected&quot;:{&quot;nested&quot;:true}}', false)
            ->assertSee('pending')
            ->assertSee('active')
            ->assertSee('false')
            ->assertSee('true');
    }

    public function test_query_errors_do_not_expose_elasticsearch_details(): void
    {
        /** @var FakeLogElasticsearchClient $fake */
        $fake = $this->app->make(LogElasticsearchClientInterface::class);
        $fake->searchResolver = static fn (): never => throw new RuntimeException(
            'Could not connect to elastic.internal:9200/private-index',
        );
        Log::spy();

        foreach ([
            route('activity-logs.overview'),
            route('activity-logs.logs.index'),
            route('activity-logs.logs.show', 'event-1'),
        ] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('Failed to query Elasticsearch. Check the application log for details.')
                ->assertDontSee('elastic.internal')
                ->assertDontSee('private-index');
        }

        Log::shouldHaveReceived('error')
            ->times(3)
            ->withArgs(fn (string $message, array $context): bool => $message === 'Elastic Audit activity dashboard query failed'
                && $context['error'] === 'Could not connect to elastic.internal:9200/private-index');
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
            ->assertSee('Activity trail')
            ->assertSee('Activity over time')
            ->assertSee('id="activityChart"', false)
            ->assertSee('src="/vendor/elastic-audit/chart-', false)
            ->assertSee('Interval')
            ->assertSee('Success rate')
            ->assertSee('80%');
    }
}
