<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogContext;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogData;
use Tsitsishvili\ElasticAudit\Services\ActivityLogIndexer;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;

class ActivityLogIndexerTest extends TestCase
{
    private const WRITE_ALIAS = 'app_activity_logs_write';

    private MockObject&LogElasticsearchClientInterface $client;

    private ActivityLogIndexer $indexer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client  = $this->createMock(LogElasticsearchClientInterface::class);
        $this->indexer = new ActivityLogIndexer($this->client, self::WRITE_ALIAS);
    }

    public function test_indexes_to_write_alias(): void
    {
        $this->client
            ->expects($this->once())
            ->method('index')
            ->with($this->callback(fn (array $p) => $p['index'] === self::WRITE_ALIAS));

        $this->indexer->index($this->makeData());
    }

    public function test_document_id_is_sha256_of_event_id(): void
    {
        $data       = $this->makeData();
        $expectedId = hash('sha256', $data->eventId);

        $this->client
            ->expects($this->once())
            ->method('index')
            ->with($this->callback(fn (array $p) => $p['id'] === $expectedId));

        $this->indexer->index($data);
    }

    public function test_document_body_contains_all_required_fields(): void
    {
        $captured = null;

        $this->client
            ->expects($this->once())
            ->method('index')
            ->with($this->callback(function (array $p) use (&$captured) {
                $captured = $p['body'];
                return true;
            }));

        $this->indexer->index($this->makeData());

        $this->assertArrayHasKey('@timestamp', $captured);
        $this->assertArrayHasKey('event_id', $captured);
        $this->assertArrayHasKey('actor', $captured);
        $this->assertArrayHasKey('action', $captured);
        $this->assertArrayHasKey('entity', $captured);
        $this->assertArrayHasKey('changes', $captured);
        $this->assertArrayHasKey('metadata', $captured);
        $this->assertArrayHasKey('success', $captured);
        $this->assertArrayHasKey('error', $captured);
        $this->assertArrayHasKey('retention_days', $captured);
        $this->assertSame(ActivityLogData::SCHEMA_VERSION, $captured['schema_version']);
    }

    public function test_actor_fields_are_mapped_correctly(): void
    {
        $data     = $this->makeData();
        $captured = null;

        $this->client->method('index')->with($this->callback(function (array $p) use (&$captured) {
            $captured = $p['body'];
            return true;
        }));

        $this->indexer->index($data);

        $this->assertSame('user', $captured['actor']['type']);
        $this->assertSame('42', $captured['actor']['id']);
    }

    public function test_uuid_actor_id_is_indexed_as_keyword_string(): void
    {
        $captured = null;
        $data     = $this->makeData('550e8400-e29b-41d4-a716-446655440000');

        $this->client->method('index')->with($this->callback(function (array $p) use (&$captured) {
            $captured = $p['body'];
            return true;
        }));

        $this->indexer->index($data);

        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $captured['actor']['id']);
    }

    public function test_bulk_indexes_multiple_documents_with_single_bulk_call(): void
    {
        $captured = null;

        $this->client
            ->expects($this->once())
            ->method('bulk')
            ->with($this->callback(function (array $p) use (&$captured) {
                $captured = $p['body'];
                return true;
            }));

        $this->indexer->bulk([$this->makeData(), $this->makeData()]);

        $this->assertCount(4, $captured);
        $this->assertSame(self::WRITE_ALIAS, $captured[0]['index']['_index']);
    }

    private function makeData(int|string|null $actorId = 42): ActivityLogData
    {
        $context = ActivityLogContext::forActor(
            actorType: 'user',
            actorId: $actorId,
            entityType: 'order',
            entityId: '7',
            requestId: 'req-123',
        );

        return ActivityLogData::make(
            action: 'order.updated',
            context: $context,
            changes: ['status' => ['old' => 'pending', 'new' => 'paid']],
        );
    }
}
