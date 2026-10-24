<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchRetentionPruner;

class PruneMetricsCommand extends Command
{
    protected $signature = 'elastic-audit:metrics:prune';

    protected $description = 'Delete metric documents older than their retention_days value.';

    public function handle(LogElasticsearchClientInterface $client): int
    {
        $readAlias = (string) config('elastic_audit_metrics.index_alias');
        $pruner    = new ElasticsearchRetentionPruner($client);

        try {
            $retentionValues = $pruner->retentionDays($readAlias);
        } catch (Throwable $exception) {
            Log::error('PruneMetricsCommand: failed to fetch retention_days buckets', [
                'error' => $exception->getMessage(),
            ]);
            $this->error('Failed to fetch retention_days values from Elasticsearch.');

            return self::FAILURE;
        }

        if ($retentionValues === []) {
            $this->info('No retention_days values found. Nothing to prune.');

            return self::SUCCESS;
        }

        foreach ($retentionValues as $days) {
            if (! $this->pruneForRetention($pruner, $readAlias, $days)) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function pruneForRetention(ElasticsearchRetentionPruner $pruner, string $alias, int $days): bool
    {
        $cutoff = now()->subDays($days)->toIso8601ZuluString();

        $this->info("Pruning documents with retention_days={$days} older than {$cutoff}...");

        try {
            $deleted = $pruner->deleteExpired($alias, $days, $cutoff);

            Log::info('PruneMetricsCommand: pruned documents', [
                'retention_days' => $days,
                'older_than'     => $cutoff,
                'deleted'        => $deleted,
            ]);

            $this->info("Deleted {$deleted} documents.");

            return true;
        } catch (Throwable $exception) {
            Log::error('PruneMetricsCommand: delete_by_query failed', [
                'retention_days' => $days,
                'error'          => $exception->getMessage(),
            ]);

            $this->error("Failed to prune documents with retention_days={$days}: {$exception->getMessage()}");

            return false;
        }
    }
}
