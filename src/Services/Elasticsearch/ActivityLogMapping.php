<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services\Elasticsearch;

use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogData;

class ActivityLogMapping
{
    public static function get(): array
    {
        return [
            'dynamic' => 'strict',
            '_meta'   => [
                'elastic_audit' => [
                    'subsystem'      => 'activity_logs',
                    'schema_version' => ActivityLogData::SCHEMA_VERSION,
                ],
            ],
            'properties' => [
                '@timestamp'     => ['type' => 'date'],
                'event_id'       => ['type' => 'keyword'],
                'schema_version' => ['type' => 'short'],
                'request_id'     => ['type' => 'keyword'],
                'trace'          => [
                    'properties' => [
                        'id'          => ['type' => 'keyword'],
                        'span_id'     => ['type' => 'keyword'],
                        'traceparent' => ['type' => 'keyword', 'index' => false],
                    ],
                ],
                'actor' => [
                    'properties' => [
                        'type' => ['type' => 'keyword'],
                        'id'   => ['type' => 'keyword'],
                    ],
                ],
                'action' => ['type' => 'keyword'],
                'entity' => [
                    'properties' => [
                        'type' => ['type' => 'keyword'],
                        'id'   => ['type' => 'keyword'],
                    ],
                ],
                'changes'  => ['type' => 'object', 'enabled' => false],
                'metadata' => ['type' => 'object', 'enabled' => false],
                'success'  => ['type' => 'boolean'],
                'error'    => [
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
