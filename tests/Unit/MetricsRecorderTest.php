<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use Fiber;
use Illuminate\Support\Facades\Bus;
use Tsitsishvili\ElasticAudit\Contracts\FunctionProfiler;
use Tsitsishvili\ElasticAudit\DataTransferObjects\CapturedProfile;
use Tsitsishvili\ElasticAudit\DataTransferObjects\MetricData;
use Tsitsishvili\ElasticAudit\Jobs\LogMetricBatchJob;
use Tsitsishvili\ElasticAudit\Jobs\LogProfileJob;
use Tsitsishvili\ElasticAudit\Services\MetricsRecorder;
use Tsitsishvili\ElasticAudit\Support\AuditSourceResolver;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class MetricsRecorderTest extends TestCase
{
    public function test_disabled_recorder_is_a_no_op(): void
    {
        Bus::fake();
        $recorder = new MetricsRecorder(config: ['enabled' => false]);

        $token = $recorder->begin('http', MetricData::TYPE_HTTP_SERVER, 'GET /');
        $recorder->record(
            'queries',
            MetricData::TYPE_DB_QUERY,
            'select sqlite',
            MetricData::OUTCOME_SUCCESS,
            1.0,
        );

        $this->assertNull($token);
        Bus::assertNotDispatched(LogMetricBatchJob::class);
    }

    public function test_context_batches_individual_child_and_root_documents_with_one_trace(): void
    {
        Bus::fake();
        $recorder = new MetricsRecorder(
            config: $this->enabledConfig(),
            sourceResolver: $this->app->make(AuditSourceResolver::class),
        );
        $token = $recorder->begin('http', MetricData::TYPE_HTTP_SERVER, 'GET orders/{order}');

        $recorder->record(
            category: 'queries',
            type: MetricData::TYPE_DB_QUERY,
            name: 'select sqlite',
            outcome: MetricData::OUTCOME_SUCCESS,
            durationMs: 2.5,
            db: ['operation' => 'select'],
        );
        $recorder->record(
            category: 'outgoing_http',
            type: MetricData::TYPE_HTTP_CLIENT,
            name: 'GET api.example.test/orders/{id}',
            outcome: MetricData::OUTCOME_SUCCESS,
            durationMs: 8.0,
            http: ['method' => 'GET', 'host' => 'api.example.test'],
        );
        $recorder->finish($token, 'GET orders/{order}', MetricData::OUTCOME_SUCCESS);

        Bus::assertDispatched(LogMetricBatchJob::class, function (LogMetricBatchJob $job): bool {
            if (count($job->items) !== 3) {
                return false;
            }

            $root  = $job->items[2];
            $types = array_map(static fn (MetricData $metric): string => $metric->type, $job->items);

            return $types === [
                MetricData::TYPE_DB_QUERY,
                MetricData::TYPE_HTTP_CLIENT,
                MetricData::TYPE_HTTP_SERVER,
            ]
                && $job->items[0]->traceId === $root->traceId
                && $job->items[0]->parentSpanId === $root->spanId;
        });
    }

    public function test_suppression_prevents_metrics_queue_recursion(): void
    {
        Bus::fake();
        $recorder = new MetricsRecorder(config: $this->enabledConfig());
        $recorder->suppress();

        $recorder->record(
            'queries',
            MetricData::TYPE_DB_QUERY,
            'insert database_queue',
            MetricData::OUTCOME_SUCCESS,
            1.0,
        );
        $recorder->resume();

        Bus::assertNotDispatched(LogMetricBatchJob::class);
    }

    public function test_independent_root_flushes_before_an_existing_context_finishes(): void
    {
        Bus::fake();
        $recorder = new MetricsRecorder(
            config: $this->enabledConfig(),
            sourceResolver: $this->app->make(AuditSourceResolver::class),
        );
        $command = $recorder->begin('commands', MetricData::TYPE_CONSOLE_COMMAND, 'queue:work');
        $job     = $recorder->begin(
            'jobs',
            MetricData::TYPE_QUEUE_JOB,
            'App\\Jobs\\ImportOrders',
            independentRoot: true,
        );

        $recorder->record(
            category: 'queries',
            type: MetricData::TYPE_DB_QUERY,
            name: 'select sqlite',
            outcome: MetricData::OUTCOME_SUCCESS,
            durationMs: 1.0,
        );
        $recorder->finish($job, 'App\\Jobs\\ImportOrders', MetricData::OUTCOME_SUCCESS);

        Bus::assertDispatched(LogMetricBatchJob::class, function (LogMetricBatchJob $batch): bool {
            if (count($batch->items) !== 2) {
                return false;
            }

            [$query, $job] = $batch->items;

            return $query->type === MetricData::TYPE_DB_QUERY
                && $job->type === MetricData::TYPE_QUEUE_JOB
                && $query->traceId === $job->traceId
                && $query->parentSpanId === $job->spanId;
        });

        $recorder->finish($command, 'queue:work', MetricData::OUTCOME_SUCCESS);
    }

    public function test_automatic_timing_turns_application_frames_into_spans_without_any_wrapping(): void
    {
        Bus::fake();
        $profiler = $this->profilerReturning([
            ['function' => 'App\\Services\\OrderService::checkout', 'self_ms' => 4.0, 'total_ms' => 120.0, 'calls' => null],
            ['function' => 'App\\Repositories\\OrderRepository::save', 'self_ms' => 90.0, 'total_ms' => 90.0, 'calls' => null],
            // Framework and vendor frames dominate a real profile and must not
            // be reported as the application's own work.
            ['function' => 'Illuminate\\Database\\Connection::select', 'self_ms' => 80.0, 'total_ms' => 80.0, 'calls' => null],
            ['function' => 'Tsitsishvili\\ElasticAudit\\Services\\MetricsRecorder::finish', 'self_ms' => 5.0, 'total_ms' => 5.0, 'calls' => null],
            // Below the configured floor.
            ['function' => 'App\\Support\\Trivial::noop', 'self_ms' => 0.2, 'total_ms' => 0.2, 'calls' => null],
        ]);

        $config                         = $this->enabledConfig();
        $config['profiles']             = ['enabled' => true, 'sample_rate' => 1];
        $config['capture']['functions'] = [
            'enabled'                   => true,
            'automatic'                 => true,
            'automatic_limit'           => 20,
            'automatic_min_duration_ms' => 1.0,
            'namespaces'                => ['App\\'],
        ];
        $recorder = new MetricsRecorder(config: $config, profiler: $profiler);

        $token = $recorder->begin('http', MetricData::TYPE_HTTP_SERVER, 'GET orders');
        $recorder->finish($token, 'GET checkout', MetricData::OUTCOME_SUCCESS);

        Bus::assertDispatched(LogMetricBatchJob::class, function (LogMetricBatchJob $job): bool {
            $functions = [];
            $root      = null;

            foreach ($job->items as $metric) {
                if ($metric->type === MetricData::TYPE_APP_FUNCTION_PROFILED) {
                    $functions[$metric->name] = $metric;
                }

                if ($metric->kind === MetricData::KIND_TRANSACTION) {
                    $root = $metric;
                }
            }

            return $root !== null
                && array_keys($functions) === [
                    'App\\Services\\OrderService::checkout',
                    'App\\Repositories\\OrderRepository::save',
                ]
                && $functions['App\\Services\\OrderService::checkout']->parentSpanId === $root->spanId
                && $functions['App\\Services\\OrderService::checkout']->transactionId === $root->transactionId;
        });
    }

    public function test_automatic_timing_is_off_until_enabled(): void
    {
        Bus::fake();
        $profiler = $this->profilerReturning([
            ['function' => 'App\\Services\\OrderService::checkout', 'self_ms' => 4.0, 'total_ms' => 120.0, 'calls' => null],
        ]);

        $config             = $this->enabledConfig();
        $config['profiles'] = ['enabled' => true, 'sample_rate' => 1];
        $recorder           = new MetricsRecorder(config: $config, profiler: $profiler);

        $token = $recorder->begin('http', MetricData::TYPE_HTTP_SERVER, 'GET orders');
        $recorder->finish($token, 'GET orders', MetricData::OUTCOME_SUCCESS);

        Bus::assertDispatched(LogMetricBatchJob::class, static function (LogMetricBatchJob $job): bool {
            foreach ($job->items as $metric) {
                if ($metric->type === MetricData::TYPE_APP_FUNCTION_PROFILED) {
                    return false;
                }
            }

            return true;
        });
    }

    public function test_available_native_profiler_dispatches_a_separate_profile_linked_to_transaction(): void
    {
        Bus::fake();
        $profiler = new class implements FunctionProfiler
        {
            public bool $started = false;

            public function available(): bool
            {
                return true;
            }

            public function driver(): ?string
            {
                return 'fake-xhprof';
            }

            public function start(): bool
            {
                return $this->started = true;
            }

            public function stop(): ?CapturedProfile
            {
                $this->started = false;

                return new CapturedProfile(
                    driver: 'fake-xhprof',
                    mode: 'instrumentation',
                    format: 'xhprof-edges',
                    sampleRateHz: null,
                    sampleCount: 2,
                    payload: ['edges' => [['function' => 'App\\Services\\OrderService::run']]],
                    hotFrames: [[
                        'function'      => 'App\\Services\\OrderService::run',
                        'file'          => null,
                        'line'          => null,
                        'self_samples'  => 2,
                        'total_samples' => 2,
                    ]],
                );
            }
        };
        $config             = $this->enabledConfig();
        $config['profiles'] = ['enabled' => true, 'sample_rate' => 1];
        $recorder           = new MetricsRecorder(config: $config, profiler: $profiler);

        $token = $recorder->begin('http', MetricData::TYPE_HTTP_SERVER, 'GET orders');
        $this->assertTrue($profiler->started);
        $recorder->finish($token, 'GET orders', MetricData::OUTCOME_SUCCESS);
        $this->assertFalse($profiler->started);

        Bus::assertDispatched(LogProfileJob::class, function (LogProfileJob $job): bool {
            return $job->profile->driver === 'fake-xhprof'
                && $job->profile->sampleCount === 2;
        });
        Bus::assertDispatched(LogMetricBatchJob::class, function (LogMetricBatchJob $job): bool {
            $transaction = $job->items[0] ?? null;

            return $transaction?->kind === MetricData::KIND_TRANSACTION
                && $transaction->profileId !== null;
        });
    }

    public function test_fibers_keep_independent_trace_stacks(): void
    {
        Bus::fake();
        $recorder = new MetricsRecorder(
            config: $this->enabledConfig(),
            sourceResolver: $this->app->make(AuditSourceResolver::class),
        );
        $mainToken = $recorder->begin('http', MetricData::TYPE_HTTP_SERVER, 'GET main');
        $fiber     = new Fiber(function () use ($recorder): void {
            $token = $recorder->begin('jobs', MetricData::TYPE_QUEUE_JOB, 'FiberJob', independentRoot: true);
            $recorder->finish($token, 'FiberJob', MetricData::OUTCOME_SUCCESS);
        });
        $fiber->start();
        $recorder->finish($mainToken, 'GET main', MetricData::OUTCOME_SUCCESS);

        $traceIds = [];
        Bus::assertDispatched(LogMetricBatchJob::class, function (LogMetricBatchJob $job) use (&$traceIds): bool {
            $traceIds[] = $job->items[0]->traceId;

            return true;
        });

        $this->assertCount(2, $traceIds);
        $this->assertNotSame($traceIds[0], $traceIds[1]);
    }

    /** @param list<array{function: string, self_ms: float, total_ms: float, calls: ?int}> $timings */
    private function profilerReturning(array $timings): FunctionProfiler
    {
        return new class($timings) implements FunctionProfiler
        {
            /** @param list<array{function: string, self_ms: float, total_ms: float, calls: ?int}> $timings */
            public function __construct(private readonly array $timings) {}

            public function available(): bool
            {
                return true;
            }

            public function driver(): ?string
            {
                return 'fake-excimer';
            }

            public function start(): bool
            {
                return true;
            }

            public function stop(): ?CapturedProfile
            {
                return new CapturedProfile(
                    driver: 'fake-excimer',
                    mode: 'sampling',
                    format: 'speedscope',
                    sampleRateHz: 99.0,
                    sampleCount: 10,
                    payload: [],
                    hotFrames: [],
                    functionTimings: $this->timings,
                );
            }
        };
    }

    /** @return array<string, mixed> */
    private function enabledConfig(): array
    {
        return [
            'enabled'    => true,
            'batch_size' => 100,
            'capture'    => [
                'http'          => ['enabled' => true, 'sample_rate' => 1, 'min_duration_ms' => 0],
                'queries'       => ['enabled' => true, 'sample_rate' => 1, 'min_duration_ms' => 0],
                'outgoing_http' => ['enabled' => true, 'sample_rate' => 1, 'min_duration_ms' => 0],
            ],
            'profiles' => ['enabled' => false],
        ];
    }
}
