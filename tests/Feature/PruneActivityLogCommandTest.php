<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature;

use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\FakeLogElasticsearchClient;
use Tsitsishvili\ElasticAudit\Tests\TestCase;
use RuntimeException;

class PruneActivityLogCommandTest extends TestCase
{
    public function test_prunes_documents_for_each_retention_value(): void
    {
        $fake = new class extends FakeLogElasticsearchClient {
            public array $deleteByQueryCalls = [];

            public function search(array $params): array
            {
                // Return two retention buckets: 30 and 90 days
                return [
                    'hits'         => ['total' => ['value' => 0], 'hits' => []],
                    'aggregations' => [
                        'retention_buckets' => [
                            'buckets' => [
                                ['key' => 30],
                                ['key' => 90],
                            ],
                        ],
                    ],
                ];
            }

            public function deleteByQuery(array $params): array
            {
                $this->deleteByQueryCalls[] = $params;
                return ['deleted' => 5];
            }
        };

        $this->app->instance(LogElasticsearchClientInterface::class, $fake);

        $this->artisan('activity-logs:prune')->assertExitCode(0);

        $this->assertCount(2, $fake->deleteByQueryCalls);
    }

    public function test_returns_success_when_no_documents(): void
    {
        $fake = new class extends FakeLogElasticsearchClient {
            public function search(array $params): array
            {
                return [
                    'hits'         => ['total' => ['value' => 0], 'hits' => []],
                    'aggregations' => ['retention_buckets' => ['buckets' => []]],
                ];
            }
        };

        $this->app->instance(LogElasticsearchClientInterface::class, $fake);

        $this->artisan('activity-logs:prune')->assertExitCode(0);
    }

    public function test_returns_failure_when_search_fails(): void
    {
        $fake = new class extends FakeLogElasticsearchClient {
            public function search(array $params): array
            {
                throw new RuntimeException('ES down');
            }
        };

        $this->app->instance(LogElasticsearchClientInterface::class, $fake);

        $this->artisan('activity-logs:prune')
            ->assertExitCode(1)
            ->expectsOutputToContain('Failed to fetch retention_days values');
    }

    public function test_returns_failure_when_delete_by_query_fails(): void
    {
        $fake = new class extends FakeLogElasticsearchClient {
            public function search(array $params): array
            {
                return [
                    'aggregations' => ['retention_buckets' => ['buckets' => [['key' => 30]]]],
                ];
            }

            public function deleteByQuery(array $params): array
            {
                throw new RuntimeException('delete failed');
            }
        };

        $this->app->instance(LogElasticsearchClientInterface::class, $fake);

        $this->artisan('activity-logs:prune')
            ->assertExitCode(1)
            ->expectsOutputToContain('Failed to prune documents with retention_days=30');
    }
}
