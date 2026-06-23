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

class LogActivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 120];

    public int $timeout = 30;

    public function __construct(
        public readonly ActivityLogData $data,
    ) {
        $this->queue = config('activity_logs.queue', 'default');
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
