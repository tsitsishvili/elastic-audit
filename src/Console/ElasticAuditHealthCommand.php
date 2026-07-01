<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

use Illuminate\Console\Command;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchLifecycle;
use Throwable;

class ElasticAuditHealthCommand extends Command
{
    protected $signature = 'elastic-audit:health {--all : Check aliases even for disabled subsystems}';

    protected $description = 'Check Elasticsearch connectivity, aliases, lifecycle, and queue configuration for Elastic Audit.';

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
            (bool) config('http_logs.enabled', false),
            (string) config('http_logs.index_alias'),
            (string) config('http_logs.index_alias_write'),
            (string) config('http_logs.queue', 'default'),
        ) || $failed;

        $failed = $this->checkSubsystem(
            $client,
            'Activity logs',
            (bool) config('activity_logs.enabled', true),
            (string) config('activity_logs.index_alias'),
            (string) config('activity_logs.index_alias_write'),
            (string) config('activity_logs.queue', 'default'),
        ) || $failed;

        if (ElasticsearchLifecycle::enabled()) {
            $this->info('Lifecycle enabled: ' . ElasticsearchLifecycle::policyName());
        } else {
            $this->line('Lifecycle disabled; document pruning commands remain responsible for retention.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function checkSubsystem(
        LogElasticsearchClientInterface $client,
        string $label,
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

        if ($queue === '') {
            $this->error("{$label}: queue name is empty.");
            $failed = true;
        } else {
            $this->info("{$label}: queue={$queue}.");
        }

        foreach (['read' => $readAlias, 'write' => $writeAlias] as $kind => $alias) {
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
}
