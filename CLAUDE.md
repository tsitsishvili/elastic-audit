# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A **Laravel package** (`tsitsishvili/elastic-audit`, namespace `Tsitsishvili\ElasticAudit\`, PSR-4 from `src/`) — not an application. It is consumed by internal apps to log third-party HTTP traffic and user/actor activity to a dedicated Elasticsearch cluster. There is no app skeleton; behavior is exercised through Orchestra Testbench.

## Git

Do not commit changes. Leave all changes staged or unstaged in the working tree for the user to review and commit themselves. Do not run `git commit` (or `git push`) unless the user explicitly asks in that message.

## Architecture

### Two independent subsystems, one shared spine

1. **HTTP logger** (stable) — logs outgoing provider calls and incoming callbacks.
2. **Activity logger** — logs actor actions and Eloquent model changes. Both subsystems live in `src/`; there is no separate branch or docs tree.

They are deliberately decoupled: they share only `LogElasticsearchClientInterface` and `ElasticAuditServiceProvider`. Each has its own config file, ES index/aliases, DTOs, job, indexer, commands, and dashboard route group. When extending one, do not couple it to the other.

### The capture → queue → index pipeline (both subsystems mirror this)

```
Service (Logger)  →  immutable *LogData DTO  →  Log*Job (queued)  →  *Indexer  →  LogElasticsearchClientInterface  →  ES write alias
   ^ caller-facing      ^ built at capture        ^ async               ^ builds the ES document
```

Why it matters when editing any link:

- **Capture must never throw.** Every capture path — the outgoing `OutgoingHttpLogMiddleware` (attached to the Guzzle handler stack of the `PendingRequest` from `HttpLog::make()`), `HttpLogger::logIncoming`, and `ActivityLogger::record` — is gated by an `enabled` flag (plus `sample_rate` for HTTP) and wrapped in `try { … } catch (Throwable) {}`. A logging failure must never break the consuming app's provider call or callback response. Preserve this invariant — and re-throw the *original* provider exception after logging (see `OutgoingHttpLogMiddleware::__invoke`).
- **Redaction happens at capture, before queueing — not in the job.** `SensitiveDataRedactor::buildPayload` decodes JSON → redacts header/body keys → re-encodes, so `bodyPreview` and `bodyHash` are derived from already-redacted content (raw secrets never reach the queue or ES). URLs are stripped of query strings; exception messages are sanitized. Payment providers (registered in `payment_provider_values`) get `PaymentRedactor`. Binary/non-UTF-8 bodies are dropped to null.
- **The job only indexes.** Jobs (`tries=3`, backoff `[10,30,120]`) carry the finished DTO and call the indexer in `handle()`. The DTO is `final readonly` and `SerializesModels`-safe.
- **Indexers write to aliases, never physical indexes**, and set a deterministic document ID: HTTP uses `sha256(requestId|attempt)` (so retries dedup per attempt), activity uses `sha256(eventId)`.

### Caller-provided enums (the main extension contract)

The package ships no concrete providers/event types/entities. Consuming apps implement three single-method contracts — `ProviderContract`, `EventTypeContract`, `EntityTypeContract` (each `getValue(): string`, typically a backed enum) — and register the class names under `http_logs.enums.*`. Stub enums are published to the consuming app. In code, resolve enums only from config + server-set values; **never trust provider/event/entity values from URL segments or request input** (see the `resolve*` methods in `IncomingHttpLogMiddleware`). Tests use the fixtures in `tests/Fixtures/Test*.php`.

### Public surfaces

- `HttpLog::make($provider, $eventType, $context)` returns a native Laravel `PendingRequest` (built by `HttpLogClientFactory`) with `OutgoingHttpLogMiddleware` on its Guzzle handler stack — so the full `Http` client API works, `Http::fake()` works in tests, and no request can bypass logging. Use `HttpLog::logIncoming(...)` or `IncomingHttpLogMiddleware` for callbacks.
- Activity: `ActivityLog::record(...)` and the `ActivityLoggable` Eloquent trait (auto-diffs on created/updated/deleted).
- Dashboards are optional, gated by `<config>.dashboard.enabled`, served under a configurable path. `AuthorizeDashboard` middleware is always appended after the app's stack. Read-side logic lives in the `*DashboardQuery` classes (which clamp page size and whitelist sort fields).

### Service provider wiring

`ElasticAuditServiceProvider` is the only provider: it `mergeConfigFrom` each config, binds the shared ES client + each indexer/query as singletons (injecting the configured write/read alias), registers `http-logs:*` (and activity) commands, registers dashboard route groups, and declares publish groups (`elastic-audit`, `elastic-audit-views`).

## Conventions and guardrails

These are enforced by `CODING_STANDARDS.md` — read it before non-trivial changes.

- **Treat public classes, contracts, config keys, command signatures, and DTO constructor signatures as package API.** Don't rename/remove without a major version; prefer adding optional behavior over changing existing behavior. Update `CHANGELOG.md` under `[Unreleased]` for any notable change.
- **ES mappings and indexed document shape are potentially breaking.** Keep mappings backward-compatible; mappings are `dynamic: strict`. Bump `*LogData::SCHEMA_VERSION` when the document shape changes.
- Never point logs at the product-search Elasticsearch cluster; this package uses its own `log_elasticsearch.php` connection.
- `declare(strict_types=1)` in every PHP file; PSR-12; `final readonly` DTOs; constructor property promotion; Artisan commands prefixed `http-logs:*` (or `activity-logs:*`); tests named after observable behavior (`test_successful_request_dispatches_log_job`).
- Add/update tests when touching HTTP logging, callback middleware, redaction, queue/job behavior, ES indexing/pruning, or config resolution.
