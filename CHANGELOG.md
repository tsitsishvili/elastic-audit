# Changelog

All notable changes to this package are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- GitHub Actions CI workflow running the test suite across PHP 8.2, 8.3, and 8.4 on both the lowest and highest supported dependency versions.
- Dependabot configuration for GitHub Actions and Composer dev dependencies.
- `composer test` script.

### Changed

- Raised the minimum `elasticsearch/elasticsearch` constraint to `^8.5`, the first release where `Client` implements the `ClientInterface` the package type-hints; earlier 8.x versions failed at container resolution.
- Added an explicit `guzzlehttp/psr7: ^2.0` requirement to guarantee the PSR-17 factory used by the Elasticsearch transport is present.
