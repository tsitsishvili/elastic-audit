<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

use Illuminate\Console\Command;
use Throwable;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchIndexNames;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchLifecycle;

final class RolloverProfilesIndexCommand extends Command
{
    protected $signature = 'elastic-audit:profiles:rollover';

    protected $description = 'Roll over the profiles write alias when lifecycle conditions match.';

    public function handle(LogElasticsearchClientInterface $client): int
    {
        $readAlias  = (string) config('elastic_audit_metrics.profiles.index_alias');
        $writeAlias = (string) config('elastic_audit_metrics.profiles.index_alias_write');

        try {
            $current  = ElasticsearchIndexNames::currentWriteIndex($client->getAlias($writeAlias), $writeAlias);
            $newIndex = ElasticsearchIndexNames::needsExplicitRolloverIndex($current)
                ? ElasticsearchIndexNames::nextAvailableRolloverIndex($client, $readAlias)
                : null;
            $result = $client->rollover($writeAlias, ElasticsearchLifecycle::rolloverConditions(), $newIndex);
        } catch (Throwable $exception) {
            $this->error("Failed to roll over {$writeAlias}: ".$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(($result['rolled_over'] ?? false)
            ? "Rolled over {$writeAlias}."
            : "Rollover conditions were not met for {$writeAlias}.");

        return self::SUCCESS;
    }
}
