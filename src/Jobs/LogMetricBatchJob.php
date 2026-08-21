<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;
use Tsitsishvili\ElasticAudit\DataTransferObjects\MetricData;
use Tsitsishvili\ElasticAudit\Events\AuditOperationFailed;
use Tsitsishvili\ElasticAudit\Services\MetricsIndexer;
use Tsitsishvili\ElasticAudit\Support\AuditFailureReporter;
use Tsitsishvili\ElasticAudit\Support\LogJobOptions;

class LogMetricBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 120];

    public int $timeout = 30;

    /** @param list<MetricData> $items */
    public function __construct(
        public readonly array $items,
    ) {
        $this->queue   = config('elastic_audit_metrics.queue', 'default');
        $this->tries   = LogJobOptions::tries('elastic_audit_metrics.job');
        $this->backoff = LogJobOptions::backoff('elastic_audit_metrics.job');
        $this->timeout = LogJobOptions::batchTimeout('elastic_audit_metrics.job');

        // Dispatch while MetricsRecorder's suppression guard is active, even
        // when the application's queue connection defaults to after_commit.
        $this->beforeCommit();
    }

    public function handle(MetricsIndexer $indexer): void
    {
        $indexer->bulk($this->items);
    }

    public function failed(Throwable $exception): void
    {
        AuditFailureReporter::reportUsingContainer(
            subsystem: AuditOperationFailed::SUBSYSTEM_METRICS,
            stage: AuditOperationFailed::STAGE_INDEXING,
            exception: $exception,
            context: ['event_count' => count($this->items)],
            logMessage: 'LogMetricBatchJob failed',
        );
    }
}
