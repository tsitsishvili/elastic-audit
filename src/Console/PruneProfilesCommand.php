<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchRetentionPruner;

final class PruneProfilesCommand extends Command
{
    protected $signature = 'elastic-audit:profiles:prune';

    protected $description = 'Delete profile documents older than their retention_days value.';

    public function handle(LogElasticsearchClientInterface $client): int
    {
        $alias  = (string) config('elastic_audit_metrics.profiles.index_alias');
        $pruner = new ElasticsearchRetentionPruner($client);

        try {
            $retentionValues = $pruner->retentionDays($alias);

            foreach ($retentionValues as $days) {
                $cutoff  = now()->subDays($days)->toIso8601ZuluString();
                $deleted = $pruner->deleteExpired($alias, $days, $cutoff);
                $this->info("Deleted {$deleted} profile documents with retention_days={$days}.");
            }
        } catch (Throwable $exception) {
            Log::error('PruneProfilesCommand failed', ['error' => $exception->getMessage()]);
            $this->error('Failed to prune profile documents from Elasticsearch.');

            return self::FAILURE;
        }

        if ($retentionValues === []) {
            $this->info('No retention_days values found. Nothing to prune.');
        }

        return self::SUCCESS;
    }
}
