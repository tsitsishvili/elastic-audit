<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Support;

use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;

/**
 * Keeps a subsystem's write alias attached to exactly one index.
 *
 * The package attaches the write alias without an explicit is_write_index flag.
 * Elasticsearch's rollover API then removes the alias from the previous index
 * whenever it rolls over — from ILM, the rollover command, or a re-run of the
 * create-index command. With an explicit is_write_index: true, the same API
 * keeps the alias on the previous index with the flag set to false and copies
 * the true flag to the new index, so every later rollover leaves another stale
 * entry behind. Deployments provisioned by earlier package versions carry that
 * flag; normalization detaches their stale entries and clears it.
 *
 * All array helpers take the GET _alias/<name> response shape:
 * ['<index>' => ['aliases' => ['<alias>' => ['is_write_index' => bool, ...]]]].
 */
final class ElasticsearchWriteAlias
{
    /**
     * The one index the alias writes to: the single index flagged is_write_index
     * true, or the only index when none carries a flag. Null when the alias is
     * ambiguous or explicitly non-writable.
     *
     * @param  array<string, mixed>  $aliasResponse
     */
    public static function writeIndex(array $aliasResponse, string $writeAlias): ?string
    {
        $aliasIndexes         = [];
        $explicitWriteIndexes = [];

        foreach ($aliasResponse as $index => $metadata) {
            $alias = is_array($metadata) ? ($metadata['aliases'][$writeAlias] ?? null) : null;

            if (! is_array($alias)) {
                continue;
            }

            $aliasIndexes[(string) $index] = $alias;

            if (($alias['is_write_index'] ?? null) === true) {
                $explicitWriteIndexes[] = (string) $index;
            }
        }

        if (count($explicitWriteIndexes) === 1) {
            return $explicitWriteIndexes[0];
        }

        if ($explicitWriteIndexes !== [] || count($aliasIndexes) !== 1) {
            return null;
        }

        $index = array_key_first($aliasIndexes);

        return ! array_key_exists('is_write_index', $aliasIndexes[$index]) ? $index : null;
    }

    /**
     * Indexes the alias still lists besides its write index. Empty when the
     * write index cannot be resolved; the health command reports that state.
     *
     * @param  array<string, mixed>  $aliasResponse
     * @return list<string>
     */
    public static function staleIndexes(array $aliasResponse, string $writeAlias): array
    {
        $writeIndex = self::writeIndex($aliasResponse, $writeAlias);

        if ($writeIndex === null) {
            return [];
        }

        $stale = [];

        foreach ($aliasResponse as $index => $metadata) {
            if ((string) $index !== $writeIndex && is_array($metadata) && isset($metadata['aliases'][$writeAlias])) {
                $stale[] = (string) $index;
            }
        }

        return $stale;
    }

    /**
     * @param  array<string, mixed>  $aliasResponse
     */
    public static function hasExplicitWriteFlag(array $aliasResponse, string $writeAlias): bool
    {
        $writeIndex = self::writeIndex($aliasResponse, $writeAlias);

        if ($writeIndex === null) {
            return false;
        }

        return ($aliasResponse[$writeIndex]['aliases'][$writeAlias]['is_write_index'] ?? null) === true;
    }

    /**
     * Alias actions that leave the alias on its write index alone, without an
     * explicit flag. An add action replaces the alias metadata on that index,
     * which is what clears is_write_index. Empty when nothing needs to change.
     *
     * @param  array<string, mixed>  $aliasResponse
     * @return list<array<string, array<string, string>>>
     */
    public static function normalizationActions(array $aliasResponse, string $writeAlias): array
    {
        $writeIndex = self::writeIndex($aliasResponse, $writeAlias);

        if ($writeIndex === null) {
            return [];
        }

        $actions = [];

        foreach (self::staleIndexes($aliasResponse, $writeAlias) as $index) {
            $actions[] = ['remove' => ['index' => $index, 'alias' => $writeAlias]];
        }

        if (self::hasExplicitWriteFlag($aliasResponse, $writeAlias)) {
            $actions[] = ['add' => ['index' => $writeIndex, 'alias' => $writeAlias]];
        }

        return $actions;
    }

    /**
     * Apply the normalization in one atomic aliases request.
     *
     * @return list<string> The indexes the alias was detached from.
     */
    public static function normalize(LogElasticsearchClientInterface $client, string $writeAlias): array
    {
        $response = $client->getAlias($writeAlias);
        $actions  = self::normalizationActions($response, $writeAlias);

        if ($actions !== []) {
            $client->updateAliases($actions);
        }

        return self::staleIndexes($response, $writeAlias);
    }
}
