# Trace explorer: everything that happened in one trace

Status: ready for owner approval · 2026-10-26

## Intent

When a maintainer looks at an HTTP log, an activity log, or a transaction in the dashboards, they want to click its
trace ID, or paste a trace or request ID into a search box, and see **everything that happened in that request on one
page**: the request transaction and its spans, every HTTP log (outgoing calls and incoming callbacks), every activity
event, and the queued jobs the request started. Today that means searching three dashboards by hand, and for most
requests it is impossible, because the audit logs and the metrics share no key.

## Current state (verified in code and in the packages-testing-app cluster)

- Metrics documents (transactions, spans, profiles) carry `trace.id`. The trace page
  `GET {metrics prefix}/traces/{traceId}` (`MetricsDashboardController::trace`, `MetricsDashboardQuery::trace`,
  `resources/views/metrics/trace.blade.php`) shows only metrics documents.
- HTTP logs and activity logs have `trace.id`, `trace.span_id`, and `trace.traceparent` fields, but these are filled only
  from an explicit context value or an inbound `traceparent` header (`TraceContext::merge` in `HttpLogData` and
  `ActivityLogData`). They never read the trace that `MetricsRecorder` started for the current request. In a normal
  request with no inbound `traceparent`, every audit document has `trace.id = null`.
- `request_id` is **not** shared within a request. `HttpLogContext` and `ActivityLogContext` default it to a fresh ULID,
  and `ActivityLoggable` makes a new one per event, so one cancel request wrote two activity events with two different
  request IDs. The trace ID is therefore the only key that can join a request together.
- Audit documents store `@timestamp` to the whole second (`2026-10-26T17:25:41Z`); metrics store milliseconds
  (`2026-10-26T17:25:41.618Z`). A 25 ms request fits inside one second, so audit events cannot be placed on a
  millisecond timeline.
- HTTP and activity log detail pages already print the trace ID as plain text. Both log lists already accept a
  `trace_id` filter. The transaction list already links each row's name to the trace page.

## Goals

1. **Link the data.** While a metrics trace is active, every HTTP log and activity log written in it stores that trace's
   `trace.id` and `trace.span_id`. This includes audit logs written by queued jobs that run under a propagated trace.
2. **Millisecond timestamps.** Audit documents store `@timestamp` with milliseconds, in UTC, so they can be ordered
   against spans.
3. **One merged timeline on the trace page** (the user chose this layout over separate sections). The existing trace
   page becomes a single timeline, in time order, of:
   - the request transaction and its spans (as today);
   - every HTTP log with that `trace.id`;
   - every activity event with that `trace.id`;
   - transactions of queued jobs in the same trace (see Queued jobs below).

   Each row shows a kind tag (REQUEST, QUERY, HTTP, ACTIVITY, JOB, and the other span kinds already shown), a one-line
   label, its offset from the start of the trace (`+18.9 ms`), and its duration when it has one. Filter chips at the top
   show each kind with its count and show or hide it. Clicking a row expands its details inline; HTTP and activity rows
   also link to their existing full detail page. The profile link and the "Application functions" ranking stay at the
   bottom, as today.
4. **Trace ID is a link everywhere it appears.** HTTP log list rows and detail page, activity log list rows and detail
   page, and the transaction list (already linked by name) all link to the trace page when a trace ID is present.
5. **Search box in the sidebar header** that accepts a trace ID or a request ID and goes to the trace page.

## Non-goals

- Changing what `request_id` means or making it shared per HTTP request. It stays as it is; search uses it only to find
  a document and follow that document's `trace.id`.
- Backfilling `trace.id` or millisecond timestamps into documents that are already indexed. Old documents stay
  unlinked; the page must still work for them (see Fallbacks).
- A replacement for Kibana or a general log search. The page is scoped to one trace.
- Cross-application tracing beyond what already happens: if another application on the same cluster continues the trace
  through `traceparent`, its documents may appear on the page, labelled with their `service.name`. Nothing extra is built
  for it.

## Anti-goals (would be a failure even if it technically works)

- The page breaks, or the dashboards stop rendering, when the HTTP log or activity subsystem or its dashboard is
  disabled, or when one Elasticsearch query fails. Each source is queried independently; a failing or disabled source
  is reported in a notice and the rest still renders.
- Showing anything that was not stored. The page shows only stored, already-redacted documents; it adds no new capture.
- Audit capture becoming more expensive or able to throw. Reading the active trace must be a cheap, non-throwing lookup,
  in line with "metrics are observational and must not alter application behavior".
- Metric documents gaining audit data. Metrics stay free of bodies, headers, and audit IDs (see AGENTS.md); the join
  happens only at read time on the trace page.

## Decided parts of the how

### Write side

- **Trace precedence** for an audit document: an explicit `traceId` / `traceParent` on the context, then an inbound
  `traceparent` header, then the trace that `MetricsRecorder` has active for the current execution slot (Fiber and
  coroutine local, like the rest of the recorder). When none exists, the fields stay null, as today.
- The trace is captured **when the audit data is built, before queue dispatch**, the same way `service.*` and
  `execution.*` are snapshotted, so that the queued indexing job does not pick up a different trace.
- `trace.span_id` is the span that was active when the audit document was created, so a future version could nest it
  under that span. In this version the timeline places audit rows by time only.
- `@timestamp` on HTTP and activity documents keeps the same field and type but includes milliseconds.

### Trace page

- It stays at `GET {metrics prefix}/traces/{traceId}` and keeps its 32-hex ID validation.
- Data: the existing metrics query, plus a `term` query on `trace.id` against the HTTP log read alias and the activity
  log read alias, each sorted by `@timestamp` ascending.
- A response of 404 only when all three sources return nothing and none failed. If metrics has nothing (sampled out, or
  metrics disabled at capture time) but audit logs exist, the page renders the audit rows with absolute times instead of
  offsets.
- Inline details: HTTP rows show provider and event, method, URL, status, duration, and the stored request and response
  bodies; activity rows show action, actor, entity, and the changes (before and after). Each also links to its full
  detail page when that dashboard is enabled.
- Filter chips are client-side (Alpine, as the rest of the dashboards) and need no reload.

### Queued jobs

- Jobs started by the request run later as their own `queue.job` transactions with the same `trace.id` (W3C propagation
  through queue payloads already exists). They appear on the timeline as a JOB row at their own start time, collapsed by
  default; expanding shows that job's spans. Audit logs written inside the job appear as their own rows in time order.

### Links and search

- Trace links are rendered only when the metrics dashboard is enabled, because that is where the trace page lives.
- The search box lives in the shared sidebar (`resources/views/partials/nav.blade.php`) and is shown only when the
  metrics dashboard is enabled. It submits to a new GET route under the metrics dashboard routes, which behaves like
  this:
  - a 32-hex value is treated as a trace ID and redirects to the trace page;
  - anything else is looked up as a `request_id` (and as an `event_id`) in the HTTP and activity aliases; if the found
    document has a `trace.id`, it redirects to the trace page;
  - if found but without a trace (older documents), it redirects to that document's detail page, or to the HTTP or
    activity log list filtered by that request ID when there are several;
  - if nothing is found, it returns to the referring page with a "No trace or request found" message.

### Access

- All three dashboards share one gate (`Dashboard::check` via `AuthorizeDashboard`), but each has its own configurable
  `middleware` list, so an application can protect one dashboard more tightly than another.
- **Decided by the owner:** HTTP and activity rows are shown on the trace page only when the viewer also passes that
  dashboard's own configured `middleware` (in addition to the shared gate). Opening the performance dashboard alone is
  not enough to see audit data.
- When a source is withheld, its rows and filter chip are left out and the page shows a short notice that some audit
  data is hidden for this viewer, without a count (my call, so the count does not leak either).
- The same rule applies to the search box: a request or event ID is looked up only in the aliases whose dashboard the
  viewer passes, and the result never redirects into a dashboard they cannot open.
- How the other dashboard's middleware is evaluated for the current request is left to the builder. It must give the
  same answer as actually requesting that dashboard, treat any non-success outcome (403, redirect to login, exception)
  as "no access", and have no side effects.

### Calls made while writing this (not discussed with the owner)

- The feature needs metrics enabled at capture time: without an active metrics trace there is nothing to link, and the
  trace page lives in the metrics dashboard. With metrics disabled, behaviour is unchanged.
- An audit document is linked whenever the recorder has a trace context for the current execution, even if that
  transaction is later sampled out; the page then shows the audit rows without a transaction (see Trace page).
- Audit rows appear on a trace page only when their own dashboard is enabled, and their "open full log" links follow
  the same rule.

## Fallbacks for old data

Documents written before this change have no trace ID and second-precision timestamps. They are reachable through the
request ID search, which lands on their detail page or a filtered list. They never appear on a trace page.

## Constraints

- PHP 8.2+, Laravel 12 and 13, the existing Tailwind v4 and Alpine dashboard stack. No new dependencies.
- Follow `CODING_STANDARDS.md`, `pint`, `phpstan`, and keep the dashboards' existing look, theming, and dark mode.
- Built assets in `public/vendor/elastic-audit` are committed; rebuild with `npm run build` when CSS or JS changes.
- Tests must not need a live Elasticsearch cluster (use the existing fake client in `tests/TestCase.php`).
- Run `graphify update .` after code changes.
- Update `CHANGELOG.md` (Unreleased), `METRICS.md`, `AUDIT_LOGS.md` and `ACTIVITY_LOGS.md` where they describe trace
  fields, and `UPGRADE.md` with the note that existing documents stay unlinked.

## Left to the builder

- Whether the new trace and timestamp behaviour needs a `SCHEMA_VERSION` bump on `HttpLogData` and `ActivityLogData`,
  following the rules this repository already applies to schema changes.
- Exactly how a public, read-only accessor for the active trace is exposed from `MetricsRecorder` (the existing
  `current()` is private).
- The offset arithmetic for rows, reusing whatever the current trace view uses for span start times.
- Result caps per source (the metrics query already caps at 1000) and the wording of a "showing the first N" notice.
- The visual details of rows, chips, and inline panels within the existing dashboard style.
- The route name and path for the search endpoint.
