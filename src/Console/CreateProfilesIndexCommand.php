<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

use Elastic\Transport\Exception\NoNodeAvailableException;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\ProfileMapping;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchIndexNames;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchIndexTemplate;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchLifecycle;

final class CreateProfilesIndexCommand extends Command
{
    protected $signature = 'elastic-audit:profiles:create-index';

    protected $description = 'Create the Elastic Audit profiles Elasticsearch index and aliases.';

    public function handle(LogElasticsearchClientInterface $client): int
    {
        $readAlias  = (string) config('elastic_audit_metrics.profiles.index_alias');
        $writeAlias = (string) config('elastic_audit_metrics.profiles.index_alias_write');

        try {
            ElasticsearchIndexNames::assertValid($readAlias, 'Profiles read alias');
            ElasticsearchIndexNames::assertValid($writeAlias, 'Profiles write alias');
            $mappings = ProfileMapping::get();
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
            $this->attachAlias($client, $physicalIndex, $readAlias);
            $this->attachAlias($client, $physicalIndex, $writeAlias, ['is_write_index' => true]);
        } catch (NoNodeAvailableException $exception) {
            $this->error('Cannot reach log Elasticsearch cluster: '.$exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Failed to create or roll over the profiles index: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $extraProps */
    private function attachAlias(
        LogElasticsearchClientInterface $client,
        string $physicalIndex,
        string $alias,
        array $extraProps = [],
    ): void {
        if (! $client->existsAlias($alias)) {
            $client->putAlias($physicalIndex, $alias, $extraProps ? ['body' => $extraProps] : []);

            return;
        }

        $client->updateAliases([
            ['add' => ['index' => $physicalIndex, 'alias' => $alias] + $extraProps],
        ]);
    }
}
