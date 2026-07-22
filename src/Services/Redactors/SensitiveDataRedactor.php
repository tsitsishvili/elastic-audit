<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services\Redactors;

use Tsitsishvili\ElasticAudit\DataTransferObjects\RedactedHttpPayload;
use Tsitsishvili\ElasticAudit\DataTransferObjects\RedactionRules;

class SensitiveDataRedactor
{
    /**
     * How to store bodies that cannot be key-redacted because they do not
     * decode to a key/value structure (XML, plain text, scalar JSON):
     * 'metadata' keeps headers plus a hash of the raw body; 'preview' stores
     * the raw body untouched — every value in it is stored in clear text.
     */
    public const UNDECODABLE_MODE_METADATA = 'metadata';

    public const UNDECODABLE_MODE_PREVIEW = 'preview';

    /** Bodies larger than this are captured as headers-only (no decode/redact/hash). */
    public const DEFAULT_CAPTURE_MAX_BYTES = 1_048_576;

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

    /** Header values that are URLs and therefore need value-level sanitizing. */
    private const URL_VALUE_HEADERS = [
        'location',
        'content_location',
        'referer',
        'referrer',
    ];

    /** Headers that can contain a URL alongside directives or link metadata. */
    private const EMBEDDED_URL_HEADERS = [
        'link',
        'refresh',
    ];

    private const ERROR_MESSAGE_MAX_BYTES = 2048;

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
        RedactionRules $headers = new RedactionRules,
        RedactionRules $body = new RedactionRules,
        private readonly string $undecodableBodyMode = self::UNDECODABLE_MODE_METADATA,
        private readonly int $captureMaxBytes = self::DEFAULT_CAPTURE_MAX_BYTES,
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
            if ($this->isSensitiveHeader((string) $name)) {
                $result[$name] = '[REDACTED]';

                continue;
            }

            $normalizedName = $this->normalizeName((string) $name);

            $result[$name] = match (true) {
                in_array($normalizedName, self::URL_VALUE_HEADERS, true)     => $this->sanitizeUrlHeaderValue($value),
                in_array($normalizedName, self::EMBEDDED_URL_HEADERS, true) => $this->sanitizeEmbeddedUrlHeaderValue($value),
                default                                                      => $value,
            };
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
        if (! is_array($body)) {
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
        // mb_strcut caps by bytes without splitting a multibyte sequence.
        $storedStr = $truncated ? mb_strcut($body, 0, $maxBytes, 'UTF-8') : $body;
        $preview   = mb_strcut($body, 0, $previewBytes, 'UTF-8');
        $hash      = 'sha256:'.hash('sha256', $body);
        $decoded   = json_decode($storedStr, true);

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

        if ($rawBody === '') {
            return RedactedHttpPayload::empty($redactedHeaders);
        }

        // Oversized bodies are stored headers-only: decoding, redacting, and
        // hashing them would cost unbounded memory/CPU on the request path.
        if (strlen($rawBody) > $this->captureMaxBytes) {
            return RedactedHttpPayload::empty($redactedHeaders);
        }

        // Binary bodies expose no redactable structure; keep headers only.
        if (str_contains($rawBody, "\0") || ! mb_check_encoding($rawBody, 'UTF-8')) {
            return RedactedHttpPayload::empty($redactedHeaders);
        }

        // Decode → redact → re-encode so preview and hash are derived from redacted content,
        // ensuring no raw PII/secrets appear in bodyPreview or bodyHash.
        $decoded       = $this->decodeBody($headers, $rawBody);
        $redactedArray = is_array($decoded) ? $this->redactBody($decoded) : null;

        if ($redactedArray === null && $this->undecodableBodyMode !== self::UNDECODABLE_MODE_PREVIEW) {
            // The body cannot be key-redacted, so storing it would leak any
            // secret it carries. Keep only a raw-body hash for correlation.
            return new RedactedHttpPayload(
                headers: $redactedHeaders,
                body: null,
                bodyPreview: null,
                bodyHash: 'sha256:'.hash('sha256', $rawBody),
                bodyTruncated: false,
            );
        }

        $redactedString = $redactedArray !== null ? (string) json_encode($redactedArray) : $rawBody;

        $payload = $this->truncateAndHash($redactedString, $maxBytes, $previewBytes);

        return new RedactedHttpPayload(
            headers: $redactedHeaders,
            body: $payload->body,
            bodyPreview: $payload->bodyPreview,
            bodyHash: $payload->bodyHash,
            bodyTruncated: $payload->bodyTruncated,
        );
    }

    private function decodeBody(array $headers, string $rawBody): mixed
    {
        if ($this->isFormUrlEncoded($headers)) {
            parse_str($rawBody, $parsed);

            return $parsed;
        }

        return json_decode($rawBody, true);
    }

    private function isFormUrlEncoded(array $headers): bool
    {
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'Content-Type') !== 0) {
                continue;
            }

            $line = is_array($value) ? implode(';', array_map('strval', $value)) : (string) $value;

            return str_contains(strtolower($line), 'application/x-www-form-urlencoded');
        }

        return false;
    }

    /** Remove URI userinfo, query, and fragment components before storage. */
    public function sanitizeUrl(string $url): string
    {
        $url = explode('#', $url, 2)[0];
        $url = explode('?', $url, 2)[0];

        return (string) preg_replace(
            '~^((?:[a-z][a-z0-9+.-]*:)?//)[^/@\s]+@~i',
            '$1',
            $url,
        );
    }

    /**
     * Sanitize URLs and conservative credential-shaped values embedded in an
     * exception message without changing ordinary prose.
     */
    public function sanitizeErrorMessage(string $message): string
    {
        $message = (string) preg_replace_callback(
            '~(?:[a-z][a-z0-9+.-]*:)?//[^\s\'"<>]+~i',
            function (array $matches): string {
                $matched = $matches[0];
                $url     = rtrim($matched, '.,;!)]}');
                $suffix  = substr($matched, strlen($url));
                $marker  = str_contains($url, '?')
                    ? '?[REDACTED]'
                    : (str_contains($url, '#') ? '#[REDACTED]' : '');

                return $this->sanitizeUrl($url).$marker.$suffix;
            },
            $message,
        );

        // Also catch relative URL/path queries while leaving prose such as
        // "Retry?" untouched.
        $message = (string) preg_replace(
            '/(?<=[\w\/])\?[^\s\'"<>]+/',
            '?[REDACTED]',
            $message,
        );

        $message = (string) preg_replace(
            '~\b(authorization|proxy[-_ ]authorization)(\s*[:=]\s*)(?:(?:bearer|basic)\s+)?[^\s,;]+~i',
            '$1$2[REDACTED]',
            $message,
        );

        $message = (string) preg_replace(
            '~\b(password|passwd|passphrase|passcode|secret|api[_-]?key|token|access[_-]?token|refresh[_-]?token|credential|cookie|signature|hmac)(\s*[:=]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s,;]+)~i',
            '$1$2[REDACTED]',
            $message,
        );

        // JSON-style "key": value pairs, where the quote before the colon
        // defeats the key[:=]value pass above (e.g. a provider error body
        // echoed verbatim into the exception message).
        $message = (string) preg_replace(
            '~("(?:password|passwd|passphrase|passcode|secret|api[_-]?key|token|access[_-]?token|refresh[_-]?token|credential|cookie|signature|hmac|pan|cvv|cvc|card[_-]?number|card[_-]?holder|iban)"\s*:\s*)(?:"[^"]*"|-?\d+(?:\.\d+)?|true|false|null)~i',
            '$1"[REDACTED]"',
            $message,
        );

        $message = (string) preg_replace(
            '~\b(Bearer)(\s+)[A-Za-z0-9._\~+\/=:-]+~i',
            '$1$2[REDACTED]',
            $message,
        );

        return mb_strcut($message, 0, self::ERROR_MESSAGE_MAX_BYTES, 'UTF-8');
    }

    private function sanitizeUrlHeaderValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $key => $item) {
                $sanitized[$key] = $this->sanitizeUrlHeaderValue($item);
            }

            return $sanitized;
        }

        return is_string($value) ? $this->sanitizeUrl($value) : $value;
    }

    private function sanitizeEmbeddedUrlHeaderValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $key => $item) {
                $sanitized[$key] = $this->sanitizeEmbeddedUrlHeaderValue($item);
            }

            return $sanitized;
        }

        return is_string($value) ? $this->sanitizeErrorMessage($value) : $value;
    }
}
