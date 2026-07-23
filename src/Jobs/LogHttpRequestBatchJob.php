<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogData;
use Tsitsishvili\ElasticAudit\Events\AuditOperationFailed;
use Tsitsishvili\ElasticAudit\Services\HttpLogIndexer;
use Tsitsishvili\ElasticAudit\Support\AuditFailureReporter;
use Tsitsishvili\ElasticAudit\Support\LogJobOptions;

class LogHttpRequestBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 120];

    public int $timeout = 60;

    /**
     * @param  list<HttpLogData>  $items
     */
    public function __construct(
        public readonly array $items,
    ) {
        $this->queue   = config('http_logs.queue', 'default');
        $this->tries   = LogJobOptions::tries('http_logs.job');
        $this->backoff = LogJobOptions::backoff('http_logs.job');
        $this->timeout = LogJobOptions::batchTimeout('http_logs.job');
    }

    public function handle(HttpLogIndexer $indexer): void
    {
        $indexer->bulk($this->items);
    }

    public function failed(Throwable $e): void
    {
        AuditFailureReporter::reportUsingContainer(
            subsystem: AuditOperationFailed::SUBSYSTEM_HTTP,
            stage: AuditOperationFailed::STAGE_INDEXING,
            exception: $e,
            context: ['count' => count($this->items)],
            logMessage: 'LogHttpRequestBatchJob failed',
        );
    }
}
