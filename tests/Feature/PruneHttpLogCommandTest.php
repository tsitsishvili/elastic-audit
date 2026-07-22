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
                    'buckets' => [
                        ['key' => ['retention_days' => 30]],
                        ['key' => ['retention_days' => 90]],
                    ],
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
            'aggregations' => ['retention_buckets' => ['buckets' => [
                ['key' => ['retention_days' => 30]],
            ]]],
        ]);

        $this->esClient->method('deleteByQuery')->willReturn(['deleted' => 5]);

        $this->artisan('http-logs:prune')
            ->assertSuccessful()
            ->expectsOutputToContain('Deleted 5 documents');
    }

    public function test_returns_failure_when_search_fails(): void
    {
        $this->esClient->method('search')->willThrowException(new RuntimeException('ES down'));

        $this->esClient->expects($this->never())->method('deleteByQuery');

        $this->artisan('http-logs:prune')
            ->assertFailed()
            ->expectsOutputToContain('Failed to fetch retention_days values');
    }

    public function test_rejects_unsafe_retention_values_already_present_in_elasticsearch(): void
    {
        $this->esClient->method('search')->willReturn([
            'aggregations' => [
                'retention_buckets' => [
                    'buckets' => [['key' => ['retention_days' => 0]]],
                ],
            ],
        ]);

        $this->esClient->expects($this->never())->method('deleteByQuery');

        $this->artisan('http-logs:prune')
            ->expectsOutputToContain('Failed to fetch retention_days values')
            ->assertFailed();
    }

    public function test_rejects_fractional_retention_values_already_present_in_elasticsearch(): void
    {
        $this->esClient->method('search')->willReturn([
            'aggregations' => [
                'retention_buckets' => [
                    'buckets' => [['key' => ['retention_days' => '1.5']]],
                ],
            ],
        ]);

        $this->esClient->expects($this->never())->method('deleteByQuery');

        $this->artisan('http-logs:prune')
            ->expectsOutputToContain('Failed to fetch retention_days values')
            ->assertFailed();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function test_returns_failure_when_delete_by_query_fails(): void
    {
        $this->esClient->method('search')->willReturn([
            'aggregations' => ['retention_buckets' => ['buckets' => [
                ['key' => ['retention_days' => 30]],
            ]]],
        ]);

        $this->esClient->method('deleteByQuery')->willThrowException(new RuntimeException('delete failed'));

        $this->artisan('http-logs:prune')
            ->assertFailed()
            ->expectsOutputToContain('Failed to prune documents with retention_days=30');
    }

    public function test_returns_failure_when_retention_search_is_partial(): void
    {
        $this->esClient->method('search')->willReturn([
            'timed_out'    => true,
            'aggregations' => ['retention_buckets' => ['buckets' => []]],
        ]);

        $this->esClient->expects($this->never())->method('deleteByQuery');

        $this->artisan('http-logs:prune')
            ->assertFailed()
            ->expectsOutputToContain('Failed to fetch retention_days values');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function test_returns_failure_when_delete_by_query_times_out(): void
    {
        $this->esClient->method('search')->willReturn([
            'aggregations' => ['retention_buckets' => ['buckets' => [
                ['key' => ['retention_days' => 30]],
            ]]],
        ]);
        $this->esClient->method('deleteByQuery')->willReturn([
            'timed_out' => true,
            'deleted'   => 10,
        ]);

        $this->artisan('http-logs:prune')
            ->assertFailed()
            ->expectsOutputToContain('Failed to prune documents with retention_days=30');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function test_returns_failure_when_delete_by_query_returns_failures(): void
    {
        $this->esClient->method('search')->willReturn([
            'aggregations' => ['retention_buckets' => ['buckets' => [
                ['key' => ['retention_days' => 30]],
            ]]],
        ]);
        $this->esClient->method('deleteByQuery')->willReturn([
            'deleted'  => 10,
            'failures' => [['cause' => ['reason' => 'shard failed']]],
        ]);

        $this->artisan('http-logs:prune')
            ->assertFailed()
            ->expectsOutputToContain('Failed to prune documents with retention_days=30');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function test_returns_failure_when_delete_by_query_has_version_conflicts(): void
    {
        $this->esClient->method('search')->willReturn([
            'aggregations' => ['retention_buckets' => ['buckets' => [
                ['key' => ['retention_days' => 30]],
            ]]],
        ]);
        $this->esClient->method('deleteByQuery')->willReturn([
            'deleted'           => 10,
            'version_conflicts' => 1,
        ]);

        $this->artisan('http-logs:prune')
            ->assertFailed()
            ->expectsOutputToContain('Failed to prune documents with retention_days=30');
    }
}
