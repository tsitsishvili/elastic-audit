<?php

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

    'index_prefix' => env('LOG_ELASTICSEARCH_INDEX_PREFIX', 'app_logs'),

    // Number of ES replicas for the logs index. Use 0 only for single-node staging clusters.
    'replicas' => env('LOG_ELASTICSEARCH_REPLICAS', 0),
];
