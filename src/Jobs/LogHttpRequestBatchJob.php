<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogData;
use Tsitsishvili\ElasticAudit\Services\HttpLogIndexer;
use Tsitsishvili\ElasticAudit\Support\LogJobOptions;
use Throwable;

class LogHttpRequestBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 120];

    public int $timeout = 60;

    /**
     * @param list<HttpLogData> $items
     */
    public function __construct(
        public readonly array $items,
    ) {
        $this->queue = config('http_logs.queue', 'default');
        $this->tries = LogJobOptions::tries('http_logs.job');
        $this->backoff = LogJobOptions::backoff('http_logs.job');
        $this->timeout = LogJobOptions::timeout('http_logs.job.batch', (int) config('http_logs.job.batch_timeout', 60));
    }

    public function handle(HttpLogIndexer $indexer): void
    {
        $indexer->bulk($this->items);
    }

    public function failed(Throwable $e): void
    {
        Log::error('LogHttpRequestBatchJob failed', [
            'count' => count($this->items),
            'error' => $e->getMessage(),
        ]);
    }
}
