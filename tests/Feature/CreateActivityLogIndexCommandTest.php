<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature;

use Elastic\Transport\Exception\NoNodeAvailableException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\ActivityLogMapping;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\FakeLogElasticsearchClient;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class CreateActivityLogIndexCommandTest extends TestCase
{
    public function test_creates_index_and_aliases(): void
    {
        $fake = new class extends FakeLogElasticsearchClient {
            public array $createdIndexes = [];
            public array $createdAliases = [];
            public array $indexTemplates = [];
            public bool $indexExists     = false;
            public bool $aliasExists     = false;

            public function existsIndex(string $index): bool { return $this->indexExists; }
            public function existsAlias(string $name): bool  { return $this->aliasExists; }

            public function putIndexTemplate(string $name, array $template): void
            {
                $this->indexTemplates[$name] = $template;
            }

            public function createIndex(array $params): array
            {
                $this->createdIndexes[] = $params['index'];
                return [];
            }

            public function putAlias(string $index, string $name, array $params = []): void
            {
                $this->createdAliases[] = $name;
            }
        };

        $this->app->instance(LogElasticsearchClientInterface::class, $fake);

        $this->artisan('activity-logs:create-index')
            ->assertExitCode(0);

        $this->assertNotEmpty($fake->createdIndexes);
        $this->assertSame(config('activity_logs.index_alias') . '-000001', $fake->createdIndexes[0]);
        $this->assertArrayHasKey(config('activity_logs.index_alias') . '_template', $fake->indexTemplates);
        $this->assertSame([config('activity_logs.index_alias') . '-*'], $fake->indexTemplates[config('activity_logs.index_alias') . '_template']['index_patterns']);
        $this->assertSame(ActivityLogMapping::get(), $fake->indexTemplates[config('activity_logs.index_alias') . '_template']['template']['mappings']);
        $this->assertArrayHasKey(config('activity_logs.index_alias'), $fake->indexTemplates[config('activity_logs.index_alias') . '_template']['template']['aliases']);
        $this->assertContains(config('activity_logs.index_alias'), $fake->createdAliases);
        $this->assertContains(config('activity_logs.index_alias_write'), $fake->createdAliases);
    }

    public function test_creates_next_available_index_when_initial_index_already_exists(): void
    {
        $fake = new class extends FakeLogElasticsearchClient {
            public array $createdIndexes = [];
            public array $createdAliases = [];

            public function existsIndex(string $index): bool
            {
                return $index === config('activity_logs.index_alias') . '-000001';
            }

            public function existsAlias(string $name): bool
            {
                return false;
            }

            public function createIndex(array $params): array
            {
                $this->createdIndexes[] = $params['index'];

                return [];
            }

            public function putAlias(string $index, string $name, array $params = []): void
            {
                $this->createdAliases[] = $name;
            }
        };

        $this->app->instance(LogElasticsearchClientInterface::class, $fake);

        $this->artisan('activity-logs:create-index')
            ->assertExitCode(0);

        $this->assertSame(config('activity_logs.index_alias') . '-000002', $fake->createdIndexes[0]);
        $this->assertContains(config('activity_logs.index_alias'), $fake->createdAliases);
        $this->assertContains(config('activity_logs.index_alias_write'), $fake->createdAliases);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function test_returns_failure_when_es_unreachable(): void
    {
        $fake = $this->createMock(LogElasticsearchClientInterface::class);
        $fake->method('existsIndex')->willThrowException(new NoNodeAvailableException('no node'));

        $this->app->instance(LogElasticsearchClientInterface::class, $fake);

        $this->artisan('activity-logs:create-index')
            ->assertExitCode(1);
    }
}
