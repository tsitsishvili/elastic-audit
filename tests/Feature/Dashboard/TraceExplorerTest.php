<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature\Dashboard;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tsitsishvili\ElasticAudit\Dashboard\Dashboard;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\FakeLogElasticsearchClient;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

final class TraceExplorerTest extends TestCase
{
    private FakeLogElasticsearchClient $fake;

    private string $traceId;

    protected function setUp(): void
    {
        parent::setUp();
        Dashboard::auth(fn () => true);
        $this->traceId = str_repeat('b', 32);
        $this->fake    = $this->app->make(LogElasticsearchClientInterface::class);
    }

    protected function tearDown(): void
    {
        Dashboard::auth(null);
        parent::tearDown();
    }

    public function test_trace_page_merges_spans_http_and_activity_rows(): void
    {
        $this->resolve(metrics: [$this->root()], http: [$this->httpDoc()], activity: [$this->activityDoc()]);

        $this->get($this->url())
            ->assertOk()
            ->assertSeeInOrder(['GET orders.store', 'payment · charge', 'order.created'])
            ->assertSee('+2.0 ms');
    }

    public function test_audit_rows_are_hidden_when_the_viewer_fails_that_dashboards_middleware(): void
    {
        config()->set('http_logs.dashboard.middleware', ['web', 'some-alias']);
        $this->resolve(metrics: [$this->root()], http: [$this->httpDoc()], activity: [$this->activityDoc()]);

        $this->get($this->url())
            ->assertOk()
            ->assertDontSee('payment · charge')
            ->assertSee('Some audit data in this trace is hidden')
            ->assertSee('order.created');

        foreach ($this->fake->searchCalls as $call) {
            $this->assertStringNotContainsString('http_logs', (string) $call['index']);
        }
    }

    public function test_page_renders_audit_rows_when_metrics_has_none(): void
    {
        $this->resolve(metrics: [], http: [$this->httpDoc()], activity: [$this->activityDoc()]);

        $this->get($this->url())
            ->assertOk()
            ->assertSee('payment · charge')
            ->assertSee('10:00:00.002')
            ->assertDontSee('Some audit data in this trace is hidden');
    }

    public function test_page_is_404_when_no_source_has_the_trace(): void
    {
        $this->resolve(metrics: [], http: [], activity: []);

        $this->get($this->url())->assertNotFound();
    }

    public function test_a_failing_source_shows_a_notice_and_the_rest_still_renders(): void
    {
        $this->fake->searchResolver = function (array $params): array {
            if (str_contains((string) $params['index'], 'activity_logs')) {
                throw new RuntimeException('boom');
            }

            return str_contains((string) $params['index'], 'http_logs')
                ? $this->hits([])
                : $this->hits([$this->root()]);
        };

        $this->get($this->url())
            ->assertOk()
            ->assertSee('GET orders.store')
            ->assertSee('Failed to query Elasticsearch');
    }

    public function test_failing_source_is_named_in_the_error_log(): void
    {
        Log::spy();
        $this->fake->searchResolver = function (array $params): array {
            if (str_contains((string) $params['index'], 'activity_logs')) {
                throw new RuntimeException('boom');
            }

            return $this->hits(str_contains((string) $params['index'], 'metrics') ? [$this->root()] : []);
        };

        $this->get($this->url())->assertOk();

        Log::shouldHaveReceived('error')->with(
            'Elastic Audit metrics dashboard query failed',
            ['source' => 'activity', 'trace_id' => $this->traceId, 'error' => 'boom'],
        )->once();
    }

    public function test_a_missing_audit_index_counts_as_no_documents(): void
    {
        // Default config: HTTP logging off, so its index or alias was never created.
        $this->fake->searchResolver = function (array $params): array {
            $index = (string) $params['index'];

            // Elasticsearch answers index_not_found for a missing target unless both flags are set.
            if (str_contains($index, 'http_logs') && ! (($params['ignore_unavailable'] ?? false) && ($params['allow_no_indices'] ?? false))) {
                throw new RuntimeException('index_not_found_exception');
            }

            return $this->hits(str_contains($index, 'metrics') ? [$this->root()] : []);
        };

        $this->get($this->url())
            ->assertOk()
            ->assertSee('GET orders.store')
            ->assertDontSee('Failed to query Elasticsearch');
    }

    public function test_audit_rows_are_capped_with_a_notice(): void
    {
        $this->fake->searchResolver = function (array $params): array {
            if (str_contains((string) $params['index'], 'http_logs')) {
                return $this->hits([$this->httpDoc()], 350);
            }

            return $this->hits(str_contains((string) $params['index'], 'metrics') ? [$this->root()] : []);
        };

        $this->get($this->url())
            ->assertOk()
            ->assertSee('Showing the first 200 HTTP logs of 350');
    }

    public function test_job_rows_render_their_spans_as_collapsible_children(): void
    {
        $job = ['kind' => 'transaction', 'type' => 'queue.job', 'name' => 'SendReceipt', '@timestamp' => '2026-01-01T10:00:00.010Z', 'duration_ms' => 5,
            'trace'    => ['id' => $this->traceId, 'parent_span_id' => 'tx1'], 'transaction' => ['id' => 'tx2']];
        $span = ['kind'   => 'span', 'type' => 'db.query', 'name' => 'select', 'db' => ['statement' => 'select 1 from receipts'], '@timestamp' => '2026-01-01T10:00:00.011Z',
            'duration_ms' => 1, 'trace' => ['id' => $this->traceId], 'transaction' => ['id' => 'tx2']];
        $this->resolve(metrics: [$this->root(), $job, $span], http: [], activity: []);

        $this->get($this->url())
            ->assertOk()
            ->assertSeeInOrder(['GET orders.store', 'SendReceipt', 'select 1 from receipts'])
            ->assertSee('open.r1', false)
            ->assertSee('+11.0 ms');
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('elastic_audit_metrics.dashboard.enabled', true);
        $app['config']->set('elastic_audit_metrics.dashboard.middleware', ['web']);
        $app['config']->set('http_logs.dashboard.middleware', ['web']);
        $app['config']->set('activity_logs.dashboard.middleware', ['web']);
        $app->instance(LogElasticsearchClientInterface::class, new FakeLogElasticsearchClient);
    }

    private function url(): string
    {
        return route('elastic-audit-metrics.traces.show', $this->traceId);
    }

    /** @param list<array<string, mixed>> $metrics */
    private function resolve(array $metrics, array $http, array $activity): void
    {
        $this->fake->searchResolver = function (array $params) use ($metrics, $http, $activity): array {
            $index = (string) $params['index'];

            return $this->hits(match (true) {
                str_contains($index, 'http_logs')     => $http,
                str_contains($index, 'activity_logs') => $activity,
                str_contains($index, 'metrics')       => $metrics,
                default                               => [],
            });
        };
    }

    /** @param list<array<string, mixed>> $docs */
    private function hits(array $docs, ?int $total = null): array
    {
        return ['hits' => [
            'total' => ['value' => $total ?? count($docs)],
            'hits'  => array_map(fn (array $d) => ['_id' => $d['event_id'] ?? 'm', '_source' => $d], $docs),
        ]];
    }

    private function root(): array
    {
        return [
            'kind'        => 'transaction',
            'type'        => 'http.server',
            'name'        => 'GET orders.store',
            'outcome'     => 'success',
            'duration_ms' => 25,
            '@timestamp'  => '2026-01-01T10:00:00.000Z',
            'trace'       => ['id' => $this->traceId],
            'transaction' => ['id' => 'tx1'],
        ];
    }

    private function httpDoc(): array
    {
        return [
            'event_id'   => 'evt-http-1',
            'provider'   => 'payment',
            'event_type' => 'charge',
            'direction'  => 'outbound',
            '@timestamp' => '2026-01-01T10:00:00.014Z', // stamped at the end of the 12 ms call, so it starts at +2 ms
            'http'       => ['method' => 'POST', 'url' => 'https://pay.test/charge', 'status_code' => 201, 'latency_ms' => 12],
            'trace'      => ['id' => $this->traceId],
        ];
    }

    private function activityDoc(): array
    {
        return [
            'event_id'   => 'evt-act-1',
            'action'     => 'order.created',
            '@timestamp' => '2026-01-01T10:00:00.005Z',
            'actor'      => ['type' => 'user', 'id' => '7'],
            'entity'     => ['type' => 'order', 'id' => '42'],
            'changes'    => ['status' => ['old' => null, 'new' => 'paid']],
            'trace'      => ['id' => $this->traceId],
        ];
    }
}
