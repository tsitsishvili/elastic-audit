# Contributing

This package is shared by internal Laravel applications. Keep changes small, explicit, and backward-compatible unless a major version is planned.

Before contributing, read the [Coding Standards](CODING_STANDARDS.md).

## Local Setup

Composer lock files are intentionally not committed for this library. Confirm the active PHP binary first, then resolve
dependencies for that runtime:

```bash
php -v
composer update
npm ci
```

If multiple PHP versions are installed, invoke Composer through the intended binary. For example, a Homebrew PHP 8.4
installation may use:

```bash
/opt/homebrew/opt/php@8.4/bin/php /usr/local/bin/composer update
```

Do not reuse a `vendor/` directory resolved by a newer PHP version when testing an older runtime. Either resolve
dependencies again with that runtime or use a clean container.

Validate Composer metadata:

```bash
composer validate --no-check-publish
composer audit --no-dev
composer analyse
composer format:test
```

Run the package tests after a test runner is configured (see
[Development / Testing](AUDIT_LOGS.md#development--testing)):

```bash
composer test
npm run build
```

With a disposable Elasticsearch cluster listening on port 9200, run the real integration smoke test:

```bash
ELASTICSEARCH_INTEGRATION=1 ELASTICSEARCH_URL=http://127.0.0.1:9200 \
    vendor/bin/phpunit tests/Integration/ElasticsearchSmokeTest.php
```

Generated files under `public/vendor/elastic-audit` are committed so Composer consumers do not need Node.js; rebuild
and commit them whenever dashboard templates, CSS, JavaScript, or frontend dependencies change.

## Development Guidelines

- Preserve the public API unless the change is released as a new major version.
- Keep logging failures isolated from application/provider behavior.
- Never store raw secrets, tokens, passwords, full payment data, or unredacted authorization headers.
- Do not resolve trusted log metadata from user-controlled request input.
- Keep config keys backward-compatible when possible.
- Add or update tests when changing logging behavior, redaction behavior, queue behavior, commands, or Elasticsearch mappings.
- Update [`README.md`](README.md) when installation, configuration, or usage changes.
- Update [`CHANGELOG.md`](CHANGELOG.md) for every notable change.

## Versioning

Use Git tags as Composer versions.

```bash
git tag v1.0.0
git push origin v1.0.0
```

Version rules:

- `PATCH`, for example `v1.0.1`: bug fixes only.
- `MINOR`, for example `v1.1.0`: backward-compatible features.
- `MAJOR`, for example `v2.0.0`: breaking changes.

Breaking changes include:

- Removing or renaming public classes, methods, config keys, commands, or contracts.
- Changing enum contract expectations.
- Changing indexed document shape in a way that breaks existing dashboards or queries.
- Changing queue behavior in a way consuming applications must adapt to.
- Changing redaction behavior in a way that affects security assumptions.

### Branch Policy

- `main` is the source of the latest stable release and must remain releasable.
- Use short-lived topic branches for features and fixes.
- Keep `vN.x` branches only when an older major is receiving intentional backports.
- Do not use a permanent `dev` branch as an integration branch; it obscures which line is releasable.
- Tag releases from `main`. Backport tags may be created from the corresponding supported maintenance branch.

## Release Checklist

Before tagging a release:

- Confirm the package installs in a consuming Laravel application.
- Run Composer validation.
- Run the runtime dependency audit.
- Run tests.
- Run static analysis and the formatting check.
- Run the PHP compatibility matrix and Elasticsearch 8/9 smoke matrix.
- Rebuild dashboard assets.
- Review redaction-sensitive changes carefully.
- Update `CHANGELOG.md`.
- Commit the release changes.
- Tag the release with a SemVer tag, for example `v1.0.0`.
- Push the tag to GitHub.

## Pull Request Checklist

Before requesting review:

- The change is scoped to one behavior or feature.
- Tests were added or updated when behavior changed.
- Documentation was updated when usage changed.
- `CHANGELOG.md` includes the change under `[Unreleased]`.
- No generated dependencies, IDE files, local env files, or logs are included.
- No secrets or real provider payloads are included in code, tests, or docs.
