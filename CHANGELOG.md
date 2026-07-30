# Changelog

All notable changes to this package are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Added indexed application identity and execution origin to both HTTP and activity documents. Sources are snapshotted
  before queue dispatch, support route/controller, queue job, Artisan command, and explicit manual origins, and are
  visible/filterable in both dashboards. Application identity comes from Laravel's existing `app.name` and `app.env`
  configuration. Outgoing-request origins are resolved per request, so a reused audited client attributes each call to
  the context that issued it. `elastic-audit:health` reports the resolved identity and flags an unchanged default
  `app.name`.

### Changed

- HTTP and activity document schema versions are now 5 and 4. Existing installations must roll over enabled write
  aliases to install the new strict mappings.
- Documented explicit `ActivityLog::record()` usage for raw SQL/query-builder writes; the package intentionally does not
  install `DB::listen()`.

## [4.1.0] - 2026-07-23

### Added

- Added sanitized, non-blocking `AuditOperationFailed` Laravel events for complete HTTP/activity capture loss and jobs
  that exhaust their indexing retries. Failure context is shallow and excludes raw exceptions, headers, payloads,
  model changes, and arbitrary metadata.
- Added `elastic-audit:health --json` for deployment automation and schema-aware health checks for current write-index
  and index-template mappings. New mappings carry subsystem/schema metadata; structurally compatible v4 mappings
  created before that metadata remain valid.
- Added Larastan static analysis, Pint formatting, CI quality gates, and a security policy with an explicit threat
  model.

### Changed

- Documented a releasable-`main`, short-lived-topic-branch workflow and local dependency resolution for the active PHP
  runtime.

## [4.0.0] - 2026-07-22

### Added

- Permanent document and index retention. HTTP and activity logs can default to `retain_forever`, individual contexts
  accept `retainForever: true`, and `log_elasticsearch.lifecycle.delete_enabled=false` keeps ILM rollover without a
  delete phase. Health checks reject permanent subsystem defaults that conflict with whole-index deletion.
- Bounded HTTP body capture through `body_capture_max_bytes` / `HTTP_LOGS_BODY_CAPTURE_MAX_BYTES` (default 1 MB), plus
  `undecodable_body_mode` / `HTTP_LOGS_UNDECODABLE_BODY_MODE` for choosing hash-only metadata (default) or an explicitly
  reviewed clear-text preview for XML, plain text, scalar JSON, and other bodies that cannot be key-redacted.
- Release CI now includes runtime dependency auditing, SemVer tag triggers, and real Elasticsearch 8/9 lifecycle,
  indexing, rollover, health, and pruning smoke tests.

### Changed

- HTTP `userId` and activity `actorId` now accept `int|string|null`, including UUIDs and other string identifiers.
  `ActivityLoggable` preserves string ids returned by `Auth::id()` and custom `activityActor()` implementations.
- Elasticsearch maps HTTP `user_id` and activity `actor.id` as `keyword` and indexes every non-null id as a string.
  HTTP and activity schema versions are now 4 and 3. Existing installations must run the relevant create-index command
  before logging string ids; see the [Upgrade Guide](UPGRADE.md#upgrading-from-320).
- Context factories now read validated default retention from `http_logs.retention_days` and
  `activity_logs.retention_days`. Finite values must be between `1` and `32767`.
- Successful incoming callbacks are now captured from terminable middleware after the response is sent. Exception
  paths remain inline so their sanitized failure details and HTTP status are preserved.
- Activity single and batch jobs dispatch after the surrounding database transaction commits. Rolled-back model changes
  no longer leave activity documents claiming that they persisted.
- HTTP and activity Elasticsearch `_id` values are now the raw `event_id` ULID rather than its SHA-256 hash. Queue
  retries remain idempotent while operational tooling can address a document by its visible event id.
- Subsystem aliases and the default lifecycle policy are derived from one canonical index prefix when their config is
  `null`. `APP_NAME` fallbacks are slugged, explicit invalid Elasticsearch names are rejected, and new configurations
  default to one replica instead of zero.
- Composer now declares the Promise and PSR HTTP interfaces used by the middleware directly, requires the patched
  `guzzlehttp/guzzle ^7.15.1` floor, and removes the unused direct PSR-7 implementation requirement. Publishable
  application enum templates moved outside the package's PSR-4 source tree, and CI validates strict optimized
  autoloading plus PHP syntax on `v4.x`.

### Fixed

- Dashboard CSS and JavaScript assets now use same-origin relative URLs, preventing mixed-content failures when Laravel
  runs behind a TLS-terminating reverse proxy.
- Stored URLs, URL-valued headers, and exception messages now remove userinfo, query/fragment secrets, authorization
  values, and common credential-shaped values. Diagnostic messages are UTF-8-safe and capped at 2048 bytes.
- Incoming and outgoing payment capture now use one provider-redactor resolver; integer, string, and backed-enum config
  values resolve consistently. One audited pending request also keeps one sampling decision across Laravel retries.
- Callback capture safely accepts configured enum instances, rejects arbitrary values without throwing, preserves
  Symfony HTTP exception status codes, and cannot replace the response or original handler exception.
- HTTP request/response reads are memory-bounded, restore seekable streams, skip multipart/binary/oversized bodies, and
  truncate UTF-8 previews without splitting multibyte characters.
- Activity capture preserves sensitive `{old, new}` diff shape, sanitizes activity errors, renders malformed legacy
  diffs defensively, prevents force-delete duplicate events, and isolates custom activity hooks from model persistence.
- Prune commands composite-page every retention value and detect timeouts, shard failures, version conflicts, and
  partial deletion failures.
- Re-running a create-index command with an existing write alias uses Elasticsearch rollover instead of manually
  swapping the alias, preserving lifecycle progression for the previous generation.
- Dashboard controllers log the real Elasticsearch exception server-side while returning only a generic error to the
  browser.
- `elastic-audit:health --all` can verify aliases for disabled subsystems without rejecting their intentionally unused
  enum, queue, retention, or capture configuration.
- Malformed retry and timeout configuration now falls back safely instead of coercing fractional/non-scalar values;
  failed HTTP job diagnostics include the unique `event_id`.

## [3.2.0] - 2026-07-16

### Added

- Added package-owned Laravel Boost resources for consuming applications: concise guidelines and an
  `elastic-audit-development` Agent Skill covering HTTP auditing, activity capture, redaction, queues, Elasticsearch
  operations, and testing. Laravel Boost remains an optional development dependency of the consuming application.
- Added `AGENTS.md`, a standalone agent guide shipped in the Composer distribution, so applications that do not use
  Laravel Boost can point a coding agent at `vendor/tsitsishvili/elastic-audit/AGENTS.md` with no extra tooling.
- Added the `elastic-audit-ai` publish tag, which copies the Agent Skill to `.ai/skills/elastic-audit-development` and
  the standalone guide to `AGENTS.elastic-audit.md` for applications that do not use Laravel Boost.

### Changed

- Expanded `.gitattributes` with explicit text/binary handling, generated-asset metadata, and Composer archive rules.
  Distribution archives now exclude development dependencies, tests, CI/editor files, caches, and frontend build
  sources while retaining runtime assets, package guides, and Laravel Boost resources.
- Indexed the agent guide from the README and both subsystem guides so it is discoverable from any entry point.

### Fixed

- Fixed links to `CONTRIBUTING.md` and `CODING_STANDARDS.md` resolving to missing files in the Composer distribution,
  where both documents are intentionally excluded. The README and both subsystem guides now link to them on GitHub, so
  every link in a published package resolves.

## [3.1.1] - 2026-07-16

### Fixed

- Fixed HTTP and activity dashboards failing to load their CSS and JavaScript until assets were manually published.
  Manifest-listed assets are now served directly from the Composer package with immutable cache headers; publishing
  `elastic-audit-assets` remains available as an optional static-delivery optimization.

## [3.1.0] - 2026-07-16

### Added

- Dashboard CSS and JavaScript are now built into versioned package assets and can be published with
  `php artisan vendor:publish --tag=elastic-audit-assets --force`; the main `elastic-audit` publish tag also includes
  them for new installations.

### Changed

- Dashboard pages now use compiled Tailwind CSS and locally bundled Alpine.js/Chart.js instead of runtime CDN assets,
  removing the production dependency on third-party asset hosts.
- CI now validates the active `v3.x` branch, tests PHP 8.5, and verifies that committed dashboard assets match their
  sources.

### Fixed

- Restored highest-dependency test compatibility with Laravel 13.19 and later, whose Testbench base class now exposes
  a public `query()` request helper.

## [3.0.3] - 2026-07-06

### Changed

- Refactored the HTTP and activity dashboards to share one layout, overview controls, and stat-card components while
  preserving their existing routes and query behavior.
- Changed Composer package metadata from proprietary to MIT and added maintainer metadata, matching the repository
  license.

## [3.0.2] - 2026-07-06

### Fixed

- `http-logs:create-index` and `activity-logs:create-index` now create the next available rollover-compatible physical
  index (`-000002`, `-000003`, etc.) when the initial `-000001` index already exists, instead of repeatedly targeting
  the first generation.
- Create-index commands now install matching Elasticsearch index templates so ILM-created rollover indexes inherit the
  package mappings, lifecycle settings, replica settings, and read aliases.

## [3.0.1] - 2026-07-05

### Fixed

- Create HTTP/activity log indexes with rollover-compatible `-000001` names, and allow rollover commands to migrate
  legacy timestamp-named write indexes by explicitly naming the next rollover index.

## [3.0.0] - 2026-07-04

### Changed

- HTTP log documents are now indexed by `event_id` instead of `request_id|queue_attempt`, preventing multiple provider
  calls that share one correlation request id from overwriting each other.
- New published configs default Elasticsearch lifecycle management to enabled with a `360d` delete phase; prune
  commands remain available for per-document retention or manual fallback cleanup.
- HTTP and activity log jobs now read attempts, retry backoff, single-job timeout, and batch-job timeout from config.
- HTTP enum contracts now extend PHP's `BackedEnum` and no longer require application enums to implement `getValue()`.
- `http_logs.enums.provider`, `.event_type`, and `.entity_type` now default to `null`. Missing or invalid enum classes
  are treated as not configured and cause incoming callback logging to be skipped instead of throwing.
- `http-logs:prune` and `activity-logs:prune` now return a failure exit code when Elasticsearch search/delete
  operations fail, so schedulers and CI can detect retention failures.
- `elastic-audit:health` now validates HTTP enum classes, job retry settings, lifecycle delete configuration, and
  rollover conditions in addition to cluster reachability and aliases.

### Fixed

- Fixed `elastic-audit:lifecycle-policy` for Elasticsearch PHP client v9 by sending the required ILM `policy`
  parameter instead of `name`.
- Fixed a sensitive-data leak where incoming `application/x-www-form-urlencoded` bodies could be stored in
  `body_preview` before redaction.
- Incoming callback middleware now records a failed log entry with sanitized exception details when the callback
  handler throws, then rethrows the original exception.
- Elasticsearch bulk indexing now treats per-item failures as job failures even when Elasticsearch returns HTTP 200.

## [2.5.0] - 2026-07-01

### Added

- Outgoing HTTP logging now captures and redacts request headers, bringing outgoing logs to parity with incoming
  callback logs.
- HTTP and activity documents now include optional W3C trace context (`trace.id`, `trace.span_id`, and stored
  `trace.traceparent`) parsed from `traceparent` headers or passed explicitly on the log context. Dashboard filters
  accept `trace_id`.
- Elasticsearch operations now include `elastic-audit:health`, `elastic-audit:lifecycle-policy`,
  `http-logs:rollover`, and `activity-logs:rollover`. New indexes can opt into ILM settings through
  `log_elasticsearch.lifecycle`.
- Bulk replay support via `HttpLogIndexer::bulk(...)`, `ActivityLogIndexer::bulk(...)`, `LogHttpRequestBatchJob`, and
  `LogActivityBatchJob`.
- `ActivityLoggable` now logs `restored` and `force_deleted` events for SoftDeletes models and exposes
  `activityActor()` / `activityEntityId()` hooks for model-specific context.

### Changed

- **Activity logs no longer depend on `EntityTypeContract`.** `ActivityLogContext::forActor()` now accepts a plain `string $entityType` instead of an `EntityTypeContract` instance — the activity subsystem already stored and indexed `entityType` as a free string (the `ActivityLoggable` trait never used the contract). The `EntityTypeContract` contract and its config-driven resolution remain in use by the HTTP logger; only the activity surface is decoupled. **Potentially breaking** for callers that passed an enum to `forActor()` — pass `$enum->value` (or a literal string) instead. No document-shape or mapping change.
- Documentation examples now use the current `^2.5` package line and the actual default HTTP dashboard path
  `/logger/http-logs`.

## [2.4.0] - 2026-06-30

### Added

- The `ActivityLoggable` trait now supports an overridable `activityMetadata(string $event, array $changes): array` hook, letting models attach arbitrary extra data (arrays, tags, request IP, tenant, etc.) to auto-logged `created`/`updated`/`deleted` events. It defaults to `[]`, is redacted by key name like `changes`, and populates the existing `metadata` map (stored but not indexed) — the same field already available via the `metadata:` argument of `ActivityLog::record()`. No document-shape or mapping change.

## [2.3.0] - 2026-06-27

### Changed

- **Dashboard visual refresh** (presentation only — no routes, query params, config keys, or indexed document shapes change; affects the publishable `elastic-audit-views` assets). The shared nav/layout chrome and the HTTP dashboard views were restyled around a small set of shared design tokens (light/dark palettes, a panel surface, keyboard focus rings) for cleaner, more legible output.
  - **Top navigation**: now sticky and light-themed (was solid dark slate), with an "Elastic Audit" wordmark, pill-style dashboard switcher and page tabs, and responsive stacking on small screens.
  - **HTTP log list** (`http-logs.logs.index`): heading renamed "Logs" → "HTTP log stream"; status/direction as pill badges, method as a chip, success/failure as labeled "ok"/"fail" badges; responsive header and pagination.
  - **HTTP log detail** (`http-logs.logs.show`): header regrouped into a panel; the Headers and Body preview blocks gain a copy button and a scrollable max-height area.
  - **HTTP overview** (`http-logs.overview`): heading renamed "Overview" → "Third-party HTTP traffic"; charts render on a card backdrop so they stay legible in dark mode.

> **Upgrading:** apps that previously published the views (`vendor:publish --tag=elastic-audit-views`) keep their own copies and must re-publish to pick up this refresh.

## [2.2.0] - 2026-06-25

### Added

- **Activity overview charts**: the activity dashboard overview (`activity-logs.overview`) now renders an "Activity over time" stacked bar chart (successful vs failed events per bucket), fed by the existing `over_time` date-histogram aggregation. Adds an **interval** selector (per hour / per day) alongside the range pills, a **Success Rate** stat card, and renders Top Actions / By Actor Type as proportional bar rows. Mirrors the HTTP overview interactions: click a bar to open matching logs for that bucket, drag across to zoom into a sub-range, and export each chart to PNG.
- HTTP overview charts support **drag-to-zoom**: dragging horizontally across any chart re-opens the overview scoped to that sub-range (`range=custom` with the corresponding `from`/`to`). A single click still opens the matching logs for one bucket.
- **Live** auto-refresh toggle on both log list views (`http-logs.logs.index` and `activity-logs.logs.index`): reloads the list every 10s, with the on/off state persisted in `localStorage` (`tphl_live_logs` / `tphl_live_activity`). Off by default. Mirrors the existing Live toggle on the HTTP overview.

### Changed

- HTTP overview dashboard: the Volume / Latency / Error-rate charts now render one per row at a larger height (was a cramped three-up grid), so trends are easier to read.

### Security

- Dashboard CDN assets are now **version-pinned**, and the jsDelivr-hosted scripts (Chart.js `4.4.7`, Alpine.js `3.14.8`) carry Subresource Integrity (`integrity` + `crossorigin="anonymous"`) so a compromised CDN cannot inject altered code. The Tailwind Play CDN is pinned to `3.4.16` but intentionally left without SRI: it serves no CORS header, so `integrity`+`crossorigin` would cause the browser to block it.

### Fixed

- HTTP overview "Custom range": the From/To `datetime-local` inputs now populate after a chart drag-to-zoom. The drag writes ISO `...Z` `from`/`to` params, which a `datetime-local` input rejects; the inputs now normalize incoming values to the app timezone (`Y-m-d\TH:i`), matching the log list view.

## [2.1.0] - 2026-06-24

### Added

- `IncomingHttpLogMiddleware` now records the optional `external.id` and `user_id` fields for incoming callbacks from the server-set request attributes `third_party_external_id` (string) and `third_party_user_id` (int) — read only from `$request->attributes`, never URL segments or request input. An empty `third_party_external_id` and a non-numeric `third_party_user_id` are both treated as null. Brings incoming logging to parity with outgoing calls, which already populate these via `HttpLogContext`.
- `http_logs.redaction` config with `allow` and `block` lists so consuming apps can tune redaction without forking the package, kept separate per surface under `redaction.headers` and `redaction.body`. `block` adds extra names to redact (word-matched, like the built-ins); `allow` exempts specific names even when a built-in or `block` rule matches (exact match, takes precedence). `SensitiveDataRedactor`/`PaymentRedactor` accept a `RedactionRules` value object per surface and are bound as singletons that read the config.
- Activity logging now redacts the `changes` and `metadata` maps at capture using the same rules as the HTTP logger (so model attribute diffs like `password`/`email` no longer reach Elasticsearch in clear text). Tunable via the new `activity_logs.redaction` `allow`/`block` config. `ActivityLogger` accepts a `SensitiveDataRedactor` and is bound with one built from the activity config.

### Changed

- `SensitiveDataRedactor` now redacts credential body keys `username`, `user_name`, `login`, `pwd`, `passwd`, `passphrase`, `pin`, and `otp` (in addition to the existing `password`).
- Header and body redaction now match secret **words** instead of exact names or raw substrings. Names are normalized (camelCase, kebab-case, dotted and spaced variants all fold to `snake_case`) and matched on word boundaries, so `accessToken`/`access-token`/`access_token` are all redacted while embedded matches like `key` in `monkey`/`keyword` are not.
  - Headers redact these words in any position — `authorization`, `cookie`, `signature`, `hmac`, `secret`, `password`, `passcode`, `credential`, `apikey` — and `token`/`key` only as the final word (so `x-api-key`, `postman-token`, `idempotency-key` are redacted but `x-token-type` is not). This catches vendor variants such as `x-asd-signature` and `x-functions-key`.
  - Body redacts `password`, `passwd`, `passphrase`, `passcode`, `secret`, `signature`, `hmac`, `authorization`, `credential` in any position (so `password_confirmation`, `webhook_secret`, `webhook_signature` are caught), and `token` only as the final word (so `access_token`/`csrf_token` are redacted but the non-secret `token_type`/`token_expires_in` stay visible). Short or ambiguous keys (`pin`, `pan`, `bin`, `cvv`, `key`, …) remain exact-match to avoid false positives like `shipping` or `keyword`.

## [1.0.0] - 2026-06-24

Initial stable release. Provides two independent subsystems on a shared Elasticsearch connection: HTTP traffic logging (outgoing third-party requests and incoming callbacks) and actor/model activity logging, with redaction at capture, queued indexing, sampling, pruning commands, and optional dashboards.

### Added

- GitHub Actions CI workflow running the test suite across PHP 8.2, 8.3, and 8.4 on both the lowest and highest supported dependency versions.
- Dependabot configuration for GitHub Actions and Composer dev dependencies.
- `composer test` script.

### Changed

- Raised the minimum `elasticsearch/elasticsearch` constraint to `^8.5`, the first release where `Client` implements the `ClientInterface` the package type-hints; earlier 8.x versions failed at container resolution.
- Added an explicit `guzzlehttp/psr7: ^2.0` requirement to guarantee the PSR-17 factory used by the Elasticsearch transport is present.

[Unreleased]: https://github.com/tsitsishvili/elastic-audit/compare/v4.1.0...HEAD
[4.1.0]: https://github.com/tsitsishvili/elastic-audit/compare/v4.0.0...v4.1.0
[4.0.0]: https://github.com/tsitsishvili/elastic-audit/compare/v3.2.0...v4.0.0
[3.2.0]: https://github.com/tsitsishvili/elastic-audit/compare/v.3.1.1...v3.2.0
[3.1.1]: https://github.com/tsitsishvili/elastic-audit/compare/v3.1.0...v.3.1.1
[3.1.0]: https://github.com/tsitsishvili/elastic-audit/compare/v3.0.3...v3.1.0
[3.0.3]: https://github.com/tsitsishvili/elastic-audit/compare/v3.0.2...v3.0.3
[3.0.2]: https://github.com/tsitsishvili/elastic-audit/compare/v3.0.1...v3.0.2
[3.0.1]: https://github.com/tsitsishvili/elastic-audit/compare/v3.0.0...v3.0.1
[3.0.0]: https://github.com/tsitsishvili/elastic-audit/compare/v2.5.0...v3.0.0
[2.5.0]: https://github.com/tsitsishvili/elastic-audit/compare/v2.4.0...v2.5.0
[2.4.0]: https://github.com/tsitsishvili/elastic-audit/compare/v2.3.0...v2.4.0
[2.3.0]: https://github.com/tsitsishvili/elastic-audit/compare/v2.2.0...v2.3.0
[2.2.0]: https://github.com/tsitsishvili/elastic-audit/compare/v2.1.0...v2.2.0
[2.1.0]: https://github.com/tsitsishvili/elastic-audit/compare/v1.0.0...v2.1.0
[1.0.0]: https://github.com/tsitsishvili/elastic-audit/releases/tag/v1.0.0
