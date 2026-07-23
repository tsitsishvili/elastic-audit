<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

use Elastic\Transport\Exception\NoNodeAvailableException;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\ActivityLogMapping;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchIndexNames;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchIndexTemplate;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchLifecycle;

class CreateActivityLogIndexCommand extends Command
{
    protected $signature = 'activity-logs:create-index';

    protected $description = 'Create the activity logs Elasticsearch index and aliases.';

    public function handle(LogElasticsearchClientInterface $client): int
    {
        $readAlias  = (string) config('activity_logs.index_alias');
        $writeAlias = (string) config('activity_logs.index_alias_write');

        try {
            ElasticsearchIndexNames::assertValid($readAlias, 'Activity logs read alias');
            ElasticsearchIndexNames::assertValid($writeAlias, 'Activity logs write alias');

            $mappings = ActivityLogMapping::get();

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
            $this->attachAlias($client, $physicalIndex, $writeAlias, ['is_write_index' => true]);
        } catch (NoNodeAvailableException $e) {
            $this->error('Cannot reach log Elasticsearch cluster: '.$e->getMessage());
            $this->error('Check LOG_ELASTICSEARCH_HOST / LOG_ELASTICSEARCH_PORT in your .env.');

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Failed to create or roll over the activity logs index: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function attachAlias(LogElasticsearchClientInterface $client, string $physicalIndex, string $alias, array $extraProps = []): void
    {
        if (! $client->existsAlias($alias)) {
            $this->info("Attaching alias: {$alias}");
            $client->putAlias($physicalIndex, $alias, $extraProps ? ['body' => $extraProps] : []);

            return;
        }

        $this->info("Adding {$physicalIndex} to alias {$alias}");
        $client->updateAliases([
            ['add' => ['index' => $physicalIndex, 'alias' => $alias] + $extraProps],
        ]);
    }
}
