<?php

return [
    'enabled'            => env('HTTP_LOGS_ENABLED', false),
    'queue'              => env('HTTP_LOGS_QUEUE', 'default'),
    'job'                => [
        'tries'         => env('HTTP_LOGS_JOB_TRIES', 3),
        'backoff'       => explode(',', (string) env('HTTP_LOGS_JOB_BACKOFF', '10,30,120')),
        'timeout'       => env('HTTP_LOGS_JOB_TIMEOUT', 30),
        'batch_timeout' => env('HTTP_LOGS_BATCH_JOB_TIMEOUT', 60),
    ],
    // Default per-document retention, used when a log context does not set one
    // explicitly. retain_forever takes precedence over this default. The prune
    // command ignores permanent documents; ILM deletion still applies per index.
    'retention_days'     => env('HTTP_LOGS_RETENTION_DAYS', 360),
    'retain_forever'     => env('HTTP_LOGS_RETAIN_FOREVER', false),
    'sample_rate'        => env('HTTP_LOGS_SAMPLE_RATE', 1.0),
    'body_preview_bytes' => env('HTTP_LOGS_BODY_PREVIEW_BYTES', 4096),
    'body_max_bytes'     => env('HTTP_LOGS_BODY_MAX_BYTES', 32768),

    /*
     * Bodies larger than this many bytes are captured as headers-only —
     * no body, preview, or hash — so a huge provider response is never
     * decoded, redacted, or hashed in memory on the request path.
     */
    'body_capture_max_bytes' => env('HTTP_LOGS_BODY_CAPTURE_MAX_BYTES', 1048576),

    /*
     * How to store bodies that cannot be key-redacted because they do not
     * decode to a key/value structure (XML/SOAP, plain text, scalar JSON):
     *
     * metadata = store headers plus a sha256 hash of the raw body (default);
     * preview  = store the raw body untouched. Every value in it — including
     *            any secret — is kept in clear text, so opt in only for
     *            providers whose non-JSON payloads are known to be safe.
     */
    'undecodable_body_mode' => env('HTTP_LOGS_UNDECODABLE_BODY_MODE', 'metadata'),

    /*
       * preview = store sanitized body;
       * metadata = drop body, keep only status/host/path
    */
    'payment_body_mode'  => env('HTTP_LOGS_PAYMENT_BODY_MODE', 'preview'),

    // Null derives aliases from log_elasticsearch.index_prefix. Set a string
    // only when this subsystem intentionally needs custom aliases.
    'index_alias'       => null,
    'index_alias_write' => null,

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
        'provider'            => null,
        'event_type'          => null,
        'entity_type'         => null,
        'entity_type_default' => 'none',
    ],

    /*
     * String, integer, or backed-enum provider values that should use PaymentRedactor.
     * Example: [Provider::Tbc, Provider::Bog->value, 'credo']
     */
    'payment_provider_values' => [],

    /*
     * Redaction overrides applied on top of the package's built-in rules, kept
     * separate for request/response headers and JSON body keys. Names are
     * matched case-insensitively after normalization (camelCase and kebab-case
     * fold to snake_case, so 'accessToken' == 'access-token' == 'access_token').
     *
     * 'block'  Extra names to ALWAYS redact, in addition to the defaults.
     *          Matched as whole words in any position, exactly like the
     *          built-ins — e.g. 'customer_reference' here also redacts
     *          'customerReference', and the word 'reference' redacts any
     *          '*_reference' key.
     * 'allow'  Names to NEVER redact, even when a built-in (or 'block') rule
     *          would match. Matched exactly (not as a word), so it un-redacts
     *          only the named field, not a whole family. Takes precedence over
     *          everything else. Use with care: anything listed here is stored
     *          in clear text. Example: body ['email'] to keep emails in logs.
     */
    'redaction' => [
        'headers' => [
            'block' => [],
            'allow' => [],
        ],
        'body' => [
            'block' => [],
            'allow' => [],
        ],
    ],
];
