# Upgrade Guide

This document lists notable behavior changes and any steps required when upgrading.
For the full list of changes see the [Changelog](CHANGELOG.md).

Changes are tagged by **likelihood of impact** so you can quickly find what affects you.

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
> non-secret `token_type` is kept). See [Redaction Notes](README.md#redaction-notes).

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
