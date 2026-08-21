## Elastic Audit

Elastic Audit records third-party HTTP traffic and actor/model activity in a dedicated Elasticsearch cluster. HTTP
logs and activity logs are independent subsystems with separate configuration, queues, indexes, and dashboards.

- Inspect `config/app.php`, `config/http_logs.php`, `config/activity_logs.php`, `config/elastic_audit_metrics.php`, and
  `config/log_elasticsearch.php` before changing an integration. Never edit the package files under `vendor/`.
- Use `HttpLog::make(...)` instead of Laravel's `Http` facade when an outgoing provider request must be audited. It
  returns an `Illuminate\Http\Client\PendingRequest`, so fluent setup and single-request verbs remain available. Do not
  use Laravel `pool()` / `batch()` for audited calls because they create separate requests without this middleware.
- Pass existing backed enum cases implementing `ProviderContract`, `EventTypeContract`, and `EntityTypeContract` to
  HTTP logging APIs. Inspect the consuming application's registered enum classes and never invent enum cases.
- For incoming callbacks, use `IncomingHttpLogMiddleware` and set `third_party_*` request attributes from trusted
  application code. Never derive provider, event, or entity types from user-controlled request input.
- Use `ActivityLog::record(...)` for explicit domain events and `ActivityLoggable` for automatic Eloquent lifecycle
  events. Activity entity and actor types are free string labels.
- Give every application writing to shared aliases a stable, unique `APP_NAME`. Indexed `service.*` and `execution.*`
  fields are snapshotted before queue dispatch.
- Raw SQL/query-builder writes bypass `ActivityLoggable`; call `ActivityLog::record(...)` explicitly with domain
  changes. Do not use `DB::listen()` as a substitute for an activity audit trail.
- Logging dispatches queued jobs. Keep the configured queue worker running and use `Bus::fake()` when asserting job
  dispatch in tests; unit tests should not require a live Elasticsearch cluster.
- Optional performance monitoring is disabled by default. When enabled it records transaction/span documents for HTTP,
  SQL, queue publish/run, commands, scheduled tasks, outbound HTTP, Redis, cache, mail, and notifications. Sampled PHP
  profiles require Excimer (recommended) or modern XHProf and use a separate profiles index. Preserve W3C HTTP/queue
  propagation and execution-local trace state; keep both queues running and tune sampling/thresholds.
- Never add SQL bindings, HTTP bodies/headers/query strings, URL credentials, audit identifiers, or errors to metrics.
  Normalize inline SQL literals and dynamic paths, and do not instrument Elastic Audit's own internals.
- Time application code with `Performance::measure('bounded.label', fn () => ...)`. It records an `app.function` span
  in the current transaction, nests, and passes the return value and exceptions through. Names are indexed as
  `keyword`, so never build one from user input or a record id. Profiles are sampled and answer a different question.
- Elastic Audit's delivery jobs, dashboards, and asset route are excluded automatically; never re-add them. Exclude
  application noise with `capture.http.exclude_paths`, `capture.jobs.exclude`, `capture.commands.exclude`, and
  `capture.outgoing_http.exclude_hosts`. Each suppresses the whole unit of work, so an excluded request or job stops
  producing child spans too. Patterns are `fnmatch()` with `FNM_NOESCAPE`; paths carry no leading slash.
- Complete capture or terminal indexing failures emit a sanitized `AuditOperationFailed` Laravel event. It contains no
  raw exception, headers, payloads, changes, or arbitrary metadata.
- Review redaction before capturing new headers, fields, or metadata. Treat every `redaction.allow` entry as a security
  exception because allowed values are stored in clear text.
- Undecodable bodies default to headers plus a raw-body hash. `HTTP_LOGS_UNDECODABLE_BODY_MODE=preview` stores them in
  clear text and needs explicit security review. Bodies over the 1 MB default capture cap are headers-only.
- Successful incoming callbacks are queued in terminable middleware after the response is sent. Activity jobs dispatch
  after database commit, so rolled-back changes intentionally produce no activity document.
- After infrastructure or configuration changes, run `php artisan elastic-audit:health`; add `--json` for deployment
  automation. Use `--all` only when aliases for disabled subsystems have also been provisioned and should be checked.
  Install the lifecycle policy before creating HTTP, activity, or enabled metrics indexes on a fresh environment.
- Finite `retentionDays` values must be `1`–`32767`; use `retainForever: true` for a permanent event and never pass both.
  Permanent documents are ignored by prune commands, but permanent storage also requires
  `log_elasticsearch.lifecycle.delete_enabled=false` because ILM deletes whole indexes independently.

@boostsnippet('Outgoing audited request', 'php')
$response = HttpLog::make(
    provider: Provider::Delivery,
    eventType: EventType::DeliveryOrderCreate,
    context: HttpLogContext::forEntity(
        entityType: EntityType::Order,
        entityId: (string) $order->getKey(),
    ),
)->post($url, $payload);
@endboostsnippet
