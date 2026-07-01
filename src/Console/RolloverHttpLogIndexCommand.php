<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

use Illuminate\Console\Command;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchLifecycle;
use Throwable;

class RolloverHttpLogIndexCommand extends Command
{
    protected $signature = 'http-logs:rollover';

    protected $description = 'Roll over the HTTP logs Elasticsearch write alias when configured lifecycle conditions match.';

    public function handle(LogElasticsearchClientInterface $client): int
    {
        return $this->rollover($client, (string) config('http_logs.index_alias_write'));
    }

    private function rollover(LogElasticsearchClientInterface $client, string $writeAlias): int
    {
        try {
            $result = $client->rollover($writeAlias, ElasticsearchLifecycle::rolloverConditions());
        } catch (Throwable $e) {
            $this->error("Failed to roll over {$writeAlias}: " . $e->getMessage());

            return self::FAILURE;
        }

        $rolledOver = (bool) ($result['rolled_over'] ?? false);
        $this->info($rolledOver ? "Rolled over {$writeAlias}." : "Rollover conditions were not met for {$writeAlias}.");

        return self::SUCCESS;
    }
}
