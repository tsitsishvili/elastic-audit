<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services\Redactors;

use Tsitsishvili\ElasticAudit\DataTransferObjects\RedactedHttpPayload;
use Tsitsishvili\ElasticAudit\DataTransferObjects\RedactionRules;

class SensitiveDataRedactor
{
    /**
     * Secret words matched as whole words (in any position) inside a header
     * name. Matching is word-aware after normalization, so these never fire
     * mid-word — 'secret' does not match 'secretary'. Catches vendor variants
     * like 'x-asd-signature' and 'x-client-secret'.
     */
    private const REDACTED_HEADER_WORDS = [
        'authorization',
        'cookie',
        'signature',
        'hmac',
        'secret',
        'password',
        'passcode',
        'credential',
        'apikey',
    ];

    /**
     * Words redacted only when they are the final word of a header name, so a
     * qualifier prefix stays visible: 'x-api-key'/'postman-token' are redacted
     * but a header containing 'monkey' is not, and 'token-type' would not be.
     */
    private const REDACTED_HEADER_TRAILING_WORDS = [
        'token',
        'key',
    ];

    /**
     * Secret words matched as whole words (in any position) inside a body key.
     * Word-aware matching catches compound keys — 'password_confirmation',
     * 'webhook_secret', 'webhook_signature' — without firing mid-word.
     */
    private const REDACTED_BODY_WORDS = [
        'password',
        'passwd',
        'passphrase',
        'passcode',
        'secret',
        'signature',
        'hmac',
        'authorization',
        'credential',
    ];

    /**
     * 'token' is redacted only as the final word, so the secret-bearing
     * 'access_token'/'csrf_token' are redacted while the non-secret OAuth
     * metadata 'token_type'/'token_expires_in' stay visible.
     */
    private const REDACTED_BODY_TRAILING_WORDS = [
        'token',
    ];

    /**
     * Exact (normalized) body keys for short or ambiguous names that must not
     * be word-matched — 'pin' must not catch 'shipping', 'key' must not catch
     * 'monkey'/'keyword'. Compared after normalization so camelCase and
     * hyphenated variants ('cardNumber', 'card-number') match too.
     */
    private const REDACTED_BODY_KEYS = [
        'username',
        'user_name',
        'login',
        'pwd',
        'pin',
        'otp',

        'api_key',
        'apikey',
        'private_key',
        'public_key',
        'key',

        'card_number',
        'pan',
        'cvv',
        'cvc',
        'card_holder',
        'cardholder',
        'bin',
        'expiry',
        'expiry_date',
        'exp_date',
        'exp_month',
        'exp_year',

        'personal_id',
        'national_id',
        'id_number',
        'passport_number',
        'tax_id',

        'phone',
        'phone_number',
        'mobile',
        'mobile_number',

        'email',
        'recipient',

        'account_number',
        'iban',
        'bank_account',

        'birth_date',
        'date_of_birth',

        'session_id',
        'cookie',
    ];

    /** @var string[] */
    private array $headerAllow;
    /** @var string[] */
    private array $headerBlock;
    /** @var string[] */
    private array $bodyAllow;
    /** @var string[] */
    private array $bodyBlock;

    public function __construct(
        RedactionRules $headers = new RedactionRules(),
        RedactionRules $body = new RedactionRules(),
    ) {
        $this->headerAllow = array_map($this->normalizeName(...), $headers->allow);
        $this->headerBlock = array_map($this->normalizeName(...), $headers->block);
        $this->bodyAllow   = array_map($this->normalizeName(...), $body->allow);
        $this->bodyBlock   = array_map($this->normalizeName(...), $body->block);
    }

    public function redactHeaders(array $headers): array
    {
        $result = [];

        foreach ($headers as $name => $value) {
            $result[$name] = $this->isSensitiveHeader((string)$name)
                ? '[REDACTED]'
                : $value;
        }

        return $result;
    }

    private function isSensitiveHeader(string $name): bool
    {
        if (in_array($this->normalizeName($name), $this->headerAllow, true)) {
            return false;
        }

        return $this->matchesSecretWord(
            $name,
            [...self::REDACTED_HEADER_WORDS, ...$this->headerBlock],
            self::REDACTED_HEADER_TRAILING_WORDS,
        );
    }

    public function redactBody(mixed $body): mixed
    {
        if (!is_array($body)) {
            return $body;
        }

        $result = [];

        foreach ($body as $key => $value) {
            if (is_string($key) && $this->isSensitiveBodyKey($key)) {
                $result[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $result[$key] = $this->redactBody($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function isSensitiveBodyKey(string $key): bool
    {
        $normalized = $this->normalizeName($key);

        if (in_array($normalized, $this->bodyAllow, true)) {
            return false;
        }

        if (in_array($normalized, self::REDACTED_BODY_KEYS, true)) {
            return true;
        }

        return $this->matchesSecretWord(
            $key,
            [...self::REDACTED_BODY_WORDS, ...$this->bodyBlock],
            self::REDACTED_BODY_TRAILING_WORDS,
        );
    }

    /**
     * Normalize a header or key name to lower snake_case so camelCase,
     * kebab-case, dotted and spaced variants compare equal: 'accessToken',
     * 'access-token', 'access.token' and 'access_token' all become
     * 'access_token'.
     */
    private function normalizeName(string $name): string
    {
        $name = (string)preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);

        return strtolower((string)preg_replace('/[-.\s]+/', '_', $name));
    }

    /**
     * Word-aware match against secret words. The name is normalized first, then
     * patterns are matched on '_' word boundaries (with an optional plural 's'),
     * so they never fire mid-word. Words in $anywhere match in any position;
     * words in $trailing match only as the final word, keeping qualifier
     * prefixes like 'token_type' visible.
     *
     * @param string[] $anywhere
     * @param string[] $trailing
     */
    private function matchesSecretWord(string $name, array $anywhere, array $trailing): bool
    {
        $name = $this->normalizeName($name);

        if ($anywhere !== [] && preg_match($this->wordPattern($anywhere, '(?:_|$)'), $name) === 1) {
            return true;
        }

        return $trailing !== [] && preg_match($this->wordPattern($trailing, '$'), $name) === 1;
    }

    /**
     * Build a regex matching any of $words as a whole word (optionally plural)
     * starting on a '_' boundary and ending with $suffix.
     *
     * @param string[] $words
     */
    private function wordPattern(array $words, string $suffix): string
    {
        $group = implode('|', array_map(static fn (string $w): string => preg_quote($w, '/'), $words));

        return '/(?:^|_)(?:' . $group . ')s?' . $suffix . '/';
    }

    /**
     * Truncate, hash, and decode a string that has already been redacted.
     * Binary detection is included for direct callers (e.g. test utilities).
     */
    public function truncateAndHash(string $body, int $maxBytes, int $previewBytes): RedactedHttpPayload
    {
        if (str_contains($body, "\0") || !mb_check_encoding($body, 'UTF-8')) {
            return new RedactedHttpPayload(
                headers: [],
                body: null,
                bodyPreview: null,
                bodyHash: null,
                bodyTruncated: false,
            );
        }

        $byteLength = strlen($body);
        $truncated  = $byteLength > $maxBytes;
        $storedStr  = $truncated ? substr($body, 0, $maxBytes) : $body;
        $preview    = mb_substr($body, 0, $previewBytes);
        $hash       = 'sha256:' . hash('sha256', $body);
        $decoded    = json_decode($storedStr, true);

        return new RedactedHttpPayload(
            headers: [],
            body: is_array($decoded) ? $decoded : null,
            bodyPreview: $preview,
            bodyHash: $hash,
            bodyTruncated: $truncated,
        );
    }

    public function buildPayload(array $headers, string $rawBody, int $maxBytes, int $previewBytes): RedactedHttpPayload
    {
        $redactedHeaders = $this->redactHeaders($headers);

        if (str_contains($rawBody, "\0") || !mb_check_encoding($rawBody, 'UTF-8')) {
            return new RedactedHttpPayload(
                headers: $redactedHeaders,
                body: null,
                bodyPreview: null,
                bodyHash: null,
                bodyTruncated: false,
            );
        }

        // Decode → redact → re-encode so preview and hash are derived from redacted content,
        // ensuring no raw PII/secrets appear in bodyPreview or bodyHash.
        $decoded        = json_decode($rawBody, true);
        $redactedArray  = is_array($decoded) ? $this->redactBody($decoded) : null;
        $redactedString = $redactedArray !== null ? (string)json_encode($redactedArray) : $rawBody;

        $payload = $this->truncateAndHash($redactedString, $maxBytes, $previewBytes);

        return new RedactedHttpPayload(
            headers: $redactedHeaders,
            body: $payload->body,
            bodyPreview: $payload->bodyPreview,
            bodyHash: $payload->bodyHash,
            bodyTruncated: $payload->bodyTruncated,
        );
    }

    /**
     * Strip query strings from URLs embedded in exception messages to prevent
     * API keys passed as query params from leaking into error logs.
     */
    public function sanitizeErrorMessage(string $message): string
    {
        return (string)preg_replace('/\?[^\s\'"<>]*/i', '?[REDACTED]', $message);
    }
}
