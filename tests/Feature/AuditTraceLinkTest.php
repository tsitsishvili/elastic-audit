<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogContext;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogData;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogContext;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogData;
use Tsitsishvili\ElasticAudit\DataTransferObjects\MetricData;
use Tsitsishvili\ElasticAudit\DataTransferObjects\RedactedHttpPayload;
use Tsitsishvili\ElasticAudit\Enums\HttpDirection;
use Tsitsishvili\ElasticAudit\Http\Middleware\IncomingHttpLogMiddleware;
use Tsitsishvili\ElasticAudit\Jobs\LogHttpRequestJob;
use Tsitsishvili\ElasticAudit\Jobs\LogMetricBatchJob;
use Tsitsishvili\ElasticAudit\Services\MetricsRecorder;
use Tsitsishvili\ElasticAudit\Support\TraceContext;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEntityType;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEventType;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestProvider;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class AuditTraceLinkTest extends TestCase
{
    public function test_activity_log_data_adopts_the_active_metrics_trace(): void
    {
        $trace = $this->beginTransaction();

        $data = ActivityLogData::make('order.updated', ActivityLogContext::forActor(
            actorType: 'user',
            actorId: 1,
            entityType: 'order',
            entityId: '1',
        ));

        $this->assertSame($trace->traceId, $data->traceId);
        $this->assertSame($trace->spanId, $data->spanId);
    }

    public function test_http_log_data_adopts_the_active_metrics_trace(): void
    {
        $trace = $this->beginTransaction();

        $data = $this->httpLogData();

        $this->assertSame($trace->traceId, $data->traceId);
        $this->assertSame($trace->spanId, $data->spanId);
    }

    public function test_audit_data_has_no_trace_when_metrics_are_disabled(): void
    {
        config(['elastic_audit_metrics.enabled' => false]);

        $this->assertNull($this->httpLogData()->traceId);
        $this->assertNull(ActivityLogData::make('order.updated', ActivityLogContext::forActor(
            actorType: 'user',
            actorId: 1,
            entityType: 'order',
            entityId: '1',
        ))->traceId);
    }

    public function test_http_log_timestamp_has_milliseconds(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            $this->httpLogData()->timestamp,
        );
    }

    public function test_activity_log_timestamp_has_milliseconds(): void
    {
        $data = ActivityLogData::make('order.updated', ActivityLogContext::forActor(
            actorType: 'user',
            actorId: 1,
            entityType: 'order',
            entityId: '1',
        ));

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            $data->timestamp,
        );
    }

    #[DefineEnvironment('enableMetrics')]
    public function test_incoming_callback_log_carries_the_requests_metrics_trace(): void
    {
        Bus::fake();
        Route::post('/_test/traced-callback', function () {
            request()->attributes->set('third_party_provider', TestProvider::Delivery->value);
            request()->attributes->set('third_party_event_type', TestEventType::DeliveryStatusCallback->value);
            request()->attributes->set('third_party_entity_type', TestEntityType::Order->value);

            return response()->json(['received' => true]);
        })->middleware(IncomingHttpLogMiddleware::class);

        $this->postJson('/_test/traced-callback')->assertOk();

        // The callback is logged in terminate(); it must still see the request's transaction.
        $root = null;
        Bus::assertDispatched(LogMetricBatchJob::class, function (LogMetricBatchJob $job) use (&$root): bool {
            $root = collect($job->items)->first(fn (MetricData $m): bool => $m->type === MetricData::TYPE_HTTP_SERVER);

            return $root !== null;
        });
        Bus::assertDispatched(LogHttpRequestJob::class, fn (LogHttpRequestJob $job): bool => $job->data->direction === HttpDirection::Incoming
            && $job->data->traceId === $root->traceId
            && $job->data->spanId === $root->spanId);
    }

    protected function enableMetrics($app): void
    {
        $app['config']->set('elastic_audit_metrics.enabled', true);
        $app['config']->set('elastic_audit_metrics.profiles.enabled', false);
        $app['config']->set('http_logs.enabled', true);
    }

    private function beginTransaction(): TraceContext
    {
        Bus::fake();
        config(['elastic_audit_metrics.enabled' => true]);

        $recorder = app(MetricsRecorder::class);
        $this->assertNotNull($recorder->begin('http', MetricData::TYPE_HTTP_SERVER, 'GET /orders'));

        $trace = $recorder->propagationContext();
        $this->assertNotNull($trace);

        return $trace;
    }

    private function httpLogData(): HttpLogData
    {
        $empty = new RedactedHttpPayload([], null, null, null, false);

        return HttpLogData::make(
            provider: TestProvider::Delivery,
            eventType: TestEventType::DeliveryOrderCreate,
            direction: HttpDirection::Outgoing,
            httpMethod: 'POST',
            httpUrl: 'https://delivery.example/orders',
            latencyMs: 10,
            context: HttpLogContext::forEntity(entityType: TestEntityType::Order, entityId: '1'),
            request: $empty,
            response: $empty,
        );
    }
}
