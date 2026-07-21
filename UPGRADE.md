# Upgrade Guide

This document lists notable behavior changes and any steps required when upgrading.
For the full list of changes see the [Changelog](CHANGELOG.md).

Changes are tagged by **likelihood of impact** so you can quickly find what affects you.

## Upgrading from 3.2.0

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
php artisan http-logs:create-index
php artisan activity-logs:create-index
php artisan elastic-audit:health --all
```

Run only the create-index commands for enabled subsystems. Existing physical indices remain attached to the read alias
and keep their old numeric mapping. Reindex old documents and detach the old indices when all queries must see one
uniform field type. In particular, an activity dashboard UUID `actor_id` filter can fail or return partial results while
the read alias spans both the old `long` mapping and the new `keyword` mapping.

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
