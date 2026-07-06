<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use Elastic\Elasticsearch\Endpoints\Indices;
use Elastic\Elasticsearch\Endpoints\Ilm;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Elastic\Transport\Exception\NoNodeAvailableException;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClient;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\SpyElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Tests\TestCase;
use PHPUnit\Framework\MockObject\Stub;
use RuntimeException;

class LogElasticsearchClientTest extends TestCase
{
    private Stub&SpyElasticsearchClientInterface $esClient;

    private Stub&Indices $indices;

    private Stub&Elasticsearch $esResponse;

    private LogElasticsearchClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->esResponse = $this->createStub(Elasticsearch::class);
        $this->indices    = $this->createStub(Indices::class);
        $this->esClient   = $this->createStub(SpyElasticsearchClientInterface::class);
        $this->esClient->method('indices')->willReturn($this->indices);

        $this->client = new LogElasticsearchClient($this->esClient);
    }

    public function test_index_delegates_to_client(): void
    {
        $params   = ['index' => 'test', 'body' => ['foo' => 'bar']];
        $esClient = $this->createMock(SpyElasticsearchClientInterface::class);
        $esClient->method('indices')->willReturn($this->indices);
        $client = new LogElasticsearchClient($esClient);

        $esClient->expects($this->once())->method('index')->with($params);

        $client->index($params);
    }

    public function test_index_rethrows_exception_after_logging(): void
    {
        $this->esClient->method('index')->willThrowException(new RuntimeException('ES down'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ES down');

        $this->client->index([]);
    }

    public function test_bulk_delegates_to_client(): void
    {
        $params   = ['body' => []];
        $esClient = $this->createMock(SpyElasticsearchClientInterface::class);
        $esClient->method('indices')->willReturn($this->indices);
        $this->esResponse->method('asArray')->willReturn(['errors' => false, 'items' => []]);
        $esClient->method('bulk')->willReturn($this->esResponse);
        $client = new LogElasticsearchClient($esClient);

        $esClient->expects($this->once())->method('bulk')->with($params);

        $client->bulk($params);
    }

    public function test_bulk_rethrows_exception(): void
    {
        $this->esClient->method('bulk')->willThrowException(new RuntimeException('bulk failed'));

        $this->expectException(RuntimeException::class);

        $this->client->bulk([]);
    }

    public function test_bulk_throws_when_response_contains_item_errors(): void
    {
        $this->esResponse->method('asArray')->willReturn([
            'errors' => true,
            'items'  => [
                [
                    'index' => [
                        'status' => 400,
                        'error'  => [
                            'type'   => 'mapper_parsing_exception',
                            'reason' => 'failed to parse field',
                        ],
                    ],
                ],
            ],
        ]);
        $this->esClient->method('bulk')->willReturn($this->esResponse);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mapper_parsing_exception');

        $this->client->bulk(['body' => []]);
    }

    public function test_search_returns_array(): void
    {
        $expected = ['hits' => ['total' => 5]];

        $this->esResponse->method('asArray')->willReturn($expected);
        $this->esClient->method('search')->willReturn($this->esResponse);

        $this->assertSame($expected, $this->client->search([]));
    }

    public function test_search_rethrows_exception(): void
    {
        $this->esClient->method('search')->willThrowException(new RuntimeException('search failed'));

        $this->expectException(RuntimeException::class);

        $this->client->search([]);
    }

    public function test_delete_by_query_returns_array(): void
    {
        $expected = ['deleted' => 42];

        $this->esResponse->method('asArray')->willReturn($expected);
        $this->esClient->method('deleteByQuery')->willReturn($this->esResponse);

        $this->assertSame($expected, $this->client->deleteByQuery([]));
    }

    public function test_delete_by_query_rethrows_exception(): void
    {
        $this->esClient->method('deleteByQuery')->willThrowException(new RuntimeException('failed'));

        $this->expectException(RuntimeException::class);

        $this->client->deleteByQuery([]);
    }

    public function test_create_index_returns_array(): void
    {
        $expected = ['acknowledged' => true];

        $this->esResponse->method('asArray')->willReturn($expected);
        $this->indices->method('create')->willReturn($this->esResponse);

        $this->assertSame($expected, $this->client->createIndex(['index' => 'test']));
    }

    public function test_put_index_template_delegates_to_indices(): void
    {
        $template = [
            'index_patterns' => ['logs-*'],
            'template'       => [
                'settings' => ['number_of_shards' => 1],
                'mappings' => ['dynamic' => 'strict'],
            ],
        ];

        $indices = $this->createMock(Indices::class);
        $indices->expects($this->once())->method('putIndexTemplate')->with($this->callback(
            fn (array $p): bool => $p['name'] === 'logs_template' && $p['body'] === $template
        ));

        $esClient = $this->createStub(SpyElasticsearchClientInterface::class);
        $esClient->method('indices')->willReturn($indices);
        $client = new LogElasticsearchClient($esClient);

        $client->putIndexTemplate('logs_template', $template);
    }

    public function test_exists_index_returns_true(): void
    {
        $this->esResponse->method('asBool')->willReturn(true);
        $this->indices->method('exists')->willReturn($this->esResponse);

        $this->assertTrue($this->client->existsIndex('test-index'));
    }

    public function test_exists_index_returns_false_on_non_node_exception(): void
    {
        $this->indices->method('exists')->willThrowException(new RuntimeException('404'));

        $this->assertFalse($this->client->existsIndex('missing-index'));
    }

    public function test_exists_index_rethrows_no_node_available_exception(): void
    {
        $this->indices->method('exists')->willThrowException(new NoNodeAvailableException());

        $this->expectException(NoNodeAvailableException::class);

        $this->client->existsIndex('test');
    }

    public function test_put_alias_delegates_to_indices(): void
    {
        $indices = $this->createMock(Indices::class);
        $indices->expects($this->once())->method('putAlias');

        $esClient = $this->createStub(SpyElasticsearchClientInterface::class);
        $esClient->method('indices')->willReturn($indices);
        $client = new LogElasticsearchClient($esClient);

        $client->putAlias('test-index', 'test-alias');
    }

    public function test_put_alias_merges_extra_params(): void
    {
        $indices = $this->createMock(Indices::class);
        $indices->expects($this->once())->method('putAlias')->with($this->callback(
            fn (array $p) => $p['index'] === 'idx' && $p['name'] === 'alias' && ($p['body']['is_write_index'] ?? false) === true
        ));

        $esClient = $this->createStub(SpyElasticsearchClientInterface::class);
        $esClient->method('indices')->willReturn($indices);
        $client = new LogElasticsearchClient($esClient);

        $client->putAlias('idx', 'alias', ['body' => ['is_write_index' => true]]);
    }

    public function test_exists_alias_returns_bool(): void
    {
        $this->esResponse->method('asBool')->willReturn(true);
        $this->indices->method('existsAlias')->willReturn($this->esResponse);

        $this->assertTrue($this->client->existsAlias('test-alias'));
    }

    public function test_exists_alias_returns_false_on_generic_exception(): void
    {
        $this->indices->method('existsAlias')->willThrowException(new RuntimeException('404'));

        $this->assertFalse($this->client->existsAlias('missing'));
    }

    public function test_exists_alias_rethrows_no_node_available_exception(): void
    {
        $this->indices->method('existsAlias')->willThrowException(new NoNodeAvailableException());

        $this->expectException(NoNodeAvailableException::class);

        $this->client->existsAlias('test');
    }

    public function test_update_aliases_delegates_to_indices(): void
    {
        $actions = [['add' => ['index' => 'idx', 'alias' => 'a']]];

        $indices = $this->createMock(Indices::class);
        $indices->expects($this->once())->method('updateAliases')->with(
            $this->callback(fn (array $p) => $p['body']['actions'] === $actions)
        );

        $esClient = $this->createStub(SpyElasticsearchClientInterface::class);
        $esClient->method('indices')->willReturn($indices);
        $client = new LogElasticsearchClient($esClient);

        $client->updateAliases($actions);
    }

    public function test_put_lifecycle_policy_uses_elasticsearch_policy_parameter(): void
    {
        $policy = ['phases' => ['hot' => ['actions' => []]]];

        $ilm = $this->createMock(Ilm::class);
        $ilm->expects($this->once())->method('putLifecycle')->with($this->callback(
            fn (array $p): bool => $p['policy'] === 'audit-policy'
                && ! array_key_exists('name', $p)
                && $p['body'] === ['policy' => $policy]
        ));

        $esClient = $this->createStub(SpyElasticsearchClientInterface::class);
        $esClient->method('ilm')->willReturn($ilm);
        $client = new LogElasticsearchClient($esClient);

        $client->putLifecyclePolicy('audit-policy', $policy);
    }

    public function test_rollover_passes_alias_conditions_and_optional_new_index(): void
    {
        $indices = $this->createMock(Indices::class);
        $indices->expects($this->once())->method('rollover')->with($this->callback(
            fn (array $p): bool => $p['alias'] === 'logs_write'
                && $p['new_index'] === 'logs-000001'
                && $p['body']['conditions'] === ['max_age' => '30d']
        ))->willReturn($this->esResponse);

        $this->esResponse->method('asArray')->willReturn(['rolled_over' => true]);

        $esClient = $this->createStub(SpyElasticsearchClientInterface::class);
        $esClient->method('indices')->willReturn($indices);
        $client = new LogElasticsearchClient($esClient);

        $this->assertSame(
            ['rolled_over' => true],
            $client->rollover('logs_write', ['max_age' => '30d'], 'logs-000001')
        );
    }
}
