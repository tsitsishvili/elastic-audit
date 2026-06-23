<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogContext;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogData;
use Tsitsishvili\ElasticAudit\Jobs\LogActivityJob;
use Tsitsishvili\ElasticAudit\Services\ActivityLogIndexer;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEntityType;
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

    private function makeData(): ActivityLogData
    {
        $context = ActivityLogContext::forActor(
            actorType: 'user',
            actorId: 1,
            entityType: TestEntityType::Order,
            entityId: '1',
        );

        return ActivityLogData::make(action: 'order.created', context: $context);
    }
}
