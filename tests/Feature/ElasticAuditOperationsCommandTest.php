<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature;

use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\FakeLogElasticsearchClient;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class ElasticAuditOperationsCommandTest extends TestCase
{
    public function test_health_command_succeeds_when_cluster_and_aliases_are_available(): void
    {
        config(['http_logs.enabled' => true, 'activity_logs.enabled' => true]);

        $this->app->instance(LogElasticsearchClientInterface::class, new FakeLogElasticsearchClient());

        $this->artisan('elastic-audit:health')->assertSuccessful();
    }

    public function test_health_command_fails_when_cluster_is_unreachable(): void
    {
        $fake = new class extends FakeLogElasticsearchClient {
            public function ping(): bool
            {
                return false;
            }
        };

        $this->app->instance(LogElasticsearchClientInterface::class, $fake);

        $this->artisan('elastic-audit:health')->assertFailed();
    }

    public function test_lifecycle_policy_command_puts_configured_policy(): void
    {
        $fake = new class extends FakeLogElasticsearchClient {
            public ?string $policyName = null;
            public array $policy = [];

            public function putLifecyclePolicy(string $name, array $policy): void
            {
                $this->policyName = $name;
                $this->policy     = $policy;
            }
        };

        $this->app->instance(LogElasticsearchClientInterface::class, $fake);

        $this->artisan('elastic-audit:lifecycle-policy')->assertSuccessful();

        $this->assertSame(config('log_elasticsearch.lifecycle.policy_name'), $fake->policyName);
        $this->assertArrayHasKey('phases', $fake->policy);
    }

    public function test_http_rollover_command_uses_write_alias_and_conditions(): void
    {
        $fake = new class extends FakeLogElasticsearchClient {
            public ?string $alias = null;
            public array $conditions = [];

            public function rollover(string $alias, array $conditions): array
            {
                $this->alias      = $alias;
                $this->conditions = $conditions;

                return ['rolled_over' => true];
            }
        };

        $this->app->instance(LogElasticsearchClientInterface::class, $fake);

        $this->artisan('http-logs:rollover')->assertSuccessful();

        $this->assertSame(config('http_logs.index_alias_write'), $fake->alias);
        $this->assertArrayHasKey('max_age', $fake->conditions);
    }
}
