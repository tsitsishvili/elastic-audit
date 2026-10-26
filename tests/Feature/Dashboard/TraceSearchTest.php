<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature\Dashboard;

use Illuminate\Testing\TestResponse;
use Tsitsishvili\ElasticAudit\Dashboard\Dashboard;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\FakeLogElasticsearchClient;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

final class TraceSearchTest extends TestCase
{
    private FakeLogElasticsearchClient $fake;

    private string $traceId;

    protected function setUp(): void
    {
        parent::setUp();
        Dashboard::auth(fn () => true);
        $this->traceId = str_repeat('a', 32);
        $this->fake    = new FakeLogElasticsearchClient;
        $this->app->instance(LogElasticsearchClientInterface::class, $this->fake);
    }

    protected function tearDown(): void
    {
        Dashboard::auth(null);
        parent::tearDown();
    }

    public function test_trace_id_redirects_to_the_trace_page(): void
    {
        $this->find($this->traceId)->assertRedirect($this->traceUrl());
        $this->assertSame([], $this->fake->searchCalls);
    }

    public function test_traceparent_and_upper_case_input_are_normalised(): void
    {
        $this->find('  00-'.strtoupper($this->traceId).'-'.str_repeat('b', 16).'-01 ')->assertRedirect($this->traceUrl());
        $this->find(strtoupper($this->traceId))->assertRedirect($this->traceUrl());
    }

    public function test_request_id_with_a_trace_redirects_to_the_trace_page(): void
    {
        $this->serve(http: [$this->httpDoc()]);

        $this->find('req-1')->assertRedirect($this->traceUrl());
    }

    public function test_request_id_without_a_trace_redirects_to_its_log(): void
    {
        $this->serve(http: [$this->httpDoc(['trace' => []])]);

        $this->find('req-1')->assertRedirect(route('http-logs.logs.show', 'evt-http-1'));

        $this->serve(activity: [$this->activityDoc(['trace' => []])]);

        $this->find('req-1')->assertRedirect(route('activity-logs.logs.show', 'evt-act-1'));
    }

    public function test_request_id_shared_by_several_logs_redirects_to_the_filtered_list(): void
    {
        $this->serve(http: [$this->httpDoc(['trace' => []]), $this->httpDoc(['trace' => [], 'event_id' => 'evt-http-2'])]);

        $this->find('req-1')->assertRedirect(route('http-logs.logs.index', ['request_id' => 'req-1']));
    }

    public function test_unknown_id_shows_the_not_found_page_with_404(): void
    {
        $this->serve();

        $this->find('<b>nope</b>')
            ->assertNotFound()
            ->assertSee('No trace or request found for "&lt;b&gt;nope&lt;/b&gt;".', false)
            ->assertSee('aria-label="Find trace or request ID"', false);

        $this->get(route('elastic-audit-metrics.find', ['id' => '  ']))
            ->assertRedirect(route('elastic-audit-metrics.overview'));
    }

    public function test_sources_the_viewer_cannot_open_are_not_searched(): void
    {
        config()->set('http_logs.dashboard.middleware', ['web', 'other']);
        $this->serve(http: [$this->httpDoc()], activity: [$this->activityDoc(['trace' => []])]);

        $this->find('req-1')->assertRedirect(route('activity-logs.logs.show', 'evt-act-1'));

        $this->assertNotEmpty($this->fake->searchCalls);

        foreach ($this->fake->searchCalls as $call) {
            $this->assertStringNotContainsString('http_logs', (string) $call['index']);
        }
    }

    public function test_nav_shows_the_search_box_when_the_metrics_dashboard_is_enabled(): void
    {
        $this->get(route('elastic-audit-metrics.overview'))
            ->assertOk()
            ->assertSee('placeholder="Find trace or request ID…"', false)
            ->assertSee('action="'.route('elastic-audit-metrics.find', [], false).'"', false);
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('elastic_audit_metrics.dashboard.enabled', true);
        $app['config']->set('elastic_audit_metrics.dashboard.middleware', ['web']);
        $app['config']->set('http_logs.dashboard.middleware', ['web']);
        $app['config']->set('activity_logs.dashboard.middleware', ['web']);
    }

    private function find(string $id): TestResponse
    {
        return $this->get(route('elastic-audit-metrics.find', ['id' => $id]));
    }

    private function traceUrl(): string
    {
        return route('elastic-audit-metrics.traces.show', $this->traceId);
    }

    /**
     * @param  list<array<string, mixed>>  $http
     * @param  list<array<string, mixed>>  $activity
     */
    private function serve(array $http = [], array $activity = []): void
    {
        $this->fake->searchResolver = function (array $params) use ($http, $activity): array {
            $docs = match (true) {
                str_contains((string) $params['index'], 'http_logs')     => $http,
                str_contains((string) $params['index'], 'activity_logs') => $activity,
                default                                                  => [],
            };

            return ['hits' => [
                'total' => ['value' => count($docs)],
                'hits'  => array_map(fn (array $d) => ['_id' => $d['event_id'], '_source' => $d], $docs),
            ]];
        };
    }

    private function httpDoc(array $override = []): array
    {
        return $override + [
            'event_id'   => 'evt-http-1',
            '@timestamp' => '2026-01-01T10:00:00.002Z',
            'request_id' => 'req-1',
            'trace'      => ['id' => $this->traceId],
        ];
    }

    private function activityDoc(array $override = []): array
    {
        return $override + [
            'event_id'   => 'evt-act-1',
            '@timestamp' => '2026-01-01T10:00:00.005Z',
            'request_id' => 'req-1',
            'trace'      => ['id' => $this->traceId],
        ];
    }
}
