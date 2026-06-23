<?php

return [
    'enabled'            => env('HTTP_LOGS_ENABLED', false),
    'queue'              => env('HTTP_LOGS_QUEUE', 'default'),
    'sample_rate'        => env('HTTP_LOGS_SAMPLE_RATE', 1.0),
    'body_preview_bytes' => env('HTTP_LOGS_BODY_PREVIEW_BYTES', 4096),
    'body_max_bytes'     => env('HTTP_LOGS_BODY_MAX_BYTES', 32768),

    /*
       * preview = store sanitized body;
       * metadata = drop body, keep only status/host/path
    */
    'payment_body_mode'  => env('HTTP_LOGS_PAYMENT_BODY_MODE', 'preview'),

    'index_alias'             => strtolower(env('LOG_ELASTICSEARCH_INDEX_PREFIX', env('APP_NAME'))) . '_http_logs',
    'index_alias_write'       => strtolower(env('LOG_ELASTICSEARCH_INDEX_PREFIX', env('APP_NAME'))) . '_http_logs_write',

    /*
     * Web dashboard for browsing logged requests (Horizon-style).
     *
     * 'enabled'    Register the dashboard routes. Disable to hide the UI entirely.
     * 'prefix'     Shared group URL segment placed before every dashboard, e.g. /logger.
     *              Both dashboards default-read the ELASTIC_AUDIT_DASHBOARD_PREFIX env var,
     *              so changing it moves both at once. Set to '' to serve at the root.
     * 'path'       The dashboard's own subpath under the group prefix, e.g. 'http-logs'
     *              produces /logger/http-logs.
     * 'middleware' Middleware applied to every dashboard route. The package always
     *              appends its own authorization middleware after this stack.
     * 'per_page'   Number of log rows shown per page in the list view.
     *
     * Access is controlled by an authorization callback. By default the dashboard is
     * only reachable in the local environment. Override it from a service provider:
     *
     *   use Tsitsishvili\ElasticAudit\Dashboard\Dashboard;
     *
     *   Dashboard::auth(fn ($request) => $request->user()?->isAdmin() === true);
     */
    'dashboard' => [
        'enabled'    => env('HTTP_LOGS_DASHBOARD_ENABLED', true),
        'prefix'     => env('ELASTIC_AUDIT_DASHBOARD_PREFIX', 'logger'),
        'path'       => env('HTTP_LOGS_DASHBOARD_PATH', 'http-logs'),
        'middleware' => ['web'],
        'per_page'   => 25,
    ],

    /*
     * Register the backed enum classes your application uses for provider, event type, and
     * entity type. All three must implement the corresponding Contract interface.
     * The middleware uses these to resolve string attribute values from the request.
     *
     * 'entity_type_default' is the fallback string value when no entity type attribute is set.
     */
    'enums' => [
        'provider'            => \App\Enums\ElasticAudit\Provider::class,
        'event_type'          => \App\Enums\ElasticAudit\EventType::class,
        'entity_type'         => \App\Enums\ElasticAudit\EntityType::class,
        'entity_type_default' => 'none',
    ],

    /*
     * String values of provider enum cases that should use PaymentRedactor.
     * Set this in your app's config override with the ->value of your payment provider cases.
     * Example: ['tbc', 'bog', 'credo'...]
     */
    'payment_provider_values' => [],
];
