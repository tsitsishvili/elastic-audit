<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Integration;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogContext;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogData;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogContext;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogData;
use Tsitsishvili\ElasticAudit\DataTransferObjects\RedactedHttpPayload;
use Tsitsishvili\ElasticAudit\Enums\HttpDirection;
use Tsitsishvili\ElasticAudit\Services\ActivityLogIndexer;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Services\HttpLogIndexer;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEntityType;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEventType;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestProvider;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

#[Group('integration')]
final class ElasticsearchSmokeTest extends TestCase
{
    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('ELASTICSEARCH_INTEGRATION') !== '1') {
            $this->markTestSkipped('Set ELASTICSEARCH_INTEGRATION=1 to run against a real Elasticsearch cluster.');
        }

        $url = parse_url((string) (getenv('ELASTICSEARCH_URL') ?: 'http://127.0.0.1:9200'));

        if (! is_array($url) || ! isset($url['host'])) {
            $this->fail('ELASTICSEARCH_URL must be a valid URL.');
        }

        $suffix       = strtolower((string) Str::ulid());
        $this->prefix = "elastic_audit_smoke_{$suffix}";

        config([
            'http_logs.enabled'               => true,
            'http_logs.index_alias'           => "{$this->prefix}_http_logs",
            'http_logs.index_alias_write'     => "{$this->prefix}_http_logs_write",
            'activity_logs.enabled'           => true,
            'activity_logs.index_alias'       => "{$this->prefix}_activity_logs",
            'activity_logs.index_alias_write' => "{$this->prefix}_activity_logs_write",
            'log_elasticsearch.hosts'         => [[
                'host'   => $url['host'],
                'port'   => $url['port'] ?? 9200,
                'scheme' => $url['scheme'] ?? 'http',
            ]],
            'log_elasticsearch.basicAuthentication' => [
                'username' => $url['user'] ?? '',
                'password' => $url['pass'] ?? '',
            ],
            'log_elasticsearch.replicas'                          => 0,
            'log_elasticsearch.lifecycle.enabled'                 => true,
            'log_elasticsearch.lifecycle.policy_name'             => "{$this->prefix}_policy",
            'log_elasticsearch.lifecycle.delete_enabled'          => true,
            'log_elasticsearch.lifecycle.delete_after'            => '360d',
            'log_elasticsearch.lifecycle.rollover_max_age'        => '30d',
            'log_elasticsearch.lifecycle.rollover_max_shard_size' => '50gb',
        ]);

        $this->app->forgetInstance(LogElasticsearchClientInterface::class);
        $this->app->forgetInstance(HttpLogIndexer::class);
        $this->app->forgetInstance(ActivityLogIndexer::class);
    }

    public function test_real_cluster_lifecycle_indexing_rollover_health_and_pruning(): void
    {
        $client = $this->app->make(LogElasticsearchClientInterface::class);

        $this->assertTrue($client->ping());

        $this->artisan('elastic-audit:lifecycle-policy')->assertSuccessful();
        $this->artisan('http-logs:create-index')->assertSuccessful();
        $this->artisan('activity-logs:create-index')->assertSuccessful();

        $httpData     = $this->httpData();
        $activityData = $this->activityData();

        $this->app->make(HttpLogIndexer::class)->index($httpData);
        $this->app->make(ActivityLogIndexer::class)->index($activityData);

        $this->indexExpiredHttpDocument($client);
        $this->indexExpiredActivityDocument($client);

        $this->assertDocumentExists($client, (string) config('http_logs.index_alias'), $httpData->eventId);
        $this->assertDocumentExists($client, (string) config('activity_logs.index_alias'), $activityData->eventId);
        $this->assertDocumentExists($client, (string) config('http_logs.index_alias'), 'expired-http-document');
        $this->assertDocumentExists($client, (string) config('activity_logs.index_alias'), 'expired-activity-document');

        // Re-running create-index must use an unconditional, template-backed rollover.
        $this->artisan('http-logs:create-index')->assertSuccessful();
        $this->artisan('activity-logs:create-index')->assertSuccessful();

        // The read aliases must retain the rolled-over index and all of its documents.
        $this->assertDocumentExists($client, (string) config('http_logs.index_alias'), $httpData->eventId);
        $this->assertDocumentExists($client, (string) config('activity_logs.index_alias'), $activityData->eventId);
        $this->assertDocumentExists($client, (string) config('http_logs.index_alias'), 'expired-http-document');
        $this->assertDocumentExists($client, (string) config('activity_logs.index_alias'), 'expired-activity-document');

        // Exercise the conditional rollover endpoints too; unmet conditions are a successful no-op.
        $this->artisan('http-logs:rollover')->assertSuccessful();
        $this->artisan('activity-logs:rollover')->assertSuccessful();
        $this->artisan('elastic-audit:health --all')->assertSuccessful();

        $this->artisan('http-logs:prune')
            ->expectsOutputToContain('Deleted 1 documents.')
            ->assertSuccessful();
        $this->artisan('activity-logs:prune')
            ->expectsOutputToContain('Deleted 1 documents.')
            ->assertSuccessful();

        $this->assertDocumentExists($client, (string) config('http_logs.index_alias'), $httpData->eventId);
        $this->assertDocumentExists($client, (string) config('activity_logs.index_alias'), $activityData->eventId);
        $this->assertDocumentEventuallyMissing(
            $client,
            (string) config('http_logs.index_alias'),
            'expired-http-document',
        );
        $this->assertDocumentEventuallyMissing(
            $client,
            (string) config('activity_logs.index_alias'),
            'expired-activity-document',
        );
    }

    private function httpData(): HttpLogData
    {
        return HttpLogData::make(
            provider: TestProvider::Delivery,
            eventType: TestEventType::DeliveryOrderCreate,
            direction: HttpDirection::Outgoing,
            httpMethod: 'POST',
            httpUrl: 'https://provider.example/orders',
            latencyMs: 12,
            context: HttpLogContext::forEntity(TestEntityType::Order, 'smoke-order'),
            request: RedactedHttpPayload::empty(),
            response: RedactedHttpPayload::empty(),
            httpStatusCode: 200,
        );
    }

    private function activityData(): ActivityLogData
    {
        return ActivityLogData::make(
            action: 'order.smoke_tested',
            context: ActivityLogContext::forActor(
                actorType: 'system',
                actorId: 'integration-suite',
                entityType: 'order',
                entityId: 'smoke-order',
            ),
            metadata: ['source' => 'integration'],
        );
    }

    private function indexExpiredHttpDocument(LogElasticsearchClientInterface $client): void
    {
        $client->index([
            'index'   => (string) config('http_logs.index_alias_write'),
            'id'      => 'expired-http-document',
            'refresh' => 'wait_for',
            'body'    => [
                '@timestamp'     => now()->subDays(2)->toIso8601ZuluString(),
                'event_id'       => 'expired-http-document',
                'schema_version' => HttpLogData::SCHEMA_VERSION,
                'request_id'     => 'integration-prune',
                'provider'       => 'integration',
                'event_type'     => 'integration',
                'direction'      => HttpDirection::Outgoing->value,
                'user_id'        => null,
                'attempt'        => 1,
                'success'        => true,
                'retention_days' => 1,
                'trace'          => ['id' => null, 'span_id' => null, 'traceparent' => null],
                'http'           => [
                    'method'       => 'GET', 'url' => 'https://provider.example/smoke',
                    'host'         => 'provider.example', 'path' => '/smoke', 'status_code' => 200,
                    'status_class' => '2xx', 'latency_ms' => 1, 'timed_out' => false,
                ],
                'entity'   => ['type' => 'order', 'id' => 'expired'],
                'external' => ['id' => null],
                'request'  => $this->emptyPayloadDocument(),
                'response' => $this->emptyPayloadDocument(),
                'error'    => ['class' => null, 'message' => null],
            ],
        ]);
    }

    private function indexExpiredActivityDocument(LogElasticsearchClientInterface $client): void
    {
        $client->index([
            'index'   => (string) config('activity_logs.index_alias_write'),
            'id'      => 'expired-activity-document',
            'refresh' => 'wait_for',
            'body'    => [
                '@timestamp'     => now()->subDays(2)->toIso8601ZuluString(),
                'event_id'       => 'expired-activity-document',
                'schema_version' => ActivityLogData::SCHEMA_VERSION,
                'request_id'     => 'integration-prune',
                'trace'          => ['id' => null, 'span_id' => null, 'traceparent' => null],
                'actor'          => ['type' => 'system', 'id' => 'integration-suite'],
                'action'         => 'order.expired',
                'entity'         => ['type' => 'order', 'id' => 'expired'],
                'changes'        => [],
                'metadata'       => [],
                'success'        => true,
                'error'          => ['class' => null, 'message' => null],
                'retention_days' => 1,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function emptyPayloadDocument(): array
    {
        return [
            'headers'        => [],
            'body'           => null,
            'body_preview'   => null,
            'body_hash'      => null,
            'body_truncated' => false,
        ];
    }

    private function assertDocumentExists(
        LogElasticsearchClientInterface $client,
        string $alias,
        string $eventId,
    ): void {
        $this->assertSame(1, $this->documentCount($client, $alias, $eventId));
    }

    private function assertDocumentEventuallyMissing(
        LogElasticsearchClientInterface $client,
        string $alias,
        string $eventId,
    ): void {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            if ($this->documentCount($client, $alias, $eventId) === 0) {
                $this->addToAssertionCount(1);

                return;
            }

            usleep(100_000);
        }

        $this->assertSame(0, $this->documentCount($client, $alias, $eventId));
    }

    private function documentCount(
        LogElasticsearchClientInterface $client,
        string $alias,
        string $eventId,
    ): int {
        $result = $client->search([
            'index' => $alias,
            'body'  => ['query' => ['ids' => ['values' => [$eventId]]]],
        ]);

        return (int) ($result['hits']['total']['value'] ?? 0);
    }
}
