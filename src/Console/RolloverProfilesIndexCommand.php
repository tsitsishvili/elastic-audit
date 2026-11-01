<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

use Illuminate\Console\Command;
use Throwable;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchIndexNames;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchLifecycle;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchWriteAlias;

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

            // Runs whether or not this call rolled over: a scheduled run also
            // detaches entries left behind by earlier ILM rollovers of an alias
            // that an older package version attached with an explicit flag.
            $detached = ElasticsearchWriteAlias::normalize($client, $writeAlias);
        } catch (Throwable $exception) {
            $this->error("Failed to roll over or clean up {$writeAlias}: ".$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(($result['rolled_over'] ?? false)
            ? "Rolled over {$writeAlias}."
            : "Rollover conditions were not met for {$writeAlias}.");

        if ($detached !== []) {
            $this->info("Detached {$writeAlias} from previous indexes: ".implode(', ', $detached));
        }

        return self::SUCCESS;
    }
}
