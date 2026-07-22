<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogData;
use Tsitsishvili\ElasticAudit\Services\ActivityLogIndexer;
use Tsitsishvili\ElasticAudit\Support\LogJobOptions;
use Throwable;

class LogActivityBatchJob implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 120];

    public int $timeout = 60;

    /**
     * @param list<ActivityLogData> $items
     */
    public function __construct(
        public readonly array $items,
    ) {
        $this->queue = config('activity_logs.queue', 'default');
        $this->tries = LogJobOptions::tries('activity_logs.job');
        $this->backoff = LogJobOptions::backoff('activity_logs.job');
        $this->timeout = LogJobOptions::timeout('activity_logs.job.batch', (int) config('activity_logs.job.batch_timeout', 60));
    }

    public function handle(ActivityLogIndexer $indexer): void
    {
        $indexer->bulk($this->items);
    }

    public function failed(Throwable $e): void
    {
        Log::error('LogActivityBatchJob failed', [
            'count' => count($this->items),
            'error' => $e->getMessage(),
        ]);
    }
}
