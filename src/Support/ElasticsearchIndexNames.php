<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Support;

use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;

final class ElasticsearchIndexNames
{
    public static function initialRolloverIndex(string $baseName): string
    {
        return self::rolloverIndex($baseName, 1);
    }

    public static function needsExplicitRolloverIndex(?string $currentIndex): bool
    {
        return $currentIndex === null || preg_match('/^.*-\d+$/', $currentIndex) !== 1;
    }

    public static function currentWriteIndex(array $aliasResponse, string $writeAlias): ?string
    {
        foreach ($aliasResponse as $index => $metadata) {
            $alias = $metadata['aliases'][$writeAlias] ?? null;

            if (is_array($alias) && ($alias['is_write_index'] ?? false) === true) {
                return (string) $index;
            }
        }

        $firstIndex = array_key_first($aliasResponse);

        return $firstIndex === null ? null : (string) $firstIndex;
    }

    public static function nextAvailableRolloverIndex(
        LogElasticsearchClientInterface $client,
        string $baseName,
    ): string {
        for ($generation = 1; $generation < 1000000; $generation++) {
            $index = self::rolloverIndex($baseName, $generation);

            if (! $client->existsIndex($index)) {
                return $index;
            }
        }

        return self::rolloverIndex($baseName, 1000000);
    }

    private static function rolloverIndex(string $baseName, int $generation): string
    {
        return sprintf('%s-%06d', $baseName, $generation);
    }
}
