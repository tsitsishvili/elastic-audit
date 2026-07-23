<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogContext;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogData;
use Tsitsishvili\ElasticAudit\DataTransferObjects\RedactedHttpPayload;
use Tsitsishvili\ElasticAudit\Enums\HttpDirection;
use Tsitsishvili\ElasticAudit\Events\AuditOperationFailed;
use Tsitsishvili\ElasticAudit\Jobs\LogHttpRequestBatchJob;
use Tsitsishvili\ElasticAudit\Jobs\LogHttpRequestJob;
use Tsitsishvili\ElasticAudit\Services\HttpLogIndexer;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEntityType;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEventType;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestProvider;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class LogHttpRequestJobTest extends TestCase
{
    private LogHttpRequestJob $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->job = new LogHttpRequestJob($this->makeLogData());
    }

    public function test_handle_delegates_to_indexer(): void
    {
        $indexer = $this->createMock(HttpLogIndexer::class);
        $indexer->expects($this->once())->method('index');

        $this->job->handle($indexer);
    }

    public function test_job_uses_queue_from_config(): void
    {
        $this->assertSame('default', $this->job->queue);
    }

    public function test_job_has_correct_retry_policy(): void
    {
        $this->assertSame(3, $this->job->tries);
        $this->assertSame([10, 30, 120], $this->job->backoff);
        $this->assertSame(30, $this->job->timeout);
    }

    public function test_job_uses_retry_policy_from_config(): void
    {
        config([
            'http_logs.job.tries'         => '5',
            'http_logs.job.backoff'       => '1,5,20',
            'http_logs.job.timeout'       => '45',
            'http_logs.job.batch_timeout' => '90',
        ]);

        $job      = new LogHttpRequestJob($this->makeLogData());
        $batchJob = new LogHttpRequestBatchJob([$this->makeLogData()]);

        $this->assertSame(5, $job->tries);
        $this->assertSame([1, 5, 20], $job->backoff);
        $this->assertSame(45, $job->timeout);
        $this->assertSame(5, $batchJob->tries);
        $this->assertSame([1, 5, 20], $batchJob->backoff);
        $this->assertSame(90, $batchJob->timeout);
    }

    public function test_invalid_non_integer_retry_values_use_safe_defaults_for_single_and_batch_jobs(): void
    {
        config([
            'http_logs.job.tries'         => 5.0,
            'http_logs.job.backoff'       => [1, 2.5, true, null, ' 4 ', '10.5'],
            'http_logs.job.timeout'       => '10.5',
            'http_logs.job.batch_timeout' => new \stdClass,
        ]);

        $job      = new LogHttpRequestJob($this->makeLogData());
        $batchJob = new LogHttpRequestBatchJob([$this->makeLogData()]);

        $this->assertSame(3, $job->tries);
        $this->assertSame([1, 4], $job->backoff);
        $this->assertSame(30, $job->timeout);
        $this->assertSame(3, $batchJob->tries);
        $this->assertSame([1, 4], $batchJob->backoff);
        $this->assertSame(60, $batchJob->timeout);
    }

    public function test_failed_log_includes_event_id(): void
    {
        $data = $this->makeLogData();
        Event::fake([AuditOperationFailed::class]);

        Log::shouldReceive('error')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'LogHttpRequestJob failed'
                && $context['subsystem'] === AuditOperationFailed::SUBSYSTEM_HTTP
                && $context['stage'] === AuditOperationFailed::STAGE_INDEXING
                && $context['event_id'] === $data->eventId
                && $context['request_id'] === $data->requestId
                && $context['error'] === 'indexing failed',
        );

        (new LogHttpRequestJob($data))->failed(new RuntimeException('indexing failed'));

        Event::assertDispatched(
            AuditOperationFailed::class,
            fn (AuditOperationFailed $event): bool => $event->context['event_id'] === $data->eventId,
        );
    }

    public function test_terminal_batch_failure_dispatches_observability_event(): void
    {
        Event::fake([AuditOperationFailed::class]);

        (new LogHttpRequestBatchJob([$this->makeLogData()]))->failed(new RuntimeException('indexing failed'));

        Event::assertDispatched(
            AuditOperationFailed::class,
            fn (AuditOperationFailed $event): bool => $event->subsystem === AuditOperationFailed::SUBSYSTEM_HTTP
                && $event->stage === AuditOperationFailed::STAGE_INDEXING
                && $event->context['count'] === 1,
        );
    }

    private function makeLogData(): HttpLogData
    {
        $empty   = new RedactedHttpPayload([], null, null, null, false);
        $context = HttpLogContext::forEntity(
            entityType: TestEntityType::Order,
            entityId: '1',
        );

        return HttpLogData::make(
            provider: TestProvider::Delivery,
            eventType: TestEventType::DeliveryOrderCreate,
            direction: HttpDirection::Outgoing,
            httpMethod: 'POST',
            httpUrl: 'https://delivery.example/orders',
            latencyMs: 50,
            context: $context,
            request: $empty,
            response: $empty,
        );
    }
}
