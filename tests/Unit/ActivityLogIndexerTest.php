<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogContext;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogData;
use Tsitsishvili\ElasticAudit\DataTransferObjects\AuditSource;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ExecutionOrigin;
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

    public function test_uses_event_id_as_document_id(): void
    {
        $data = $this->makeData();

        $this->client
            ->expects($this->once())
            ->method('index')
            ->with($this->callback(fn (array $p) => $p['id'] === $data->eventId));

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
        $this->assertArrayHasKey('service', $captured);
        $this->assertArrayHasKey('execution', $captured);
        $this->assertArrayHasKey('actor', $captured);
        $this->assertArrayHasKey('action', $captured);
        $this->assertArrayHasKey('entity', $captured);
        $this->assertArrayHasKey('changes', $captured);
        $this->assertArrayHasKey('metadata', $captured);
        $this->assertArrayHasKey('success', $captured);
        $this->assertArrayHasKey('error', $captured);
        $this->assertArrayHasKey('retention_days', $captured);
        $this->assertSame(ActivityLogData::SCHEMA_VERSION, $captured['schema_version']);
        $this->assertSame('orders-api', $captured['service']['name']);
        $this->assertSame('testing', $captured['service']['environment']);
        $this->assertSame('queue', $captured['execution']['type']);
        $this->assertSame('App\\Jobs\\UpdateOrder', $captured['execution']['name']);
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

    public function test_indexes_legacy_queued_data_without_trace_properties(): void
    {
        $legacyData = $this->withoutTraceProperties($this->makeData());

        $this->client
            ->expects($this->once())
            ->method('index')
            ->with($this->callback(fn (array $p): bool => $p['body']['trace'] === [
                'id'          => null,
                'span_id'     => null,
                'traceparent' => null,
            ]));

        $this->indexer->index($legacyData);
    }

    public function test_indexes_legacy_queued_data_without_source_property(): void
    {
        $legacyData = $this->withoutProperties($this->makeData(), ['source']);

        $this->client
            ->expects($this->once())
            ->method('index')
            ->with($this->callback(fn (array $p): bool => $p['body']['service'] === [
                'name'        => null,
                'environment' => null,
            ] && $p['body']['execution'] === [
                'type'   => null,
                'name'   => null,
                'action' => null,
            ]));

        $this->indexer->index($legacyData);
    }

    public function test_permanent_document_has_no_indexed_retention_value(): void
    {
        $captured = null;

        $this->client
            ->expects($this->once())
            ->method('index')
            ->with($this->callback(function (array $params) use (&$captured): bool {
                $captured = $params['body'];

                return true;
            }));

        $this->indexer->index($this->makeData(retainForever: true));

        $this->assertArrayHasKey('retention_days', $captured);
        $this->assertNull($captured['retention_days']);
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

    private function makeData(int|string|null $actorId = 42, bool $retainForever = false): ActivityLogData
    {
        $context = ActivityLogContext::forActor(
            actorType: 'user',
            actorId: $actorId,
            entityType: 'order',
            entityId: '7',
            requestId: 'req-123',
            retainForever: $retainForever,
        );

        return ActivityLogData::make(
            action: 'order.updated',
            context: $context,
            changes: ['status' => ['old' => 'pending', 'new' => 'paid']],
            source: new AuditSource(
                serviceName: 'orders-api',
                serviceEnvironment: 'testing',
                execution: new ExecutionOrigin('queue', 'App\\Jobs\\UpdateOrder'),
            ),
        );
    }

    private function withoutTraceProperties(ActivityLogData $data): ActivityLogData
    {
        return $this->withoutProperties($data, ['traceId', 'spanId', 'traceParent']);
    }

    /**
     * @param  list<string>  $properties
     */
    private function withoutProperties(ActivityLogData $data, array $properties): ActivityLogData
    {
        $reflection = new ReflectionClass($data);
        $legacyData = $reflection->newInstanceWithoutConstructor();

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (in_array($property->getName(), $properties, true)) {
                continue;
            }

            $property->setValue($legacyData, $property->getValue($data));
        }

        return $legacyData;
    }
}
