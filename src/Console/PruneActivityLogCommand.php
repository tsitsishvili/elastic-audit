<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchRetentionPruner;
use Throwable;

class PruneActivityLogCommand extends Command
{
    protected $signature = 'activity-logs:prune';

    protected $description = 'Delete activity log documents older than their retention_days value.';

    public function handle(LogElasticsearchClientInterface $client): int
    {
        $readAlias = (string) config('activity_logs.index_alias');
        $pruner    = new ElasticsearchRetentionPruner($client);

        try {
            $retentionValues = $pruner->retentionDays($readAlias);
        } catch (Throwable $e) {
            Log::error('PruneActivityLogCommand: failed to fetch retention_days buckets', [
                'error' => $e->getMessage(),
            ]);
            $this->error('Failed to fetch retention_days values from Elasticsearch.');

            return self::FAILURE;
        }

        if (empty($retentionValues)) {
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

            Log::info('PruneActivityLogCommand: pruned documents', [
                'retention_days' => $days,
                'older_than'     => $cutoff,
                'deleted'        => $deleted,
            ]);

            $this->info("Deleted {$deleted} documents.");

            return true;
        } catch (Throwable $e) {
            Log::error('PruneActivityLogCommand: delete_by_query failed', [
                'retention_days' => $days,
                'error'          => $e->getMessage(),
            ]);

            $this->error("Failed to prune documents with retention_days={$days}: {$e->getMessage()}");

            return false;
        }
    }
}
