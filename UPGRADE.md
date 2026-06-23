# Upgrade Guide

## From `tsitsishvili/elastic-logger` to `tsitsishvili/elastic-audit`

This is the package's rename from its original "third-party HTTP logger" identity to
`elastic-audit`. It is a **breaking** change: composer name, PHP namespace, config files,
config keys, console commands, environment variables, public classes, and the facade all
changed. Follow the steps below in a consuming application.

> See [CHANGELOG.md](CHANGELOG.md) for the complete old → new mapping.

### 1. Swap the Composer package

```bash
composer remove tsitsishvili/elastic-logger
composer require tsitsishvili/elastic-audit
```

If you pin packages in `composer.json`, update the constraint there and run `composer update`.
The service provider and facades are auto-discovered — no manual registration needed.

### 2. Update PHP references

Replace the namespace and renamed symbols across your app code:

| Old | New |
| --- | --- |
| `Tsitsishvili\ElasticLogger\…` | `Tsitsishvili\ElasticAudit\…` |
| `ThirdPartyHttp` facade | `HttpLog` facade |
| `ThirdPartyProviderContract` / `ThirdPartyEventTypeContract` / `ThirdPartyEntityTypeContract` | `ProviderContract` / `EventTypeContract` / `EntityTypeContract` |
| `App\Enums\ThirdPartyLogger\ThirdParty{Provider,EventType,EntityType}` (published stubs) | `App\Enums\ElasticAudit\{Provider,EventType,EntityType}` |

A project-wide find/replace usually covers it. For example:

```bash
# from your application root — review the diff before committing
grep -rl 'Tsitsishvili\\ElasticLogger' app config | xargs sed -i '' 's/Tsitsishvili\\ElasticLogger/Tsitsishvili\\ElasticAudit/g'
```

If you implemented the three caller-provided enums, move them from
`app/Enums/ThirdPartyLogger/` to `app/Enums/ElasticAudit/` (or re-publish the stubs — step 4)
and update their `implements` clauses to the renamed contracts.

### 3. Rename config files and keys

| Old | New |
| --- | --- |
| `config/elastic_logger.php` (`config('elastic_logger.*')`) | `config/http_logs.php` (`config('http_logs.*')`) |
| `config/activity_logger.php` (`config('activity_logger.*')`) | `config/activity_logs.php` (`config('activity_logs.*')`) |
| `config/log_elasticsearch.php` | unchanged |

Rename your published config files and update any `config('elastic_logger.…')` /
`config('activity_logger.…')` calls in your app accordingly. Update the registered enum
class names under `http_logs.enums.*` to the new `App\Enums\ElasticAudit\*` paths.

### 4. Re-publish (recommended)

```bash
php artisan vendor:publish --tag=elastic-audit          # config + enum stubs
php artisan vendor:publish --tag=elastic-audit-views    # dashboard Blade views (only if customized)
```

(The old publish tags were `elastic-logger` / `elastic-logger-views`.)

### 5. Update environment variables

| Old | New |
| --- | --- |
| `ELASTIC_HTTP_LOGS_*` (e.g. `ELASTIC_HTTP_LOGS_ENABLED`, `_QUEUE`, `_SAMPLE_RATE`, `_DASHBOARD_PATH`, …) | `HTTP_LOGS_*` |
| `ACTIVITY_LOGGER_*` | `ACTIVITY_LOGS_*` |
| `LOGGER_DASHBOARD_GROUP_PREFIX` | `ELASTIC_AUDIT_DASHBOARD_PREFIX` |
| `LOG_ELASTICSEARCH_*` | unchanged |

Update `.env`, `.env.example`, and any deployment/secret stores.

### 6. Update console commands and schedules

| Old | New |
| --- | --- |
| `third-party-logs:create-index` | `http-logs:create-index` |
| `third-party-logs:prune` | `http-logs:prune` |
| `activity-logger:create-index` | `activity-logs:create-index` |
| `activity-logger:prune` | `activity-logs:prune` |

Update any `$schedule->command(...)` calls and CI/cron entries.

### 7. Recreate the Elasticsearch indices/aliases

The index-alias suffixes changed (`_elastic_logger` → `_http_logs`,
`_activity_logger` → `_activity_logs`), so the package now reads/writes **new aliases**:

```bash
php artisan http-logs:create-index
php artisan activity-logs:create-index
```

Your historical data under the old aliases is **not** read by the renamed package. If you
need it, either reindex the old indices into the new aliases, or add the old physical
indices to the new read alias in Elasticsearch. New deployments can skip this.

### 8. Update route-name references (if any)

If your app or views reference the dashboard route names, they changed:

| Old | New |
| --- | --- |
| `route('elastic-logger.*')` | `route('http-logs.*')` |
| `route('activity-logger.*')` | `route('activity-logs.*')` |

The dashboard's default URL **path** also moved from `/…/third-party` to `/…/http-logs`
(override via `HTTP_LOGS_DASHBOARD_PATH` to keep the old URL). The Blade view namespace
changed from `elastic-logger::` to `elastic-audit::`.

### 9. Verify

```bash
php artisan config:clear
php artisan route:list   # confirm http-logs.* / activity-logs.* route names
```

Exercise an outgoing call and an incoming callback in a non-production environment and
confirm documents land under the new aliases.
