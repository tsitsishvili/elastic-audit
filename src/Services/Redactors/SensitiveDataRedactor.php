<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services\Redactors;

use Tsitsishvili\ElasticAudit\DataTransferObjects\RedactedHttpPayload;

class SensitiveDataRedactor
{
    private const REDACTED_HEADERS = [
        'authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
        'api-key',
        'proxy-authorization',
    ];

    private const REDACTED_BODY_KEYS = [
        'password',

        'token',
        'access_token',
        'refresh_token',
        'id_token',
        'auth_token',
        'bearer_token',
        'authorization',

        'api_key',
        'apikey',
        'client_secret',
        'secret',
        'secret_key',
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

    public function redactHeaders(array $headers): array
    {
        $result = [];

        foreach ($headers as $name => $value) {
            $result[$name] = in_array(strtolower($name), self::REDACTED_HEADERS, true)
                ? '[REDACTED]'
                : $value;
        }

        return $result;
    }

    public function redactBody(mixed $body): mixed
    {
        if (!is_array($body)) {
            return $body;
        }

        $result = [];

        foreach ($body as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACTED_BODY_KEYS, true)) {
                $result[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $result[$key] = $this->redactBody($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
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
