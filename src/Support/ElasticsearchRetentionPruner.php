<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Support;

use RuntimeException;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;

final class ElasticsearchRetentionPruner
{
    private const COMPOSITE_PAGE_SIZE = 1000;

    public function __construct(
        private readonly LogElasticsearchClientInterface $client,
    ) {}

    /**
     * Fetch every distinct retention_days value without the cardinality limit
     * of a terms aggregation.
     *
     * @return list<int>
     */
    public function retentionDays(string $alias): array
    {
        $values = [];
        $after  = null;

        do {
            $composite = [
                'size'    => self::COMPOSITE_PAGE_SIZE,
                'sources' => [
                    ['retention_days' => ['terms' => ['field' => 'retention_days']]],
                ],
            ];

            if ($after !== null) {
                $composite['after'] = $after;
            }

            $result = $this->client->search([
                'index' => $alias,
                'body'  => [
                    'size' => 0,
                    'aggs' => [
                        'retention_buckets' => ['composite' => $composite],
                    ],
                ],
            ]);

            $this->assertSearchComplete($result);

            $aggregation = $result['aggregations']['retention_buckets'] ?? null;

            if (! is_array($aggregation)) {
                throw new RuntimeException('Elasticsearch retention aggregation is missing from the response.');
            }

            $buckets = $aggregation['buckets'] ?? null;

            if (! is_array($buckets)) {
                throw new RuntimeException('Elasticsearch retention aggregation returned invalid buckets.');
            }

            foreach ($buckets as $bucket) {
                $value = is_array($bucket) ? ($bucket['key']['retention_days'] ?? null) : null;

                if (! is_int($value) && ! is_string($value)) {
                    throw new RuntimeException('Elasticsearch retention aggregation returned an invalid retention_days key.');
                }

                $validatedDays = filter_var($value, FILTER_VALIDATE_INT);

                if ($validatedDays === false) {
                    throw new RuntimeException('Elasticsearch retention aggregation returned an invalid retention_days key.');
                }

                $days          = RetentionDays::validate($validatedDays);
                $values[$days] = $days;
            }

            if ($buckets === [] || ! isset($aggregation['after_key'])) {
                break;
            }

            $nextAfter = $aggregation['after_key'];

            if (! is_array($nextAfter) || $nextAfter === [] || $nextAfter === $after) {
                throw new RuntimeException('Elasticsearch retention aggregation returned an invalid pagination cursor.');
            }

            $after = $nextAfter;
        } while (true);

        return array_values($values);
    }

    public function deleteExpired(string $alias, int $days, string $cutoff): int
    {
        $result = $this->client->deleteByQuery([
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

        if (($result['timed_out'] ?? false) === true) {
            throw new RuntimeException('Elasticsearch delete_by_query timed out.');
        }

        if (! empty($result['failures'])) {
            throw new RuntimeException('Elasticsearch delete_by_query returned one or more failures.');
        }

        if ((int) ($result['version_conflicts'] ?? 0) > 0) {
            throw new RuntimeException('Elasticsearch delete_by_query returned one or more version conflicts.');
        }

        return (int) ($result['deleted'] ?? 0);
    }

    private function assertSearchComplete(array $result): void
    {
        if (($result['timed_out'] ?? false) === true) {
            throw new RuntimeException('Elasticsearch retention aggregation timed out.');
        }

        if ((int) ($result['_shards']['failed'] ?? 0) > 0) {
            throw new RuntimeException('Elasticsearch retention aggregation returned failed shards.');
        }
    }
}
