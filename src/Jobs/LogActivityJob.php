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

class LogActivityJob implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 120];

    public int $timeout = 30;

    public function __construct(
        public readonly ActivityLogData $data,
    ) {
        $this->queue = config('activity_logs.queue', 'default');
        $this->tries = LogJobOptions::tries('activity_logs.job');
        $this->backoff = LogJobOptions::backoff('activity_logs.job');
        $this->timeout = LogJobOptions::timeout('activity_logs.job', 30);
    }

    public function handle(ActivityLogIndexer $indexer): void
    {
        $indexer->index($this->data);
    }

    public function failed(Throwable $e): void
    {
        Log::error('LogActivityJob failed', [
            'action'   => $this->data->action,
            'event_id' => $this->data->eventId,
            'error'    => $e->getMessage(),
        ]);
    }
}
