<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature;

use Elastic\Transport\Exception\NoNodeAvailableException;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Tests\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;

class CreateHttpLogIndexCommandTest extends TestCase
{
    private MockObject&LogElasticsearchClientInterface $esClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->esClient = $this->createMock(LogElasticsearchClientInterface::class);
        $this->app->instance(LogElasticsearchClientInterface::class, $this->esClient);
    }

    public function test_creates_index_and_attaches_aliases_when_index_does_not_exist(): void
    {
        $this->esClient->method('existsIndex')->willReturn(false);
        $this->esClient->method('existsAlias')->willReturn(false);

        $this->esClient->expects($this->once())->method('createIndex');
        $this->esClient->expects($this->exactly(2))->method('putAlias');

        $this->artisan('http-logs:create-index')->assertSuccessful();
    }

    public function test_skips_index_creation_when_index_already_exists(): void
    {
        $this->esClient->method('existsIndex')->willReturn(true);
        $this->esClient->method('existsAlias')->willReturn(false);

        $this->esClient->expects($this->never())->method('createIndex');
        $this->esClient->expects($this->exactly(2))->method('putAlias');

        $this->artisan('http-logs:create-index')->assertSuccessful();
    }

    public function test_swaps_write_alias_when_it_already_exists(): void
    {
        $this->esClient->method('existsIndex')->willReturn(false);
        $this->esClient->method('existsAlias')->willReturnOnConsecutiveCalls(false, true);
        $this->esClient->method('createIndex')->willReturn(['acknowledged' => true]);

        $this->esClient->expects($this->once())->method('putAlias');
        $this->esClient->expects($this->once())->method('updateAliases');

        $this->artisan('http-logs:create-index')->assertSuccessful();
    }

    public function test_adds_to_read_alias_when_it_already_exists(): void
    {
        $this->esClient->method('existsIndex')->willReturn(false);
        $this->esClient->method('existsAlias')->willReturnOnConsecutiveCalls(true, false);
        $this->esClient->method('createIndex')->willReturn(['acknowledged' => true]);

        $this->esClient->expects($this->once())->method('putAlias');
        $this->esClient->expects($this->once())->method('updateAliases');

        $this->artisan('http-logs:create-index')->assertSuccessful();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function test_returns_failure_when_no_node_available(): void
    {
        $this->esClient->method('existsIndex')->willThrowException(new NoNodeAvailableException());

        $this->artisan('http-logs:create-index')->assertFailed();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function test_guard_passes_when_logs_host_is_empty(): void
    {
        config(['log_elasticsearch.hosts' => [['host' => '', 'port' => 9200, 'scheme' => 'http']]]);

        $this->esClient->method('existsIndex')->willReturn(false);
        $this->esClient->method('existsAlias')->willReturn(false);

        $this->artisan('http-logs:create-index')->assertSuccessful();
    }
}
