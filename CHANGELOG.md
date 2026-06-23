# Changelog

All notable changes to this package are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed (BREAKING) — package renamed `elastic-logger` → `elastic-audit`

The package was rebranded from its original "third-party HTTP logger" identity to
reflect that it now logs both third-party HTTP traffic **and** actor/model activity to
a dedicated Elasticsearch (audit) cluster. Consuming apps must update references.

- **Composer / namespace:** `tsitsishvili/elastic-logger` → `tsitsishvili/elastic-audit`;
  PHP namespace `Tsitsishvili\ElasticLogger\` → `Tsitsishvili\ElasticAudit\`.
- **Service provider:** `ElasticLoggerServiceProvider` → `ElasticAuditServiceProvider`.
- **Facade:** `ThirdPartyHttp` → `HttpLog` (`ActivityLog` unchanged).
- **HTTP subsystem classes** (now the `HttpLog*` family):
  - `ThirdPartyHttpManager` → `HttpLogManager`
  - `ThirdPartyHttpClientFactory` → `HttpLogClientFactory`
  - `ElasticLogger` (service) → `HttpLogger`
  - `ElasticLogIndexer` → `HttpLogIndexer`
  - `ThirdPartyHttpLogData` / `…LogContext` / `…LogMapping` → `HttpLogData` / `HttpLogContext` / `HttpLogMapping`
  - `ThirdPartyHttpDirection` → `HttpDirection`
  - `LogThirdPartyHttpRequestJob` → `LogHttpRequestJob`
  - `ThirdPartyCallbackLogMiddleware` → `IncomingHttpLogMiddleware`
  - `LogDashboardQuery` → `HttpLogDashboardQuery`; `DashboardController` → `HttpLogDashboardController`
- **Contracts:** `ThirdPartyProviderContract` / `ThirdPartyEventTypeContract` / `ThirdPartyEntityTypeContract`
  → `ProviderContract` / `EventTypeContract` / `EntityTypeContract`.
- **Published stub enums:** `App\Enums\ThirdPartyLogger\ThirdParty{Provider,EventType,EntityType}`
  → `App\Enums\ElasticAudit\{Provider,EventType,EntityType}`.
- **Config files / keys:** `config/elastic_logger.php` (`config('elastic_logger.*')`) → `config/http_logs.php`
  (`config('http_logs.*')`); `config/activity_logger.php` → `config/activity_logs.php`.
  The shared `config/log_elasticsearch.php` connection is unchanged.
- **Console commands:** `third-party-logs:create-index` / `third-party-logs:prune`
  → `http-logs:create-index` / `http-logs:prune`; `activity-logger:*` → `activity-logs:*`.
- **Environment variables:** `ELASTIC_HTTP_LOGS_*` → `HTTP_LOGS_*`; `ACTIVITY_LOGGER_*` → `ACTIVITY_LOGS_*`;
  `LOGGER_DASHBOARD_GROUP_PREFIX` → `ELASTIC_AUDIT_DASHBOARD_PREFIX`.
- **Index aliases:** the `_elastic_logger` / `_activity_logger` alias suffixes become
  `_http_logs` / `_activity_logs`.
- **Publish tags:** `elastic-logger` / `elastic-logger-views` → `elastic-audit` / `elastic-audit-views`.
- **Dashboard route-name prefixes:** `elastic-logger.*` → `http-logs.*`; `activity-logger.*` → `activity-logs.*`.
  The Blade view namespace `elastic-logger::` → `elastic-audit::`. (Dashboard URL path defaults are unchanged.)

### Fixed

- Corrected a pre-existing PSR-4 mismatch where `CreateThirdPartyHttpLoggerIndexCommand.php` and
  `PruneThirdPartyHttpLoggerCommand.php` declared classes named `*ElasticLoggerIndexCommand` /
  `*ElasticLoggerCommand`; file names and class names now agree.
- `CLAUDE.md` no longer references a non-existent `ThirdPartyHttpClient`/`HttpLogClient` class:
  `HttpLog::make()` returns a Laravel `PendingRequest`, and outgoing capture happens in
  `OutgoingHttpLogMiddleware`.
