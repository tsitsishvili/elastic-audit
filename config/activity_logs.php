<?php

return [
    'enabled' => env('ACTIVITY_LOGS_ENABLED', true),
    'queue'   => env('ACTIVITY_LOGS_QUEUE', 'default'),
    'job'     => [
        'tries'         => env('ACTIVITY_LOGS_JOB_TRIES', 3),
        'backoff'       => explode(',', (string) env('ACTIVITY_LOGS_JOB_BACKOFF', '10,30,120')),
        'timeout'       => env('ACTIVITY_LOGS_JOB_TIMEOUT', 30),
        'batch_timeout' => env('ACTIVITY_LOGS_BATCH_JOB_TIMEOUT', 60),
    ],
    // retain_forever makes the default document lifetime permanent. Individual
    // contexts can still pass retentionDays to opt into a finite lifetime. The
    // lifecycle delete phase must also be disabled to retain whole indexes.
    'retention_days' => env('ACTIVITY_LOGS_RETENTION_DAYS', 360),
    'retain_forever' => env('ACTIVITY_LOGS_RETAIN_FOREVER', false),

    // Null derives aliases from log_elasticsearch.index_prefix. Set a string
    // only when this subsystem intentionally needs custom aliases.
    'index_alias'       => null,
    'index_alias_write' => null,

    /*
     * Redaction applied to the 'changes' and 'metadata' maps before an activity
     * event is queued. The same built-in rules as the HTTP logger apply (so a
     * model's 'password'/'email' attribute diffs are redacted by key name), plus
     * the overrides below.
     *
     * 'block'  Extra attribute/metadata keys to ALWAYS redact (whole-word match).
     * 'allow'  Keys to NEVER redact, even when a built-in/'block' rule matches
     *          (exact match; takes precedence). Use with care.
     */
    'redaction' => [
        'block' => [],
        'allow' => [],
    ],

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
