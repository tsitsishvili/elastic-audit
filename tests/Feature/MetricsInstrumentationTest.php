<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tsitsishvili\ElasticAudit\DataTransferObjects\MetricData;
use Tsitsishvili\ElasticAudit\Facades\Performance;
use Tsitsishvili\ElasticAudit\Jobs\LogActivityJob;
use Tsitsishvili\ElasticAudit\Jobs\LogHttpRequestJob;
use Tsitsishvili\ElasticAudit\Jobs\LogMetricBatchJob;
use Tsitsishvili\ElasticAudit\Support\MetricsExclusions;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class MetricsInstrumentationTest extends TestCase
{
    public function test_http_query_and_outgoing_http_are_captured_as_individual_sanitized_spans(): void
    {
        Bus::fake();
        Http::fake(['provider.example.test/*' => Http::response(['ok' => true])]);
        Route::get('/metrics/orders/{order}', function (string $order) {
            DB::select("select 'private@example.test' as email, 123 as id");
            Http::get("https://provider.example.test/orders/{$order}?token=private-token");

            return response()->json(['ok' => true]);
        })->name('metrics.orders.show');

        $this->get('/metrics/orders/550e8400-e29b-41d4-a716-446655440000')->assertOk();

        Bus::assertDispatched(LogMetricBatchJob::class, function (LogMetricBatchJob $job): bool {
            $byType = [];

            foreach ($job->items as $metric) {
                $byType[$metric->type] = $metric;
            }

            if (! isset(
                $byType[MetricData::TYPE_HTTP_SERVER],
                $byType[MetricData::TYPE_DB_QUERY],
                $byType[MetricData::TYPE_HTTP_CLIENT],
            )) {
                return false;
            }

            $query    = $byType[MetricData::TYPE_DB_QUERY];
            $outgoing = $byType[MetricData::TYPE_HTTP_CLIENT];
            $root     = $byType[MetricData::TYPE_HTTP_SERVER];

            return $root->http['route'] === 'metrics.orders.show'
                && $root->kind === MetricData::KIND_TRANSACTION
                && $query->kind === MetricData::KIND_SPAN
                && $root->transactionId === $root->spanId
                && $query->transactionId === $root->transactionId
                && $query->db['statement'] === 'select ? as email, ? as id'
                && ! str_contains((string) $query->db['statement'], 'private@example.test')
                && $outgoing->http['host'] === 'provider.example.test'
                && $outgoing->http['path'] === '/orders/{id}'
                && ! str_contains($outgoing->name, 'private-token')
                && $query->traceId === $root->traceId
                && $query->parentSpanId === $root->spanId;
        });
    }

    public function test_w3c_context_is_continued_and_propagated_to_outgoing_http(): void
    {
        Bus::fake();
        Http::fake(['provider.example.test/*' => Http::response()]);
        Route::get('/metrics/trace-context', function () {
            Http::get('https://provider.example.test/health');

            return response()->noContent();
        });

        $traceId      = '4bf92f3577b34da6a3ce929d0e0e4736';
        $upstreamSpan = '00f067aa0ba902b7';
        $traceParent  = "00-{$traceId}-{$upstreamSpan}-01";

        $this->get('/metrics/trace-context', ['traceparent' => $traceParent])->assertNoContent();

        Http::assertSent(function ($request) use ($traceId): bool {
            $header = $request->header('traceparent')[0] ?? '';

            return preg_match("/^00-{$traceId}-[a-f0-9]{16}-01$/", $header) === 1;
        });
        Bus::assertDispatched(LogMetricBatchJob::class, function (LogMetricBatchJob $batch) use ($traceId, $upstreamSpan): bool {
            $root = collect($batch->items)->first(
                fn (MetricData $metric): bool => $metric->kind === MetricData::KIND_TRANSACTION,
            );

            return $root?->traceId === $traceId && $root->parentSpanId === $upstreamSpan;
        });
    }

    public function test_queue_job_continues_propagated_trace_context(): void
    {
        Bus::fake();
        $traceId = '4bf92f3577b34da6a3ce929d0e0e4736';
        $spanId  = '00f067aa0ba902b7';
        $job     = new class($traceId, $spanId)
        {
            public function __construct(private string $traceId, private string $spanId) {}

            public function resolveName(): string
            {
                return 'App\\Jobs\\PropagatedJob';
            }

            public function payload(): array
            {
                return [
                    'createdAt'           => time(),
                    'elastic_audit_trace' => [
                        'traceparent' => "00-{$this->traceId}-{$this->spanId}-01",
                    ],
                ];
            }

            public function getQueue(): string
            {
                return 'default';
            }

            public function attempts(): int
            {
                return 1;
            }
        };

        Event::dispatch(new JobProcessing('redis', $job));
        Event::dispatch(new JobProcessed('redis', $job));

        Bus::assertDispatched(LogMetricBatchJob::class, function (LogMetricBatchJob $batch) use ($traceId, $spanId): bool {
            $root = $batch->items[0] ?? null;

            return $root?->type === MetricData::TYPE_QUEUE_JOB
                && $root->traceId === $traceId
                && $root->parentSpanId === $spanId;
        });
    }

    public function test_queue_jobs_and_console_commands_are_timed_automatically(): void
    {
        Bus::fake();
        $input  = new ArrayInput([]);
        $output = new BufferedOutput;

        Event::dispatch(new CommandStarting('orders:sync', $input, $output));
        Event::dispatch(new CommandFinished('orders:sync', $input, $output, 0));

        $job = new class
        {
            public function resolveName(): string
            {
                return 'App\\Jobs\\ImportOrders';
            }

            public function payload(): array
            {
                return ['createdAt' => time() - 2];
            }

            public function getQueue(): string
            {
                return 'imports';
            }

            public function attempts(): int
            {
                return 2;
            }
        };

        Event::dispatch(new JobProcessing('redis', $job));
        Event::dispatch(new JobProcessed('redis', $job));

        Bus::assertDispatched(LogMetricBatchJob::class, function (LogMetricBatchJob $batch): bool {
            $metric = $batch->items[0] ?? null;

            return $metric?->type === MetricData::TYPE_CONSOLE_COMMAND
                && $metric->console['command'] === 'orders:sync'
                && $metric->console['exit_code'] === 0;
        });
        Bus::assertDispatched(LogMetricBatchJob::class, function (LogMetricBatchJob $batch): bool {
            $metric = $batch->items[0] ?? null;

            return $metric?->type === MetricData::TYPE_QUEUE_JOB
                && $metric->queue['job'] === 'App\\Jobs\\ImportOrders'
                && $metric->queue['name'] === 'imports'
                && $metric->queue['attempt'] === 2
                && $metric->queue['wait_ms'] >= 1000;
        });
    }

    public function test_cache_and_redis_capture_omits_keys_values_and_command_arguments(): void
    {
        Bus::fake();
        Event::dispatch(new RetrievingKey('redis', 'customer:private-token'));
        Event::dispatch(new CacheHit('redis', 'customer:private-token', 'private-value'));

        $connection = $this->createStub(Connection::class);
        $connection->method('getName')->willReturn('cache');
        Event::dispatch(new CommandExecuted('get', ['customer:private-token'], 2.5, $connection));

        Bus::assertDispatched(LogMetricBatchJob::class, function (LogMetricBatchJob $batch): bool {
            $metric = $batch->items[0] ?? null;

            return $metric?->type === MetricData::TYPE_CACHE_OPERATION
                && $metric->cache === ['operation' => 'get', 'store' => 'redis', 'result' => 'hit']
                && ! str_contains(serialize($metric), 'private-token')
                && ! str_contains(serialize($metric), 'private-value');
        });
        Bus::assertDispatched(LogMetricBatchJob::class, function (LogMetricBatchJob $batch): bool {
            $metric = $batch->items[0] ?? null;

            return $metric?->type === MetricData::TYPE_REDIS_COMMAND
                && $metric->redis === ['command' => 'GET', 'connection' => 'cache']
                && ! str_contains(serialize($metric), 'private-token');
        });
    }

    public function test_queue_worker_command_does_not_retain_individual_job_metrics(): void
    {
        Bus::fake();
        $input  = new ArrayInput([]);
        $output = new BufferedOutput;
        $job    = new class
        {
            public function resolveName(): string
            {
                return 'App\\Jobs\\WorkerJob';
            }

            public function payload(): array
            {
                return ['createdAt' => time()];
            }

            public function getQueue(): string
            {
                return 'default';
            }

            public function attempts(): int
            {
                return 1;
            }
        };

        Event::dispatch(new CommandStarting('queue:work', $input, $output));
        Event::dispatch(new JobProcessing('redis', $job));
        Event::dispatch(new JobProcessed('redis', $job));

        Bus::assertDispatched(LogMetricBatchJob::class, function (LogMetricBatchJob $batch): bool {
            $metric = $batch->items[0] ?? null;

            return $metric?->type === MetricData::TYPE_QUEUE_JOB
                && $metric->queue['job'] === 'App\\Jobs\\WorkerJob';
        });
        Bus::assertNotDispatched(LogMetricBatchJob::class, static function (LogMetricBatchJob $batch): bool {
            foreach ($batch->items as $metric) {
                if ($metric->type === MetricData::TYPE_CONSOLE_COMMAND
                    && $metric->console['command'] === 'queue:work') {
                    return true;
                }
            }

            return false;
        });

        Event::dispatch(new CommandFinished('queue:work', $input, $output, 0));
    }

    public function test_daemon_command_loop_work_outside_a_job_is_not_recorded(): void
    {
        Bus::fake();

        $input  = new ArrayInput([]);
        $output = new BufferedOutput;

        Event::dispatch(new CommandStarting('queue:work', $input, $output));

        // The worker's own bookkeeping between jobs: on the database queue and
        // cache drivers these queries are caused by delivering metrics, so
        // recording them would make the metrics queue feed itself.
        DB::select('select 1 as polled');
        Event::dispatch(new RetrievingKey('database', 'illuminate:queue:restart', []));
        Event::dispatch(new CacheHit('database', 'illuminate:queue:restart', 'value', []));

        Bus::assertNotDispatched(LogMetricBatchJob::class);

        Event::dispatch(new CommandFinished('queue:work', $input, $output, 0));

        // Once the daemon exits, context-less spans are recorded again.
        DB::select('select 1 as after_daemon');

        Bus::assertDispatched(LogMetricBatchJob::class, static function (LogMetricBatchJob $batch): bool {
            foreach ($batch->items as $metric) {
                if ($metric->type === MetricData::TYPE_DB_QUERY) {
                    return true;
                }
            }

            return false;
        });
    }

    public function test_outgoing_connection_failure_uses_stable_request_identity(): void
    {
        Bus::fake();
        Http::fake([
            'provider.example.test/*' => Http::failedConnection('connection refused'),
        ]);

        try {
            Http::get('https://provider.example.test/orders/123');
            $this->fail('The fake connection should fail.');
        } catch (ConnectionException) {
            // Expected; metrics must preserve the application's exception behavior.
        }

        Bus::assertDispatched(LogMetricBatchJob::class, function (LogMetricBatchJob $batch): bool {
            $metric = $batch->items[0] ?? null;

            return $metric?->type === MetricData::TYPE_HTTP_CLIENT
                && $metric->outcome === MetricData::OUTCOME_FAILURE
                && $metric->http['host'] === 'provider.example.test'
                && $metric->http['status_code'] === null;
        });
    }

    public function test_unmatched_http_route_does_not_capture_the_request_path(): void
    {
        Bus::fake();

        $this->get('/reset/private-reset-token')->assertNotFound();

        Bus::assertDispatched(LogMetricBatchJob::class, function (LogMetricBatchJob $batch): bool {
            foreach ($batch->items as $metric) {
                if ($metric->type !== MetricData::TYPE_HTTP_SERVER) {
                    continue;
                }

                return $metric->name === 'GET unmatched'
                    && $metric->http['route'] === 'unmatched'
                    && ! str_contains($metric->name, 'private-reset-token');
            }

            return false;
        });
    }

    public function test_individual_capture_categories_can_be_disabled(): void
    {
        config([
            'elastic_audit_metrics.capture.queries.enabled'       => false,
            'elastic_audit_metrics.capture.outgoing_http.enabled' => false,
        ]);
        Bus::fake();
        Http::fake(['provider.example.test/*' => Http::response()]);
        Route::get('/metrics/configurable', function () {
            DB::select('select 1');
            Http::get('https://provider.example.test/health');

            return response()->noContent();
        });

        $this->get('/metrics/configurable')->assertNoContent();

        Bus::assertDispatched(LogMetricBatchJob::class, function (LogMetricBatchJob $job): bool {
            $types = array_map(static fn (MetricData $metric): string => $metric->type, $job->items);

            return $types === [MetricData::TYPE_HTTP_SERVER];
        });
    }

    public function test_the_packages_own_dashboards_and_delivery_jobs_are_never_recorded(): void
    {
        config([
            'http_logs.dashboard.enabled'             => true,
            'http_logs.dashboard.prefix'              => 'logger',
            'http_logs.dashboard.path'                => 'third-party',
            'elastic_audit_metrics.dashboard.enabled' => true,
        ]);
        Bus::fake();

        $exclusions = $this->app->make(MetricsExclusions::class);

        $this->assertTrue($exclusions->path('logger/third-party'));
        $this->assertTrue($exclusions->path('logger/third-party/logs/abc'));
        $this->assertTrue($exclusions->path('vendor/elastic-audit/styles.css'));
        $this->assertTrue($exclusions->job(LogActivityJob::class));
        $this->assertTrue($exclusions->job(LogHttpRequestJob::class));
        $this->assertFalse($exclusions->path('api/products'));
        $this->assertFalse($exclusions->job('App\\Jobs\\SyncOrders'));

        // A request to an excluded path records nothing at all, not even the
        // queries it runs.
        Route::get('/logger/third-party', function () {
            DB::select('select 1 as dashboard_query');

            return response()->noContent();
        });

        $this->get('/logger/third-party')->assertNoContent();

        Bus::assertNotDispatched(LogMetricBatchJob::class);
    }

    public function test_application_configured_paths_and_jobs_are_excluded(): void
    {
        config([
            'elastic_audit_metrics.capture.http.exclude_paths' => ['up', 'internal/*'],
            'elastic_audit_metrics.capture.jobs.exclude'       => ['App\\Jobs\\Noisy*'],
        ]);
        Bus::fake();

        $exclusions = $this->app->make(MetricsExclusions::class);

        $this->assertTrue($exclusions->path('up'));
        $this->assertTrue($exclusions->path('internal/debug/state'));
        $this->assertTrue($exclusions->job('App\\Jobs\\NoisyBroadcast'));
        $this->assertFalse($exclusions->path('internal'));
        $this->assertFalse($exclusions->job('App\\Jobs\\SyncOrders'));

        Route::get('/internal/debug/state', function () {
            DB::select('select 1 as internal_query');

            return response()->noContent();
        });

        $this->get('/internal/debug/state')->assertNoContent();

        Bus::assertNotDispatched(LogMetricBatchJob::class);
    }

    public function test_a_root_below_its_minimum_duration_takes_its_spans_with_it(): void
    {
        config(['elastic_audit_metrics.capture.http.min_duration_ms' => 5000]);
        Bus::fake();
        Route::get('/metrics/fast', function () {
            DB::select('select 1 as fast');

            return response()->noContent();
        });

        $this->get('/metrics/fast')->assertNoContent();

        // The transaction is below the threshold and is never indexed. Keeping
        // its spans would leave documents whose transaction.id resolves to
        // nothing, which no dashboard lists but retention still pays for.
        Bus::assertNotDispatched(LogMetricBatchJob::class);
    }

    public function test_a_root_above_its_minimum_duration_keeps_its_spans(): void
    {
        config(['elastic_audit_metrics.capture.http.min_duration_ms' => 0]);
        Bus::fake();
        Route::get('/metrics/kept', function () {
            DB::select('select 1 as kept');

            return response()->noContent();
        });

        $this->get('/metrics/kept')->assertNoContent();

        Bus::assertDispatched(LogMetricBatchJob::class, static function (LogMetricBatchJob $batch): bool {
            $kinds = [];

            foreach ($batch->items as $metric) {
                $kinds[$metric->kind] = ($kinds[$metric->kind] ?? 0) + 1;
            }

            return ($kinds[MetricData::KIND_TRANSACTION] ?? 0) === 1
                && ($kinds[MetricData::KIND_SPAN] ?? 0) >= 1;
        });
    }

    public function test_repl_and_test_suite_entrypoints_are_not_timed_by_default(): void
    {
        Bus::fake();

        $output = new BufferedOutput;

        // `php artisan test` times the whole suite as a single transaction and
        // tinker times a human sitting at a REPL. Both dominate latency
        // percentiles and describe nothing about the application.
        foreach (['tinker', 'test', 'dusk', 'serve', 'pail'] as $command) {
            $input = new ArrayInput([]);
            Event::dispatch(new CommandStarting($command, $input, $output));
            DB::select('select 1 as during_excluded_command');
            Event::dispatch(new CommandFinished($command, $input, $output, 0));
        }

        Bus::assertNotDispatched(LogMetricBatchJob::class);
    }

    public function test_ordinary_console_commands_are_still_timed(): void
    {
        Bus::fake();

        $input  = new ArrayInput([]);
        $output = new BufferedOutput;

        Event::dispatch(new CommandStarting('orders:reconcile', $input, $output));
        Event::dispatch(new CommandFinished('orders:reconcile', $input, $output, 0));

        Bus::assertDispatched(LogMetricBatchJob::class, static function (LogMetricBatchJob $batch): bool {
            foreach ($batch->items as $metric) {
                if ($metric->type === MetricData::TYPE_CONSOLE_COMMAND
                    && $metric->console['command'] === 'orders:reconcile') {
                    return true;
                }
            }

            return false;
        });
    }

    public function test_measured_code_is_recorded_as_a_span_inside_the_surrounding_transaction(): void
    {
        Bus::fake();
        Route::get('/metrics/checkout', function () {
            return Performance::measure('checkout.totals', function () {
                DB::select('select 1 as inside_measured_block');

                return Performance::measure('checkout.tax', fn (): string => 'ok');
            });
        })->name('metrics.checkout');

        $this->get('/metrics/checkout')->assertOk()->assertSee('ok');

        Bus::assertDispatched(LogMetricBatchJob::class, function (LogMetricBatchJob $batch): bool {
            $byName = [];

            foreach ($batch->items as $metric) {
                $byName[$metric->name] = $metric;
            }

            if (! isset($byName['checkout.totals'], $byName['checkout.tax'], $byName['GET metrics.checkout'])) {
                return false;
            }

            $root  = $byName['GET metrics.checkout'];
            $outer = $byName['checkout.totals'];
            $inner = $byName['checkout.tax'];

            return $outer->kind === MetricData::KIND_SPAN
                && $outer->type === MetricData::TYPE_APP_FUNCTION
                && $inner->kind === MetricData::KIND_SPAN
                // Both belong to the request's transaction...
                && $outer->transactionId === $root->transactionId
                && $inner->transactionId === $root->transactionId
                // ...and nest: outer under the request, inner under outer.
                && $outer->parentSpanId === $root->spanId
                && $inner->parentSpanId === $outer->spanId
                && $outer->durationMs >= $inner->durationMs;
        });
    }

    public function test_measure_passes_the_return_value_through_and_rethrows(): void
    {
        Bus::fake();

        $this->assertSame(42, Performance::measure('answer', fn (): int => 42));

        try {
            Performance::measure('boom', function (): void {
                throw new RuntimeException('original');
            });
            $this->fail('measure() must not swallow the exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('original', $exception->getMessage());
        }

        Bus::assertDispatched(LogMetricBatchJob::class, static function (LogMetricBatchJob $batch): bool {
            foreach ($batch->items as $metric) {
                if ($metric->name === 'boom') {
                    return $metric->outcome === MetricData::OUTCOME_FAILURE;
                }
            }

            return false;
        });
    }

    public function test_measured_spans_can_be_disabled_like_any_other_category(): void
    {
        config(['elastic_audit_metrics.capture.functions.enabled' => false]);
        Bus::fake();

        $this->assertSame('still runs', Performance::measure('disabled', fn (): string => 'still runs'));

        Bus::assertNotDispatched(LogMetricBatchJob::class);
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('elastic_audit_metrics.enabled', true);
        $app['config']->set('elastic_audit_metrics.profiles.enabled', false);
    }
}
