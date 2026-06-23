<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

use Elastic\Transport\Exception\NoNodeAvailableException;
use Illuminate\Console\Command;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\HttpLogMapping;

class CreateHttpLogIndexCommand extends Command
{
    protected $signature = 'http-logs:create-index';

    protected $description = 'Create the third-party HTTP logs Elasticsearch index and aliases.';

    public function handle(LogElasticsearchClientInterface $client): int
    {
        $prefix        = config('log_elasticsearch.index_prefix', 'app_logs');
        $physicalIndex = $prefix . '_http_logs_' . now()->format('Ymd_His');
        $readAlias     = $prefix . '_http_logs';
        $writeAlias    = $prefix . '_http_logs_write';

        try {
            if (! $client->existsIndex($physicalIndex)) {
                $this->info("Creating index: {$physicalIndex}");

                $client->createIndex([
                    'index' => $physicalIndex,
                    'body'  => [
                        'mappings' => HttpLogMapping::get(),
                        'settings' => [
                            'number_of_shards'   => 1,
                            'number_of_replicas' => config('log_elasticsearch.replicas', 1),
                        ],
                    ],
                ]);

                $this->info('Index created.');
            } else {
                $this->info("Index already exists: {$physicalIndex}");
            }

            $this->attachAlias($client, $physicalIndex, $readAlias, [], exclusive: false);
            $this->attachAlias($client, $physicalIndex, $writeAlias, ['is_write_index' => true], exclusive: true);
        } catch (NoNodeAvailableException $e) {
            $this->error('Cannot reach log Elasticsearch cluster: ' . $e->getMessage());
            $this->error('Check LOG_ELASTICSEARCH_HOST / LOG_ELASTICSEARCH_PORT in your .env.');

            return self::FAILURE;
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function attachAlias(LogElasticsearchClientInterface $client, string $physicalIndex, string $alias, array $extraProps, bool $exclusive = false): void
    {
        if (! $client->existsAlias($alias)) {
            $this->info("Attaching alias: {$alias}");
            $client->putAlias($physicalIndex, $alias, $extraProps ? ['body' => $extraProps] : []);

            return;
        }

        if ($exclusive) {
            $this->info("Swapping alias {$alias} → {$physicalIndex}");
            $client->updateAliases([
                ['remove' => ['index' => '*', 'alias' => $alias]],
                ['add' => ['index' => $physicalIndex, 'alias' => $alias] + $extraProps],
            ]);
        } else {
            $this->info("Adding {$physicalIndex} to alias {$alias}");
            $client->updateAliases([
                ['add' => ['index' => $physicalIndex, 'alias' => $alias] + $extraProps],
            ]);
        }
    }
}
