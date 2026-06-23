<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Throwable;

class PruneHttpLogCommand extends Command
{
    protected $signature = 'http-logs:prune';

    protected $description = 'Delete third-party HTTP log documents older than their retention_days value.';

    public function handle(LogElasticsearchClientInterface $client): int
    {
        $readAlias = config('http_logs.index_alias');

        $retentionValues = $this->fetchDistinctRetentionDays($client, $readAlias);

        if (empty($retentionValues)) {
            $this->info('No retention_days values found. Nothing to prune.');

            return self::SUCCESS;
        }

        foreach ($retentionValues as $days) {
            $this->pruneForRetention($client, $readAlias, (int) $days);
        }

        return self::SUCCESS;
    }

    private function fetchDistinctRetentionDays(LogElasticsearchClientInterface $client, string $alias): array
    {
        try {
            $result = $client->search([
                'index' => $alias,
                'body'  => [
                    'size' => 0,
                    'aggs' => [
                        'retention_buckets' => [
                            'terms' => [
                                'field' => 'retention_days',
                                'size'  => 50,
                            ],
                        ],
                    ],
                ],
            ]);

            $buckets = $result['aggregations']['retention_buckets']['buckets'] ?? [];

            return array_column($buckets, 'key');
        } catch (Throwable $e) {
            Log::error('PruneHttpLogCommand: failed to fetch retention_days buckets', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function pruneForRetention(LogElasticsearchClientInterface $client, string $alias, int $days): void
    {
        $cutoff = now()->subDays($days)->toIso8601ZuluString();

        $this->info("Pruning documents with retention_days={$days} older than {$cutoff}...");

        try {
            $result = $client->deleteByQuery([
                'index' => $alias,
                'body'  => [
                    'query' => [
                        'bool' => [
                            'filter' => [
                                ['term' => ['retention_days' => $days]],
                                ['range' => ['@timestamp' => ['lt' => $cutoff]]],
                            ],
                        ],
                    ],
                ],
            ]);

            $deleted = $result['deleted'] ?? 0;

            Log::info('PruneHttpLogCommand: pruned documents', [
                'retention_days' => $days,
                'older_than'     => $cutoff,
                'deleted'        => $deleted,
            ]);

            $this->info("Deleted {$deleted} documents.");
        } catch (Throwable $e) {
            Log::error('PruneHttpLogCommand: delete_by_query failed', [
                'retention_days' => $days,
                'error'          => $e->getMessage(),
            ]);
        }
    }
}
