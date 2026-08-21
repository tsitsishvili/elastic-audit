<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

use Elastic\Transport\Exception\NoNodeAvailableException;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\MetricMapping;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchIndexNames;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchIndexTemplate;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchLifecycle;

class CreateMetricsIndexCommand extends Command
{
    protected $signature = 'elastic-audit:metrics:create-index';

    protected $description = 'Create the Elastic Audit metrics Elasticsearch index and aliases.';

    public function handle(LogElasticsearchClientInterface $client): int
    {
        $readAlias  = (string) config('elastic_audit_metrics.index_alias');
        $writeAlias = (string) config('elastic_audit_metrics.index_alias_write');

        try {
            ElasticsearchIndexNames::assertValid($readAlias, 'Metrics read alias');
            ElasticsearchIndexNames::assertValid($writeAlias, 'Metrics write alias');

            $mappings = MetricMapping::get();

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
        } catch (NoNodeAvailableException $exception) {
            $this->error('Cannot reach log Elasticsearch cluster: '.$exception->getMessage());
            $this->error('Check LOG_ELASTICSEARCH_HOST / LOG_ELASTICSEARCH_PORT in your .env.');

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Failed to create or roll over the metrics index: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function attachAlias(
        LogElasticsearchClientInterface $client,
        string $physicalIndex,
        string $alias,
        array $extraProps = [],
    ): void {
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
