# Elastic Audit — Agent Guide

Guidance for AI coding agents integrating `tsitsishvili/elastic-audit` into a **consuming Laravel application**.
This file ships inside the Composer distribution, so it is readable at
`vendor/tsitsishvili/elastic-audit/AGENTS.md` without any extra tooling.

If the application uses [Laravel Boost](https://laravel.com/docs/boost), the same guidance is delivered automatically —
see [With Laravel Boost](README.md#with-laravel-boost). This file is the equivalent for applications that do not use
Boost.

## What this package does

Elastic Audit records third-party HTTP traffic and actor/model activity in a dedicated Elasticsearch cluster. It has two
**independent** subsystems that share one Elasticsearch connection:

| Subsystem        | Entry points                                    | Config                     |
| ---------------- | ----------------------------------------------- | -------------------------- |
| Audit/HTTP logs  | `HttpLog` facade, HTTP middleware               | `config/http_logs.php`     |
| Activity logs    | `ActivityLog` facade, `ActivityLoggable` trait  | `config/activity_logs.php` |

Both read the shared connection from `config/log_elasticsearch.php`. Enable only the subsystem the task needs.

## Rules

- Read `config/http_logs.php`, `config/activity_logs.php`, and `config/log_elasticsearch.php` before changing an
  integration. **Never edit files under `vendor/`.**
- Use `HttpLog::make(...)` instead of Laravel's `Http` facade when an outgoing provider request must be audited. It
  returns an `Illuminate\Http\Client\PendingRequest`, so the normal Laravel HTTP client API stays available.
- Pass real backed enum cases implementing `ProviderContract`, `EventTypeContract`, and `EntityTypeContract` to the HTTP
  logging APIs. Inspect the classes registered under `http_logs.enums` and **never invent enum cases**.
- For incoming callbacks, use `IncomingHttpLogMiddleware` and set the `third_party_*` request attributes from trusted
  server-side code. **Never derive provider, event, or entity types from user-controlled request input** — doing so lets
  a caller spoof audit metadata.
- Use `ActivityLog::record(...)` for explicit domain events and `ActivityLoggable` for automatic Eloquent lifecycle
  events. Activity actor and entity types are free-form strings; if the app models them as enums, pass `->value`.
- HTTP `userId` and activity `actorId` accept integers, strings, UUIDs, or null. The package indexes non-null ids as
  keyword strings; preserve the application's real identifier instead of coercing UUIDs or string ids to integers.
- Logging dispatches queued jobs. Keep a worker running for the configured queues, and use `Bus::fake()` when asserting
  dispatch in tests — unit tests must not require a live Elasticsearch cluster.
- Review redaction before capturing new headers, fields, or metadata. Treat every `redaction.allow` entry as a security
  exception, because allowed values are stored in clear text.
- Default document retention comes from each subsystem's `retention_days` (both 360) / `retain_forever` config. Pass
  `retentionDays` for a finite override or `retainForever: true` for a permanent individual event; never pass both.
  Permanent documents have a null `retention_days` and are ignored by prune commands. ILM independently deletes whole
  indexes, so permanent storage also requires `log_elasticsearch.lifecycle.delete_enabled=false` and an updated policy.
  Finite values must be `1`–`32767`.
- After infrastructure or config changes, run `php artisan elastic-audit:health --all`. Install the lifecycle policy
  before creating indexes on a fresh environment.

## Log an outgoing provider request

```php
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogContext;
use Tsitsishvili\ElasticAudit\Facades\HttpLog;

$response = HttpLog::make(
    provider: Provider::Delivery,
    eventType: EventType::DeliveryOrderCreate,
    context: HttpLogContext::forEntity(
        entityType: EntityType::Order,
        entityId: (string) $order->getKey(),
        externalId: $order->provider_id,
        userId: auth()->id(),
    ),
)
    ->timeout(10)
    ->withToken(config('services.delivery.token'))
    ->post($url, $payload);
```

Keep the provider call's existing exception and response handling. Logging queues a sanitized event and preserves the
original request behavior.

## Log an incoming callback

```php
use Tsitsishvili\ElasticAudit\Http\Middleware\IncomingHttpLogMiddleware;

Route::post('/callbacks/delivery', DeliveryCallbackController::class)
    ->middleware(IncomingHttpLogMiddleware::class);

// Inside trusted application code — never from route params, query strings, headers, or the body:
$request->attributes->set('third_party_provider', Provider::Delivery->value);
$request->attributes->set('third_party_event_type', EventType::DeliveryStatusCallback->value);
$request->attributes->set('third_party_entity_type', EntityType::Order->value);
$request->attributes->set('third_party_entity_id', (string) $order->getKey());
$request->attributes->set('third_party_user_id', auth()->id());
```

The middleware skips capture when registered enum classes or matching values cannot be resolved. Use
`HttpLog::logIncoming()` only when middleware cannot represent the flow, and pass the real response and exception so
status and failure data stay accurate.

## Record activity

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
    changes: ['status' => ['old' => $oldStatus, 'new' => $order->status]],
    metadata: ['source' => 'checkout'],
);
```

Apply `ActivityLoggable` to an Eloquent model only when automatic `created`, `updated`, `deleted`, `restored`, and
`force_deleted` events are wanted. Use `$activityLogOnly` / `$activityLogExcept` to avoid noisy or sensitive attribute
diffs, and override `activityActor()`, `activityEntityId()`, or `activityMetadata()` only when the defaults do not fit
the domain.

## Set up a fresh environment

```bash
php artisan vendor:publish --tag=elastic-audit   # config + enum stubs
php artisan elastic-audit:lifecycle-policy       # install first
php artisan http-logs:create-index               # only if HTTP logs are enabled
php artisan activity-logs:create-index           # only if activity logs are enabled
php artisan elastic-audit:health --all
```

Capture dispatches jobs rather than indexing synchronously, so keep a worker running for the `HTTP_LOGS_QUEUE` and
`ACTIVITY_LOGS_QUEUE` queues. Configure dashboard authorization with `Dashboard::auth(...)` before exposing either
dashboard outside `local`.

## Verify changes

- Assert that disabled configurations are no-ops.
- Use `Bus::fake()` and assert `LogHttpRequestJob` / `LogActivityJob` dispatch instead of requiring Elasticsearch.
- Use Laravel HTTP fakes for provider responses while exercising the audited `PendingRequest`.
- Test callback attribute mapping, especially invalid or absent enum values.
- Test new redaction rules with representative camelCase, kebab-case, and snake_case keys.
- Run `php artisan elastic-audit:health --all` only where the logs cluster is reachable.

## Deeper reference

- [Audit Logs Guide](AUDIT_LOGS.md) — configuration reference, redaction, sampling, dashboards, troubleshooting.
- [Activity Logs Guide](ACTIVITY_LOGS.md) — activity config, manual logging, the `ActivityLoggable` trait, dashboard.
- [README](README.md) — quick start and requirements.
