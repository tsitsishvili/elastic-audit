<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature;

use Tsitsishvili\ElasticAudit\Contracts\FunctionProfiler;
use Tsitsishvili\ElasticAudit\DataTransferObjects\CapturedProfile;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchLifecycle;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\FakeLogElasticsearchClient;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class MetricsOperationsCommandTest extends TestCase
{
    public function test_health_checks_enabled_metrics_aliases_mapping_and_config(): void
    {
        config([
            'http_logs.enabled'                      => false,
            'activity_logs.enabled'                  => false,
            'elastic_audit_metrics.enabled'          => true,
            'elastic_audit_metrics.queue'            => 'telemetry',
            'elastic_audit_metrics.retention_days'   => 14,
            'elastic_audit_metrics.profiles.enabled' => false,
        ]);
        $this->app->instance(LogElasticsearchClientInterface::class, new FakeLogElasticsearchClient);

        $this->artisan('elastic-audit:health')
            ->expectsOutputToContain('Metrics: queue=telemetry.')
            ->expectsOutputToContain('Metrics: retention_days=14.')
            ->expectsOutputToContain('Metrics: read alias exists')
            ->assertSuccessful();
    }

    public function test_health_rejects_invalid_metrics_job_and_retention_config(): void
    {
        config([
            'http_logs.enabled'                    => false,
            'activity_logs.enabled'                => false,
            'elastic_audit_metrics.enabled'        => true,
            'elastic_audit_metrics.job.tries'      => 0,
            'elastic_audit_metrics.retention_days' => 0,
        ]);
        $this->app->instance(LogElasticsearchClientInterface::class, new FakeLogElasticsearchClient);

        $this->artisan('elastic-audit:health')
            ->expectsOutputToContain('Metrics: job.tries must be a positive integer.')
            ->expectsOutputToContain('Metrics: retention_days must be an integer between 1 and 32767.')
            ->assertFailed();
    }

    public function test_health_reports_missing_native_profiler_when_profiles_are_enabled(): void
    {
        config([
            'http_logs.enabled'                      => false,
            'activity_logs.enabled'                  => false,
            'elastic_audit_metrics.enabled'          => true,
            'elastic_audit_metrics.profiles.enabled' => true,
        ]);
        $this->app->instance(LogElasticsearchClientInterface::class, new FakeLogElasticsearchClient);
        $this->app->instance(FunctionProfiler::class, $this->unavailableProfiler());

        $this->artisan('elastic-audit:health')
            ->expectsOutputToContain('ext-excimer (recommended) or ext-xhprof is required')
            ->assertFailed();
    }

    public function test_metrics_prune_uses_metrics_read_alias(): void
    {
        $fake = new class extends FakeLogElasticsearchClient
        {
            public array $deleteCalls = [];

            public function search(array $params): array
            {
                return [
                    'aggregations' => ['retention_buckets' => ['buckets' => [
                        ['key' => ['retention_days' => 30]],
                    ]]],
                ];
            }

            public function deleteByQuery(array $params): array
            {
                $this->deleteCalls[] = $params;

                return ['deleted' => 2];
            }
        };
        $this->app->instance(LogElasticsearchClientInterface::class, $fake);

        $this->artisan('elastic-audit:metrics:prune')->assertSuccessful();

        $this->assertSame(config('elastic_audit_metrics.index_alias'), $fake->deleteCalls[0]['index']);
        $this->assertSame(
            ['term' => ['retention_days' => 30]],
            $fake->deleteCalls[0]['body']['query']['bool']['filter'][0],
        );
    }

    public function test_metrics_rollover_uses_write_alias_and_lifecycle_conditions(): void
    {
        $fake = new class extends FakeLogElasticsearchClient
        {
            public array $rollovers = [];

            public function rollover(string $alias, array $conditions, ?string $newIndex = null): array
            {
                $this->rollovers[] = compact('alias', 'conditions', 'newIndex');

                return ['rolled_over' => false];
            }
        };
        $this->app->instance(LogElasticsearchClientInterface::class, $fake);

        $this->artisan('elastic-audit:metrics:rollover')->assertSuccessful();

        $this->assertSame(config('elastic_audit_metrics.index_alias_write'), $fake->rollovers[0]['alias']);
        $this->assertSame(ElasticsearchLifecycle::rolloverConditions(), $fake->rollovers[0]['conditions']);
        $this->assertNull($fake->rollovers[0]['newIndex']);
    }

    private function unavailableProfiler(): FunctionProfiler
    {
        return new class implements FunctionProfiler
        {
            public function available(): bool
            {
                return false;
            }

            public function driver(): ?string
            {
                return null;
            }

            public function start(): bool
            {
                return false;
            }

            public function stop(): ?CapturedProfile
            {
                return null;
            }
        };
    }
}
