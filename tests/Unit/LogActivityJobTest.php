<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogContext;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogData;
use Tsitsishvili\ElasticAudit\Jobs\LogActivityBatchJob;
use Tsitsishvili\ElasticAudit\Jobs\LogActivityJob;
use Tsitsishvili\ElasticAudit\Services\ActivityLogIndexer;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class LogActivityJobTest extends TestCase
{
    public function test_handle_calls_indexer_with_data(): void
    {
        $data    = $this->makeData();
        $indexer = $this->createMock(ActivityLogIndexer::class);

        $indexer->expects($this->once())->method('index')->with($data);

        (new LogActivityJob($data))->handle($indexer);
    }

    public function test_job_uses_configured_queue(): void
    {
        config(['activity_logs.queue' => 'high-priority']);

        $job = new LogActivityJob($this->makeData());

        $this->assertSame('high-priority', $job->queue);
    }

    public function test_job_uses_retry_policy_from_config(): void
    {
        config([
            'activity_logs.job.tries' => 4,
            'activity_logs.job.backoff' => [2, 10, 30],
            'activity_logs.job.timeout' => 40,
            'activity_logs.job.batch_timeout' => 100,
        ]);

        $job = new LogActivityJob($this->makeData());
        $batchJob = new LogActivityBatchJob([$this->makeData()]);

        $this->assertSame(4, $job->tries);
        $this->assertSame([2, 10, 30], $job->backoff);
        $this->assertSame(40, $job->timeout);
        $this->assertSame(4, $batchJob->tries);
        $this->assertSame([2, 10, 30], $batchJob->backoff);
        $this->assertSame(100, $batchJob->timeout);
    }

    private function makeData(): ActivityLogData
    {
        $context = ActivityLogContext::forActor(
            actorType: 'user',
            actorId: 1,
            entityType: 'order',
            entityId: '1',
        );

        return ActivityLogData::make(action: 'order.created', context: $context);
    }
}
