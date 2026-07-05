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

    public function test_health_command_fails_when_http_enum_config_is_invalid(): void
    {
        config([
            'http_logs.enabled' => true,
            'http_logs.enums.provider' => null,
        ]);

        $this->app->instance(LogElasticsearchClientInterface::class, new FakeLogElasticsearchClient());

        $this->artisan('elastic-audit:health')
            ->expectsOutputToContain('HTTP logs: enums.provider')
            ->assertFailed();
    }

    public function test_health_command_fails_when_job_options_are_invalid(): void
    {
        config([
            'activity_logs.enabled' => true,
            'activity_logs.job.tries' => 0,
            'activity_logs.job.backoff' => ['10', '-1'],
        ]);

        $this->app->instance(LogElasticsearchClientInterface::class, new FakeLogElasticsearchClient());

        $this->artisan('elastic-audit:health')
            ->expectsOutputToContain('Activity logs: job.tries')
            ->expectsOutputToContain('Activity logs: job.backoff')
            ->assertFailed();
    }

    public function test_health_command_fails_when_alias_config_is_invalid(): void
    {
        config([
            'activity_logs.enabled' => true,
            'activity_logs.index_alias' => 'same_alias',
            'activity_logs.index_alias_write' => 'same_alias',
        ]);

        $this->app->instance(LogElasticsearchClientInterface::class, new FakeLogElasticsearchClient());

        $this->artisan('elastic-audit:health')
            ->expectsOutputToContain('Activity logs: read and write aliases must be different.')
            ->assertFailed();
    }

    public function test_health_command_fails_when_lifecycle_delete_phase_is_missing(): void
    {
        config([
            'log_elasticsearch.lifecycle.enabled' => true,
            'log_elasticsearch.lifecycle.delete_after' => null,
        ]);

        $this->app->instance(LogElasticsearchClientInterface::class, new FakeLogElasticsearchClient());

        $this->artisan('elastic-audit:health')
            ->expectsOutputToContain('lifecycle.delete_after is empty')
            ->assertFailed();
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
            public ?string $newIndex = 'unset';

            public function rollover(string $alias, array $conditions, ?string $newIndex = null): array
            {
                $this->alias      = $alias;
                $this->conditions = $conditions;
                $this->newIndex   = $newIndex;

                return ['rolled_over' => true];
            }
        };

        $this->app->instance(LogElasticsearchClientInterface::class, $fake);

        $this->artisan('http-logs:rollover')->assertSuccessful();

        $this->assertSame(config('http_logs.index_alias_write'), $fake->alias);
        $this->assertArrayHasKey('max_age', $fake->conditions);
        $this->assertNull($fake->newIndex);
    }

    public function test_http_rollover_command_explicitly_names_next_index_for_legacy_write_index(): void
    {
        $fake = new class extends FakeLogElasticsearchClient {
            public ?string $newIndex = null;

            public function getAlias(string $name): array
            {
                return [
                    'app_http_logs_20260627_084222' => [
                        'aliases' => [
                            $name => ['is_write_index' => true],
                        ],
                    ],
                ];
            }

            public function existsIndex(string $index): bool
            {
                return false;
            }

            public function rollover(string $alias, array $conditions, ?string $newIndex = null): array
            {
                $this->newIndex = $newIndex;

                return ['rolled_over' => true];
            }
        };

        $this->app->instance(LogElasticsearchClientInterface::class, $fake);

        $this->artisan('http-logs:rollover')->assertSuccessful();

        $this->assertSame(config('http_logs.index_alias') . '-000001', $fake->newIndex);
    }
}
