<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services\Elasticsearch;

use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogData;

class HttpLogMapping
{
    public static function get(): array
    {
        return [
            'dynamic' => 'strict',
            '_meta'   => [
                'elastic_audit' => [
                    'subsystem'      => 'http_logs',
                    'schema_version' => HttpLogData::SCHEMA_VERSION,
                ],
            ],
            'properties' => [
                '@timestamp'     => ['type' => 'date'],
                'event_id'       => ['type' => 'keyword'],
                'schema_version' => ['type' => 'short'],
                'request_id'     => ['type' => 'keyword'],
                'provider'       => ['type' => 'keyword'],
                'event_type'     => ['type' => 'keyword'],
                'direction'      => ['type' => 'keyword'],
                'user_id'        => ['type' => 'keyword'],
                'attempt'        => ['type' => 'short'],
                'success'        => ['type' => 'boolean'],
                'retention_days' => ['type' => 'short'],
                'trace'          => [
                    'properties' => [
                        'id'          => ['type' => 'keyword'],
                        'span_id'     => ['type' => 'keyword'],
                        'traceparent' => ['type' => 'keyword', 'index' => false],
                    ],
                ],
                'http' => [
                    'properties' => [
                        'method'       => ['type' => 'keyword'],
                        'url'          => ['type' => 'keyword', 'index' => false],
                        'host'         => ['type' => 'keyword'],
                        'path'         => ['type' => 'keyword'],
                        'status_code'  => ['type' => 'short'],
                        'status_class' => ['type' => 'keyword'],
                        'latency_ms'   => ['type' => 'integer'],
                        'timed_out'    => ['type' => 'boolean'],
                    ],
                ],
                'entity' => [
                    'properties' => [
                        'type' => ['type' => 'keyword'],
                        'id'   => ['type' => 'keyword'],
                    ],
                ],
                'external' => [
                    'properties' => [
                        'id' => ['type' => 'keyword'],
                    ],
                ],
                'request' => [
                    'properties' => [
                        'headers'        => ['type' => 'object', 'enabled' => false],
                        'body'           => ['type' => 'object', 'enabled' => false],
                        'body_preview'   => ['type' => 'text'],
                        'body_hash'      => ['type' => 'keyword'],
                        'body_truncated' => ['type' => 'boolean'],
                    ],
                ],
                'response' => [
                    'properties' => [
                        'headers'        => ['type' => 'object', 'enabled' => false],
                        'body'           => ['type' => 'object', 'enabled' => false],
                        'body_preview'   => ['type' => 'text'],
                        'body_hash'      => ['type' => 'keyword'],
                        'body_truncated' => ['type' => 'boolean'],
                    ],
                ],
                'error' => [
                    'properties' => [
                        'class'   => ['type' => 'keyword'],
                        'message' => ['type' => 'text'],
                    ],
                ],
            ],
        ];
    }
}
