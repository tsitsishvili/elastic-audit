<?php

return [
    'enabled'           => env('ACTIVITY_LOGS_ENABLED', true),
    'queue'             => env('ACTIVITY_LOGS_QUEUE', 'default'),
    'retention_days'    => 360,

    'index_alias'       => strtolower(env('LOG_ELASTICSEARCH_INDEX_PREFIX', env('APP_NAME'))) . '_activity_logs',
    'index_alias_write' => strtolower(env('LOG_ELASTICSEARCH_INDEX_PREFIX', env('APP_NAME'))) . '_activity_logs_write',

    /*
     * Web dashboard for browsing activity logs.
     *
     * 'prefix' is the shared group URL segment (default-reads ELASTIC_AUDIT_DASHBOARD_PREFIX,
     * shared with the HTTP logs dashboard); 'path' is this dashboard's subpath under it,
     * e.g. /logger/activity. Set 'prefix' to '' to serve at the root.
     */
    'dashboard' => [
        'enabled'    => env('ACTIVITY_LOGS_DASHBOARD_ENABLED', true),
        'prefix'     => env('ELASTIC_AUDIT_DASHBOARD_PREFIX', 'logger'),
        'path'       => env('ACTIVITY_LOGS_DASHBOARD_PATH', 'activity'),
        'middleware' => ['web'],
        'per_page'   => 25,
    ],
];
