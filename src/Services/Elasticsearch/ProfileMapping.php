<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services\Elasticsearch;

use Tsitsishvili\ElasticAudit\DataTransferObjects\ProfileData;

final class ProfileMapping
{
    public static function get(): array
    {
        return [
            'dynamic' => 'strict',
            '_meta'   => [
                'elastic_audit' => [
                    'subsystem'      => 'profiles',
                    'schema_version' => ProfileData::SCHEMA_VERSION,
                ],
            ],
            'properties' => [
                '@timestamp'     => ['type' => 'date'],
                'profile_id'     => ['type' => 'keyword'],
                'schema_version' => ['type' => 'short'],
                'service'        => ['properties' => [
                    'name'        => ['type' => 'keyword'],
                    'environment' => ['type' => 'keyword'],
                ]],
                'execution' => ['properties' => [
                    'type'   => ['type' => 'keyword'],
                    'name'   => ['type' => 'keyword'],
                    'action' => ['type' => 'keyword'],
                ]],
                'trace' => ['properties' => [
                    'id'             => ['type' => 'keyword'],
                    'transaction_id' => ['type' => 'keyword'],
                ]],
                'transaction' => ['properties' => [
                    'name' => ['type' => 'keyword'],
                    'type' => ['type' => 'keyword'],
                ]],
                'duration_ms'    => ['type' => 'double'],
                'driver'         => ['type' => 'keyword'],
                'mode'           => ['type' => 'keyword'],
                'format'         => ['type' => 'keyword'],
                'sample_rate_hz' => ['type' => 'double'],
                'sample_count'   => ['type' => 'integer'],
                'truncated'      => ['type' => 'boolean'],
                'hot_frames'     => [
                    'type'       => 'nested',
                    'properties' => [
                        'function'      => ['type' => 'keyword'],
                        'file'          => ['type' => 'keyword'],
                        'line'          => ['type' => 'integer'],
                        'self_samples'  => ['type' => 'integer'],
                        'total_samples' => ['type' => 'integer'],
                    ],
                ],
                // The raw Speedscope/XHProf payload is retrievable but not indexed.
                'payload'        => ['type' => 'object', 'enabled' => false],
                'retention_days' => ['type' => 'short'],
            ],
        ];
    }
}
