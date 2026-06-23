<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature;

use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Tests\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;

class PruneHttpLogCommandTest extends TestCase
{
    private MockObject&LogElasticsearchClientInterface $esClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->esClient = $this->createMock(LogElasticsearchClientInterface::class);
        $this->app->instance(LogElasticsearchClientInterface::class, $this->esClient);
    }

    public function test_exits_successfully_when_no_retention_buckets_found(): void
    {
        $this->esClient->method('search')->willReturn([
            'aggregations' => ['retention_buckets' => ['buckets' => []]],
        ]);

        $this->esClient->expects($this->never())->method('deleteByQuery');

        $this->artisan('http-logs:prune')
            ->assertSuccessful()
            ->expectsOutputToContain('Nothing to prune');
    }

    public function test_calls_delete_by_query_for_each_retention_day(): void
    {
        $this->esClient->method('search')->willReturn([
            'aggregations' => [
                'retention_buckets' => [
                    'buckets' => [['key' => 30], ['key' => 90]],
                ],
            ],
        ]);

        $this->esClient->method('deleteByQuery')->willReturn(['deleted' => 10]);

        $this->esClient->expects($this->exactly(2))->method('deleteByQuery');

        $this->artisan('http-logs:prune')->assertSuccessful();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function test_shows_deleted_count_per_retention_period(): void
    {
        $this->esClient->method('search')->willReturn([
            'aggregations' => ['retention_buckets' => ['buckets' => [['key' => 30]]]],
        ]);

        $this->esClient->method('deleteByQuery')->willReturn(['deleted' => 5]);

        $this->artisan('http-logs:prune')
            ->assertSuccessful()
            ->expectsOutputToContain('Deleted 5 documents');
    }

    public function test_handles_search_exception_gracefully(): void
    {
        $this->esClient->method('search')->willThrowException(new RuntimeException('ES down'));

        $this->esClient->expects($this->never())->method('deleteByQuery');

        $this->artisan('http-logs:prune')->assertSuccessful();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function test_handles_delete_by_query_exception_gracefully(): void
    {
        $this->esClient->method('search')->willReturn([
            'aggregations' => ['retention_buckets' => ['buckets' => [['key' => 30]]]],
        ]);

        $this->esClient->method('deleteByQuery')->willThrowException(new RuntimeException('delete failed'));

        $this->artisan('http-logs:prune')->assertSuccessful();
    }
}
