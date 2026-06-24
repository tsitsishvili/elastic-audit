<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Bus;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogContext;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogContext;
use Tsitsishvili\ElasticAudit\Facades\HttpLog;
use Tsitsishvili\ElasticAudit\Jobs\LogActivityJob;
use Tsitsishvili\ElasticAudit\Services\ActivityLogger;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Services\HttpLogIndexer;
use Tsitsishvili\ElasticAudit\Services\Redactors\SensitiveDataRedactor;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEntityType;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEventType;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestProvider;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class ElasticAuditServiceProviderTest extends TestCase
{
    public function test_registers_log_elasticsearch_client_as_singleton(): void
    {
        $a = $this->app->make(LogElasticsearchClientInterface::class);
        $b = $this->app->make(LogElasticsearchClientInterface::class);

        $this->assertSame($a, $b);
    }

    public function test_registers_log_indexer_as_singleton(): void
    {
        $a = $this->app->make(HttpLogIndexer::class);
        $b = $this->app->make(HttpLogIndexer::class);

        $this->assertSame($a, $b);
    }

    public function test_http_logs_config_is_merged(): void
    {
        $this->assertNotNull(config('http_logs.enabled'));
    }

    public function test_log_elasticsearch_config_is_merged(): void
    {
        $this->assertNotNull(config('log_elasticsearch.hosts'));
    }

    public function test_facade_make_returns_pending_request(): void
    {
        $context = HttpLogContext::forEntity(
            entityType: TestEntityType::Order,
            entityId: '1',
        );

        $client = HttpLog::make(
            provider: TestProvider::Delivery,
            eventType: TestEventType::DeliveryOrderCreate,
            context: $context,
        );

        $this->assertInstanceOf(PendingRequest::class, $client);
    }

    public function test_payment_provider_uses_payment_redactor(): void
    {
        $context = HttpLogContext::forEntity(
            entityType: TestEntityType::Order,
            entityId: '1',
        );

        $client = HttpLog::make(
            provider: TestProvider::Payment,
            eventType: TestEventType::PaymentCallback,
            context: $context,
        );

        $this->assertInstanceOf(PendingRequest::class, $client);
    }

    public function test_redactor_applies_configured_allow_and_block_lists(): void
    {
        config([
            'http_logs.redaction.body.allow' => ['email'],
            'http_logs.redaction.body.block' => ['customer_reference'],
        ]);

        $redactor = $this->app->make(SensitiveDataRedactor::class);

        $result = $redactor->redactBody([
            'email'              => 'user@example.com',
            'customer_reference' => 'CR-1',
            'password'           => 'secret',
        ]);

        $this->assertSame('user@example.com', $result['email']);
        $this->assertSame('[REDACTED]', $result['customer_reference']);
        $this->assertSame('[REDACTED]', $result['password']);
    }

    public function test_activity_logger_applies_configured_redaction(): void
    {
        config([
            'activity_logs.redaction.allow' => ['email'],
            'activity_logs.redaction.block' => ['internal_note'],
        ]);

        $logger = $this->app->make(ActivityLogger::class);

        Bus::fake();
        $logger->record(
            action: 'user.updated',
            context: ActivityLogContext::forActor(
                actorType: 'user',
                actorId: 1,
                entityType: TestEntityType::Order,
                entityId: '1',
            ),
            changes: [
                'email'         => ['old' => 'a@x.com', 'new' => 'b@x.com'], // allowed → kept
                'internal_note' => ['old' => 'x', 'new' => 'y'],            // blocked → redacted
                'password'      => ['old' => 'h1', 'new' => 'h2'],          // default → redacted
            ],
        );

        Bus::assertDispatched(LogActivityJob::class, function (LogActivityJob $job) {
            return $job->data->changes['email'] === ['old' => 'a@x.com', 'new' => 'b@x.com']
                && $job->data->changes['internal_note'] === '[REDACTED]'
                && $job->data->changes['password'] === '[REDACTED]';
        });
    }

    public function test_elasticsearch_client_uses_basic_auth_when_credentials_configured(): void
    {
        // Set credentials before first resolution so the lazy singleton closure picks them up
        config([
            'log_elasticsearch.basicAuthentication.username' => 'elastic',
            'log_elasticsearch.basicAuthentication.password' => 'secret',
        ]);

        $client = $this->app->make(LogElasticsearchClientInterface::class);

        $this->assertInstanceOf(LogElasticsearchClientInterface::class, $client);
    }
}
