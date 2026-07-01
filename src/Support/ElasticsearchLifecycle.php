<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Support;

final class ElasticsearchLifecycle
{
    public static function enabled(): bool
    {
        return (bool) config('log_elasticsearch.lifecycle.enabled', false);
    }

    public static function policyName(): string
    {
        return (string) config('log_elasticsearch.lifecycle.policy_name', 'elastic_audit_policy');
    }

    public static function rolloverConditions(): array
    {
        $conditions = [];

        $maxAge = config('log_elasticsearch.lifecycle.rollover_max_age');
        if (is_string($maxAge) && $maxAge !== '') {
            $conditions['max_age'] = $maxAge;
        }

        $maxShardSize = config('log_elasticsearch.lifecycle.rollover_max_shard_size');
        if (is_string($maxShardSize) && $maxShardSize !== '') {
            $conditions['max_primary_shard_size'] = $maxShardSize;
        }

        return $conditions;
    }

    public static function policy(): array
    {
        $phases = [
            'hot' => [
                'actions' => [
                    'rollover' => self::rolloverConditions(),
                ],
            ],
        ];

        $deleteAfter = config('log_elasticsearch.lifecycle.delete_after');
        if (is_string($deleteAfter) && $deleteAfter !== '') {
            $phases['delete'] = [
                'min_age' => $deleteAfter,
                'actions' => ['delete' => new \stdClass()],
            ];
        }

        return ['phases' => $phases];
    }

    public static function indexSettings(string $writeAlias): array
    {
        if (! self::enabled()) {
            return [];
        }

        return [
            'index.lifecycle.name'           => self::policyName(),
            'index.lifecycle.rollover_alias' => $writeAlias,
        ];
    }
}
