<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ProfileData;
use Tsitsishvili\ElasticAudit\Events\AuditOperationFailed;
use Tsitsishvili\ElasticAudit\Services\ProfileIndexer;
use Tsitsishvili\ElasticAudit\Support\AuditFailureReporter;
use Tsitsishvili\ElasticAudit\Support\LogJobOptions;

final class LogProfileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 120];

    public int $timeout = 60;

    public function __construct(
        public readonly ProfileData $profile,
    ) {
        $this->queue   = config('elastic_audit_metrics.profiles.queue', 'default');
        $this->tries   = LogJobOptions::tries('elastic_audit_metrics.profiles.job');
        $this->backoff = LogJobOptions::backoff('elastic_audit_metrics.profiles.job');
        $this->timeout = LogJobOptions::timeout('elastic_audit_metrics.profiles.job', 60);
        $this->beforeCommit();
    }

    public function handle(ProfileIndexer $indexer): void
    {
        $indexer->index($this->profile);
    }

    public function failed(Throwable $exception): void
    {
        AuditFailureReporter::reportUsingContainer(
            subsystem: AuditOperationFailed::SUBSYSTEM_METRICS,
            stage: AuditOperationFailed::STAGE_INDEXING,
            exception: $exception,
            context: ['event_count' => 1, 'artifact' => 'profile'],
            logMessage: 'LogProfileJob failed',
        );
    }
}
