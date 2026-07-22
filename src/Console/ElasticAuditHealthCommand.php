<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

use Illuminate\Console\Command;
use Tsitsishvili\ElasticAudit\Contracts\EntityTypeContract;
use Tsitsishvili\ElasticAudit\Contracts\EventTypeContract;
use Tsitsishvili\ElasticAudit\Contracts\ProviderContract;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchIndexNames;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchLifecycle;
use Tsitsishvili\ElasticAudit\Support\RetentionDays;
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
        $failed = $this->checkHttpCaptureOptions((bool) config('http_logs.enabled', false)) || $failed;

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

        if (! $enabled) {
            $this->line("{$label}: disabled; checking aliases because --all was supplied.");
        }

        if ($readAlias === '' || $writeAlias === '') {
            $this->error("{$label}: read/write aliases must not be empty.");
            $failed = true;
        } elseif ($readAlias === $writeAlias) {
            $this->error("{$label}: read and write aliases must be different.");
            $failed = true;
        }

        foreach (['read' => $readAlias, 'write' => $writeAlias] as $kind => $alias) {
            if ($alias !== '' && ($error = ElasticsearchIndexNames::validationError($alias)) !== null) {
                $this->error("{$label}: {$kind} alias [{$alias}] is invalid: {$error}.");
                $failed = true;
            }
        }

        if ($enabled) {
            if ($queue === '') {
                $this->error("{$label}: queue name is empty.");
                $failed = true;
            } else {
                $this->info("{$label}: queue={$queue}.");
            }

            $failed = $this->checkJobOptions($label, $configKey) || $failed;
            $failed = $this->checkRetentionDays($label, $configKey) || $failed;
        }

        $aliasResponses = [];

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
                $aliasResponses[$kind] = $client->getAlias($alias);
            } catch (Throwable $e) {
                $this->error("{$label}: failed checking {$kind} alias {$alias}: " . $e->getMessage());
                $failed = true;
            }
        }

        if (isset($aliasResponses['write'])) {
            $writeIndex = $this->resolveWriteIndex($aliasResponses['write'], $writeAlias);

            if ($writeIndex === null) {
                $this->error("{$label}: write alias {$writeAlias} must resolve to exactly one writable index.");
                $failed = true;
            } elseif (isset($aliasResponses['read']) && ! array_key_exists($writeIndex, $aliasResponses['read'])) {
                $this->error("{$label}: current write index {$writeIndex} is missing from read alias {$readAlias}.");
                $failed = true;
            } else {
                $this->info("{$label}: write alias targets {$writeIndex}.");
            }
        }

        return $failed;
    }

    private function resolveWriteIndex(array $response, string $writeAlias): ?string
    {
        $aliasIndexes         = [];
        $explicitWriteIndexes = [];

        foreach ($response as $index => $metadata) {
            $alias = is_array($metadata) ? ($metadata['aliases'][$writeAlias] ?? null) : null;

            if (! is_array($alias)) {
                continue;
            }

            $aliasIndexes[(string) $index] = $alias;

            if (($alias['is_write_index'] ?? null) === true) {
                $explicitWriteIndexes[] = (string) $index;
            }
        }

        if (count($explicitWriteIndexes) === 1) {
            return $explicitWriteIndexes[0];
        }

        if ($explicitWriteIndexes !== [] || count($aliasIndexes) !== 1) {
            return null;
        }

        $index = array_key_first($aliasIndexes);

        return ! array_key_exists('is_write_index', $aliasIndexes[$index]) ? $index : null;
    }

    private function checkRetentionDays(string $label, string $configKey): bool
    {
        $retainForever = config("{$configKey}.retain_forever", false);

        if (! is_bool($retainForever)) {
            $this->error("{$label}: retain_forever must be a boolean.");

            return true;
        }

        if ($retainForever) {
            $this->info("{$label}: default document retention is forever.");

            return false;
        }

        $retentionDays = config("{$configKey}.retention_days");
        $validatedDays = $this->validateInteger($retentionDays);

        if ($validatedDays === false
            || $validatedDays < RetentionDays::MIN
            || $validatedDays > RetentionDays::MAX) {
            $this->error(sprintf(
                '%s: retention_days must be an integer between %d and %d.',
                $label,
                RetentionDays::MIN,
                RetentionDays::MAX,
            ));

            return true;
        }

        $this->info("{$label}: retention_days={$retentionDays}.");

        return false;
    }

    private function checkJobOptions(string $label, string $configKey): bool
    {
        $failed = false;

        foreach (['tries', 'timeout'] as $key) {
            $value     = config("{$configKey}.job.{$key}");
            $validated = $this->validateInteger($value);

            if ($validated === false || $validated < 1) {
                $this->error("{$label}: job.{$key} must be a positive integer.");
                $failed = true;
            }
        }

        $batchTimeout          = config("{$configKey}.job.batch_timeout");
        $validatedBatchTimeout = $this->validateInteger($batchTimeout);

        if ($validatedBatchTimeout === false || $validatedBatchTimeout < 1) {
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
            $validated = $this->validateInteger($value);

            if ($validated === false || $validated < 0) {
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

    private function validateInteger(mixed $value): int|false
    {
        if (! is_int($value) && ! is_string($value)) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_INT);
    }

    private function checkHttpEnums(bool $enabled): bool
    {
        if (! $enabled) {
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
                $this->error("HTTP logs: enums.{$key} must be a string-backed enum implementing {$contract}.");
                $failed = true;

                continue;
            }

            $this->info("HTTP logs: enums.{$key}={$class}.");
        }

        return $failed;
    }

    private function checkHttpCaptureOptions(bool $enabled): bool
    {
        if (! $enabled) {
            return false;
        }

        $failed     = false;
        $sampleRate = config('http_logs.sample_rate');

        if (! is_numeric($sampleRate)
            || ! is_finite((float) $sampleRate)
            || (float) $sampleRate < 0.0
            || (float) $sampleRate > 1.0) {
            $this->error('HTTP logs: sample_rate must be a number between 0.0 and 1.0.');
            $failed = true;
        }

        $sizes = [];

        foreach (['body_preview_bytes', 'body_max_bytes', 'body_capture_max_bytes'] as $key) {
            $value     = config("http_logs.{$key}");
            $validated = $this->validateInteger($value);

            if ($validated === false || $validated < 0) {
                $this->error("HTTP logs: {$key} must be a non-negative integer.");
                $failed = true;

                continue;
            }

            $sizes[$key] = $validated;
        }

        if (count($sizes) === 3
            && ($sizes['body_preview_bytes'] > $sizes['body_max_bytes']
                || $sizes['body_max_bytes'] > $sizes['body_capture_max_bytes'])) {
            $this->error('HTTP logs: body byte limits must satisfy preview <= max <= capture.');
            $failed = true;
        }

        foreach (['undecodable_body_mode', 'payment_body_mode'] as $key) {
            if (! in_array(config("http_logs.{$key}"), ['metadata', 'preview'], true)) {
                $this->error("HTTP logs: {$key} must be metadata or preview.");
                $failed = true;
            }
        }

        if (! $failed) {
            $this->info('HTTP logs: capture and redaction options valid.');
        }

        return $failed;
    }

    /**
     * @param class-string $contract
     */
    private function isBackedEnumContract(mixed $class, string $contract): bool
    {
        if (! is_string($class)
            || ! enum_exists($class)
            || ! is_subclass_of($class, \BackedEnum::class)
            || ! is_subclass_of($class, $contract)) {
            return false;
        }

        return (new \ReflectionEnum($class))->getBackingType()?->getName() === 'string';
    }

    private function checkLifecycle(): bool
    {
        $failed = false;

        if (! ElasticsearchLifecycle::enabled()) {
            $this->line('Lifecycle disabled; indexes are not deleted by this package, and prune commands enforce finite document retention.');

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

        $deleteEnabled = config('log_elasticsearch.lifecycle.delete_enabled', true);

        if (! is_bool($deleteEnabled)) {
            $this->error('Lifecycle enabled but log_elasticsearch.lifecycle.delete_enabled is not a boolean.');
            $failed = true;
        } elseif (! $deleteEnabled) {
            $this->info('Lifecycle delete phase disabled; rolled-over indexes are retained forever.');
        } else {
            $deleteAfter = config('log_elasticsearch.lifecycle.delete_after');

            if (! is_string($deleteAfter) || $deleteAfter === '') {
                $this->error('Lifecycle index deletion is enabled but log_elasticsearch.lifecycle.delete_after is empty.');
                $failed = true;
            } else {
                $this->info("Lifecycle index deletion enabled: delete_after={$deleteAfter}.");
            }

            foreach (['HTTP logs' => 'http_logs', 'Activity logs' => 'activity_logs'] as $label => $configKey) {
                $checked = (bool) config("{$configKey}.enabled", false);

                if ($checked && config("{$configKey}.retain_forever", false) === true) {
                    $this->error("{$label}: retain_forever cannot guarantee permanent storage while lifecycle index deletion is enabled.");
                    $failed = true;
                }
            }
        }

        return $failed;
    }
}
