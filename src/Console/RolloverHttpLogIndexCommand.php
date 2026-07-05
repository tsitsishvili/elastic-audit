<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

use Illuminate\Console\Command;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchIndexNames;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchLifecycle;
use Throwable;

class RolloverHttpLogIndexCommand extends Command
{
    protected $signature = 'http-logs:rollover';

    protected $description = 'Roll over the HTTP logs Elasticsearch write alias when configured lifecycle conditions match.';

    public function handle(LogElasticsearchClientInterface $client): int
    {
        return $this->rollover(
            $client,
            (string) config('http_logs.index_alias'),
            (string) config('http_logs.index_alias_write'),
        );
    }

    private function rollover(LogElasticsearchClientInterface $client, string $readAlias, string $writeAlias): int
    {
        try {
            $newIndex = $this->explicitRolloverIndex($client, $readAlias, $writeAlias);
            $result   = $client->rollover($writeAlias, ElasticsearchLifecycle::rolloverConditions(), $newIndex);
        } catch (Throwable $e) {
            $this->error("Failed to roll over {$writeAlias}: " . $e->getMessage());

            return self::FAILURE;
        }

        $rolledOver = (bool) ($result['rolled_over'] ?? false);
        $this->info($rolledOver ? "Rolled over {$writeAlias}." : "Rollover conditions were not met for {$writeAlias}.");

        return self::SUCCESS;
    }

    private function explicitRolloverIndex(
        LogElasticsearchClientInterface $client,
        string $readAlias,
        string $writeAlias,
    ): ?string {
        $currentIndex = ElasticsearchIndexNames::currentWriteIndex($client->getAlias($writeAlias), $writeAlias);

        if (! ElasticsearchIndexNames::needsExplicitRolloverIndex($currentIndex)) {
            return null;
        }

        return ElasticsearchIndexNames::nextAvailableRolloverIndex($client, $readAlias);
    }
}
