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
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tsitsishvili\ElasticAudit\DataTransferObjects\MetricData;
use Tsitsishvili\ElasticAudit\Jobs\LogMetricBatchJob;
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

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('elastic_audit_metrics.enabled', true);
        $app['config']->set('elastic_audit_metrics.profiles.enabled', false);
    }
}
