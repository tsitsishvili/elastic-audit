<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

use Illuminate\Console\Command;
use Tsitsishvili\ElasticAudit\Contracts\EntityTypeContract;
use Tsitsishvili\ElasticAudit\Contracts\EventTypeContract;
use Tsitsishvili\ElasticAudit\Contracts\ProviderContract;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchLifecycle;
use Throwable;

class ElasticAuditHealthCommand extends Command
{
    protected $signature = 'elastic-audit:health {--all : Check aliases even for disabled subsystems}';

    protected $description = 'Check Elasticsearch connectivity, aliases, lifecycle, enum, and queue job configuration for Elastic Audit.';

    public function handle(LogElasticsearchClientInterface $client): int
    {
        $failed = false;

        if (! $client->ping()) {
            $this->error('Elasticsearch cluster is not reachable.');

            return self::FAILURE;
        }

        $this->info('Elasticsearch cluster reachable.');

        $failed = $this->checkSubsystem(
            $client,
            'HTTP logs',
            'http_logs',
            (bool) config('http_logs.enabled', false),
            (string) config('http_logs.index_alias'),
            (string) config('http_logs.index_alias_write'),
            (string) config('http_logs.queue', 'default'),
        ) || $failed;

        $failed = $this->checkHttpEnums((bool) config('http_logs.enabled', false)) || $failed;

        $failed = $this->checkSubsystem(
            $client,
            'Activity logs',
            'activity_logs',
            (bool) config('activity_logs.enabled', true),
            (string) config('activity_logs.index_alias'),
            (string) config('activity_logs.index_alias_write'),
            (string) config('activity_logs.queue', 'default'),
        ) || $failed;

        $failed = $this->checkLifecycle() || $failed;

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function checkSubsystem(
        LogElasticsearchClientInterface $client,
        string $label,
        string $configKey,
        bool $enabled,
        string $readAlias,
        string $writeAlias,
        string $queue,
    ): bool {
        if (! $enabled && ! $this->option('all')) {
            $this->line("{$label}: disabled; alias checks skipped.");

            return false;
        }

        $failed = false;

        if ($readAlias === '' || $writeAlias === '') {
            $this->error("{$label}: read/write aliases must not be empty.");
            $failed = true;
        } elseif ($readAlias === $writeAlias) {
            $this->error("{$label}: read and write aliases must be different.");
            $failed = true;
        }

        if ($queue === '') {
            $this->error("{$label}: queue name is empty.");
            $failed = true;
        } else {
            $this->info("{$label}: queue={$queue}.");
        }

        $failed = $this->checkJobOptions($label, $configKey) || $failed;

        foreach (['read' => $readAlias, 'write' => $writeAlias] as $kind => $alias) {
            if ($alias === '') {
                continue;
            }

            try {
                if (! $client->existsAlias($alias)) {
                    $this->error("{$label}: {$kind} alias missing: {$alias}");
                    $failed = true;

                    continue;
                }

                $this->info("{$label}: {$kind} alias exists: {$alias}");
            } catch (Throwable $e) {
                $this->error("{$label}: failed checking {$kind} alias {$alias}: " . $e->getMessage());
                $failed = true;
            }
        }

        return $failed;
    }

    private function checkJobOptions(string $label, string $configKey): bool
    {
        $failed = false;

        foreach (['tries', 'timeout'] as $key) {
            $value = config("{$configKey}.job.{$key}");

            if (! is_numeric($value) || (int) $value < 1) {
                $this->error("{$label}: job.{$key} must be a positive integer.");
                $failed = true;
            }
        }

        $batchTimeout = config("{$configKey}.job.batch_timeout");
        if (! is_numeric($batchTimeout) || (int) $batchTimeout < 1) {
            $this->error("{$label}: job.batch_timeout must be a positive integer.");
            $failed = true;
        }

        $backoff = config("{$configKey}.job.backoff");
        if (is_string($backoff)) {
            $backoff = explode(',', $backoff);
        }

        if (! is_array($backoff) || $backoff === []) {
            $this->error("{$label}: job.backoff must contain at least one non-negative integer.");

            return true;
        }

        foreach ($backoff as $value) {
            if (! is_numeric($value) || (int) $value < 0) {
                $this->error("{$label}: job.backoff must contain only non-negative integers.");
                $failed = true;

                break;
            }
        }

        if (! $failed) {
            $this->info("{$label}: job retry options valid.");
        }

        return $failed;
    }

    private function checkHttpEnums(bool $enabled): bool
    {
        if (! $enabled && ! $this->option('all')) {
            return false;
        }

        $failed = false;

        $checks = [
            'provider'    => ProviderContract::class,
            'event_type'  => EventTypeContract::class,
            'entity_type' => EntityTypeContract::class,
        ];

        foreach ($checks as $key => $contract) {
            $class = config("http_logs.enums.{$key}");

            if (! $this->isBackedEnumContract($class, $contract)) {
                $this->error("HTTP logs: enums.{$key} must be a backed enum implementing {$contract}.");
                $failed = true;

                continue;
            }

            $this->info("HTTP logs: enums.{$key}={$class}.");
        }

        return $failed;
    }

    /**
     * @param class-string $contract
     */
    private function isBackedEnumContract(mixed $class, string $contract): bool
    {
        return is_string($class)
            && enum_exists($class)
            && is_subclass_of($class, \BackedEnum::class)
            && is_subclass_of($class, $contract);
    }

    private function checkLifecycle(): bool
    {
        $failed = false;

        if (! ElasticsearchLifecycle::enabled()) {
            $this->line('Lifecycle disabled; prune commands are the fallback/manual retention path.');

            return false;
        }

        $policyName = ElasticsearchLifecycle::policyName();
        if ($policyName === '') {
            $this->error('Lifecycle enabled but log_elasticsearch.lifecycle.policy_name is empty.');
            $failed = true;
        } else {
            $this->info('Lifecycle enabled: ' . $policyName);
        }

        if (ElasticsearchLifecycle::rolloverConditions() === []) {
            $this->error('Lifecycle enabled but no rollover conditions are configured.');
            $failed = true;
        }

        $deleteAfter = config('log_elasticsearch.lifecycle.delete_after');
        if (! is_string($deleteAfter) || $deleteAfter === '') {
            $this->error('Lifecycle enabled but log_elasticsearch.lifecycle.delete_after is empty; configure ILM deletion or schedule prune commands as fallback.');
            $failed = true;
        }

        return $failed;
    }
}
