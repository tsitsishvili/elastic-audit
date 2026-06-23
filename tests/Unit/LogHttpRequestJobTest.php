<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use Tsitsishvili\ElasticAudit\DataTransferObjects\RedactedHttpPayload;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogContext;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogData;
use Tsitsishvili\ElasticAudit\Enums\HttpDirection;
use Tsitsishvili\ElasticAudit\Jobs\LogHttpRequestJob;
use Tsitsishvili\ElasticAudit\Services\HttpLogIndexer;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEntityType;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEventType;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestProvider;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class LogHttpRequestJobTest extends TestCase
{
    private LogHttpRequestJob $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->job = new LogHttpRequestJob($this->makeLogData());
    }

    public function test_handle_delegates_to_indexer(): void
    {
        $indexer = $this->createMock(HttpLogIndexer::class);
        $indexer->expects($this->once())->method('index');

        $this->job->handle($indexer);
    }

    public function test_job_uses_queue_from_config(): void
    {
        $this->assertSame('default', $this->job->queue);
    }

    public function test_job_has_correct_retry_policy(): void
    {
        $this->assertSame(3, $this->job->tries);
        $this->assertSame([10, 30, 120], $this->job->backoff);
        $this->assertSame(30, $this->job->timeout);
    }

    private function makeLogData(): HttpLogData
    {
        $empty   = new RedactedHttpPayload([], null, null, null, false);
        $context = HttpLogContext::forEntity(
            entityType: TestEntityType::Order,
            entityId: '1',
        );

        return HttpLogData::make(
            provider: TestProvider::Delivery,
            eventType: TestEventType::DeliveryOrderCreate,
            direction: HttpDirection::Outgoing,
            httpMethod: 'POST',
            httpUrl: 'https://delivery.example/orders',
            latencyMs: 50,
            context: $context,
            request: $empty,
            response: $empty,
        );
    }
}
