<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature\Dashboard;

use Orchestra\Testbench\Attributes\DefineEnvironment;
use Tsitsishvili\ElasticAudit\Dashboard\Dashboard;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\FakeLogElasticsearchClient;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

final class TraceLinkTest extends TestCase
{
    private FakeLogElasticsearchClient $fake;

    private string $traceId;

    protected function setUp(): void
    {
        parent::setUp();
        Dashboard::auth(fn () => true);
        $this->traceId = str_repeat('c', 32);
        $this->fake    = new FakeLogElasticsearchClient;
        $this->app->instance(LogElasticsearchClientInterface::class, $this->fake);
    }

    protected function tearDown(): void
    {
        Dashboard::auth(null);
        parent::tearDown();
    }

    public function test_http_log_detail_links_its_trace_id(): void
    {
        $this->serve($this->httpDoc());

        $this->get(route('http-logs.logs.show', 'evt-http-1'))
            ->assertOk()
            ->assertSee('href="'.$this->traceUrl().'"', false);
    }

    public function test_activity_log_detail_links_its_trace_id(): void
    {
        $this->serve($this->activityDoc());

        $this->get(route('activity-logs.logs.show', 'evt-act-1'))
            ->assertOk()
            ->assertSee('href="'.$this->traceUrl().'"', false);
    }

    public function test_list_rows_link_trace_ids(): void
    {
        $this->serve($this->httpDoc(), $this->httpDoc(['event_id' => 'evt-http-2', 'trace' => []]));
        $html = $this->get(route('http-logs.logs.index'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'href="'.$this->traceUrl().'"'));
        $this->assertStringContainsString('trace ↗', $html);

        $this->serve($this->activityDoc(), $this->activityDoc(['event_id' => 'evt-act-2', 'trace' => []]));
        $html = $this->get(route('activity-logs.logs.index'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'href="'.$this->traceUrl().'"'));
        $this->assertStringContainsString('trace ↗', $html);
    }

    #[DefineEnvironment('disableMetricsDashboard')]
    public function test_trace_id_is_plain_text_when_the_metrics_dashboard_is_disabled(): void
    {
        $this->serve($this->httpDoc());

        $this->get(route('http-logs.logs.show', 'evt-http-1'))
            ->assertOk()
            ->assertSee($this->traceId)
            ->assertDontSee('/traces/', false);

        $this->get(route('http-logs.logs.index'))
            ->assertOk()
            ->assertDontSee('/traces/', false)
            ->assertDontSee('trace ↗');
    }

    protected function disableMetricsDashboard($app): void
    {
        $app['config']->set('elastic_audit_metrics.dashboard.enabled', false);
    }

    private function traceUrl(): string
    {
        return route('elastic-audit-metrics.traces.show', $this->traceId, false);
    }

    private function serve(array ...$docs): void
    {
        $this->fake->searchResponse = ['hits' => [
            'total' => ['value' => count($docs)],
            'hits'  => array_map(fn (array $d) => ['_id' => $d['event_id'], '_source' => $d], $docs),
        ]];
    }

    private function httpDoc(array $override = []): array
    {
        return $override + [
            'event_id'   => 'evt-http-1',
            'provider'   => 'payment',
            'event_type' => 'charge',
            'direction'  => 'outbound',
            '@timestamp' => '2026-01-01T10:00:00.002Z',
            'http'       => ['method' => 'POST', 'path' => '/charge', 'status_code' => 201, 'latency_ms' => 12],
            'trace'      => ['id' => $this->traceId],
        ];
    }

    private function activityDoc(array $override = []): array
    {
        return $override + [
            'event_id'   => 'evt-act-1',
            'action'     => 'order.created',
            '@timestamp' => '2026-01-01T10:00:00.005Z',
            'actor'      => ['type' => 'user', 'id' => '7'],
            'entity'     => ['type' => 'order', 'id' => '42'],
            'trace'      => ['id' => $this->traceId],
        ];
    }
}
