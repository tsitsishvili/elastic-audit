<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services\Elasticsearch;

class ActivityLogMapping
{
    public static function get(): array
    {
        return [
            'dynamic'    => 'strict',
            'properties' => [
                '@timestamp'     => ['type' => 'date'],
                'event_id'       => ['type' => 'keyword'],
                'schema_version' => ['type' => 'short'],
                'request_id'     => ['type' => 'keyword'],
                'actor'          => [
                    'properties' => [
                        'type' => ['type' => 'keyword'],
                        'id'   => ['type' => 'long'],
                    ],
                ],
                'action'         => ['type' => 'keyword'],
                'entity'         => [
                    'properties' => [
                        'type' => ['type' => 'keyword'],
                        'id'   => ['type' => 'keyword'],
                    ],
                ],
                'changes'        => ['type' => 'object', 'enabled' => false],
                'metadata'       => ['type' => 'object', 'enabled' => false],
                'success'        => ['type' => 'boolean'],
                'error'          => [
                    'properties' => [
                        'class'   => ['type' => 'keyword'],
                        'message' => ['type' => 'text'],
                    ],
                ],
                'retention_days' => ['type' => 'short'],
            ],
        ];
    }
}
