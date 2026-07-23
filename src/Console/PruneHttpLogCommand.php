<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchRetentionPruner;

class PruneHttpLogCommand extends Command
{
    protected $signature = 'http-logs:prune';

    protected $description = 'Delete third-party HTTP log documents older than their retention_days value.';

    public function handle(LogElasticsearchClientInterface $client): int
    {
        $readAlias = (string) config('http_logs.index_alias');
        $pruner    = new ElasticsearchRetentionPruner($client);

        try {
            $retentionValues = $pruner->retentionDays($readAlias);
        } catch (Throwable $e) {
            Log::error('PruneHttpLogCommand: failed to fetch retention_days buckets', [
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

            Log::info('PruneHttpLogCommand: pruned documents', [
                'retention_days' => $days,
                'older_than'     => $cutoff,
                'deleted'        => $deleted,
            ]);

            $this->info("Deleted {$deleted} documents.");

            return true;
        } catch (Throwable $e) {
            Log::error('PruneHttpLogCommand: delete_by_query failed', [
                'retention_days' => $days,
                'error'          => $e->getMessage(),
            ]);

            $this->error("Failed to prune documents with retention_days={$days}: {$e->getMessage()}");

            return false;
        }
    }
}
