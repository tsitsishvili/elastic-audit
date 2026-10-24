<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Integration;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogContext;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogData;
use Tsitsishvili\ElasticAudit\DataTransferObjects\AuditSource;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ExecutionOrigin;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogContext;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogData;
use Tsitsishvili\ElasticAudit\DataTransferObjects\MetricData;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ProfileData;
use Tsitsishvili\ElasticAudit\DataTransferObjects\RedactedHttpPayload;
use Tsitsishvili\ElasticAudit\Enums\HttpDirection;
use Tsitsishvili\ElasticAudit\Services\ActivityLogIndexer;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Services\HttpLogIndexer;
use Tsitsishvili\ElasticAudit\Services\MetricsIndexer;
use Tsitsishvili\ElasticAudit\Services\ProfileIndexer;
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
            // Metrics stay disabled: enabled profiles make health demand ext-excimer/xhprof, which CI
            // runners lack. health --all still checks these aliases against the strict mappings.
            'elastic_audit_metrics.index_alias'                => "{$this->prefix}_metrics",
            'elastic_audit_metrics.index_alias_write'          => "{$this->prefix}_metrics_write",
            'elastic_audit_metrics.profiles.index_alias'       => "{$this->prefix}_profiles",
            'elastic_audit_metrics.profiles.index_alias_write' => "{$this->prefix}_profiles_write",
            'log_elasticsearch.hosts'                          => [[
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
        $this->app->forgetInstance(MetricsIndexer::class);
        $this->app->forgetInstance(ProfileIndexer::class);
    }

    public function test_real_cluster_lifecycle_indexing_rollover_health_and_pruning(): void
    {
        $client = $this->app->make(LogElasticsearchClientInterface::class);

        $this->assertTrue($client->ping());

        $this->artisan('elastic-audit:lifecycle-policy')->assertSuccessful();
        $this->artisan('http-logs:create-index')->assertSuccessful();
        $this->artisan('activity-logs:create-index')->assertSuccessful();
        $this->artisan('elastic-audit:metrics:create-index')->assertSuccessful();
        $this->artisan('elastic-audit:profiles:create-index')->assertSuccessful();

        $httpData     = $this->httpData();
        $activityData = $this->activityData();
        $metrics      = $this->metricData();
        $profile      = $this->profileData($metrics[0]);

        $this->app->make(HttpLogIndexer::class)->index($httpData);
        $this->app->make(ActivityLogIndexer::class)->index($activityData);
        // Bulk throws on any rejected item, so every strict sub-mapping is exercised here.
        $this->app->make(MetricsIndexer::class)->bulk($metrics);
        $this->app->make(ProfileIndexer::class)->index($profile);

        $this->indexExpiredHttpDocument($client);
        $this->indexExpiredActivityDocument($client);
        $this->indexExpiredMetricDocument($client);
        $this->indexExpiredProfileDocument($client);

        $live = [
            (string) config('http_logs.index_alias')                      => [$httpData->eventId, 'expired-http-document'],
            (string) config('activity_logs.index_alias')                  => [$activityData->eventId, 'expired-activity-document'],
            (string) config('elastic_audit_metrics.index_alias')          => [$metrics[0]->eventId, 'expired-metric-document'],
            (string) config('elastic_audit_metrics.profiles.index_alias') => [$profile->profileId, 'expired-profile-document'],
        ];

        $this->assertDocumentsExist($client, $live);

        foreach ($metrics as $metric) {
            $this->assertDocumentExists($client, (string) config('elastic_audit_metrics.index_alias'), $metric->eventId);
        }

        // Re-running create-index must use an unconditional, template-backed rollover.
        $this->artisan('http-logs:create-index')->assertSuccessful();
        $this->artisan('activity-logs:create-index')->assertSuccessful();
        $this->artisan('elastic-audit:metrics:create-index')->assertSuccessful();
        $this->artisan('elastic-audit:profiles:create-index')->assertSuccessful();

        // The read aliases must retain the rolled-over index and all of its documents.
        $this->assertDocumentsExist($client, $live);

        // Exercise the conditional rollover endpoints too; unmet conditions are a successful no-op.
        $this->artisan('http-logs:rollover')->assertSuccessful();
        $this->artisan('activity-logs:rollover')->assertSuccessful();
        $this->artisan('elastic-audit:metrics:rollover')->assertSuccessful();
        $this->artisan('elastic-audit:profiles:rollover')->assertSuccessful();
        $this->artisan('elastic-audit:health --all')->assertSuccessful();

        $this->artisan('http-logs:prune')
            ->expectsOutputToContain('Deleted 1 documents.')
            ->assertSuccessful();
        $this->artisan('activity-logs:prune')
            ->expectsOutputToContain('Deleted 1 documents.')
            ->assertSuccessful();
        $this->artisan('elastic-audit:metrics:prune')
            ->expectsOutputToContain('Deleted 1 documents.')
            ->assertSuccessful();
        $this->artisan('elastic-audit:profiles:prune')
            ->expectsOutputToContain('Deleted 1 profile documents with retention_days=1.')
            ->assertSuccessful();

        foreach ($live as $alias => [$keptId, $expiredId]) {
            $this->assertDocumentExists($client, $alias, $keptId);
            $this->assertDocumentEventuallyMissing($client, $alias, $expiredId);
        }
    }

    /** @param array<string, array{string, string}> $documents */
    private function assertDocumentsExist(LogElasticsearchClientInterface $client, array $documents): void
    {
        foreach ($documents as $alias => $ids) {
            foreach ($ids as $id) {
                $this->assertDocumentExists($client, $alias, $id);
            }
        }
    }

    /**
     * A transaction plus one span per detail object, so each strict sub-mapping receives a real document.
     *
     * @return list<MetricData>
     */
    private function metricData(): array
    {
        $transaction = MetricData::make(
            type: MetricData::TYPE_HTTP_SERVER,
            name: 'POST orders.store',
            outcome: MetricData::OUTCOME_SUCCESS,
            durationMs: 42.5,
            http: [
                'method' => 'POST', 'route' => 'orders.store', 'controller' => 'OrderController@store',
                'host'   => 'app.example', 'path' => '/orders', 'status_code' => 201,
            ],
            kind: MetricData::KIND_TRANSACTION,
            spanCount: 9,
        );

        $span = static fn (string $type, string $name, array $detail): MetricData => MetricData::make(
            ...$detail,
            type: $type,
            name: $name,
            outcome: MetricData::OUTCOME_SUCCESS,
            durationMs: 1.5,
            traceId: $transaction->traceId,
            parentSpanId: $transaction->spanId,
            transactionId: $transaction->transactionId,
        );

        return [
            $transaction,
            $span(MetricData::TYPE_DB_QUERY, 'select orders', ['db' => [
                'connection' => 'mysql', 'driver' => 'mysql', 'operation' => 'select',
                'statement'  => 'select * from orders where id = ?', 'fingerprint' => 'smoke-fingerprint',
            ]]),
            $span(MetricData::TYPE_QUEUE_PUBLISH, 'App\\Jobs\\SyncOrder', ['queue' => [
                'connection' => 'redis', 'name' => 'default', 'job' => 'App\\Jobs\\SyncOrder',
                'attempt'    => 1, 'wait_ms' => 3.5,
            ]]),
            $span(MetricData::TYPE_CONSOLE_COMMAND, 'orders:sync', ['console' => [
                'command' => 'orders:sync', 'exit_code' => 0,
            ]]),
            $span(MetricData::TYPE_REDIS_COMMAND, 'GET', ['redis' => ['command' => 'GET', 'connection' => 'default']]),
            $span(MetricData::TYPE_CACHE_OPERATION, 'cache get', ['cache' => [
                'operation' => 'get', 'store' => 'redis', 'result' => 'hit',
            ]]),
            $span(MetricData::TYPE_MAIL_SEND, 'mail', ['mail' => ['transport' => 'smtp']]),
            $span(MetricData::TYPE_NOTIFICATION_SEND, 'OrderShipped', ['notification' => [
                'class' => 'App\\Notifications\\OrderShipped', 'channel' => 'mail',
            ]]),
            $span(MetricData::TYPE_SCHEDULED_TASK, 'orders:sync', ['scheduler' => [
                'task' => 'orders:sync', 'fingerprint' => 'smoke-schedule', 'exit_code' => 0,
            ]]),
            $span(MetricData::TYPE_APP_FUNCTION, 'OrderService::place', ['code' => ['self_ms' => 1.25]]),
        ];
    }

    private function profileData(MetricData $transaction): ProfileData
    {
        return new ProfileData(
            profileId: (string) Str::ulid(),
            timestamp: $transaction->timestamp,
            traceId: $transaction->traceId,
            transactionId: (string) $transaction->transactionId,
            transactionName: $transaction->name,
            transactionType: $transaction->type,
            durationMs: $transaction->durationMs,
            driver: 'excimer',
            mode: 'sampling',
            format: 'speedscope',
            sampleRateHz: 99.0,
            sampleCount: 4,
            truncated: false,
            payload: [
                '$schema'  => 'https://www.speedscope.app/file-format-schema.json',
                'shared'   => ['frames' => [['name' => 'OrderController::store', 'file' => null, 'line' => 42]]],
                'profiles' => [['type' => 'sampled', 'samples' => [[0], [0]], 'weights' => [1, 1]]],
            ],
            hotFrames: [[
                'function'      => 'OrderController::store',
                'file'          => null,
                'line'          => 42,
                'self_samples'  => 3,
                'total_samples' => 4,
            ]],
            retentionDays: 7,
            source: new AuditSource('integration', 'testing', ExecutionOrigin::manual('smoke')),
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

    private function indexExpiredMetricDocument(LogElasticsearchClientInterface $client): void
    {
        $client->index([
            'index'   => (string) config('elastic_audit_metrics.index_alias_write'),
            'id'      => 'expired-metric-document',
            'refresh' => 'wait_for',
            'body'    => [
                '@timestamp'     => now()->subDays(2)->toIso8601ZuluString('millisecond'),
                'event_id'       => 'expired-metric-document',
                'schema_version' => MetricData::SCHEMA_VERSION,
                'trace'          => ['id' => 'expired-trace', 'span_id' => 'expired-span'],
                'transaction'    => ['id' => 'expired-span', 'sampled' => true, 'span_count' => 0],
                'kind'           => MetricData::KIND_TRANSACTION,
                'type'           => MetricData::TYPE_HTTP_SERVER,
                'name'           => 'GET expired',
                'outcome'        => MetricData::OUTCOME_SUCCESS,
                'duration_ms'    => 1.0,
                'retention_days' => 1,
            ],
        ]);
    }

    private function indexExpiredProfileDocument(LogElasticsearchClientInterface $client): void
    {
        $client->index([
            'index'   => (string) config('elastic_audit_metrics.profiles.index_alias_write'),
            'id'      => 'expired-profile-document',
            'refresh' => 'wait_for',
            'body'    => [
                '@timestamp'     => now()->subDays(2)->toIso8601ZuluString('millisecond'),
                'profile_id'     => 'expired-profile-document',
                'schema_version' => ProfileData::SCHEMA_VERSION,
                'trace'          => ['id' => 'expired-trace', 'transaction_id' => 'expired-span'],
                'duration_ms'    => 1.0,
                'driver'         => 'excimer',
                'mode'           => 'sampling',
                'format'         => 'speedscope',
                'sample_count'   => 0,
                'truncated'      => false,
                'hot_frames'     => [],
                'payload'        => [],
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
