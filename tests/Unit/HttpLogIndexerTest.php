<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use Tsitsishvili\ElasticAudit\DataTransferObjects\RedactedHttpPayload;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogContext;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogData;
use Tsitsishvili\ElasticAudit\Enums\HttpDirection;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Services\HttpLogIndexer;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEntityType;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEventType;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HttpLogIndexerTest extends TestCase
{
    private const WRITE_ALIAS = 'app_logs_http_logs_write';

    private MockObject&LogElasticsearchClientInterface $logClient;

    private HttpLogIndexer $indexer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logClient = $this->createMock(LogElasticsearchClientInterface::class);
        $this->indexer   = new HttpLogIndexer($this->logClient, self::WRITE_ALIAS);
    }

    public function test_indexes_to_write_alias(): void
    {
        $this->logClient
            ->expects($this->once())
            ->method('index')
            ->with($this->callback(fn (array $p) => $p['index'] === self::WRITE_ALIAS));

        $this->indexer->index($this->makeLogData());
    }

    public function test_generates_deterministic_id_from_request_id_and_attempt(): void
    {
        $data       = $this->makeLogData();
        $attempt    = 2;
        $expectedId = hash('sha256', $data->requestId . '|' . $attempt);

        $this->logClient
            ->expects($this->once())
            ->method('index')
            ->with($this->callback(fn (array $p) => $p['id'] === $expectedId));

        $this->indexer->index($data, $attempt);
    }

    public function test_document_includes_http_timed_out_flag(): void
    {
        $captured = null;

        $this->logClient
            ->expects($this->once())
            ->method('index')
            ->with($this->callback(function (array $p) use (&$captured): bool {
                $captured = $p['body'];

                return true;
            }));

        $this->indexer->index($this->makeLogData(timedOut: true));

        $this->assertTrue($captured['http']['timed_out']);
    }

    private function makeLogData(bool $timedOut = false): HttpLogData
    {
        $empty   = new RedactedHttpPayload([], null, null, null, false);
        $context = HttpLogContext::forEntity(
            entityType: TestEntityType::Order,
            entityId: '123',
        );

        return HttpLogData::make(
            provider: TestProvider::Delivery,
            eventType: TestEventType::DeliveryOrderCreate,
            direction: HttpDirection::Outgoing,
            httpMethod: 'POST',
            httpUrl: 'https://delivery.example/orders',
            latencyMs: 120,
            context: $context,
            request: $empty,
            response: $empty,
            httpStatusCode: 201,
            success: true,
            timedOut: $timedOut,
        );
    }
}
