---
name: elastic-audit-development
description: Integrate and maintain Tsitsishvili Elastic Audit in Laravel applications, including outgoing and incoming HTTP logs, activity logging, redaction, Elasticsearch setup, queues, dashboards, tests, and troubleshooting. Use when adding or changing Elastic Audit configuration, provider clients, callback auditing, domain audit events, automatic model activity capture, or operational commands.
---

# Elastic Audit Development

Use the package's public APIs and published application configuration to add audit capture without changing the
surrounding request behavior or exposing sensitive data.

## Inspect before editing

1. Confirm `tsitsishvili/elastic-audit` is installed and inspect its version.
2. Read the consuming application's `config/app.php`, `config/http_logs.php`, `config/activity_logs.php`,
   `config/elastic_audit_metrics.php`, and `config/log_elasticsearch.php`. If package config has not been published and
   the user wants a full setup, run:

   ```bash
   php artisan vendor:publish --tag=elastic-audit
   ```

3. Inspect the classes registered under `http_logs.enums`. Use only real backed enum cases implementing the package
   contracts; never guess provider, event-type, or entity-type cases.
4. Decide whether the task concerns third-party HTTP traffic, actor/model activity, or both. Do not enable or configure
   an unrelated subsystem.
5. When applications share aliases, confirm each one has a stable, unique `APP_NAME`. Source identity and execution
   origin are indexed and snapshotted before queue dispatch.
6. Read `vendor/tsitsishvili/elastic-audit/AUDIT_LOGS.md` or `ACTIVITY_LOGS.md` when the task needs details beyond this
   workflow. Do not edit files under `vendor/`.

## Log outgoing provider requests

Build an `HttpLogContext`, then use `HttpLog::make()` in place of `Http::`. The result is Laravel's normal
`PendingRequest`, so apply timeouts, retries, authentication, headers, and request methods as usual.

Use single-request verbs on that returned instance. Laravel `pool()` and `batch()` create separate pending requests and
do not inherit the package middleware. Request hooks that mutate the outgoing request after capture can also make the
stored snapshot differ from the bytes ultimately sent.

```php
use App\Enums\ElasticAudit\EntityType;
use App\Enums\ElasticAudit\EventType;
use App\Enums\ElasticAudit\Provider;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogContext;
use Tsitsishvili\ElasticAudit\Facades\HttpLog;

$context = HttpLogContext::forEntity(
    entityType: EntityType::Order,
    entityId: (string) $order->getKey(),
    externalId: $order->provider_id,
    userId: auth()->id(),
);

$response = HttpLog::make(
    provider: Provider::Delivery,
    eventType: EventType::DeliveryOrderCreate,
    context: $context,
)
    ->timeout(10)
    ->withToken(config('services.delivery.token'))
    ->post($url, $payload);
```

`userId` accepts `int|string|null`, including UUIDs. Keep the application's real identifier type; Elastic Audit
normalizes every non-null id to a keyword string only when building the Elasticsearch document.

Keep the provider call's existing exception and response handling. Logging queues a sanitized event and preserves the
original request behavior.

## Log incoming callbacks

Attach `IncomingHttpLogMiddleware` to callback routes. Set its request attributes from trusted server-side mappings
before returning the response:

```php
use Tsitsishvili\ElasticAudit\Http\Middleware\IncomingHttpLogMiddleware;

Route::post('/callbacks/delivery', DeliveryCallbackController::class)
    ->middleware(IncomingHttpLogMiddleware::class);

$request->attributes->set('third_party_provider', Provider::Delivery->value);
$request->attributes->set('third_party_event_type', EventType::DeliveryStatusCallback->value);
$request->attributes->set('third_party_entity_type', EntityType::Order->value);
$request->attributes->set('third_party_entity_id', (string) $order->getKey());
$request->attributes->set('third_party_user_id', auth()->id());
```

Never take `third_party_provider`, `third_party_event_type`, or `third_party_entity_type` from route parameters, query
strings, headers, or request bodies. User-controlled values could spoof audit metadata. The middleware skips capture
when registered enum classes or matching values cannot be resolved.

Successful callbacks are captured in the middleware's `terminate()` phase after the response is sent. Exception paths
are captured inline so their details are not lost. Direct unit tests of the middleware must call `terminate()` after a
successful `handle()` call; tests through Laravel's HTTP kernel execute the terminable phase normally.

Use `HttpLog::logIncoming()` only when middleware cannot represent the flow. Pass the actual response and exception
details so status and failure data remain accurate.

## Record activity

Use explicit events for domain actions whose meaning is richer than an Eloquent lifecycle event:

```php
use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogContext;
use Tsitsishvili\ElasticAudit\Facades\ActivityLog;

ActivityLog::record(
    action: 'order.status_updated',
    context: ActivityLogContext::forActor(
        actorType: 'user',
        actorId: auth()->id(),
        entityType: 'order',
        entityId: (string) $order->getKey(),
    ),
    changes: [
        'status' => ['old' => $oldStatus, 'new' => $order->status],
    ],
    metadata: ['source' => 'checkout'],
);
```

Use `ActivityLoggable` on an Eloquent model only when automatic `created`, `updated`, `deleted`, `restored`, and
`force_deleted` events are desired. Configure `$activityLogOnly` or `$activityLogExcept` to avoid noisy or sensitive
attribute diffs. Override `activityActor()`, `activityEntityId()`, or `activityMetadata()` only when the defaults do not
represent the application's domain.

Activity `actorType` and `entityType` values are strings. If the application uses an enum for them, pass `->value`.
`actorId` accepts `int|string|null`, including UUIDs, and is indexed as a keyword string. Activity jobs dispatch only
after the surrounding database transaction commits; a rollback intentionally discards the queued audit event.

`ActivityLoggable` does not observe raw SQL or query-builder updates. For those writes, call `ActivityLog::record()`
explicitly with meaningful old/new values, preferably inside the same transaction. Do not add `DB::listen()` as an
audit substitute: SQL text lacks trustworthy actor, intent, and before/after state. The package automatically records
the active route/controller, queue job, or Artisan command; pass `ExecutionOrigin::manual(...)` on the context only
when the domain needs a more specific origin.

## Protect sensitive data

- Review the fields, headers, body data, changes, and metadata introduced by the change.
- Add application-specific secret names to the relevant `redaction.block` list.
- Avoid `redaction.allow` unless clear-text storage is an explicit, reviewed requirement. Allow entries override both
  built-in and configured blocking.
- Register payment provider enum values in `http_logs.payment_provider_values` so payment-specific body handling is
  applied.
- Undecodable bodies default to headers plus a raw-body hash. Treat
  `HTTP_LOGS_UNDECODABLE_BODY_MODE=preview` like an allow-list exception because it stores the raw body in clear text.
- Bodies larger than `body_capture_max_bytes` (1 MB by default) are headers-only. URL userinfo/query/fragment data is
  omitted, but payload previews and allowed fields can still contain personal or secret data.

## Configure operations

For a fresh environment, apply infrastructure in this order:

```bash
php artisan elastic-audit:lifecycle-policy
php artisan http-logs:create-index       # when HTTP logs are enabled
php artisan activity-logs:create-index   # when activity logs are enabled
php artisan elastic-audit:metrics:create-index  # when metrics are enabled
php artisan elastic-audit:profiles:create-index # when profiles are enabled
php artisan elastic-audit:health
```

The plain health command validates enabled subsystems. Add `--json` for deployment automation. Use `--all` only when
aliases for disabled subsystems have also been provisioned and should be checked. Keep a worker running for
`HTTP_LOGS_QUEUE`, `ACTIVITY_LOGS_QUEUE`, `ELASTIC_AUDIT_METRICS_QUEUE`, and `ELASTIC_AUDIT_PROFILES_QUEUE` when the
respective subsystems are enabled; capture dispatches jobs rather than indexing synchronously. Performance monitoring
uses explicit transaction/span documents for HTTP, queries, queue publish/run, commands, scheduled tasks, outbound
HTTP, Redis, cache, mail, and notifications. Profiles prefer Excimer, optionally use modern XHProf, and live in a
separate index. Preserve W3C HTTP/queue propagation and execution-local trace state.
Time application code with `Performance::measure('bounded.label', fn () => ...)`, which records a nested
`app.function` span and passes the callback's return value and exceptions through unchanged; span names are indexed as
`keyword`, so never derive one from user input or a record id.
Keep SQL bindings, HTTP bodies/headers/query strings, URL credentials, audit identifiers, and errors out of metrics;
never instrument Elastic Audit's own capture/indexing internals. Its delivery jobs, dashboards, and asset route are
excluded automatically. Exclude application noise with `capture.http.exclude_paths`, `capture.jobs.exclude`,
`capture.commands.exclude`, and `capture.outgoing_http.exclude_hosts`; each suppresses the whole unit of work, so an
excluded request or job produces no child spans either. Patterns use `fnmatch()` with `FNM_NOESCAPE`, and paths carry
no leading slash. Default audit document retention comes from each audit
subsystem's `retention_days` / `retain_forever` config. Pass `retentionDays`
for a finite override or
`retainForever: true` for a permanent event; never pass both. Permanent documents are ignored by prune commands, but
permanent storage also requires `log_elasticsearch.lifecycle.delete_enabled=false` because ILM deletes whole indexes
independently. Finite values must be `1`–`32767`. Configure dashboard authorization with `Dashboard::auth(...)` before
exposing either dashboard outside `local`.

## Verify changes

- Test disabled configurations as no-ops.
- Use `Bus::fake()` and assert `LogHttpRequestJob` or `LogActivityJob` dispatch instead of requiring Elasticsearch.
- Complete capture or terminal indexing failures emit a sanitized `AuditOperationFailed` event; test monitoring
  listeners without expecting raw exceptions or payload data on that event.
- Use Laravel HTTP fakes for provider responses while exercising the audited `PendingRequest`.
- Test callback attribute mapping, especially invalid or absent enum values, and exercise the terminable success path.
- Test new redaction rules with representative camelCase, kebab-case, and snake_case keys.
- Run the focused tests first, then the full application suite.
- Run `php artisan elastic-audit:health` in an environment that is allowed to reach the logs cluster. Add `--json` for
  machine-readable deploy checks and `--all` only when provisioned aliases for disabled subsystems should also be
  checked.
