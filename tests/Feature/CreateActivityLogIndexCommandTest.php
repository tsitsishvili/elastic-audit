<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature;

use Elastic\Transport\Exception\NoNodeAvailableException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\FakeLogElasticsearchClient;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class CreateActivityLogIndexCommandTest extends TestCase
{
    public function test_creates_index_and_aliases(): void
    {
        $fake = new class extends FakeLogElasticsearchClient {
            public array $createdIndexes = [];
            public array $createdAliases = [];
            public bool $indexExists     = false;
            public bool $aliasExists     = false;

            public function existsIndex(string $index): bool { return $this->indexExists; }
            public function existsAlias(string $name): bool  { return $this->aliasExists; }

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
