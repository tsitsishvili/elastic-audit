# Trace Explorer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Clicking or searching a trace ID (or a request ID) shows everything that happened in that trace on one merged
timeline: spans, HTTP logs, activity events, and queued jobs.

**Architecture:** Audit documents start recording the active metrics trace and millisecond timestamps at write time.
At read time the existing trace page queries the metrics, HTTP log, and activity log aliases independently, gates the
audit sources through a new `DashboardAccess` check, and hands the documents to a pure `TraceTimeline` builder that the
Blade view renders. Trace IDs become links via one partial, and a sidebar search box resolves IDs through a new
`TraceSearchController`.

**Tech Stack:** PHP 8.2+, Laravel 12/13, Orchestra Testbench + PHPUnit, Blade, Tailwind v4, Alpine.

**Spec:** `docs/superpowers/specs/2026-10-26-trace-explorer-design.md`

## Global Constraints

- PHP 8.2+, Laravel 12 and 13, the existing Tailwind v4 and Alpine dashboard stack. No new dependencies.
- Follow `CODING_STANDARDS.md`; `composer format` (pint) and `composer analyse` (phpstan) must pass.
- Tests must not need a live Elasticsearch cluster: use `Tests\Fixtures\FakeLogElasticsearchClient`
  (`$fake->searchResolver` routes by `$params['index']`, which contains `http_logs`, `activity_logs`, `metrics`, or
  `profiles`; `$fake->searchCalls` records every search).
- Built assets in `public/vendor/elastic-audit` are committed: run `npm run build` in any task that adds Tailwind classes.
- Run `graphify update .` after each task's code change.
- Metrics stay free of audit data: the join happens only at read time.
- Reading the active trace must never throw or alter application behaviour.
- No `SCHEMA_VERSION` bump: no mapping changes (`@timestamp` stays `date`; `trace.*` fields already exist).
- Branch: work on `trace-explorer` cut from `v5.x`. The working tree currently also holds uncommitted sidebar-toggle and
  "Browse transactions" changes and the spec; the owner decides whether those are committed to `v5.x` first.

## Review Focus

- A request that writes hundreds of audit rows (bulk import): the page caps each audit source at 200 rows and says so
  instead of timing out. Pinned in Task 5.
- An audit row stamped slightly before the first span (clock skew, or a job on another worker): it is still shown, in
  time order, with a negative offset, never dropped or clamped. Pinned in Task 4.
- A pasted ID with spaces, upper-case hex, or a whole `traceparent` header: search normalises it to the trace ID.
  Pinned in Task 7.
- A viewer whose HTTP log dashboard middleware redirects to a login page: treated as no access, no redirect leaks into
  the trace page, and the HTTP alias is never queried. Pinned in Tasks 3 and 5.
- An application that passes its own shared request ID, so one request ID matches several logs: search lands on the
  filtered list instead of an arbitrary one. Pinned in Task 7.

---

### Task 1: Audit logs adopt the active metrics trace

**Files:**
- Modify: `src/Support/TraceContext.php` (`merge()`, new `active()`)
- Modify: `src/DataTransferObjects/HttpLogData.php:72-76`, `src/DataTransferObjects/ActivityLogData.php:46-50`
- Test: `tests/Unit/TraceContextTest.php`, create `tests/Feature/AuditTraceLinkTest.php`
- Docs: `AUDIT_LOGS.md`, `ACTIVITY_LOGS.md`, `METRICS.md` (trace field descriptions), `CHANGELOG.md` (Unreleased → Added)

**Interfaces:**
- Produces: `TraceContext::active(): ?TraceContext` and
  `TraceContext::merge(?string $traceId = null, ?string $spanId = null, ?string $traceParent = null, ?string $traceState = null, ?TraceContext $active = null): self`.
  HTTP and activity documents written inside a metrics trace carry `trace.id` and `trace.span_id`.

- [ ] **Step 1: Write the failing unit tests** in `tests/Unit/TraceContextTest.php`

```php
public function test_merge_falls_back_to_the_active_trace(): void
{
    $active = new TraceContext(traceId: str_repeat('a', 32), spanId: str_repeat('b', 16));
    $merged = TraceContext::merge(active: $active);
    $this->assertSame(str_repeat('a', 32), $merged->traceId);
    $this->assertSame(str_repeat('b', 16), $merged->spanId);
    $this->assertNull($merged->traceParent); // never fabricate a header
}

public function test_merge_prefers_explicit_ids_over_the_active_trace(): void
{
    $merged = TraceContext::merge(traceId: str_repeat('c', 32), spanId: str_repeat('d', 16),
        active: new TraceContext(traceId: str_repeat('a', 32), spanId: str_repeat('b', 16)));
    $this->assertSame(str_repeat('c', 32), $merged->traceId);
}

public function test_merge_prefers_an_inbound_traceparent_over_the_active_trace(): void
{
    $merged = TraceContext::merge(traceParent: '00-'.str_repeat('e', 32).'-'.str_repeat('f', 16).'-01',
        active: new TraceContext(traceId: str_repeat('a', 32), spanId: str_repeat('b', 16)));
    $this->assertSame(str_repeat('e', 32), $merged->traceId);
}
```

- [ ] **Step 2: Write the failing feature tests** in `tests/Feature/AuditTraceLinkTest.php`

  Enable metrics (`elastic_audit_metrics.enabled = true`) and start a transaction with `app(MetricsRecorder::class)->begin(...)`
  the way `tests/Feature/MetricsInstrumentationTest.php` does; build data with the existing `HttpLogData::make` /
  `ActivityLogData::make` arguments used in `tests/Unit/HttpLoggerTest.php` and `tests/Unit/ActivityLogContextTest.php`.
  - `test_activity_log_data_adopts_the_active_metrics_trace`: `$data->traceId === $recorder->propagationContext()->traceId`
    and `$data->spanId === $recorder->propagationContext()->spanId`.
  - `test_http_log_data_adopts_the_active_metrics_trace`: same assertions for `HttpLogData`.
  - `test_audit_data_has_no_trace_when_metrics_are_disabled`: with `elastic_audit_metrics.enabled = false`,
    `$data->traceId` is `null`.

- [ ] **Step 3: Run them to verify they fail**

  Run: `vendor/bin/phpunit --filter 'TraceContextTest|AuditTraceLinkTest'`
  Expected: FAIL (unknown named parameter `active`, method `active` not found).

- [ ] **Step 4: Implement**
  - `merge()`: `traceId`/`spanId`/`sampled`/`traceState` come from `$active` only when neither the explicit `traceId` nor
    the parsed `traceParent` yields a trace ID. `traceParent` is never taken from `$active`.
  - `active()`: return `null` unless `config('elastic_audit_metrics.enabled')` is true and `MetricsRecorder::class` is
    bound; otherwise `app(MetricsRecorder::class)->propagationContext()`. Wrap in `try { } catch (Throwable) { return null; }`.
  - Both DTO `make()` methods pass `active: TraceContext::active()` to `merge()`. This runs before queue dispatch, so the
    snapshot matches `service.*` and `execution.*`.

- [ ] **Step 5: Run the tests and the full suite**

  Run: `vendor/bin/phpunit --filter 'TraceContextTest|AuditTraceLinkTest'`, then `composer test`
  Expected: PASS, no regressions.

- [ ] **Step 6: Update the docs listed under Files** (audit and activity trace fields are filled from the active metrics
  trace when no explicit or inbound trace exists), then `composer format`, `composer analyse`, `graphify update .`

- [ ] **Step 7: Commit**

```bash
git add src/Support/TraceContext.php src/DataTransferObjects tests AUDIT_LOGS.md ACTIVITY_LOGS.md METRICS.md CHANGELOG.md graphify-out
git commit -m "feat: link audit logs to the active metrics trace"
```

---

### Task 2: Millisecond timestamps on audit documents

**Files:**
- Modify: `src/DataTransferObjects/HttpLogData.php:84`, `src/DataTransferObjects/ActivityLogData.php:54`
- Test: `tests/Feature/AuditTraceLinkTest.php`
- Docs: `UPGRADE.md` (existing documents keep second precision and no trace link), `CHANGELOG.md` (Changed)

**Interfaces:**
- Produces: `HttpLogData::$timestamp` and `ActivityLogData::$timestamp` formatted `Y-m-d\TH:i:s.v\Z` (UTC).

- [ ] **Step 1: Write the failing tests**
  - `test_http_log_timestamp_has_milliseconds` and `test_activity_log_timestamp_has_milliseconds`:
    `$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $data->timestamp);`
- [ ] **Step 2: Run to verify they fail** — `vendor/bin/phpunit --filter timestamp_has_milliseconds`; Expected: FAIL.
- [ ] **Step 3: Implement** with `now()->toIso8601ZuluString('millisecond')` in both `make()` methods.
- [ ] **Step 4: Run** `composer test`; Expected: PASS (fix any existing assertion that pinned the old second format).
- [ ] **Step 5: Docs, format, analyse, graphify, commit** — `git commit -m "feat: record audit timestamps with milliseconds"`

---

### Task 3: `DashboardAccess` — does this viewer pass another dashboard's middleware?

**Files:**
- Create: `src/Dashboard/DashboardAccess.php`
- Test: `tests/Feature/Dashboard/DashboardAccessTest.php`

**Interfaces:**
- Produces:

```php
final class DashboardAccess
{
    public const HTTP = 'http';         // config key http_logs.dashboard
    public const ACTIVITY = 'activity'; // config key activity_logs.dashboard
    public const METRICS = 'metrics';   // config key elastic_audit_metrics.dashboard

    public function __construct(private readonly Router $router, private readonly Container $container) {}

    /** The dashboard's `enabled` flag. */
    public function enabled(string $dashboard): bool;

    /** Enabled, passes Dashboard::check(), and passes the dashboard's own middleware beyond the metrics dashboard's. */
    public function allows(Request $request, string $dashboard): bool;
}
```

- [ ] **Step 1: Write the failing tests** (register throwaway middleware with `$this->app['router']->aliasMiddleware()`,
  set `http_logs.dashboard.middleware`, call `app(DashboardAccess::class)->allows(request(), DashboardAccess::HTTP)`)
  - `test_same_middleware_as_the_metrics_dashboard_is_allowed` → `true`
  - `test_disabled_dashboard_is_denied` → `false`
  - `test_shared_gate_denial_denies` (`Dashboard::auth(fn () => false)`) → `false`
  - `test_extra_middleware_that_aborts_with_403_denies` → `false`
  - `test_extra_middleware_that_redirects_to_login_denies` (returns `redirect('/login')`) → `false`
  - `test_extra_middleware_that_passes_allows` → `true`
  - `test_extra_middleware_that_throws_denies` (throws `AuthenticationException`) → `false`
- [ ] **Step 2: Run to verify they fail** — `vendor/bin/phpunit --filter DashboardAccessTest`; Expected: FAIL (class missing).
- [ ] **Step 3: Implement.** Extra middleware = `$router->resolveMiddleware($target)` minus
  `$router->resolveMiddleware($metrics)`, where each list is the dashboard's configured `middleware` (default `['web']`)
  plus `AuthorizeDashboard::class`. Running only the difference avoids re-running `web` (session, CSRF), which is the
  "no side effects" rule. Send the request through `new Pipeline($this->container)`; allowed only if the destination
  closure was reached. Any `Throwable` means denied.
- [ ] **Step 4: Run the tests** — Expected: PASS.
- [ ] **Step 5: Format, analyse, graphify, commit** — `git commit -m "feat: check dashboard access across dashboards"`

---

### Task 4: `TraceTimeline` — merge one trace's documents into ordered rows

**Files:**
- Create: `src/Dashboard/TraceTimeline.php`
- Test: `tests/Unit/Dashboard/TraceTimelineTest.php`

**Interfaces:**
- Consumes: `_source` arrays from the metrics, HTTP log, and activity log aliases.
- Produces:

```php
final class TraceTimeline
{
    /**
     * @param  list<array<string, mixed>>  $metrics       metrics docs, profiled frames included
     * @param  list<array<string, mixed>>  $httpLogs
     * @param  list<array<string, mixed>>  $activityLogs
     * @return array{start: ?float, rows: list<TraceRow>, profiled: list<array<string, mixed>>, counts: array<string, int>}
     *
     * TraceRow = array{kind: 'transaction'|'span'|'job'|'http'|'activity', type: string, at: ?float,
     *                  offset_ms: ?float, duration_ms: ?float, doc: array<string, mixed>, children: list<TraceRow>}
     */
    public static function build(array $metrics, array $httpLogs, array $activityLogs): array;
}
```

  Rules (from the spec and the current `metrics/trace.blade.php`):
  - `at` is `@timestamp` in epoch milliseconds (`Carbon::parse()->getPreciseTimestamp(3)`), `null` if unparsable.
  - Documents of type `app.function.profiled` go to `profiled`, sorted by `duration_ms` descending, never into `rows`.
  - The root is the transaction without `trace.parent_span_id`, else the earliest transaction. Every other transaction is
    a `job` row whose `children` are the spans with the same `transaction.id`, collapsed in the view.
  - The root, the root's spans, spans with no matching transaction, HTTP logs (`kind: 'http'`, duration from
    `http.latency_ms`), and activity logs (`kind: 'activity'`, no duration) are top-level rows: the root first, the rest
    by `at`. Children are ordered by `at`.
  - `start` is the earliest `at` among metrics documents; `offset_ms = at - start`, which may be negative. With no
    metrics documents, `start` and every `offset_ms` are `null`.
  - `counts` has the keys `span`, `http`, `activity`, `job` (the root is not counted).

- [ ] **Step 1: Write the failing tests**
  - `test_root_transaction_is_first_and_rows_are_in_time_order`
  - `test_audit_rows_are_interleaved_by_millisecond_timestamp`: a span at `.618`, an activity log at `.620`, and a span at
    `.625` come out in that order.
  - `test_job_transactions_collapse_their_own_spans`: a `queue.job` transaction with two spans gives one `job` row with
    two children, and those spans are absent from the top level.
  - `test_profiled_frames_are_kept_out_of_rows`
  - `test_without_metrics_rows_have_no_offsets`
  - `test_audit_row_before_the_trace_start_gets_a_negative_offset` (Review Focus): an HTTP log 3 ms before the root gives
    `offset_ms === -3.0` and the row is still present.
  - `test_counts_per_kind`
- [ ] **Step 2: Run to verify they fail** — `vendor/bin/phpunit --filter TraceTimelineTest`; Expected: FAIL.
- [ ] **Step 3: Implement `build()`.**
- [ ] **Step 4: Run** — Expected: PASS.
- [ ] **Step 5: Format, analyse, graphify, commit** — `git commit -m "feat: build a merged timeline for a trace"`

---

### Task 5: Trace page shows the merged timeline

**Files:**
- Modify: `src/Http/Controllers/MetricsDashboardController.php` (`trace()`), `resources/views/metrics/trace.blade.php`
- Test: create `tests/Feature/Dashboard/TraceExplorerTest.php`; keep `test_trace_and_profile_render_linked_data` passing
- Assets: `npm run build`
- Docs: `METRICS.md` ("Trace explorer" section), `CHANGELOG.md`

**Interfaces:**
- Consumes: `DashboardAccess::allows()` (Task 3), `TraceTimeline::build()` (Task 4),
  `HttpLogDashboardQuery::search(array $filters, int $page, int $perPage, string $sortField, string $sortDir)` and the same
  method on `ActivityDashboardQuery`, both of which already accept `['trace_id' => ...]`.
- Produces: the view receives `$timeline` (the `build()` result), `$errors` (`array<'metrics'|'http'|'activity', string>`),
  `$auditHidden` (bool), `$truncated` (`array<'http'|'activity', int>`, the total when above the cap), `$traceId`,
  `$timezone`.

- [ ] **Step 1: Write the failing tests** (fake resolver keyed on index name; `Dashboard::auth(fn () => true)`)
  - `test_trace_page_merges_spans_http_and_activity_rows`: `assertSeeInOrder` of the root name, the HTTP row label
    (`provider · event_type`), and the activity `action`, plus the offset text `+2.0 ms`.
  - `test_audit_rows_are_hidden_when_the_viewer_fails_that_dashboards_middleware` (Review Focus): the HTTP dashboard gets
    an extra denying middleware; assert the HTTP label is absent, the text "Some audit data in this trace is hidden" is
    present, and no entry of `$fake->searchCalls` targets an `http_logs` index.
  - `test_page_renders_audit_rows_when_metrics_has_none`: assertOk with absolute times.
  - `test_page_is_404_when_no_source_has_the_trace`
  - `test_a_failing_source_shows_a_notice_and_the_rest_still_renders`: the activity search throws; assertOk, spans
    visible, notice visible.
  - `test_audit_rows_are_capped_with_a_notice` (Review Focus): the HTTP search reports total `350`; assert
    "Showing the first 200 HTTP logs of 350".
- [ ] **Step 2: Run to verify they fail** — `vendor/bin/phpunit --filter TraceExplorerTest`; Expected: FAIL.
- [ ] **Step 3: Controller.** `trace(Request $request, string $traceId, HttpLogDashboardQuery $http, ActivityDashboardQuery $activity, DashboardAccess $access): View`
  — keep the 32-hex check. Each source has its own `try`/`catch` that writes to `$errors`. Audit sources are queried only
  when `allows()` is true; `$auditHidden` is true when a source's dashboard is enabled but not allowed. Each audit source
  uses `search(['trace_id' => $traceId], 1, self::AUDIT_ROWS_PER_SOURCE, '@timestamp', 'asc')`, with
  `private const AUDIT_ROWS_PER_SOURCE = 200`. Return 404 only when there are no rows, no profiled frames, and no errors.
- [ ] **Step 4: View.** Keep the header (name, trace ID, start, duration, span count) and the profiled-functions and
  profile link sections. Replace the span list with `$timeline['rows']`:
  - kind tag colours from the existing `$styles` map, plus HTTP and ACTIVITY tags;
  - label: spans as today; HTTP `provider · event_type · METHOD url · status`; activity `action · entity.type #entity.id`;
  - right side: `+{offset} ms · {duration} ms`, or the absolute `H:i:s.v` time when `offset_ms` is null;
  - Alpine filter chips (`x-data="{ show: { span: true, http: true, activity: true, job: true } }"`) with `counts`;
  - clicking a row expands inline details. HTTP: direction, method, URL, status, latency, and the stored request and
    response body previews. Activity: actor, entity, and `changes` before/after. Both get an "Open full log" link to
    `http-logs.logs.show` / `activity-logs.logs.show` by `event_id`;
  - `job` rows are collapsed and expand to their children.

  Notice copy: "Some audit data in this trace is hidden because you don't have access to its dashboard." and
  "Showing the first 200 {HTTP logs|activity events} of {total}." Then run `npm run build`.
- [ ] **Step 5: Run** `vendor/bin/phpunit --filter 'TraceExplorerTest|MetricsDashboardTest'`, then `composer test`; Expected: PASS.
- [ ] **Step 6: Docs, format, analyse, graphify, commit** — `git commit -m "feat: show http and activity logs on the trace timeline"`

---

### Task 6: Trace IDs are links

**Files:**
- Create: `resources/views/partials/trace-link.blade.php`
- Modify: `resources/views/dashboard/logs/index.blade.php`, `resources/views/dashboard/logs/show.blade.php:72`,
  `resources/views/activity/index.blade.php`, `resources/views/activity/show.blade.php:43`
- Test: create `tests/Feature/Dashboard/TraceLinkTest.php`

**Interfaces:**
- Consumes: `DashboardAccess::enabled(DashboardAccess::METRICS)`.
- Produces: `@include('elastic-audit::partials.trace-link', ['traceId' => ?string])`. It renders a monospace link to
  `elastic-audit-metrics.traces.show` when the metrics dashboard is enabled and the ID matches `/^[a-f0-9]{32}$/`, the
  plain ID otherwise, and `—` for null.

- [ ] **Step 1: Write the failing tests**
  - `test_http_log_detail_links_its_trace_id` and `test_activity_log_detail_links_its_trace_id`: the page contains
    `href="` followed by the trace route.
  - `test_list_rows_link_trace_ids`: a trace link in each list row that has a trace (a small "trace ↗" link after the
    existing row content, not a new column).
  - `test_trace_id_is_plain_text_when_the_metrics_dashboard_is_disabled` (disable it in `defineEnvironment`).
- [ ] **Step 2: Run to verify they fail** — `vendor/bin/phpunit --filter TraceLinkTest`; Expected: FAIL.
- [ ] **Step 3: Implement** the partial. Render the detail pages' "Trace ID" value through it, and add it to the list
  rows. Run `npm run build`.
- [ ] **Step 4: Run** `composer test`; Expected: PASS.
- [ ] **Step 5: Format, analyse, graphify, commit** — `git commit -m "feat: link trace ids across the dashboards"`

---

### Task 7: Search box for trace and request IDs

**Files:**
- Create: `src/Http/Controllers/TraceSearchController.php`, `resources/views/metrics/search-miss.blade.php`
- Modify: `routes/metrics_dashboard.php`, `resources/views/partials/nav.blade.php`
- Test: create `tests/Feature/Dashboard/TraceSearchTest.php`
- Docs: `METRICS.md`, `CHANGELOG.md`

**Interfaces:**
- Consumes: `DashboardAccess` (Task 3), `HttpLogDashboardQuery::search()`/`find(string $eventId)`, and the same methods on
  `ActivityDashboardQuery`.
- Produces: `GET {prefix}/{metrics path}/find?id=…`, named `elastic-audit-metrics.find`, handled by
  `TraceSearchController::__invoke(Request $request, HttpLogDashboardQuery $http, ActivityDashboardQuery $activity, DashboardAccess $access): Response`.

  Resolution order:
  1. Trim. A `traceparent` (`/^[0-9a-f]{2}-([0-9a-f]{32})-[0-9a-f]{16}-[0-9a-f]{2}$/i`) yields its trace ID. Anything
     matching `/^[0-9a-f]{32}$/i` is lower-cased and redirects to `traces.show`.
  2. Otherwise, for each audit source the viewer `allows()`: `search(['request_id' => $id], 1, 25)`, plus `find($id)`
     as an event ID.
  3. A match with `trace.id` redirects to the trace page (earliest match).
  4. No trace, exactly one match: redirect to its `logs.show`. Several matches in one source: redirect to that source's
     `logs.index` with `request_id` set.
  5. Nothing found: render `search-miss` with status 404, the text `No trace or request found for "{id}".`, and the
     search box. Empty input redirects to `elastic-audit-metrics.overview`.

  The nav form (GET, `name="id"`, `aria-label="Find trace or request ID"`, placeholder `Find trace or request ID…`) is
  rendered only when `DashboardAccess::enabled('metrics')` is true.

- [ ] **Step 1: Write the failing tests**
  - `test_trace_id_redirects_to_the_trace_page`
  - `test_traceparent_and_upper_case_input_are_normalised` (Review Focus): `'  00-AAAA…(32)-bbbb…(16)-01 '` redirects
    to the lower-case trace ID.
  - `test_request_id_with_a_trace_redirects_to_the_trace_page`
  - `test_request_id_without_a_trace_redirects_to_its_log`
  - `test_request_id_shared_by_several_logs_redirects_to_the_filtered_list` (Review Focus)
  - `test_unknown_id_shows_the_not_found_page_with_404`
  - `test_sources_the_viewer_cannot_open_are_not_searched`: no entry of `searchCalls` targets an `http_logs` index.
  - `test_nav_shows_the_search_box_when_the_metrics_dashboard_is_enabled`
- [ ] **Step 2: Run to verify they fail** — `vendor/bin/phpunit --filter TraceSearchTest`; Expected: FAIL.
- [ ] **Step 3: Implement** the route, controller, miss view, and nav form, then run `npm run build`.
- [ ] **Step 4: Run** `composer test`; Expected: PASS.
- [ ] **Step 5: Docs, format, analyse, graphify, commit** — `git commit -m "feat: search dashboards by trace or request id"`

---

### Task 8: End-to-end check in packages-testing-app

No code. The testing app symlinks this checkout into `vendor/`, so the change is live.

- [ ] **Step 1:** Ensure Elasticsearch is up (`docker start elasticsearch`), the app is served on :8000, and
  `php artisan queue:listen` is running in `packages-testing-app`.
- [ ] **Step 2:** Run `scripts/api-traffic.sh` there; Expected: `done: 0 unexpected`.
- [ ] **Step 3:** In the activity log list, open the newest `order.cancelled` entry and click its trace link. Expected: one
  timeline with the cancel request, its queries, and both `order.updated` and `order.cancelled` rows at sub-second offsets.
- [ ] **Step 4:** Open the trace for a `catalog.sync` call. Expected: an HTTP row showing `catalog · catalog.sync`
  between the request's spans, and its inline details show the jsonplaceholder response.
- [ ] **Step 5:** Paste that HTTP log's request ID into the sidebar search. Expected: the same trace page.
- [ ] **Step 6:** Run `php artisan elastic-audit:health`. Expected: no errors (mappings unchanged).
