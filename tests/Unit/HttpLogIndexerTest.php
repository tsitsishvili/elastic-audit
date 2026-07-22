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

    public function test_generates_deterministic_id_from_event_id(): void
    {
        $data       = $this->makeLogData();
        $expectedId = hash('sha256', $data->eventId);

        $this->logClient
            ->expects($this->once())
            ->method('index')
            ->with($this->callback(fn (array $p) => $p['id'] === $expectedId));

        $this->indexer->index($data, 2);
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

    public function test_integer_user_id_is_indexed_as_keyword_string(): void
    {
        $captured = null;

        $this->logClient
            ->expects($this->once())
            ->method('index')
            ->with($this->callback(function (array $p) use (&$captured): bool {
                $captured = $p['body'];

                return true;
            }));

        $this->indexer->index($this->makeLogData(userId: 42));

        $this->assertSame('42', $captured['user_id']);
    }

    public function test_uuid_user_id_is_indexed_as_keyword_string(): void
    {
        $captured = null;

        $this->logClient
            ->expects($this->once())
            ->method('index')
            ->with($this->callback(function (array $p) use (&$captured): bool {
                $captured = $p['body'];

                return true;
            }));

        $this->indexer->index($this->makeLogData(userId: '550e8400-e29b-41d4-a716-446655440000'));

        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $captured['user_id']);
    }

    public function test_permanent_document_has_no_indexed_retention_value(): void
    {
        $captured = null;

        $this->logClient
            ->expects($this->once())
            ->method('index')
            ->with($this->callback(function (array $params) use (&$captured): bool {
                $captured = $params['body'];

                return true;
            }));

        $this->indexer->index($this->makeLogData(retainForever: true));

        $this->assertArrayHasKey('retention_days', $captured);
        $this->assertNull($captured['retention_days']);
    }

    public function test_bulk_indexes_multiple_documents_with_single_bulk_call(): void
    {
        $captured = null;

        $this->logClient
            ->expects($this->once())
            ->method('bulk')
            ->with($this->callback(function (array $p) use (&$captured): bool {
                $captured = $p['body'];

                return true;
            }));

        $this->indexer->bulk([$this->makeLogData(), $this->makeLogData()]);

        $this->assertCount(4, $captured);
        $this->assertSame(self::WRITE_ALIAS, $captured[0]['index']['_index']);
    }

    private function makeLogData(
        bool $timedOut = false,
        int|string|null $userId = null,
        bool $retainForever = false,
    ): HttpLogData {
        $empty   = new RedactedHttpPayload([], null, null, null, false);
        $context = HttpLogContext::forEntity(
            entityType: TestEntityType::Order,
            entityId: '123',
            userId: $userId,
            retainForever: $retainForever,
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
