<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogData;
use Tsitsishvili\ElasticAudit\Services\ActivityLogIndexer;
use Throwable;

class LogActivityBatchJob implements ShouldQueue
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
