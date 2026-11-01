<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

use Elastic\Transport\Exception\NoNodeAvailableException;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\HttpLogMapping;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchIndexNames;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchIndexTemplate;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchLifecycle;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchWriteAlias;

class CreateHttpLogIndexCommand extends Command
{
    protected $signature = 'http-logs:create-index';

    protected $description = 'Create the third-party HTTP logs Elasticsearch index and aliases.';

    public function handle(LogElasticsearchClientInterface $client): int
    {
        $readAlias  = (string) config('http_logs.index_alias');
        $writeAlias = (string) config('http_logs.index_alias_write');

        try {
            ElasticsearchIndexNames::assertValid($readAlias, 'HTTP logs read alias');
            ElasticsearchIndexNames::assertValid($writeAlias, 'HTTP logs write alias');

            $mappings = HttpLogMapping::get();

            $client->putIndexTemplate(
                ElasticsearchIndexTemplate::name($readAlias),
                ElasticsearchIndexTemplate::body($readAlias, $writeAlias, $mappings),
            );

            $physicalIndex = ElasticsearchIndexNames::nextAvailableRolloverIndex($client, $readAlias);

            if ($client->existsAlias($writeAlias)) {
                $this->info("Rolling over {$writeAlias} to {$physicalIndex}...");

                $result = $client->rollover($writeAlias, [], $physicalIndex);

                if (($result['rolled_over'] ?? false) !== true) {
                    throw new RuntimeException("Elasticsearch did not roll over {$writeAlias}.");
                }

                $this->info('Index rolled over.');

                if (! $this->detachPreviousWriteIndexes($client, $writeAlias)) {
                    return self::FAILURE;
                }

                $this->info('Done.');

                return self::SUCCESS;
            }

            $this->info("Creating index: {$physicalIndex}");

            $client->createIndex([
                'index' => $physicalIndex,
                'body'  => [
                    'mappings' => $mappings,
                    'settings' => [
                        'number_of_shards'   => 1,
                        'number_of_replicas' => config('log_elasticsearch.replicas', 1),
                        ...ElasticsearchLifecycle::indexSettings($writeAlias),
                    ],
                ],
            ]);

            $this->info('Index created.');

            $this->attachAlias($client, $physicalIndex, $readAlias);
            // Deliberately no is_write_index flag: Elasticsearch's rollover API only
            // moves an alias off the previous index when the flag is absent.
            $this->attachAlias($client, $physicalIndex, $writeAlias);
        } catch (NoNodeAvailableException $e) {
            $this->error('Cannot reach log Elasticsearch cluster: '.$e->getMessage());
            $this->error('Check LOG_ELASTICSEARCH_HOST / LOG_ELASTICSEARCH_PORT in your .env.');

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Failed to create or roll over the HTTP logs index: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function attachAlias(LogElasticsearchClientInterface $client, string $physicalIndex, string $alias): void
    {
        if (! $client->existsAlias($alias)) {
            $this->info("Attaching alias: {$alias}");
            $client->putAlias($physicalIndex, $alias);

            return;
        }

        $this->info("Adding {$physicalIndex} to alias {$alias}");
        $client->updateAliases([
            ['add' => ['index' => $physicalIndex, 'alias' => $alias]],
        ]);
    }

    /**
     * Rollover leaves the write alias on the previous index when an earlier
     * package version attached it with an explicit flag. The rollover itself
     * has succeeded by now, so a cleanup failure is reported on its own.
     */
    private function detachPreviousWriteIndexes(LogElasticsearchClientInterface $client, string $writeAlias): bool
    {
        try {
            $detached = ElasticsearchWriteAlias::normalize($client, $writeAlias);
        } catch (Throwable $exception) {
            $this->error("Rolled over {$writeAlias}, but could not detach it from previous indexes: ".$exception->getMessage());
            $this->error('Run php artisan http-logs:rollover to retry the cleanup.');

            return false;
        }

        if ($detached !== []) {
            $this->info("Detached {$writeAlias} from previous indexes: ".implode(', ', $detached));
        }

        return true;
    }
}
