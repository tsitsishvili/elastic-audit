<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\ServiceProvider;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogContext;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogContext;
use Tsitsishvili\ElasticAudit\ElasticAuditServiceProvider;
use Tsitsishvili\ElasticAudit\Facades\HttpLog;
use Tsitsishvili\ElasticAudit\Jobs\LogActivityJob;
use Tsitsishvili\ElasticAudit\Services\ActivityLogger;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Services\HttpLogIndexer;
use Tsitsishvili\ElasticAudit\Services\Redactors\HttpPayloadRedactorResolver;
use Tsitsishvili\ElasticAudit\Services\Redactors\SensitiveDataRedactor;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEntityType;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEventType;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestProvider;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class ElasticAuditServiceProviderTest extends TestCase
{
    public function test_shared_index_prefix_derives_all_default_aliases_and_policy_name(): void
    {
        config([
            'log_elasticsearch.index_prefix'          => 'central_logs',
            'log_elasticsearch.lifecycle.policy_name' => null,
            'http_logs.index_alias'                   => null,
            'http_logs.index_alias_write'             => null,
            'activity_logs.index_alias'               => null,
            'activity_logs.index_alias_write'         => null,
        ]);

        (new ElasticAuditServiceProvider($this->app))->register();

        $this->assertSame('central_logs_http_logs', config('http_logs.index_alias'));
        $this->assertSame('central_logs_http_logs_write', config('http_logs.index_alias_write'));
        $this->assertSame('central_logs_activity_logs', config('activity_logs.index_alias'));
        $this->assertSame('central_logs_activity_logs_write', config('activity_logs.index_alias_write'));
        $this->assertSame('central_logs_elastic_audit_policy', config('log_elasticsearch.lifecycle.policy_name'));
    }

    public function test_explicit_aliases_and_policy_name_override_shared_prefix_defaults(): void
    {
        config([
            'log_elasticsearch.index_prefix'          => 'central_logs',
            'log_elasticsearch.lifecycle.policy_name' => 'custom_policy',
            'http_logs.index_alias'                   => 'custom_http',
            'http_logs.index_alias_write'             => 'custom_http_write',
            'activity_logs.index_alias'               => 'custom_activity',
            'activity_logs.index_alias_write'         => 'custom_activity_write',
        ]);

        (new ElasticAuditServiceProvider($this->app))->register();

        $this->assertSame('custom_http', config('http_logs.index_alias'));
        $this->assertSame('custom_http_write', config('http_logs.index_alias_write'));
        $this->assertSame('custom_activity', config('activity_logs.index_alias'));
        $this->assertSame('custom_activity_write', config('activity_logs.index_alias_write'));
        $this->assertSame('custom_policy', config('log_elasticsearch.lifecycle.policy_name'));
    }

    public function test_main_publish_tag_exposes_enum_stubs_outside_the_package_namespace(): void
    {
        $paths = ServiceProvider::pathsToPublish(
            ElasticAuditServiceProvider::class,
            'elastic-audit',
        );

        $stubSource = realpath(__DIR__.'/../../stubs/Enums/ElasticAudit');
        $sources    = array_map('realpath', array_keys($paths));

        $this->assertNotFalse($stubSource);
        $this->assertContains($stubSource, $sources);
        $this->assertSame(
            app_path('Enums/ElasticAudit'),
            array_values($paths)[array_search($stubSource, $sources, true)],
        );
    }

    public function test_dashboard_assets_have_a_dedicated_publish_tag(): void
    {
        $paths = ServiceProvider::pathsToPublish(
            ElasticAuditServiceProvider::class,
            'elastic-audit-assets',
        );

        $this->assertCount(1, $paths);
        $this->assertSame(
            public_path('vendor/elastic-audit'),
            array_values($paths)[0],
        );
        $this->assertFileExists(array_keys($paths)[0].'/manifest.json');
    }

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

    public function test_registers_http_payload_redactor_resolver_as_singleton(): void
    {
        $this->assertSame(
            $this->app->make(HttpPayloadRedactorResolver::class),
            $this->app->make(HttpPayloadRedactorResolver::class),
        );
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

    public function test_undecodable_body_mode_from_config_reaches_the_redactor(): void
    {
        config(['http_logs.undecodable_body_mode' => 'preview']);
        $this->app->forgetInstance(SensitiveDataRedactor::class);

        $payload = $this->app->make(SensitiveDataRedactor::class)
            ->buildPayload([], '<order><id>42</id></order>', 32768, 4096);

        $this->assertSame('<order><id>42</id></order>', $payload->bodyPreview);
    }

    public function test_body_capture_max_bytes_from_config_reaches_the_redactor(): void
    {
        config(['http_logs.body_capture_max_bytes' => 10]);
        $this->app->forgetInstance(SensitiveDataRedactor::class);

        $payload = $this->app->make(SensitiveDataRedactor::class)
            ->buildPayload([], json_encode(['order_id' => 42]), 32768, 4096);

        $this->assertNull($payload->body);
        $this->assertNull($payload->bodyPreview);
        $this->assertNull($payload->bodyHash);
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
                entityType: 'order',
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
                && $job->data->changes['internal_note'] === ['old' => '[REDACTED]', 'new' => '[REDACTED]']
                && $job->data->changes['password'] === ['old' => '[REDACTED]', 'new' => '[REDACTED]'];
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
