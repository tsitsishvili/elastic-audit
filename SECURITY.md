# Security Policy

Elastic Audit handles headers, payloads, model changes, actor identifiers, and operational errors that may contain
sensitive data. Security reports are treated as a priority even when the affected behavior does not execute code.

## Supported Versions

Only the latest stable major release receives security fixes. The currently supported line is `4.x`. Older major
versions should be upgraded before reporting a version-specific problem unless their release notes explicitly state
otherwise.

## Reporting a Vulnerability

Do not open a public issue for a suspected vulnerability or include real credentials, access tokens, payment data, or
production payloads in a report. Use
[GitHub's private vulnerability reporting](https://github.com/tsitsishvili/elastic-audit/security/advisories/new).

Include:

- The affected package version and Laravel, PHP, Elasticsearch client, and Elasticsearch server versions.
- The smallest safe reproduction using synthetic values.
- The expected and observed redaction, authorization, retention, or failure behavior.
- Whether data was exposed in Elasticsearch, an application log, a queue payload, or the dashboard.

## Security Model

The package is designed to provide a searchable internal audit and debugging trail without allowing logging failures
to alter application behavior. It:

- Redacts configured sensitive fields before queue dispatch.
- Removes query strings, fragments, and URL credentials from stored URLs.
- Resolves incoming provider/event/entity metadata only from trusted server-side request attributes.
- Keeps dashboard routes behind application middleware and an explicit authorization callback.
- Bounds HTTP body capture and treats undecodable bodies as hash-only metadata by default.
- Emits sanitized failure diagnostics when an audit event cannot be captured or indexed.

The consuming application remains responsible for:

- TLS, credentials, network isolation, backups, encryption at rest, and least-privilege Elasticsearch roles.
- Queue durability, failed-job retention and alerting, and worker supervision.
- Dashboard authentication and authorization outside the `local` environment.
- Reviewing every redaction `allow` entry and every clear-text undecodable-body exception.
- Data classification, legal retention requirements, data-subject workflows, and incident response.

## Explicit Non-Guarantees

Elastic Audit is not, by itself, a tamper-evident or legally immutable compliance ledger. An administrator with
Elasticsearch write privileges can alter or delete documents, and a compromised application process can bypass or
forge application-level logging. Queue dispatch is best-effort and does not provide a transactional outbox guarantee.

Applications requiring evidence-grade immutability should combine the package with restricted append-only
credentials, independent cluster auditing, immutable backups or write-once storage, and an application-specific
delivery reconciliation process.
