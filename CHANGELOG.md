# Changelog

All notable changes to this package are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
