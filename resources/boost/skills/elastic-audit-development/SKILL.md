---
name: elastic-audit-development
description: Integrate and maintain Tsitsishvili Elastic Audit in Laravel applications, including outgoing and incoming HTTP logs, activity logging, redaction, Elasticsearch setup, queues, dashboards, tests, and troubleshooting. Use when adding or changing Elastic Audit configuration, provider clients, callback auditing, domain audit events, automatic model activity capture, or operational commands.
---

# Elastic Audit Development

Use the package's public APIs and published application configuration to add audit capture without changing the
surrounding request behavior or exposing sensitive data.

## Inspect before editing

1. Confirm `tsitsishvili/elastic-audit` is installed and inspect its version.
2. Read the consuming application's `config/http_logs.php`, `config/activity_logs.php`, and
   `config/log_elasticsearch.php`. If config has not been published and the user wants a full setup, run:

   ```bash
   php artisan vendor:publish --tag=elastic-audit
   ```

3. Inspect the classes registered under `http_logs.enums`. Use only real backed enum cases implementing the package
   contracts; never guess provider, event-type, or entity-type cases.
4. Decide whether the task concerns third-party HTTP traffic, actor/model activity, or both. Do not enable or configure
   an unrelated subsystem.
5. Read `vendor/tsitsishvili/elastic-audit/AUDIT_LOGS.md` or `ACTIVITY_LOGS.md` when the task needs details beyond this
   workflow. Do not edit files under `vendor/`.

## Log outgoing provider requests

Build an `HttpLogContext`, then use `HttpLog::make()` in place of `Http::`. The result is Laravel's normal
`PendingRequest`, so apply timeouts, retries, authentication, headers, and request methods as usual.

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
```

Never take `third_party_provider`, `third_party_event_type`, or `third_party_entity_type` from route parameters, query
strings, headers, or request bodies. User-controlled values could spoof audit metadata. The middleware skips capture
when registered enum classes or matching values cannot be resolved.

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

## Protect sensitive data

- Review the fields, headers, body data, changes, and metadata introduced by the change.
- Add application-specific secret names to the relevant `redaction.block` list.
- Avoid `redaction.allow` unless clear-text storage is an explicit, reviewed requirement. Allow entries override both
  built-in and configured blocking.
- Register payment provider enum values in `http_logs.payment_provider_values` so payment-specific body handling is
  applied.
- Remember that URL query strings are omitted, but payload previews and allowed fields can still contain personal or
  secret data.

## Configure operations

For a fresh environment, apply infrastructure in this order:

```bash
php artisan elastic-audit:lifecycle-policy
php artisan http-logs:create-index
php artisan activity-logs:create-index
php artisan elastic-audit:health --all
```

Run only the index command for enabled subsystems. Keep a worker running for `HTTP_LOGS_QUEUE` and
`ACTIVITY_LOGS_QUEUE`; capture dispatches jobs rather than indexing synchronously. Configure dashboard authorization
with `Dashboard::auth(...)` before exposing either dashboard outside `local`.

## Verify changes

- Test disabled configurations as no-ops.
- Use `Bus::fake()` and assert `LogHttpRequestJob` or `LogActivityJob` dispatch instead of requiring Elasticsearch.
- Use Laravel HTTP fakes for provider responses while exercising the audited `PendingRequest`.
- Test callback attribute mapping, especially invalid or absent enum values.
- Test new redaction rules with representative camelCase, kebab-case, and snake_case keys.
- Run the focused tests first, then the full application suite.
- Run `php artisan elastic-audit:health --all` in an environment that is allowed to reach the logs cluster.
