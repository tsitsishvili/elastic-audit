<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Support;

final class ElasticsearchIndexTemplate
{
    public static function name(string $readAlias): string
    {
        return "{$readAlias}_template";
    }

    public static function body(string $readAlias, string $writeAlias, array $mappings): array
    {
        return [
            'index_patterns' => ["{$readAlias}-*"],
            'template'       => [
                'settings' => [
                    'number_of_shards'   => 1,
                    'number_of_replicas' => config('log_elasticsearch.replicas', 1),
                    ...ElasticsearchLifecycle::indexSettings($writeAlias),
                ],
                'mappings' => $mappings,
                'aliases'  => [
                    $readAlias => new \stdClass,
                ],
            ],
        ];
    }
}
