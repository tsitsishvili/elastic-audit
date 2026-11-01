<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature;

use Elastic\Transport\Exception\NoNodeAvailableException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\HttpLogMapping;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
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
        $expectedIndex = config('http_logs.index_alias').'-000001';

        $this->esClient->method('existsIndex')->willReturn(false);
        $this->esClient->method('existsAlias')->willReturn(false);

        $this->esClient->expects($this->once())->method('putIndexTemplate')->with(
            config('http_logs.index_alias').'_template',
            $this->callback(fn (array $template): bool => $this->templateMatchesHttpLogs($template))
        );
        $this->esClient->expects($this->once())->method('createIndex')->with($this->callback(
            fn (array $params): bool => $params['index'] === $expectedIndex
        ));
        $this->esClient->expects($this->exactly(2))->method('putAlias')->with($expectedIndex);

        $this->artisan('http-logs:create-index')->assertSuccessful();
    }

    public function test_attaches_the_write_alias_without_an_explicit_write_flag(): void
    {
        $this->esClient->method('existsIndex')->willReturn(false);
        $this->esClient->method('existsAlias')->willReturn(false);

        $this->esClient->expects($this->exactly(2))->method('putAlias')->with(
            $this->anything(),
            $this->anything(),
            // Elasticsearch's rollover API only moves an alias off the previous
            // index when the alias carries no explicit is_write_index flag.
            $this->callback(fn (array $params): bool => ! str_contains((string) json_encode($params), 'is_write_index')),
        );

        $this->artisan('http-logs:create-index')->assertSuccessful();
    }

    public function test_creates_next_available_index_when_initial_index_already_exists(): void
    {
        $baseName      = config('http_logs.index_alias');
        $expectedIndex = $baseName.'-000002';

        $this->esClient->method('existsIndex')->willReturnCallback(
            fn (string $index): bool => $index === $baseName.'-000001'
        );
        $this->esClient->method('existsAlias')->willReturn(false);

        $this->esClient->expects($this->once())->method('createIndex')->with($this->callback(
            fn (array $params): bool => $params['index'] === $expectedIndex
        ));
        $this->esClient->expects($this->exactly(2))->method('putAlias')->with($expectedIndex);

        $this->artisan('http-logs:create-index')->assertSuccessful();
    }

    public function test_uses_rollover_api_when_write_alias_already_exists(): void
    {
        $this->esClient->method('existsIndex')->willReturn(false);
        $this->esClient->method('existsAlias')->willReturnCallback(
            fn (string $alias): bool => $alias === config('http_logs.index_alias_write')
        );
        $this->esClient->method('rollover')->willReturn(['rolled_over' => true]);

        $this->esClient->expects($this->never())->method('createIndex');
        $this->esClient->expects($this->once())->method('rollover')->with(
            config('http_logs.index_alias_write'),
            [],
            config('http_logs.index_alias').'-000001',
        );
        $this->esClient->expects($this->never())->method('updateAliases');

        $this->artisan('http-logs:create-index')->assertSuccessful();
    }

    public function test_adds_to_read_alias_when_it_already_exists(): void
    {
        $this->esClient->method('existsIndex')->willReturn(false);
        $this->esClient->method('existsAlias')->willReturnCallback(
            fn (string $alias): bool => $alias === config('http_logs.index_alias')
        );
        $this->esClient->method('createIndex')->willReturn(['acknowledged' => true]);

        $this->esClient->expects($this->once())->method('putAlias');
        $this->esClient->expects($this->once())->method('updateAliases');

        $this->artisan('http-logs:create-index')->assertSuccessful();
    }

    public function test_returns_failure_when_rollover_is_not_acknowledged(): void
    {
        $this->esClient->method('existsIndex')->willReturn(false);
        $this->esClient->method('existsAlias')->willReturn(true);
        $this->esClient->expects($this->once())->method('rollover')->willReturn(['rolled_over' => false]);

        $this->artisan('http-logs:create-index')
            ->expectsOutputToContain('did not roll over')
            ->assertFailed();
    }

    public function test_rollover_detaches_the_write_alias_from_the_previous_index_and_clears_its_flag(): void
    {
        $readAlias  = config('http_logs.index_alias');
        $writeAlias = config('http_logs.index_alias_write');

        $this->esClient->method('existsIndex')->willReturn(false);
        $this->esClient->method('existsAlias')->willReturnCallback(
            fn (string $alias): bool => $alias === $writeAlias
        );
        $this->esClient->method('rollover')->willReturn(['rolled_over' => true]);

        // The state Elasticsearch leaves behind when it rolls over an alias that
        // an earlier package version attached with is_write_index: true.
        $this->esClient->method('getAlias')->willReturn(
            $this->writeAliasResponse(['-000001' => false, '-000002' => true]),
        );

        $this->esClient->expects($this->once())->method('updateAliases')->with([
            ['remove' => ['index' => "{$readAlias}-000001", 'alias' => $writeAlias]],
            ['add' => ['index' => "{$readAlias}-000002", 'alias' => $writeAlias]],
        ]);

        $this->artisan('http-logs:create-index')
            ->expectsOutputToContain("{$readAlias}-000001")
            ->assertSuccessful();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function test_reports_a_successful_rollover_whose_alias_cleanup_failed(): void
    {
        $this->esClient->method('existsIndex')->willReturn(false);
        $this->esClient->method('existsAlias')->willReturnCallback(
            fn (string $alias): bool => $alias === config('http_logs.index_alias_write')
        );
        $this->esClient->method('rollover')->willReturn(['rolled_over' => true]);
        $this->esClient->method('getAlias')->willReturn(
            $this->writeAliasResponse(['-000001' => false, '-000002' => true]),
        );
        $this->esClient->method('updateAliases')->willThrowException(new RuntimeException('cluster busy'));

        $this->artisan('http-logs:create-index')
            ->expectsOutputToContain('Rolled over')
            ->expectsOutputToContain('http-logs:rollover')
            ->assertFailed();
    }

    public function test_rejects_an_invalid_index_alias_before_calling_elasticsearch(): void
    {
        config(['http_logs.index_alias' => 'Example App logs']);

        $this->esClient->expects($this->never())->method('putIndexTemplate');

        $this->artisan('http-logs:create-index')
            ->expectsOutputToContain('HTTP logs read alias [Example App logs] is invalid')
            ->assertFailed();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function test_returns_failure_when_no_node_available(): void
    {
        $this->esClient->method('existsIndex')->willThrowException(new NoNodeAvailableException);

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

    /**
     * @param  array<string, bool|null>  $generations  Suffix => is_write_index (null omits the flag).
     */
    private function writeAliasResponse(array $generations): array
    {
        $readAlias  = config('http_logs.index_alias');
        $writeAlias = config('http_logs.index_alias_write');
        $response   = [];

        foreach ($generations as $suffix => $flag) {
            $response[$readAlias.$suffix] = [
                'aliases' => [$writeAlias => $flag === null ? [] : ['is_write_index' => $flag]],
            ];
        }

        return $response;
    }
}
