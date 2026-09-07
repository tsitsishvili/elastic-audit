<?php

return [
    /*
     * Automatic application performance monitoring. Transactions and spans
     * are written to the metrics index; sampled call stacks are written to a
     * separate profiles index. The master switch is deliberately off by
     * default so installing this package never changes production load.
     */
    'enabled'    => env('ELASTIC_AUDIT_METRICS_ENABLED', false),
    'queue'      => env('ELASTIC_AUDIT_METRICS_QUEUE', 'default'),
    'batch_size' => env('ELASTIC_AUDIT_METRICS_BATCH_SIZE', 100),
    'job'        => [
        'tries'         => env('ELASTIC_AUDIT_METRICS_JOB_TRIES', 3),
        'backoff'       => explode(',', (string) env('ELASTIC_AUDIT_METRICS_JOB_BACKOFF', '10,30,120')),
        'timeout'       => env('ELASTIC_AUDIT_METRICS_JOB_TIMEOUT', 30),
        'batch_timeout' => env('ELASTIC_AUDIT_METRICS_BATCH_JOB_TIMEOUT', 60),
    ],

    'capture' => [
        'http' => [
            'enabled'         => env('ELASTIC_AUDIT_METRICS_HTTP_ENABLED', true),
            'sample_rate'     => env('ELASTIC_AUDIT_METRICS_HTTP_SAMPLE_RATE', 1.0),
            'min_duration_ms' => env('ELASTIC_AUDIT_METRICS_HTTP_MIN_DURATION_MS', 0),
            // Request paths that must never produce a transaction, as
            // fnmatch() patterns without a leading slash, e.g. 'up',
            // 'health/*'. Requests matched here are suppressed entirely, so
            // their queries and outgoing calls are not recorded either. This
            // package's own dashboards and assets are always excluded.
            'exclude_paths' => [],
        ],
        'queries' => [
            'enabled'             => env('ELASTIC_AUDIT_METRICS_QUERIES_ENABLED', true),
            'sample_rate'         => env('ELASTIC_AUDIT_METRICS_QUERIES_SAMPLE_RATE', 1.0),
            'min_duration_ms'     => env('ELASTIC_AUDIT_METRICS_QUERIES_MIN_DURATION_MS', 0),
            'include_statement'   => env('ELASTIC_AUDIT_METRICS_QUERIES_INCLUDE_STATEMENT', true),
            'max_statement_bytes' => env('ELASTIC_AUDIT_METRICS_QUERIES_MAX_STATEMENT_BYTES', 4096),
        ],
        'jobs' => [
            'enabled'         => env('ELASTIC_AUDIT_METRICS_JOBS_ENABLED', true),
            'sample_rate'     => env('ELASTIC_AUDIT_METRICS_JOBS_SAMPLE_RATE', 1.0),
            'min_duration_ms' => env('ELASTIC_AUDIT_METRICS_JOBS_MIN_DURATION_MS', 0),
            // Job classes that must never produce a transaction or a publish
            // span, as fnmatch() patterns, e.g. 'App\\Jobs\\Noisy*'. The whole
            // run is suppressed, so the job's own queries are not recorded
            // either. This package's audit and telemetry delivery jobs are
            // always excluded.
            'exclude' => [],
        ],
        'queue_publish' => [
            'enabled'         => env('ELASTIC_AUDIT_METRICS_QUEUE_PUBLISH_ENABLED', true),
            'sample_rate'     => env('ELASTIC_AUDIT_METRICS_QUEUE_PUBLISH_SAMPLE_RATE', 1.0),
            'min_duration_ms' => env('ELASTIC_AUDIT_METRICS_QUEUE_PUBLISH_MIN_DURATION_MS', 0),
        ],
        'commands' => [
            'enabled'         => env('ELASTIC_AUDIT_METRICS_COMMANDS_ENABLED', true),
            'sample_rate'     => env('ELASTIC_AUDIT_METRICS_COMMANDS_SAMPLE_RATE', 1.0),
            'min_duration_ms' => env('ELASTIC_AUDIT_METRICS_COMMANDS_MIN_DURATION_MS', 0),
            /*
             * Commands whose timing describes something other than application
             * work. The first group owns a long-running process, so its runtime
             * is the process lifetime; the second measures a REPL session or a
             * whole test suite. Jobs and scheduled tasks running inside an
             * excluded command remain observable as independent roots.
             */
            'exclude' => [
                'queue:work',
                'queue:listen',
                'schedule:work',
                'horizon',
                'octane:start',
                'reverb:start',
                'pulse:work',
                'serve',
                'pail',

                'tinker',
                'test',
                'dusk',
            ],
        ],
        'scheduled_tasks' => [
            'enabled'         => env('ELASTIC_AUDIT_METRICS_SCHEDULED_TASKS_ENABLED', true),
            'sample_rate'     => env('ELASTIC_AUDIT_METRICS_SCHEDULED_TASKS_SAMPLE_RATE', 1.0),
            'min_duration_ms' => env('ELASTIC_AUDIT_METRICS_SCHEDULED_TASKS_MIN_DURATION_MS', 0),
            // Descriptions and shell commands can contain secrets. The default
            // uses a constant label plus a non-reversible fingerprint.
            'include_description' => env('ELASTIC_AUDIT_METRICS_SCHEDULED_TASKS_INCLUDE_DESCRIPTION', false),
        ],
        'outgoing_http' => [
            'enabled'         => env('ELASTIC_AUDIT_METRICS_OUTGOING_HTTP_ENABLED', true),
            'sample_rate'     => env('ELASTIC_AUDIT_METRICS_OUTGOING_HTTP_SAMPLE_RATE', 1.0),
            'min_duration_ms' => env('ELASTIC_AUDIT_METRICS_OUTGOING_HTTP_MIN_DURATION_MS', 0),
            'include_path'    => env('ELASTIC_AUDIT_METRICS_OUTGOING_HTTP_INCLUDE_PATH', true),
            'exclude_hosts'   => [],
        ],
        /*
         * Timing for application code, from two independent sources.
         *
         * Explicit: Performance::measure() records exactly what you wrap, on
         * every call, with the duration actually observed.
         *
         * Automatic: when `automatic` is on, the application's own frames are
         * read straight out of each captured profile, so no code has to be
         * wrapped at all. This only covers profiled transactions, so its
         * coverage is `profiles.sample_rate`, and with the Excimer sampling
         * driver the durations are estimates rather than measurements. Those
         * spans are typed `app.function.profiled` to keep them separable from
         * the exact ones.
         */
        'functions' => [
            'enabled'         => env('ELASTIC_AUDIT_METRICS_FUNCTIONS_ENABLED', true),
            'sample_rate'     => env('ELASTIC_AUDIT_METRICS_FUNCTIONS_SAMPLE_RATE', 1.0),
            'min_duration_ms' => env('ELASTIC_AUDIT_METRICS_FUNCTIONS_MIN_DURATION_MS', 0),

            'automatic'                 => env('ELASTIC_AUDIT_METRICS_FUNCTIONS_AUTOMATIC', false),
            'automatic_limit'           => env('ELASTIC_AUDIT_METRICS_FUNCTIONS_AUTOMATIC_LIMIT', 20),
            'automatic_min_duration_ms' => env('ELASTIC_AUDIT_METRICS_FUNCTIONS_AUTOMATIC_MIN_DURATION_MS', 1.0),

            // Namespace prefixes counting as application code. Empty falls back
            // to the namespace Laravel resolves for the application itself, so
            // vendor and framework frames stay out of the results.
            'namespaces' => [],
        ],
        'redis' => [
            'enabled'         => env('ELASTIC_AUDIT_METRICS_REDIS_ENABLED', true),
            'sample_rate'     => env('ELASTIC_AUDIT_METRICS_REDIS_SAMPLE_RATE', 1.0),
            'min_duration_ms' => env('ELASTIC_AUDIT_METRICS_REDIS_MIN_DURATION_MS', 0),
        ],
        'cache' => [
            'enabled'         => env('ELASTIC_AUDIT_METRICS_CACHE_ENABLED', true),
            'sample_rate'     => env('ELASTIC_AUDIT_METRICS_CACHE_SAMPLE_RATE', 1.0),
            'min_duration_ms' => env('ELASTIC_AUDIT_METRICS_CACHE_MIN_DURATION_MS', 0),
        ],
        'mail' => [
            'enabled'         => env('ELASTIC_AUDIT_METRICS_MAIL_ENABLED', true),
            'sample_rate'     => env('ELASTIC_AUDIT_METRICS_MAIL_SAMPLE_RATE', 1.0),
            'min_duration_ms' => env('ELASTIC_AUDIT_METRICS_MAIL_MIN_DURATION_MS', 0),
        ],
        'notifications' => [
            'enabled'         => env('ELASTIC_AUDIT_METRICS_NOTIFICATIONS_ENABLED', true),
            'sample_rate'     => env('ELASTIC_AUDIT_METRICS_NOTIFICATIONS_SAMPLE_RATE', 1.0),
            'min_duration_ms' => env('ELASTIC_AUDIT_METRICS_NOTIFICATIONS_MIN_DURATION_MS', 0),
        ],
    ],

    'trace' => [
        'honor_incoming_sampled' => env('ELASTIC_AUDIT_METRICS_HONOR_INCOMING_SAMPLED', true),
        'propagate_http'         => env('ELASTIC_AUDIT_METRICS_PROPAGATE_HTTP', true),
        'propagate_queue'        => env('ELASTIC_AUDIT_METRICS_PROPAGATE_QUEUE', true),
    ],

    /*
     * Excimer is preferred for low-overhead continuous production sampling.
     * Modern PECL XHProf remains available for opt-in instrumentation profiles.
     * Profile sampling is evaluated only after a transaction is sampled.
     */
    'profiles' => [
        'enabled'           => env('ELASTIC_AUDIT_PROFILES_ENABLED', true),
        'driver'            => env('ELASTIC_AUDIT_PROFILES_DRIVER', 'auto'),
        'sample_rate'       => env('ELASTIC_AUDIT_PROFILES_SAMPLE_RATE', 0.01),
        'period_ms'         => env('ELASTIC_AUDIT_PROFILES_PERIOD_MS', 10.1),
        'max_depth'         => env('ELASTIC_AUDIT_PROFILES_MAX_DEPTH', 128),
        'max_samples'       => env('ELASTIC_AUDIT_PROFILES_MAX_SAMPLES', 10000),
        'max_payload_bytes' => env('ELASTIC_AUDIT_PROFILES_MAX_PAYLOAD_BYTES', 2097152),
        'include_paths'     => env('ELASTIC_AUDIT_PROFILES_INCLUDE_PATHS', false),
        'cpu'               => env('ELASTIC_AUDIT_PROFILES_XHPROF_CPU', true),
        'memory'            => env('ELASTIC_AUDIT_PROFILES_XHPROF_MEMORY', true),
        'queue'             => env('ELASTIC_AUDIT_PROFILES_QUEUE', 'default'),
        'job'               => [
            'tries'   => env('ELASTIC_AUDIT_PROFILES_JOB_TRIES', 3),
            'backoff' => explode(',', (string) env('ELASTIC_AUDIT_PROFILES_JOB_BACKOFF', '10,30,120')),
            'timeout' => env('ELASTIC_AUDIT_PROFILES_JOB_TIMEOUT', 60),
        ],
        'retention_days'    => env('ELASTIC_AUDIT_PROFILES_RETENTION_DAYS', 7),
        'index_alias'       => null,
        'index_alias_write' => null,
    ],

    'retention_days' => env('ELASTIC_AUDIT_METRICS_RETENTION_DAYS', 30),

    // Null derives aliases from log_elasticsearch.index_prefix.
    'index_alias'       => null,
    'index_alias_write' => null,

    'dashboard' => [
        'enabled'    => env('ELASTIC_AUDIT_METRICS_DASHBOARD_ENABLED', true),
        'prefix'     => env('ELASTIC_AUDIT_DASHBOARD_PREFIX', 'logger'),
        'path'       => env('ELASTIC_AUDIT_METRICS_DASHBOARD_PATH', 'metrics'),
        'middleware' => ['web'],
        'per_page'   => 25,
    ],
];
