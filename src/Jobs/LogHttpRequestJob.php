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
use Throwable;

class LogHttpRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 120];

    public int $timeout = 30;

    public function __construct(
        public readonly HttpLogData $data,
    ) {
        // Queue is configurable so different apps can route to their preferred worker queue
        $this->queue = config('http_logs.queue', 'default');
    }

    public function handle(HttpLogIndexer $indexer): void
    {
        $indexer->index($this->data, $this->attempts());
    }

    public function failed(Throwable $e): void
    {
        Log::error('LogHttpRequestJob failed', [
            'provider'   => $this->data->provider->getValue(),
            'event_type' => $this->data->eventType->getValue(),
            'request_id' => $this->data->requestId,
            'error'      => $e->getMessage(),
        ]);
    }
}
