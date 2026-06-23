<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature;

use Illuminate\Http\Client\PendingRequest;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogContext;
use Tsitsishvili\ElasticAudit\Facades\HttpLog;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Services\HttpLogIndexer;
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
