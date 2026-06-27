# Changelog

All notable changes to this package are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/tsitsishvili/elastic-audit/compare/v2.3.0...HEAD
[2.3.0]: https://github.com/tsitsishvili/elastic-audit/compare/v2.2.0...v2.3.0
[2.2.0]: https://github.com/tsitsishvili/elastic-audit/compare/v2.1.0...v2.2.0
[2.1.0]: https://github.com/tsitsishvili/elastic-audit/compare/v1.0.0...v2.1.0
[1.0.0]: https://github.com/tsitsishvili/elastic-audit/releases/tag/v1.0.0
