<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services\Elasticsearch;

use Tsitsishvili\ElasticAudit\DataTransferObjects\MetricData;

class MetricMapping
{
    public static function get(): array
    {
        return [
            'dynamic' => 'strict',
            '_meta'   => [
                'elastic_audit' => [
                    'subsystem'      => 'metrics',
                    'schema_version' => MetricData::SCHEMA_VERSION,
                ],
            ],
            'properties' => [
                '@timestamp'     => ['type' => 'date'],
                'event_id'       => ['type' => 'keyword'],
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
                    'span_id'        => ['type' => 'keyword'],
                    'parent_span_id' => ['type' => 'keyword'],
                ]],
                'transaction' => ['properties' => [
                    'id'         => ['type' => 'keyword'],
                    'sampled'    => ['type' => 'boolean'],
                    'span_count' => ['type' => 'integer'],
                    'profile_id' => ['type' => 'keyword'],
                ]],
                'kind'           => ['type' => 'keyword'],
                'type'           => ['type' => 'keyword'],
                'name'           => ['type' => 'keyword'],
                'outcome'        => ['type' => 'keyword'],
                'duration_ms'    => ['type' => 'double'],
                'retention_days' => ['type' => 'short'],
                'http'           => ['properties' => [
                    'method'      => ['type' => 'keyword'],
                    'route'       => ['type' => 'keyword'],
                    'controller'  => ['type' => 'keyword'],
                    'host'        => ['type' => 'keyword'],
                    'path'        => ['type' => 'keyword'],
                    'status_code' => ['type' => 'short'],
                ]],
                'db' => ['properties' => [
                    'connection'  => ['type' => 'keyword'],
                    'driver'      => ['type' => 'keyword'],
                    'operation'   => ['type' => 'keyword'],
                    'statement'   => ['type' => 'wildcard'],
                    'fingerprint' => ['type' => 'keyword'],
                ]],
                'queue' => ['properties' => [
                    'connection' => ['type' => 'keyword'],
                    'name'       => ['type' => 'keyword'],
                    'job'        => ['type' => 'keyword'],
                    'attempt'    => ['type' => 'short'],
                    'wait_ms'    => ['type' => 'double'],
                ]],
                'console' => ['properties' => [
                    'command'   => ['type' => 'keyword'],
                    'exit_code' => ['type' => 'integer'],
                ]],
                'redis' => ['properties' => [
                    'command'    => ['type' => 'keyword'],
                    'connection' => ['type' => 'keyword'],
                ]],
                'cache' => ['properties' => [
                    'operation' => ['type' => 'keyword'],
                    'store'     => ['type' => 'keyword'],
                    'result'    => ['type' => 'keyword'],
                ]],
                'mail' => ['properties' => [
                    'transport' => ['type' => 'keyword'],
                ]],
                'notification' => ['properties' => [
                    'class'   => ['type' => 'keyword'],
                    'channel' => ['type' => 'keyword'],
                ]],
                'code' => ['properties' => [
                    // Exclusive time: what the function spent itself, with the
                    // cost of everything it called removed. duration_ms holds
                    // the inclusive figure.
                    'self_ms' => ['type' => 'double'],
                ]],
                'scheduler' => ['properties' => [
                    'task'        => ['type' => 'keyword'],
                    'fingerprint' => ['type' => 'keyword'],
                    'exit_code'   => ['type' => 'integer'],
                ]],
            ],
        ];
    }
}
