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

    // An API key (the base64 "encoded" value) is used instead of basicAuthentication when set.
    'api_key' => env('LOG_ELASTICSEARCH_API_KEY'),

    'ssl' => [
        'verify' => env('LOG_ELASTICSEARCH_SSL_VERIFY', true),
        // Path to a CA certificate, for clusters signed by a private authority.
        'ca_bundle' => env('LOG_ELASTICSEARCH_CA_BUNDLE'),
    ],

    /*
     * Seconds to wait for the connection and for the whole response. Null waits
     * without limit, so a cluster that stops answering holds the dashboard page
     * or sync-queue request that called it. Prune commands wait for the delete
     * to finish: leave 'timeout' unset for the process that runs them, or give
     * it room.
     */
    'connect_timeout' => env('LOG_ELASTICSEARCH_CONNECT_TIMEOUT'),
    'timeout'         => env('LOG_ELASTICSEARCH_TIMEOUT'),

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
