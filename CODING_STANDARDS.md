# Coding Standards

These standards apply to `tsitsishvili/elastic-audit` and should be followed by all internal applications contributing to the package.

## PHP Version

- Use PHP 8.3+ syntax.
- Declare strict types in every PHP source and test file:

```php
<?php

declare(strict_types=1);
```

## Style

- Follow PSR-12 formatting.
- Prefer Laravel conventions where they are clearer than generic PHP patterns.
- Use typed properties, typed parameters, and typed return values.
- Prefer constructor property promotion for simple dependency assignment.
- Prefer `final readonly` DTOs for immutable value objects.
- Keep methods small and focused.
- Avoid unnecessary abstractions until there is clear reuse or complexity reduction.

## Naming

- Use descriptive names that match the package domain: provider, event type, entity, context, redactor, indexer, payload.
- Name config keys in `snake_case`.
- Name Artisan commands with the `http-logs:*` prefix.
- Name tests after observable behavior, for example `test_successful_request_dispatches_log_job`.

## Contracts and Public API

- Treat public classes, contracts, config keys, commands, and DTO constructor signatures as package API.
- Do not rename or remove public API without a major version release.
- Prefer adding optional behavior over changing existing behavior.
- Keep enum contracts simple and stable.

## Laravel Practices

- Use Laravel's container for package services.
- Register package bindings in the service provider.
- Use config values through `config(...)` so consuming apps can override behavior.
- Keep package commands idempotent when possible.
- Dispatch logging work to queues. Provider calls and callbacks should not index synchronously.

## Error Handling

- Logging must never break the consuming application's provider call or callback response.
- Catch and isolate failures in logging, redaction, queue dispatch preparation, and indexing failure callbacks.
- Preserve the original exception behavior of outgoing provider calls.

## Security and Redaction

- Never store raw authorization headers, tokens, passwords, API keys, full payment data, or sensitive personal data.
- Strip query strings from stored URLs.
- Sanitize exception messages before logging them.
- Do not trust provider, event type, or entity metadata from user-controlled route segments or request input.
- Payment providers must be registered in `payment_provider_values` so payment redaction is applied.

## Elasticsearch

- Keep index mappings backward-compatible whenever possible.
- Treat indexed document shape changes as potentially breaking.
- Use aliases for reads and writes.
- Keep index creation commands safe and idempotent.
- Do not point logs to the product-search Elasticsearch cluster.

## Tests

Add or update tests when changing:

- Outgoing HTTP logging behavior.
- Incoming callback middleware behavior.
- Redaction behavior.
- Queue dispatching or job retry behavior.
- Elasticsearch indexing or pruning behavior.
- Config defaults or config resolution.

Prefer focused tests that assert behavior instead of implementation details.

## Documentation

Update documentation when changing:

- Installation steps.
- Config keys or defaults.
- Environment variables.
- Public API usage.
- Commands.
- Versioning or release workflow.

Update `CHANGELOG.md` under `[Unreleased]` for every notable change.

## Git Hygiene

- Do not commit `vendor/`, `.idea/`, local `.env` files, caches, logs, or generated coverage output.
- Keep commits focused.
- Use semantic version tags for releases.
- Include migration notes in `CHANGELOG.md` for breaking changes.
