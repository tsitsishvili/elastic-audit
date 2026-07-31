<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\FakeLogElasticsearchClient;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\IntBackedProvider;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class ElasticAuditOperationsCommandTest extends TestCase
{
    public function test_health_command_succeeds_when_cluster_and_aliases_are_available(): void
    {
        config(['http_logs.enabled' => true, 'activity_logs.enabled' => true]);

        $this->app->instance(LogElasticsearchClientInterface::class, new FakeLogElasticsearchClient);

        $this->artisan('elastic-audit:health')->assertSuccessful();
    }

    public function test_health_fails_when_service_name_is_empty(): void
    {
        config(['app.name' => '']);
        $this->app->instance(LogElasticsearchClientInterface::class, new FakeLogElasticsearchClient);

        $this->artisan('elastic-audit:health')
            ->expectsOutputToContain('app.name must be a non-empty string')
            ->assertFailed();
    }

    public function test_health_warns_but_passes_on_the_default_service_name(): void
    {
        config(['app.name' => 'Laravel']);
        $this->app->instance(LogElasticsearchClientInterface::class, new FakeLogElasticsearchClient);

        $this->artisan('elastic-audit:health')
            ->expectsOutputToContain('still the framework default')
            ->assertSuccessful();
    }

    public function test_health_does_not_warn_on_a_configured_service_name(): void
    {
        config(['app.name' => 'billing-api']);
        $this->app->instance(LogElasticsearchClientInterface::class, new FakeLogElasticsearchClient);

        $this->artisan('elastic-audit:health')
            ->doesntExpectOutputToContain('still the framework default')
            ->assertSuccessful();
    }

    public function test_health_json_emits_one_machine_readable_success_result(): void
    {
        config(['http_logs.enabled' => true, 'activity_logs.enabled' => true]);
        $this->app->instance(LogElasticsearchClientInterface::class, new FakeLogElasticsearchClient);

        $status = Artisan::call('elastic-audit:health', ['--json' => true]);
        $result = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $status);
        $this->assertTrue($result['ok']);
        $this->assertNotEmpty($result['checks']);
        $this->assertContains('ok', array_column($result['checks'], 'status'));
    }

    public function test_health_json_preserves_failure_exit_code_and_error_check(): void
    {
        $fake = new class extends FakeLogElasticsearchClient
        {
            public function ping(): bool
            {
                return false;
            }
        };
        $this->app->instance(LogElasticsearchClientInterface::class, $fake);

        $status = Artisan::call('elastic-audit:health', ['--json' => true]);
        $result = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $status);
        $this->assertFalse($result['ok']);
        $this->assertSame('error', $result['checks'][0]['status']);
    }

    public function test_health_fails_when_write_index_mapping_is_incompatible(): void
    {
        config(['http_logs.enabled' => true, 'activity_logs.enabled' => false]);

        $fake = new class extends FakeLogElasticsearchClient
        {
            public function getMapping(string $index): array
            {
                $mapping                                                      = parent::getMapping($index);
                $mapping[$index]['mappings']['properties']['user_id']['type'] = 'long';

                return $mapping;
            }
        };
        $this->app->instance(LogElasticsearchClientInterface::class, $fake);

        $this->artisan('elastic-audit:health')
            ->expectsOutputToContain('properties.user_id.type expected "keyword"')
            ->assertFailed();
    }

    public function test_health_fails_when_index_template_mapping_is_incompatible(): void
    {
        config(['http_logs.enabled' => true, 'activity_logs.enabled' => false]);

        $fake = new class extends FakeLogElasticsearchClient
        {
            public function getIndexTemplate(string $name): array
            {
                $template                                                                                                   = parent::getIndexTemplate($name);
                $template['index_templates'][0]['index_template']['template']['mappings']['properties']['event_id']['type'] = 'text';

                return $template;
            }
        };
        $this->app->instance(LogElasticsearchClientInterface::class, $fake);

        $this->artisan('elastic-audit:health')
            ->expectsOutputToContain('properties.event_id.type expected "keyword"')
            ->assertFailed();
    }

    public function test_health_accepts_structurally_compatible_mapping_without_schema_metadata(): void
    {
        config(['http_logs.enabled' => true, 'activity_logs.enabled' => false]);

        $fake = new class extends FakeLogElasticsearchClient
        {
            public function getMapping(string $index): array
            {
                $mapping = parent::getMapping($index);
                unset($mapping[$index]['mappings']['_meta']);

                return $mapping;
            }

            public function getIndexTemplate(string $name): array
            {
                $template = parent::getIndexTemplate($name);
                unset($template['index_templates'][0]['index_template']['template']['mappings']['_meta']);

                return $template;
            }
        };
        $this->app->instance(LogElasticsearchClientInterface::class, $fake);

        $this->artisan('elastic-audit:health')
            ->expectsOutputToContain('has no Elastic Audit schema metadata; structural mapping is compatible')
            ->assertSuccessful();
    }

    public function test_health_fails_when_schema_metadata_is_incompatible(): void
    {
        config(['http_logs.enabled' => true, 'activity_logs.enabled' => false]);

        $fake = new class extends FakeLogElasticsearchClient
        {
            public function getMapping(string $index): array
            {
                $mapping                                                                 = parent::getMapping($index);
                $mapping[$index]['mappings']['_meta']['elastic_audit']['schema_version'] = 99;

                return $mapping;
            }
        };
        $this->app->instance(LogElasticsearchClientInterface::class, $fake);

        $this->artisan('elastic-audit:health')
            ->expectsOutputToContain('schema metadata is incompatible')
            ->assertFailed();
    }

    public function test_health_remains_compatible_with_custom_clients_that_do_not_support_schema_inspection(): void
    {
        config(['http_logs.enabled' => false, 'activity_logs.enabled' => true]);

        $client = $this->createStub(LogElasticsearchClientInterface::class);
        $client->method('ping')->willReturn(true);
        $client->method('existsAlias')->willReturn(true);
        $client->method('getAlias')->willReturnCallback(static function (string $name): array {
            $baseName = str_ends_with($name, '_write') ? substr($name, 0, -6) : $name;

            return [
                "{$baseName}-000001" => [
                    'aliases' => [$name => ['is_write_index' => true]],
                ],
            ];
        });

        $this->app->instance(LogElasticsearchClientInterface::class, $client);

        $this->artisan('elastic-audit:health')
            ->expectsOutputToContain('schema inspection unavailable for the custom Elasticsearch client')
            ->assertSuccessful();
    }

    public function test_health_command_fails_when_cluster_is_unreachable(): void
    {
        $fake = new class extends FakeLogElasticsearchClient
        {
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
            'http_logs.enabled'        => true,
            'http_logs.enums.provider' => null,
        ]);

        $this->app->instance(LogElasticsearchClientInterface::class, new FakeLogElasticsearchClient);

        $this->artisan('elastic-audit:health')
            ->expectsOutputToContain('HTTP logs: enums.provider')
            ->assertFailed();
    }

    public function test_health_all_checks_disabled_http_aliases_without_validating_unused_http_options(): void
    {
        config([
            'http_logs.enabled'                => false,
            'http_logs.enums.provider'         => null,
            'http_logs.enums.event_type'       => null,
            'http_logs.enums.entity_type'      => null,
            'http_logs.queue'                  => '',
            'http_logs.job.tries'              => 0,
            'http_logs.retention_days'         => 0,
            'http_logs.sample_rate'            => 2,
            'http_logs.body_preview_bytes'     => 200,
            'http_logs.body_max_bytes'         => 100,
            'http_logs.body_capture_max_bytes' => 50,
        ]);

        $fake = new class extends FakeLogElasticsearchClient
        {
            /** @var list<string> */
            public array $checkedAliases = [];

            public function existsAlias(string $name): bool
            {
                $this->checkedAliases[] = $name;

                return parent::existsAlias($name);
            }
        };

        $this->app->instance(LogElasticsearchClientInterface::class, $fake);

        $this->artisan('elastic-audit:health --all')
            ->expectsOutputToContain('HTTP logs: disabled; checking aliases because --all was supplied.')
            ->assertSuccessful();

        $this->assertContains(config('http_logs.index_alias'), $fake->checkedAliases);
        $this->assertContains(config('http_logs.index_alias_write'), $fake->checkedAliases);
    }

    public function test_health_command_rejects_integer_backed_http_enum(): void
    {
        config([
            'http_logs.enabled'        => true,
            'http_logs.enums.provider' => IntBackedProvider::class,
        ]);

        $this->app->instance(LogElasticsearchClientInterface::class, new FakeLogElasticsearchClient);

        $this->artisan('elastic-audit:health')
            ->expectsOutputToContain('must be a string-backed enum')
            ->assertFailed();
    }

    public function test_health_command_rejects_invalid_http_capture_options(): void
    {
        config([
            'http_logs.enabled'                => true,
            'http_logs.sample_rate'            => 1.5,
            'http_logs.body_preview_bytes'     => 200,
            'http_logs.body_max_bytes'         => 100,
            'http_logs.body_capture_max_bytes' => 50,
            'http_logs.undecodable_body_mode'  => 'raw',
            'http_logs.payment_body_mode'      => 'raw',
        ]);

        $this->app->instance(LogElasticsearchClientInterface::class, new FakeLogElasticsearchClient);

        $this->artisan('elastic-audit:health')
            ->expectsOutputToContain('sample_rate must be a number between 0.0 and 1.0')
            ->expectsOutputToContain('body byte limits must satisfy preview <= max <= capture')
            ->expectsOutputToContain('undecodable_body_mode must be metadata or preview')
            ->expectsOutputToContain('payment_body_mode must be metadata or preview')
            ->assertFailed();
    }

    public function test_health_command_fails_when_job_options_are_invalid(): void
    {
        config([
            'activity_logs.enabled'     => true,
            'activity_logs.job.tries'   => 0,
            'activity_logs.job.backoff' => ['10', '-1'],
        ]);

        $this->app->instance(LogElasticsearchClientInterface::class, new FakeLogElasticsearchClient);

        $this->artisan('elastic-audit:health')
            ->expectsOutputToContain('Activity logs: job.tries')
            ->expectsOutputToContain('Activity logs: job.backoff')
            ->assertFailed();
    }

    public function test_health_command_rejects_fractional_job_options(): void
    {
        config([
            'activity_logs.enabled'           => true,
            'activity_logs.job.tries'         => 1.5,
            'activity_logs.job.timeout'       => '30.5',
            'activity_logs.job.batch_timeout' => 90.5,
            'activity_logs.job.backoff'       => ['10', '20.5'],
        ]);

        $this->app->instance(LogElasticsearchClientInterface::class, new FakeLogElasticsearchClient);

        $this->artisan('elastic-audit:health')
            ->expectsOutputToContain('Activity logs: job.tries')
            ->expectsOutputToContain('Activity logs: job.timeout')
            ->expectsOutputToContain('Activity logs: job.batch_timeout')
            ->expectsOutputToContain('Activity logs: job.backoff')
            ->assertFailed();
    }

    public function test_health_command_fails_when_retention_is_outside_mapping_range(): void
    {
        config([
            'activity_logs.enabled'        => true,
            'activity_logs.retention_days' => 0,
        ]);

        $this->app->instance(LogElasticsearchClientInterface::class, new FakeLogElasticsearchClient);

        $this->artisan('elastic-audit:health')
            ->expectsOutputToContain('Activity logs: retention_days must be an integer between 1 and 32767.')
            ->assertFailed();
    }

    public function test_health_command_fails_when_alias_config_is_invalid(): void
    {
        config([
            'activity_logs.enabled'           => true,
            'activity_logs.index_alias'       => 'same_alias',
            'activity_logs.index_alias_write' => 'same_alias',
        ]);

        $this->app->instance(LogElasticsearchClientInterface::class, new FakeLogElasticsearchClient);

        $this->artisan('elastic-audit:health')
            ->expectsOutputToContain('Activity logs: read and write aliases must be different.')
            ->assertFailed();
    }

    public function test_health_command_fails_when_alias_contains_invalid_index_characters(): void
    {
        config([
            'activity_logs.enabled'     => true,
            'activity_logs.index_alias' => 'Example App activity',
        ]);

        $this->app->instance(LogElasticsearchClientInterface::class, new FakeLogElasticsearchClient);

        $this->artisan('elastic-audit:health')
            ->expectsOutputToContain('Activity logs: read alias [Example App activity] is invalid')
            ->assertFailed();
    }

    public function test_health_command_fails_when_sole_write_alias_index_is_explicitly_non_writable(): void
    {
        config(['activity_logs.enabled' => true]);

        $fake = new class extends FakeLogElasticsearchClient
        {
            public function getAlias(string $name): array
            {
                $baseName = str_ends_with($name, '_write') ? substr($name, 0, -6) : $name;

                return [
                    "{$baseName}-000001" => [
                        'aliases' => [$name => ['is_write_index' => false]],
                    ],
                ];
            }
        };

        $this->app->instance(LogElasticsearchClientInterface::class, $fake);

        $this->artisan('elastic-audit:health')
            ->expectsOutputToContain('must resolve to exactly one writable index')
            ->assertFailed();
    }

    public function test_health_command_accepts_an_implicit_single_write_index(): void
    {
        config(['http_logs.enabled' => false, 'activity_logs.enabled' => true]);

        $fake = new class extends FakeLogElasticsearchClient
        {
            public function getAlias(string $name): array
            {
                $baseName = str_ends_with($name, '_write') ? substr($name, 0, -6) : $name;

                return [
                    "{$baseName}-000001" => [
                        'aliases' => [$name => []],
                    ],
                ];
            }
        };

        $this->app->instance(LogElasticsearchClientInterface::class, $fake);

        $this->artisan('elastic-audit:health')->assertSuccessful();
    }

    public function test_health_command_fails_when_current_write_index_is_absent_from_read_alias(): void
    {
        config([
            'http_logs.enabled'     => false,
            'activity_logs.enabled' => true,
        ]);

        $fake = new class extends FakeLogElasticsearchClient
        {
            public function getAlias(string $name): array
            {
                $index = str_ends_with($name, '_write') ? 'activity-000002' : 'activity-000001';

                return [
                    $index => [
                        'aliases' => [$name => ['is_write_index' => true]],
                    ],
                ];
            }
        };

        $this->app->instance(LogElasticsearchClientInterface::class, $fake);

        $this->artisan('elastic-audit:health')
            ->expectsOutputToContain('is missing from read alias')
            ->assertFailed();
    }

    public function test_health_command_fails_when_lifecycle_delete_phase_is_missing(): void
    {
        config([
            'log_elasticsearch.lifecycle.enabled'      => true,
            'log_elasticsearch.lifecycle.delete_after' => null,
        ]);

        $this->app->instance(LogElasticsearchClientInterface::class, new FakeLogElasticsearchClient);

        $this->artisan('elastic-audit:health')
            ->expectsOutputToContain('lifecycle.delete_after is empty')
            ->assertFailed();
    }

    public function test_health_command_accepts_permanent_retention_when_index_deletion_is_disabled(): void
    {
        config([
            'activity_logs.retain_forever'               => true,
            'log_elasticsearch.lifecycle.enabled'        => true,
            'log_elasticsearch.lifecycle.delete_enabled' => false,
        ]);

        $this->app->instance(LogElasticsearchClientInterface::class, new FakeLogElasticsearchClient);

        $this->artisan('elastic-audit:health')
            ->expectsOutputToContain('default document retention is forever')
            ->expectsOutputToContain('rolled-over indexes are retained forever')
            ->assertSuccessful();
    }

    public function test_health_command_rejects_permanent_default_while_index_deletion_is_enabled(): void
    {
        config([
            'activity_logs.retain_forever'               => true,
            'log_elasticsearch.lifecycle.enabled'        => true,
            'log_elasticsearch.lifecycle.delete_enabled' => true,
        ]);

        $this->app->instance(LogElasticsearchClientInterface::class, new FakeLogElasticsearchClient);

        $this->artisan('elastic-audit:health')
            ->expectsOutputToContain('cannot guarantee permanent storage')
            ->assertFailed();
    }

    public function test_lifecycle_policy_command_puts_configured_policy(): void
    {
        $fake = new class extends FakeLogElasticsearchClient
        {
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
        $this->assertArrayHasKey('delete', $fake->policy['phases']);
    }

    public function test_lifecycle_policy_command_fails_when_enabled_delete_phase_has_no_age(): void
    {
        config([
            'log_elasticsearch.lifecycle.delete_enabled' => true,
            'log_elasticsearch.lifecycle.delete_after'   => null,
        ]);

        $this->app->instance(LogElasticsearchClientInterface::class, new FakeLogElasticsearchClient);

        $this->artisan('elastic-audit:lifecycle-policy')
            ->expectsOutputToContain('delete_after must be a non-empty string')
            ->assertFailed();
    }

    public function test_lifecycle_policy_omits_delete_phase_when_index_deletion_is_disabled(): void
    {
        config(['log_elasticsearch.lifecycle.delete_enabled' => false]);

        $fake = new class extends FakeLogElasticsearchClient
        {
            public array $policy = [];

            public function putLifecyclePolicy(string $name, array $policy): void
            {
                $this->policy = $policy;
            }
        };

        $this->app->instance(LogElasticsearchClientInterface::class, $fake);

        $this->artisan('elastic-audit:lifecycle-policy')->assertSuccessful();

        $this->assertArrayHasKey('hot', $fake->policy['phases']);
        $this->assertArrayNotHasKey('delete', $fake->policy['phases']);
    }

    public function test_http_rollover_command_uses_write_alias_and_conditions(): void
    {
        $fake = new class extends FakeLogElasticsearchClient
        {
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
        $fake = new class extends FakeLogElasticsearchClient
        {
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

        $this->assertSame(config('http_logs.index_alias').'-000001', $fake->newIndex);
    }
}
