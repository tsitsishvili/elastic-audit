<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

use Illuminate\Console\Command;
use Throwable;
use Tsitsishvili\ElasticAudit\Contracts\EntityTypeContract;
use Tsitsishvili\ElasticAudit\Contracts\EventTypeContract;
use Tsitsishvili\ElasticAudit\Contracts\ProviderContract;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\ActivityLogMapping;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\HttpLogMapping;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchSchemaInspectorInterface;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchIndexNames;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchIndexTemplate;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchLifecycle;
use Tsitsishvili\ElasticAudit\Support\RetentionDays;

class ElasticAuditHealthCommand extends Command
{
    private const DEFAULT_APP_NAME = 'Laravel';

    protected $signature = 'elastic-audit:health
        {--all : Check aliases even for disabled subsystems}
        {--json : Emit one machine-readable JSON result}';

    protected $description = 'Check source identity, Elasticsearch connectivity, mappings, templates, aliases, lifecycle, enums, and queue configuration.';

    private bool $jsonOutput = false;

    /**
     * @var list<array{status: 'ok'|'error'|'info', message: string}>
     */
    private array $checks = [];

    public function handle(LogElasticsearchClientInterface $client): int
    {
        $this->jsonOutput = (bool) $this->option('json');
        $this->checks     = [];
        $failed           = false;

        if (! $client->ping()) {
            $this->recordError('Elasticsearch cluster is not reachable.');

            return $this->finish(true);
        }

        $this->recordSuccess('Elasticsearch cluster reachable.');

        $failed = $this->checkServiceIdentity();

        $failed = $this->checkSubsystem(
            $client,
            'HTTP logs',
            'http_logs',
            (bool) config('http_logs.enabled', false),
            (string) config('http_logs.index_alias'),
            (string) config('http_logs.index_alias_write'),
            (string) config('http_logs.queue', 'default'),
            HttpLogMapping::get(),
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
            ActivityLogMapping::get(),
        ) || $failed;

        $failed = $this->checkLifecycle() || $failed;

        return $this->finish($failed);
    }

    private function checkServiceIdentity(): bool
    {
        $name = config('app.name');

        if (! is_string($name) || trim($name) === '') {
            $this->recordError('Service identity: app.name must be a non-empty string.');

            return true;
        }

        $environment = config('app.env');

        if ($environment !== null
            && (! is_string($environment) || trim($environment) === '')) {
            $this->recordError('Service identity: app.env must be null or a non-empty string.');

            return true;
        }

        $suffix = is_string($environment) ? ", environment={$environment}" : '';
        $this->recordSuccess("Service identity: name={$name}{$suffix}.");

        // Not an error: a single-application install is free to keep the framework
        // default. It only breaks source attribution once aliases are shared, which
        // this command cannot detect from the local configuration.
        if (trim($name) === self::DEFAULT_APP_NAME) {
            $this->recordInfo(
                'Service identity: app.name is still the framework default "'.self::DEFAULT_APP_NAME.'". '
                .'Set a stable, unique APP_NAME in every application writing to shared aliases.'
            );
        }

        return false;
    }

    private function checkSubsystem(
        LogElasticsearchClientInterface $client,
        string $label,
        string $configKey,
        bool $enabled,
        string $readAlias,
        string $writeAlias,
        string $queue,
        array $expectedMapping,
    ): bool {
        if (! $enabled && ! $this->option('all')) {
            $this->recordInfo("{$label}: disabled; alias checks skipped.");

            return false;
        }

        $failed = false;

        if (! $enabled) {
            $this->recordInfo("{$label}: disabled; checking aliases because --all was supplied.");
        }

        if ($readAlias === '' || $writeAlias === '') {
            $this->recordError("{$label}: read/write aliases must not be empty.");
            $failed = true;
        } elseif ($readAlias === $writeAlias) {
            $this->recordError("{$label}: read and write aliases must be different.");
            $failed = true;
        }

        foreach (['read' => $readAlias, 'write' => $writeAlias] as $kind => $alias) {
            if ($alias !== '' && ($error = ElasticsearchIndexNames::validationError($alias)) !== null) {
                $this->recordError("{$label}: {$kind} alias [{$alias}] is invalid: {$error}.");
                $failed = true;
            }
        }

        if ($enabled) {
            if ($queue === '') {
                $this->recordError("{$label}: queue name is empty.");
                $failed = true;
            } else {
                $this->recordSuccess("{$label}: queue={$queue}.");
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
                    $this->recordError("{$label}: {$kind} alias missing: {$alias}");
                    $failed = true;

                    continue;
                }

                $this->recordSuccess("{$label}: {$kind} alias exists: {$alias}");
                $aliasResponses[$kind] = $client->getAlias($alias);
            } catch (Throwable $e) {
                $this->recordError("{$label}: failed checking {$kind} alias {$alias}: ".$e->getMessage());
                $failed = true;
            }
        }

        if (isset($aliasResponses['write'])) {
            $writeIndex = $this->resolveWriteIndex($aliasResponses['write'], $writeAlias);

            if ($writeIndex === null) {
                $this->recordError("{$label}: write alias {$writeAlias} must resolve to exactly one writable index.");
                $failed = true;
            } elseif (isset($aliasResponses['read']) && ! array_key_exists($writeIndex, $aliasResponses['read'])) {
                $this->recordError("{$label}: current write index {$writeIndex} is missing from read alias {$readAlias}.");
                $failed = true;
            } else {
                $this->recordSuccess("{$label}: write alias targets {$writeIndex}.");

                if ($client instanceof LogElasticsearchSchemaInspectorInterface) {
                    $failed = $this->checkWriteMapping(
                        $client,
                        $label,
                        $writeIndex,
                        $expectedMapping,
                    ) || $failed;
                } else {
                    $this->recordInfo("{$label}: schema inspection unavailable for the custom Elasticsearch client.");
                }
            }
        }

        if ($client instanceof LogElasticsearchSchemaInspectorInterface
            && $readAlias !== ''
            && ElasticsearchIndexNames::validationError($readAlias) === null) {
            $failed = $this->checkIndexTemplate(
                $client,
                $label,
                $readAlias,
                $expectedMapping,
            ) || $failed;
        }

        return $failed;
    }

    /**
     * @param  array<string, mixed>  $expectedMapping
     */
    private function checkWriteMapping(
        LogElasticsearchSchemaInspectorInterface $client,
        string $label,
        string $writeIndex,
        array $expectedMapping,
    ): bool {
        try {
            $response = $client->getMapping($writeIndex);
        } catch (Throwable $e) {
            $this->recordError("{$label}: failed reading mapping for {$writeIndex}: ".$e->getMessage());

            return true;
        }

        $mapping = $response[$writeIndex]['mappings'] ?? null;

        if (! is_array($mapping)) {
            $this->recordError("{$label}: Elasticsearch returned no mapping for write index {$writeIndex}.");

            return true;
        }

        return $this->checkMappingCompatibility(
            $label,
            "write index {$writeIndex}",
            $mapping,
            $expectedMapping,
        );
    }

    /**
     * @param  array<string, mixed>  $expectedMapping
     */
    private function checkIndexTemplate(
        LogElasticsearchSchemaInspectorInterface $client,
        string $label,
        string $readAlias,
        array $expectedMapping,
    ): bool {
        $templateName = ElasticsearchIndexTemplate::name($readAlias);

        try {
            $response = $client->getIndexTemplate($templateName);
        } catch (Throwable $e) {
            $this->recordError("{$label}: failed reading index template {$templateName}: ".$e->getMessage());

            return true;
        }

        $mapping = null;

        foreach ($response['index_templates'] ?? [] as $template) {
            if (! is_array($template) || ($template['name'] ?? null) !== $templateName) {
                continue;
            }

            $candidate = $template['index_template']['template']['mappings'] ?? null;
            $mapping   = is_array($candidate) ? $candidate : null;

            break;
        }

        if ($mapping === null) {
            $this->recordError("{$label}: index template {$templateName} is missing or has no mappings.");

            return true;
        }

        return $this->checkMappingCompatibility(
            $label,
            "index template {$templateName}",
            $mapping,
            $expectedMapping,
        );
    }

    /**
     * Validate the fields the package owns while tolerating extra Elasticsearch
     * metadata. Schema metadata is optional for indexes/templates created before
     * it was introduced; their concrete field structure remains authoritative.
     *
     * @param  array<string, mixed>  $actual
     * @param  array<string, mixed>  $expected
     */
    private function checkMappingCompatibility(
        string $label,
        string $source,
        array $actual,
        array $expected,
    ): bool {
        $differences = $this->mappingDifferences($actual, $expected);

        if ($differences !== []) {
            $visible = array_slice($differences, 0, 5);
            $suffix  = count($differences) > count($visible)
                ? sprintf(' (%d additional differences)', count($differences) - count($visible))
                : '';

            $this->recordError(
                "{$label}: {$source} mapping is incompatible: ".implode('; ', $visible).$suffix.'.',
            );

            return true;
        }

        $expectedMeta = $expected['_meta']['elastic_audit'] ?? null;
        $actualMeta   = $actual['_meta']['elastic_audit'] ?? null;

        if (is_array($actualMeta)
            && is_array($expectedMeta)
            && (($actualMeta['subsystem'] ?? null) !== ($expectedMeta['subsystem'] ?? null)
                || ($actualMeta['schema_version'] ?? null) !== ($expectedMeta['schema_version'] ?? null))) {
            $this->recordError(sprintf(
                '%s: %s schema metadata is incompatible (expected %s/%s, got %s/%s).',
                $label,
                $source,
                (string) ($expectedMeta['subsystem'] ?? 'unknown'),
                (string) ($expectedMeta['schema_version'] ?? 'unknown'),
                (string) ($actualMeta['subsystem'] ?? 'unknown'),
                (string) ($actualMeta['schema_version'] ?? 'unknown'),
            ));

            return true;
        }

        if (! is_array($actualMeta)) {
            $this->recordInfo("{$label}: {$source} has no Elastic Audit schema metadata; structural mapping is compatible.");
        } else {
            $this->recordSuccess("{$label}: {$source} schema metadata and mapping are compatible.");
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $actual
     * @param  array<string, mixed>  $expected
     * @return list<string>
     */
    private function mappingDifferences(array $actual, array $expected, string $path = 'mappings'): array
    {
        $differences = [];

        if (array_key_exists('dynamic', $expected) && ($actual['dynamic'] ?? null) !== $expected['dynamic']) {
            $differences[] = "{$path}.dynamic expected ".(string) $expected['dynamic'];
        }

        $expectedProperties = $expected['properties'] ?? [];
        $actualProperties   = $actual['properties'] ?? [];

        if (! is_array($expectedProperties) || ! is_array($actualProperties)) {
            return ["{$path}.properties missing or invalid"];
        }

        foreach ($expectedProperties as $field => $expectedDefinition) {
            $fieldPath        = "{$path}.properties.{$field}";
            $actualDefinition = $actualProperties[$field] ?? null;

            if (! is_array($expectedDefinition) || ! is_array($actualDefinition)) {
                $differences[] = "{$fieldPath} missing";

                continue;
            }

            foreach (['type', 'enabled', 'index'] as $setting) {
                if (array_key_exists($setting, $expectedDefinition)
                    && ($actualDefinition[$setting] ?? null) !== $expectedDefinition[$setting]) {
                    $differences[] = sprintf(
                        '%s.%s expected %s',
                        $fieldPath,
                        $setting,
                        json_encode($expectedDefinition[$setting]),
                    );
                }
            }

            if (isset($expectedDefinition['properties'])) {
                $differences = [
                    ...$differences,
                    ...$this->mappingDifferences(
                        $actualDefinition,
                        $expectedDefinition,
                        $fieldPath,
                    ),
                ];
            }
        }

        return $differences;
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
            $this->recordError("{$label}: retain_forever must be a boolean.");

            return true;
        }

        if ($retainForever) {
            $this->recordSuccess("{$label}: default document retention is forever.");

            return false;
        }

        $retentionDays = config("{$configKey}.retention_days");
        $validatedDays = $this->validateInteger($retentionDays);

        if ($validatedDays === false
            || $validatedDays < RetentionDays::MIN
            || $validatedDays > RetentionDays::MAX) {
            $this->recordError(sprintf(
                '%s: retention_days must be an integer between %d and %d.',
                $label,
                RetentionDays::MIN,
                RetentionDays::MAX,
            ));

            return true;
        }

        $this->recordSuccess("{$label}: retention_days={$retentionDays}.");

        return false;
    }

    private function checkJobOptions(string $label, string $configKey): bool
    {
        $failed = false;

        foreach (['tries', 'timeout'] as $key) {
            $value     = config("{$configKey}.job.{$key}");
            $validated = $this->validateInteger($value);

            if ($validated === false || $validated < 1) {
                $this->recordError("{$label}: job.{$key} must be a positive integer.");
                $failed = true;
            }
        }

        $batchTimeout          = config("{$configKey}.job.batch_timeout");
        $validatedBatchTimeout = $this->validateInteger($batchTimeout);

        if ($validatedBatchTimeout === false || $validatedBatchTimeout < 1) {
            $this->recordError("{$label}: job.batch_timeout must be a positive integer.");
            $failed = true;
        }

        $backoff = config("{$configKey}.job.backoff");

        if (is_string($backoff)) {
            $backoff = explode(',', $backoff);
        }

        if (! is_array($backoff) || $backoff === []) {
            $this->recordError("{$label}: job.backoff must contain at least one non-negative integer.");

            return true;
        }

        foreach ($backoff as $value) {
            $validated = $this->validateInteger($value);

            if ($validated === false || $validated < 0) {
                $this->recordError("{$label}: job.backoff must contain only non-negative integers.");
                $failed = true;

                break;
            }
        }

        if (! $failed) {
            $this->recordSuccess("{$label}: job retry options valid.");
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
                $this->recordError("HTTP logs: enums.{$key} must be a string-backed enum implementing {$contract}.");
                $failed = true;

                continue;
            }

            $this->recordSuccess("HTTP logs: enums.{$key}={$class}.");
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
            $this->recordError('HTTP logs: sample_rate must be a number between 0.0 and 1.0.');
            $failed = true;
        }

        $sizes = [];

        foreach (['body_preview_bytes', 'body_max_bytes', 'body_capture_max_bytes'] as $key) {
            $value     = config("http_logs.{$key}");
            $validated = $this->validateInteger($value);

            if ($validated === false || $validated < 0) {
                $this->recordError("HTTP logs: {$key} must be a non-negative integer.");
                $failed = true;

                continue;
            }

            $sizes[$key] = $validated;
        }

        if (count($sizes) === 3
            && ($sizes['body_preview_bytes'] > $sizes['body_max_bytes']
                || $sizes['body_max_bytes'] > $sizes['body_capture_max_bytes'])) {
            $this->recordError('HTTP logs: body byte limits must satisfy preview <= max <= capture.');
            $failed = true;
        }

        foreach (['undecodable_body_mode', 'payment_body_mode'] as $key) {
            if (! in_array(config("http_logs.{$key}"), ['metadata', 'preview'], true)) {
                $this->recordError("HTTP logs: {$key} must be metadata or preview.");
                $failed = true;
            }
        }

        if (! $failed) {
            $this->recordSuccess('HTTP logs: capture and redaction options valid.');
        }

        return $failed;
    }

    /**
     * @param  class-string  $contract
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
            $this->recordInfo('Lifecycle disabled; indexes are not deleted by this package, and prune commands enforce finite document retention.');

            return false;
        }

        $policyName = ElasticsearchLifecycle::policyName();

        if ($policyName === '') {
            $this->recordError('Lifecycle enabled but log_elasticsearch.lifecycle.policy_name is empty.');
            $failed = true;
        } else {
            $this->recordSuccess('Lifecycle enabled: '.$policyName);
        }

        if (ElasticsearchLifecycle::rolloverConditions() === []) {
            $this->recordError('Lifecycle enabled but no rollover conditions are configured.');
            $failed = true;
        }

        $deleteEnabled = config('log_elasticsearch.lifecycle.delete_enabled', true);

        if (! is_bool($deleteEnabled)) {
            $this->recordError('Lifecycle enabled but log_elasticsearch.lifecycle.delete_enabled is not a boolean.');
            $failed = true;
        } elseif (! $deleteEnabled) {
            $this->recordSuccess('Lifecycle delete phase disabled; rolled-over indexes are retained forever.');
        } else {
            $deleteAfter = config('log_elasticsearch.lifecycle.delete_after');

            if (! is_string($deleteAfter) || $deleteAfter === '') {
                $this->recordError('Lifecycle index deletion is enabled but log_elasticsearch.lifecycle.delete_after is empty.');
                $failed = true;
            } else {
                $this->recordSuccess("Lifecycle index deletion enabled: delete_after={$deleteAfter}.");
            }

            foreach (['HTTP logs' => 'http_logs', 'Activity logs' => 'activity_logs'] as $label => $configKey) {
                $checked = (bool) config("{$configKey}.enabled", false);

                if ($checked && config("{$configKey}.retain_forever", false) === true) {
                    $this->recordError("{$label}: retain_forever cannot guarantee permanent storage while lifecycle index deletion is enabled.");
                    $failed = true;
                }
            }
        }

        return $failed;
    }

    private function finish(bool $failed): int
    {
        if ($this->jsonOutput) {
            $this->output->writeln((string) json_encode(
                [
                    'ok'     => ! $failed,
                    'checks' => $this->checks,
                ],
                JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            ));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function recordSuccess(string $message): void
    {
        $this->recordCheck('ok', $message);

        if (! $this->jsonOutput) {
            $this->info($message);
        }
    }

    private function recordError(string $message): void
    {
        $this->recordCheck('error', $message);

        if (! $this->jsonOutput) {
            $this->error($message);
        }
    }

    private function recordInfo(string $message): void
    {
        $this->recordCheck('info', $message);

        if (! $this->jsonOutput) {
            $this->line($message);
        }
    }

    /**
     * @param  'ok'|'error'|'info'  $status
     */
    private function recordCheck(string $status, string $message): void
    {
        $this->checks[] = [
            'status'  => $status,
            'message' => $message,
        ];
    }
}
