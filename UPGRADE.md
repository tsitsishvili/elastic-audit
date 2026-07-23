# Upgrade Guide

This document lists notable behavior changes and any steps required when upgrading.
For the full list of changes see the [Changelog](CHANGELOG.md).

Changes are tagged by **likelihood of impact** so you can quickly find what affects you.

## Upgrading from 4.0.0 to 4.1.0

### Low impact: audit failures are now observable

Complete capture/preparation/queue-dispatch failures and indexing jobs that exhaust their retries now write a sanitized
application error and dispatch `Tsitsishvili\ElasticAudit\Events\AuditOperationFailed`. The event contains no raw
exception or audit payload. Existing application/provider behavior remains non-blocking, and applications that do not
listen for the event require no change.

### Low impact: health validates mappings and supports JSON

The health command now checks the current write-index and index-template mappings when the built-in Elasticsearch
client is used. Run the relevant create-index command if health reports an incompatible write mapping or template:

```bash
php artisan http-logs:create-index
php artisan activity-logs:create-index
php artisan elastic-audit:health
```

New indexes/templates include Elastic Audit schema metadata. Existing structurally compatible v4 mappings without that
metadata continue to pass. `php artisan elastic-audit:health --json` is available for deployment automation.

## Upgrading from 3.2.0

### High impact: review HTTP body capture and redaction defaults

Bodies that do not decode to a JSON or form key/value structure (for example XML/SOAP, plain text, and scalar JSON)
are no longer stored as raw previews by default. Because the package cannot redact them by field name,
`HTTP_LOGS_UNDECODABLE_BODY_MODE=metadata` stores only redacted headers and a `sha256:` hash of the raw body. Set the
mode to `preview` only for providers whose undecodable bodies are known to contain no secrets; it restores clear-text
storage.

Capture is also bounded by `HTTP_LOGS_BODY_CAPTURE_MAX_BYTES` (default 1 MB). Larger bodies are captured headers-only
without being decoded or hashed in memory. Empty, binary, streamed, unreadable, and multipart bodies likewise remain
headers-only. UTF-8 previews and truncation no longer split a multibyte character.

**What you need to do:** publish or add both new settings, review integrations that depend on XML/plain-text previews,
and run the health command after deployment:

```dotenv
HTTP_LOGS_BODY_CAPTURE_MAX_BYTES=1048576
HTTP_LOGS_UNDECODABLE_BODY_MODE=metadata
```

### Medium impact: successful callback capture runs after the response

`IncomingHttpLogMiddleware` now queues successful callbacks from its `terminate()` method after the response is sent.
Failed callbacks are still captured inline so exception class, sanitized message, and Symfony HTTP status are
preserved. Logging failures cannot replace a successful callback response or the handler's original exception.

Laravel's HTTP kernel calls terminable middleware automatically. Direct middleware unit tests that previously expected
a job immediately after `handle()` must call `terminate($request, $response)` before asserting dispatch.

### Medium impact: activity events wait for database commit

Single and batch activity jobs now implement Laravel's after-commit queue contract. An event emitted inside a database
transaction is dispatched only when that transaction commits; a rollback intentionally produces no activity document.
If an application uses activity logs as attempted-operation records, emit an explicit failure event outside the rolled
back transaction instead.

`ActivityLoggable` also suppresses the ordinary deleted event during a force delete, so a force-deleted model produces
one `{entity}.force_deleted` record instead of two records. Exceptions from custom actor/entity/metadata hooks are
isolated and can no longer prevent model persistence.

### Medium impact: Elasticsearch document ids are now raw ULIDs

HTTP and activity indexers now use each DTO's `event_id` ULID directly as Elasticsearch `_id`, replacing its SHA-256
hash. Queue retries remain idempotent, and separate HTTP calls sharing one correlation `request_id` remain distinct.
Update external tools that construct a hashed `_id`; querying by `event_id` continues to work across old and new
documents. Before deploying this change, drain or pause workers running the old package version: an ambiguous job first
indexed with the old hashed `_id` and replayed after the upgrade can otherwise create a second document under the raw
ULID `_id`.

### Medium impact: canonical index names and safer topology defaults

Newly published subsystem configs leave `index_alias` and `index_alias_write` as `null`. Package registration derives
them from one canonical `log_elasticsearch.index_prefix`; the lifecycle policy name is derived the same way when null.
Existing published non-empty values remain explicit overrides. The `APP_NAME` fallback is now slugged (`Example App`
becomes `example_app`), while invalid derived or explicitly configured alias names are rejected by health and
create-index commands.

The default replica count changes from `0` to `1` for new configurations. Keep or set
`LOG_ELASTICSEARCH_REPLICAS=0` only for an intentional single-node cluster. Re-running a create-index command with an
existing write alias now uses Elasticsearch rollover instead of manually moving the alias.

**What you need to do:** compare published config with the new defaults. Set existing aliases/policy to `null` if they
should follow a changed shared prefix, choose the replica count for the actual cluster topology, then run:

```bash
php artisan elastic-audit:lifecycle-policy
php artisan http-logs:create-index       # when HTTP logs are enabled
php artisan activity-logs:create-index   # when activity logs are enabled
php artisan elastic-audit:health
```

The plain health command validates enabled subsystems. Use `--all` only when aliases for disabled subsystems have also
been provisioned and should be checked.

### Low impact: sanitization and retention failures are stricter

Stored URLs now remove userinfo, query, and fragment components. URL-valued response headers and exception messages are
sanitized for URL secrets and common credential key/value forms, and diagnostic messages are capped at 2048 bytes.
Sensitive activity changes keep their `{old, new}` structure with both values redacted; malformed legacy diffs render
defensively in the dashboard. Dashboard failures show a generic message while the real Elasticsearch exception goes to
the application log.

Prune commands now composite-page all retention values and fail on timed-out searches, failed shards, version
conflicts, or partial delete failures. Treat a new non-zero scheduler/deploy result as incomplete retention work that
needs investigation, not as an empty data set.

### Low impact: dependency and distribution metadata are stricter

The package now declares `guzzlehttp/promises` and `psr/http-message` directly, requires
`guzzlehttp/guzzle ^7.15.1`, and drops the unused direct `guzzlehttp/psr7` requirement. Publishable `App\Enums`
templates moved outside the package PSR-4 source tree; the `vendor:publish` destination is unchanged. If the consuming
application locks an older Guzzle release, update dependencies while installing v4. CI now validates optimized strict
PSR-4 loading, PHP syntax, runtime advisories, and real Elasticsearch 8/9 operations.

### High impact: user and actor ids now support strings and UUIDs

`HttpLogContext::$userId` and `ActivityLogContext::$actorId` now accept `int|string|null`. The corresponding data DTOs
preserve the supplied PHP value, including UUIDs and other string identifiers. `ActivityLoggable` also preserves string
values returned by `Auth::id()` or an `activityActor()` override instead of discarding them.

Elasticsearch stores HTTP `user_id` and activity `actor.id` as keyword strings. Their mappings changed from `long` to
`keyword`, which Elasticsearch cannot apply to an existing field in place. The HTTP and activity document schema
versions are now 4 and 3 respectively.

**What you need to do:** before resuming queue workers or emitting string/UUID ids, create a new physical index for
each enabled subsystem so its write alias targets the new mapping:

```bash
php artisan http-logs:create-index       # when HTTP logs are enabled
php artisan activity-logs:create-index   # when activity logs are enabled
php artisan elastic-audit:health
```

Run only the create-index commands for enabled subsystems. Existing physical indices remain attached to the read alias
and keep their old numeric mapping. Reindex old documents and detach the old indices when all queries must see one
uniform field type. In particular, an activity dashboard UUID `actor_id` filter can fail or return partial results while
the read alias spans both the old `long` mapping and the new `keyword` mapping.

### Medium impact: retention defaults are configurable and validated

`HttpLogContext::forEntity()` and `ActivityLogContext::forActor()` now read `http_logs.retention_days` and
`activity_logs.retention_days` when no explicit value is supplied. Both defaults remain `360`. Finite values are now
validated against the Elasticsearch `short` mapping range (`1`–`32767`) instead of accepting values that cannot be
indexed safely.

The new `HTTP_LOGS_RETAIN_FOREVER` and `ACTIVITY_LOGS_RETAIN_FOREVER` settings make new documents permanent by default.
Individual contexts can pass `retainForever: true`; an explicit `retentionDays` overrides a permanent subsystem default,
but passing both options on the same context is invalid. Permanent documents store a null `retention_days` and are
ignored by prune commands.

ILM retention is independent. To keep permanent documents after rollover, set
`LOG_ELASTICSEARCH_LIFECYCLE_DELETE_ENABLED=false` and rerun:

```bash
php artisan elastic-audit:lifecycle-policy
php artisan elastic-audit:health
```

Disabling only the delete phase preserves rollover. Existing documents are not rewritten; pause pruning and migrate
their numeric `retention_days` values if historical data must also become permanent.

### Low impact: dashboard assets use same-origin URLs

Package-served CSS and JavaScript URLs are now root-relative. This prevents mixed-content failures when Laravel runs
behind a TLS-terminating proxy that does not forward the original request scheme. No application change is required.

## Upgrading from 3.1.1

### Low impact: agent resources are available

No action is required. The package now ships Laravel Boost guidelines, an `elastic-audit-development` Agent Skill, and
a standalone `AGENTS.md` guide. Nothing is enabled automatically and no runtime behavior changes.

To use them, see [AI Agents](README.md#ai-agents). Applications using Laravel Boost can run
`php artisan boost:update --discover` and select `tsitsishvili/elastic-audit (guidelines, skills)`. Applications not
using Boost can read `vendor/tsitsishvili/elastic-audit/AGENTS.md` directly, or run:

```bash
php artisan vendor:publish --tag=elastic-audit-ai
```

If you publish that tag **and** use Laravel Boost, Boost treats the published `.ai/skills/elastic-audit-development`
copy as a user-owned skill and prefers it over the package's own copy. Refresh it with `--force` after upgrades, or
remove it and let Boost read the skill from the package.

## Upgrading from 3.1.0

### Low impact: dashboard asset publishing is optional

No action is required. Dashboard CSS and JavaScript are now served automatically from the Composer package through a
manifest-allowlisted route. Existing files under `public/vendor/elastic-audit` remain compatible and may be kept for
direct web-server or CDN delivery.

## Upgrading from 3.0.3

### Medium impact: dashboard assets are now local

The HTTP and activity dashboards no longer load Tailwind CSS, Alpine.js, or Chart.js from third-party CDNs. Current
package versions serve the bundled assets automatically, so publishing is not required. A static copy remains optional:

```bash
php artisan vendor:publish --tag=elastic-audit-assets --force
```

New installations that run `php artisan vendor:publish --tag=elastic-audit` also receive this static copy.

## Upgrading from 2.x to 3.x

### High impact: lifecycle is now the default retention path

Newly published `log_elasticsearch.php` configs default `LOG_ELASTICSEARCH_LIFECYCLE_ENABLED=true` and
`LOG_ELASTICSEARCH_LIFECYCLE_DELETE_AFTER=360d`. New indexes created with lifecycle enabled receive ILM rollover/delete
settings. The prune commands remain available when ILM is disabled, when you need per-document `retention_days`, or as
a manual cleanup fallback.

**What you need to do:** run `php artisan elastic-audit:lifecycle-policy` before creating new HTTP/activity indexes.
If your cluster does not support ILM or you intentionally rely on prune commands, set
`LOG_ELASTICSEARCH_LIFECYCLE_ENABLED=false` and continue scheduling `http-logs:prune` / `activity-logs:prune`.

### Medium impact: health checks are stricter

`elastic-audit:health` now fails when enabled HTTP logs have missing/invalid enum classes, when log job retry settings
are invalid, or when lifecycle is enabled without rollover/delete configuration.

**What you need to do:** add the health command to deploy checks after publishing config. Register valid
`http_logs.enums.*` classes if HTTP logging is enabled.

### Low impact: log job retry settings are configurable

HTTP and activity jobs now read attempts, backoff, timeout, and batch timeout from config. Defaults match previous
behavior: `tries=3`, `backoff=10,30,120`, single job timeout `30`, batch job timeout `60`.

**What you need to do:** usually nothing. Tune `HTTP_LOGS_JOB_*` or `ACTIVITY_LOGS_JOB_*` env values if your
Elasticsearch cluster needs longer indexing timeouts or different retry pacing.

### Low impact: HTTP enum contracts now use BackedEnum values directly

`ProviderContract`, `EventTypeContract`, and `EntityTypeContract` now extend PHP's `BackedEnum` and no longer require a
`getValue()` method. The package reads enum `->value` directly.

**What you need to do:** if your application enums already define `getValue()`, they can keep it. New enums only need
to be string-backed enums implementing the package contract. Non-enum classes can no longer implement these contracts.

### Medium impact: prune commands now fail loudly

`http-logs:prune` and `activity-logs:prune` now return a non-zero exit code when Elasticsearch cannot fetch
`retention_days` buckets or a `delete_by_query` operation fails. Previously those failures were logged but the command
could still exit successfully.

**What you need to do:** if these commands are scheduled in CI, cron, or a deploy pipeline, treat a failure as a real
retention problem and inspect the command output/application logs.

### Medium impact: HTTP log document IDs changed

HTTP log indexing now uses `event_id` as the Elasticsearch document id. This prevents separate provider calls with the
same correlation `request_id` from overwriting each other.

**What you need to do:** usually nothing. Existing documents remain unchanged. If you have external tooling that
derived Elasticsearch ids from `request_id|attempt`, update it to use `event_id`.

### Low impact: enum config defaults are now null

The default `http_logs.enums.*` config values are now `null`, matching the documentation. Missing or invalid enum
classes make incoming callback logging skip the event instead of throwing.

**What you need to do:** published configs should explicitly register your application enum classes if you use
`IncomingHttpLogMiddleware`.

### Low impact: lifecycle policy command works with Elasticsearch PHP v9

`elastic-audit:lifecycle-policy` now sends the ILM policy name using the Elasticsearch client's required `policy`
parameter. The command can be run while `LOG_ELASTICSEARCH_LIFECYCLE_ENABLED=false`; it will warn, but still creates or
updates the policy. New indexes only use the policy when lifecycle is enabled before index creation.

**What you need to do:** update the package in CI/tester repositories that failed with `The parameter policy is
required`, then rerun `php artisan elastic-audit:lifecycle-policy`.

## Upgrading from 1.0.0

### High impact: more data is redacted from logs

Redaction was expanded and made smarter. Fields that previously appeared in your logs may now
be stored as `[REDACTED]`:

- **Headers** are matched as whole words (after `camelCase`/`kebab-case` normalization) instead of
  an exact allow-list, so vendor-prefixed/suffixed headers are now redacted — e.g. `x-ads-signature`,
  `postman-token`, `x-csrf-token`, `x-client-secret`, `x-api-key`, `idempotency-key`.
- **Request/response bodies** now redact `username` and related credential keys (`user_name`, `login`,
  `pwd`, `passwd`, `passphrase`, `pin`, `otp`), plus compound keys via word matching such as
  `password_confirmation`, `webhook_secret`, `csrf_token`, and `webhook_signature`.
- **Activity logs** now redact the `changes` and `metadata` maps by key name. Previously these were
  stored verbatim, so a model's `password`/`email` attribute diffs reached Elasticsearch in clear text;
  they are now redacted.

**What you need to do:** usually nothing — this is a security improvement. Only **new** documents are
affected; existing indexed documents are untouched. If you depend on a specific field staying visible,
add it to the relevant `allow` list (see below).

> Word matching is precise: built-in words only match on word boundaries (`key` does not match `monkey`
> or `keyword`), and `token`/`key` match only as the final word (`access_token` is redacted, but the
> non-secret `token_type` is kept). See [Redaction Notes](AUDIT_LOGS.md#redaction-notes).

### New, optional: configurable redaction

You can now tune redaction per surface without forking the package. New config keys:

- `http_logs.redaction.headers.allow` / `.block`
- `http_logs.redaction.body.allow` / `.block`
- `activity_logs.redaction.allow` / `.block` (single flat list — activity events have no headers)

`block` adds extra names to redact (matched as whole words, like the built-ins). `allow` exempts a
specific name from redaction even when a built-in or `block` rule matches (exact match, takes
precedence — anything listed is stored in clear text).

```php
// config/http_logs.php
'redaction' => [
    'headers' => ['block' => ['x-internal-trace'], 'allow' => []],
    'body'    => ['block' => ['customer_reference'], 'allow' => ['email']],
],
```

**What you need to do:** nothing to keep the defaults — the new keys default to empty and are merged
automatically. If you **published** the config files before upgrading, re-publish or add the new
`redaction` blocks to customize them:

```bash
php artisan vendor:publish --tag=elastic-audit --force
```

### Low impact: redactor and logger constructor signatures

Backward compatible — only relevant if you construct these services manually.

- `SensitiveDataRedactor::__construct()` now accepts two optional `RedactionRules` arguments
  (`headers:` and `body:`). `new SensitiveDataRedactor()` still works unchanged.
- `ActivityLogger::__construct()` now accepts an optional `SensitiveDataRedactor`.
  `new ActivityLogger()` still works unchanged.

In normal use both are resolved from the container, which injects instances built from your config, so
no action is required.
