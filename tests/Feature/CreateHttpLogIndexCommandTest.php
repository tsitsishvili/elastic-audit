<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature;

use Elastic\Transport\Exception\NoNodeAvailableException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\HttpLogMapping;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

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
        $expectedIndex = config('http_logs.index_alias') . '-000001';

        $this->esClient->method('existsIndex')->willReturn(false);
        $this->esClient->method('existsAlias')->willReturn(false);

        $this->esClient->expects($this->once())->method('putIndexTemplate')->with(
            config('http_logs.index_alias') . '_template',
            $this->callback(fn (array $template): bool => $this->templateMatchesHttpLogs($template))
        );
        $this->esClient->expects($this->once())->method('createIndex')->with($this->callback(
            fn (array $params): bool => $params['index'] === $expectedIndex
        ));
        $this->esClient->expects($this->exactly(2))->method('putAlias')->with($expectedIndex);

        $this->artisan('http-logs:create-index')->assertSuccessful();
    }

    public function test_creates_next_available_index_when_initial_index_already_exists(): void
    {
        $baseName      = config('http_logs.index_alias');
        $expectedIndex = $baseName . '-000002';

        $this->esClient->method('existsIndex')->willReturnCallback(
            fn (string $index): bool => $index === $baseName . '-000001'
        );
        $this->esClient->method('existsAlias')->willReturn(false);

        $this->esClient->expects($this->once())->method('createIndex')->with($this->callback(
            fn (array $params): bool => $params['index'] === $expectedIndex
        ));
        $this->esClient->expects($this->exactly(2))->method('putAlias')->with($expectedIndex);

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

    private function templateMatchesHttpLogs(array $template): bool
    {
        $readAlias  = config('http_logs.index_alias');
        $writeAlias = config('http_logs.index_alias_write');

        return $template['index_patterns'] === ["{$readAlias}-*"]
            && $template['template']['mappings'] === HttpLogMapping::get()
            && ($template['template']['settings']['index.lifecycle.rollover_alias'] ?? null) === $writeAlias
            && array_key_exists($readAlias, $template['template']['aliases']);
    }
}
