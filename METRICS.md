# Application Performance Monitoring

Elastic Audit can capture how a Laravel application executes using a Sentry-style transaction, span, and profile model.
It is independent from the audit loggers, disabled by default, and writes to two dedicated Elasticsearch index families:

- `<index_prefix>_metrics` contains searchable transactions and spans;
- `<index_prefix>_profiles` contains sampled PHP call stacks linked to their transaction.

The package observes inbound HTTP requests, completed SQL queries, queue publishing and execution, Artisan commands,
scheduled tasks, outbound Laravel HTTP calls, Redis commands, cache operations, mail sends, and notification sends.
It suppresses its own queue delivery and Elasticsearch indexing so the telemetry describes the consuming application.

## Enable and provision

Install Excimer in web and worker runtimes for production profiling. Profiling is optional; transaction/span collection
continues without it.

```bash
php artisan vendor:publish --tag=elastic-audit
php artisan elastic-audit:lifecycle-policy
php artisan elastic-audit:metrics:create-index
php artisan elastic-audit:profiles:create-index
php artisan elastic-audit:health
php artisan queue:work --queue=elastic-audit-metrics
```

```dotenv
ELASTIC_AUDIT_METRICS_ENABLED=true
ELASTIC_AUDIT_METRICS_QUEUE=elastic-audit-metrics
ELASTIC_AUDIT_METRICS_RETENTION_DAYS=30

ELASTIC_AUDIT_PROFILES_ENABLED=true
ELASTIC_AUDIT_PROFILES_DRIVER=auto
ELASTIC_AUDIT_PROFILES_SAMPLE_RATE=0.01
ELASTIC_AUDIT_PROFILES_QUEUE=elastic-audit-metrics
ELASTIC_AUDIT_PROFILES_RETENTION_DAYS=7
```

Both index families use the Elasticsearch connection in `config/log_elasticsearch.php`. Run the metrics create-index
command again after upgrading from metrics schema v2; it updates the template and rolls over the strict write mapping.

## Transaction and span model

Root operations are `kind=transaction` documents. Work performed inside a root is stored as individual `kind=span`
documents. Every document has `trace.id`, `trace.span_id`, `trace.parent_span_id`, `transaction.id`, `type`, `name`,
`outcome`, `duration_ms`, `service.*`, `execution.*`, and `retention_days`.

| Operation | Type | Kind |
| --- | --- | --- |
| Inbound route | `http.server` | transaction |
| Measured application code | `app.function` | span |
| Queue job execution | `queue.job` | transaction |
| Artisan command | `console.command` | transaction |
| Scheduled task | `scheduled.task` | transaction |
| Completed SQL | `db.query` | span |
| Outbound Laravel HTTP | `http.client` | span |
| Queue publication | `queue.publish` | span |
| Redis command | `redis.command` | span |
| Cache operation | `cache.operation` | span |
| Mail send | `mail.send` | span |
| Notification send | `notification.send` | span |

Transactions record `transaction.span_count` and, when profiling succeeded, `transaction.profile_id`. Each category
has an independent `enabled`, `sample_rate`, and `min_duration_ms` configuration. Sampling a root off keeps only the
minimal in-memory context required to propagate its W3C sampled flag; it does not enqueue its transactions or spans.

## Timing your own code

Profiles answer "what was hot inside this one sampled request". They cannot
answer "how long does this operation take across every request", because they
are sampled and their frames are summarised per profile. `Performance::measure()`
fills that gap: it records every call as an `app.function` span, nested in the
surrounding transaction and aggregated by name on the dashboard.

```php
use Tsitsishvili\ElasticAudit\Facades\Performance;

$totals = Performance::measure(
    'checkout.totals',
    fn (): Totals => $this->calculator->totals($cart),
);
```

The callback's return value passes through and its exceptions are rethrown
unchanged, so wrapping a call cannot alter behaviour; a throwing block is
recorded with `outcome=failure`. Calls nest, so an inner block is a span of the
outer one, and any query or outbound call made inside is attributed to the block
that made it. When the start and end genuinely cannot share a scope, use
`beginMeasure()`/`endMeasure()` instead.

Blocks measured outside any transaction are still recorded, with their own trace.
Timing is governed by `capture.functions`, so it can be sampled, thresholded, or
turned off like any other category. Names become an Elasticsearch `keyword`:
use a bounded label such as `checkout.totals`, never one built from user input or
a record id.

## Distributed trace context

Inbound `traceparent` and `tracestate` headers are validated and continued. The package automatically injects a child
`traceparent` into requests made with Laravel's HTTP client and adds trace context to Laravel queue payloads. A worker
continues the producer trace while creating a new queue-job transaction. Disable these independently with
`trace.propagate_http` and `trace.propagate_queue`; `trace.honor_incoming_sampled` controls whether an upstream sampled
flag of `00` suppresses local capture.

Trace stacks and suppression guards are isolated by Swoole coroutine or PHP Fiber identity, so interleaved Octane and
concurrent execution cannot merge request data into another trace. Native profiler drivers remain process-global and
refuse overlapping runs.

## PHP profiles: Excimer and XHProf

`profiles.driver=auto` prefers Excimer and falls back to modern PECL XHProf:

- Excimer is the recommended continuous-production driver. It samples wall-clock stacks at `period_ms` (10.1 ms by
  default), produces a Speedscope-compatible payload, and avoids intercepting every PHP function call.
- XHProf is an optional instrumentation driver for deeper, deliberately sampled investigations. It stores aggregated
  caller/callee edges and can include CPU and memory counters. The archived Tideways XHProf fork is not supported.

Profile sampling is evaluated after transaction sampling and defaults to 1%. `max_depth`, `max_samples`, and
`max_payload_bytes` bound collection. Source paths are omitted by default; enabling `include_paths` stores paths
relative to the application root when possible and basenames otherwise. Raw profile payloads are retrievable but are
not indexed by Elasticsearch. The dashboard uses the separately indexed `hot_frames` summary.

Profile documents have independent aliases, queue, retry options, seven-day default retention, and these commands:

```bash
php artisan elastic-audit:profiles:create-index
php artisan elastic-audit:profiles:prune
php artisan elastic-audit:profiles:rollover
```

## What is never observed

The package excludes its own work unconditionally, because measuring the
observability pipeline describes the pipeline rather than the application — and
on the `database` queue and cache drivers a job that records its own delivery
makes the metrics queue feed itself:

- its audit and telemetry delivery jobs (`LogHttpRequestJob`, `LogActivityJob`,
  `LogMetricBatchJob`, `LogProfileJob`, and their batch variants), including the
  queries they run and their publish spans;
- every enabled dashboard and the dashboard asset route, under whatever prefix
  the application mounted them on;
- the bookkeeping a long-running daemon performs between units of work. While a
  command matched by `capture.commands.exclude` owns the process, work that
  belongs to no transaction is dropped. Jobs and scheduled tasks running inside
  that daemon still produce their own root transactions.

`capture.commands.exclude` ships with two groups of Laravel commands whose
duration measures something other than application work: processes that run for
their whole lifetime (`queue:work`, `queue:listen`, `schedule:work`, `horizon`,
`octane:start`, `reverb:start`, `pulse:work`, `serve`, `pail`) and development
entrypoints (`tinker`, `test`, `dusk`). `php artisan test` would otherwise record
an entire suite run as one transaction and dominate the latency percentiles.

Applications exclude their own noise on top of that:

```php
'capture' => [
    // Suppressed entirely: an excluded request records no transaction and no
    // spans for the queries and outgoing calls it makes.
    'http' => ['exclude_paths' => ['up', 'health/*']],

    // Suppressed for the whole run, including the job's own queries and its
    // publish span.
    'jobs' => ['exclude' => ['App\\Jobs\\NoisyBroadcast*']],

    'outgoing_http' => ['exclude_hosts' => ['metrics.internal.example']],
    'commands'      => ['exclude' => ['queue:work', 'schedule:work']],
],
```

Paths are matched without a leading slash. All four lists are `fnmatch()`
patterns evaluated with `FNM_NOESCAPE`, so a namespaced class name is written
with ordinary backslashes.

## Privacy and cardinality

- SQL bindings are never stored. Inline string, numeric, hex, binary, and PostgreSQL dollar-quoted literals are
  normalized before the optional statement is indexed. The fingerprint is computed before statement truncation.
- HTTP bodies, headers, query strings, URL credentials, exception messages, audit identifiers, mail subjects,
  recipients, notification payloads, cache keys, Redis arguments, and scheduled command text are not captured.
- Resolved route templates/names are captured; unmatched requests use the constant `unmatched` label.
- Outbound paths normalize common dynamic identifiers. Disable `capture.outgoing_http.include_path` when static path
  segments can still contain sensitive application data.
- Scheduled task descriptions/commands are hashed by default. `scheduled_tasks.include_description=true` is an
  explicit data-storage decision and should be security-reviewed.
- Laravel only emits `QueryExecuted` for completed queries. Failed SQL is therefore not observed by this integration.
- Only Laravel's HTTP client is automatically instrumented; arbitrary Guzzle/cURL traffic is outside this package.

## Dashboard

When `dashboard.enabled=true`, the performance UI is served at `/logger/metrics` by default. It provides throughput,
average and percentile latency, failure rate, type/slow-transaction groups, a transaction list, trace waterfall, and
linked profile hot frames/raw payload. It uses the same `Dashboard::auth(...)` authorization callback as the audit
dashboards and is local-only until the application overrides that callback.

## Operations and testing

```bash
php artisan elastic-audit:metrics:prune
php artisan elastic-audit:metrics:rollover
php artisan elastic-audit:profiles:prune
php artisan elastic-audit:profiles:rollover
php artisan elastic-audit:health
```

Use `Bus::fake()` in application tests and assert `LogMetricBatchJob` for transactions/spans or `LogProfileJob` for a
sampled profile. Delivery is best-effort and cannot change application behavior. Terminal delivery failures emit the
sanitized `AuditOperationFailed` event with subsystem `metrics`. External monitoring is still necessary if visibility
must survive loss of the shared Elasticsearch cluster.
