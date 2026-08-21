<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tsitsishvili\ElasticAudit\DataTransferObjects\MetricData;
use Tsitsishvili\ElasticAudit\Events\AuditOperationFailed;
use Tsitsishvili\ElasticAudit\Jobs\LogMetricBatchJob;
use Tsitsishvili\ElasticAudit\Services\MetricsIndexer;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class LogMetricBatchJobTest extends TestCase
{
    public function test_handle_bulk_indexes_individual_metrics_and_uses_queue_options(): void
    {
        config([
            'elastic_audit_metrics.queue'             => 'telemetry',
            'elastic_audit_metrics.job.tries'         => 5,
            'elastic_audit_metrics.job.backoff'       => [1, 5],
            'elastic_audit_metrics.job.batch_timeout' => 20,
        ]);
        $items   = [$this->makeData(), $this->makeData()];
        $indexer = $this->createMock(MetricsIndexer::class);
        $indexer->expects($this->once())->method('bulk')->with($items);

        $job = new LogMetricBatchJob($items);
        $job->handle($indexer);

        $this->assertSame('telemetry', $job->queue);
        $this->assertSame(5, $job->tries);
        $this->assertSame([1, 5], $job->backoff);
        $this->assertSame(20, $job->timeout);
        $this->assertFalse($job->afterCommit);
    }

    public function test_terminal_failure_emits_sanitized_observability_event(): void
    {
        Event::fake([AuditOperationFailed::class]);

        (new LogMetricBatchJob([$this->makeData()]))->failed(new RuntimeException('metrics index failed'));

        Event::assertDispatched(
            AuditOperationFailed::class,
            fn (AuditOperationFailed $event): bool => $event->subsystem === AuditOperationFailed::SUBSYSTEM_METRICS
                && $event->stage === AuditOperationFailed::STAGE_INDEXING
                && $event->context === ['event_count' => 1],
        );
    }

    private function makeData(): MetricData
    {
        return MetricData::make(
            type: MetricData::TYPE_DB_QUERY,
            name: 'select mysql',
            outcome: MetricData::OUTCOME_SUCCESS,
            durationMs: 1.25,
        );
    }
}
