<?php

use Illuminate\Support\Str;

$explicitIndexPrefix = env('LOG_ELASTICSEARCH_INDEX_PREFIX');
$applicationPrefix   = Str::slug((string) env('APP_NAME', 'app_logs'), '_');
$indexPrefix         = $explicitIndexPrefix !== null
    ? strtolower((string) $explicitIndexPrefix)
    : ($applicationPrefix !== '' ? $applicationPrefix : 'app_logs');

return [
    'hosts' => [
        [
            'host'   => env('LOG_ELASTICSEARCH_HOST', 'localhost'),
            'port'   => env('LOG_ELASTICSEARCH_PORT', 9200),
            'scheme' => env('LOG_ELASTICSEARCH_SCHEME', 'http'),
        ],
    ],

    'basicAuthentication' => [
        'username' => env('LOG_ELASTICSEARCH_USERNAME', ''),
        'password' => env('LOG_ELASTICSEARCH_PASSWORD', ''),
    ],

    // Explicit values are preserved. The APP_NAME fallback is slugged so names
    // such as "Example App" remain valid; derived aliases are validated by commands.
    'index_prefix' => $indexPrefix,

    // Number of ES replicas for the logs index. Use 0 only for single-node staging clusters.
    'replicas' => env('LOG_ELASTICSEARCH_REPLICAS', 1),

    /*
     * Elasticsearch Index Lifecycle Management is the default retention path for
     * newly created indexes. Prune commands remain available for per-document retention
     * overrides or clusters where ILM is intentionally disabled.
     */
    'lifecycle' => [
        'enabled' => env('LOG_ELASTICSEARCH_LIFECYCLE_ENABLED', true),
        // Null derives the policy name from index_prefix during provider registration.
        'policy_name'             => env('LOG_ELASTICSEARCH_LIFECYCLE_POLICY'),
        'rollover_max_age'        => env('LOG_ELASTICSEARCH_ROLLOVER_MAX_AGE', '30d'),
        'rollover_max_shard_size' => env('LOG_ELASTICSEARCH_ROLLOVER_MAX_SHARD_SIZE', '50gb'),
        // Disable only the delete phase while retaining ILM rollover.
        'delete_enabled' => env('LOG_ELASTICSEARCH_LIFECYCLE_DELETE_ENABLED', true),
        'delete_after'   => env('LOG_ELASTICSEARCH_LIFECYCLE_DELETE_AFTER', '360d'),
    ],
];
