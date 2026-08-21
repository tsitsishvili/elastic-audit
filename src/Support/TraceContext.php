<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Support;

final readonly class TraceContext
{
    public function __construct(
        public ?string $traceId = null,
        public ?string $spanId = null,
        public ?string $traceParent = null,
        public ?bool $sampled = null,
        public ?string $traceState = null,
    ) {}

    /** Resolve and validate W3C trace context from HTTP headers. */
    public static function fromTraceParent(?string $traceParent, ?string $traceState = null): self
    {
        $traceParent = self::normalizeHeader($traceParent);
        $traceState  = self::normalizeTraceState($traceState);

        if ($traceParent === null) {
            return new self(traceState: $traceState);
        }

        $parts = explode('-', $traceParent);

        if (count($parts) < 4) {
            return new self(traceParent: $traceParent, traceState: $traceState);
        }

        [$version, $traceId, $spanId, $flags] = array_slice($parts, 0, 4);

        if ($version === 'ff'
            || preg_match('/^[a-f0-9]{2}$/', $version) !== 1
            || ($version === '00' && count($parts) !== 4)
            || ! self::isTraceId($traceId)
            || ! self::isSpanId($spanId)
            || preg_match('/^[a-f0-9]{2}$/', $flags) !== 1) {
            return new self(traceParent: $traceParent, traceState: $traceState);
        }

        return new self(
            traceId: $traceId,
            spanId: $spanId,
            traceParent: $traceParent,
            sampled: (hexdec($flags) & 1) === 1,
            traceState: $traceState,
        );
    }

    /** Prefer explicit caller-provided fields, falling back to traceparent. */
    public static function merge(
        ?string $traceId = null,
        ?string $spanId = null,
        ?string $traceParent = null,
        ?string $traceState = null,
    ): self {
        $parsed = self::fromTraceParent($traceParent, $traceState);

        return new self(
            traceId: self::normalizeId($traceId, 32) ?? $parsed->traceId,
            spanId: self::normalizeId($spanId, 16) ?? $parsed->spanId,
            traceParent: self::normalizeHeader($traceParent) ?? $parsed->traceParent,
            sampled: $parsed->sampled,
            traceState: $parsed->traceState,
        );
    }

    public function toTraceParent(?string $spanId = null, ?bool $sampled = null): ?string
    {
        $spanId ??= $this->spanId;

        if ($this->traceId === null || $spanId === null
            || ! self::isTraceId($this->traceId) || ! self::isSpanId($spanId)) {
            return null;
        }

        return sprintf('00-%s-%s-%s', $this->traceId, $spanId, ($sampled ?? $this->sampled) ? '01' : '00');
    }

    private static function normalizeHeader(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim($value));

        return $value === '' ? null : $value;
    }

    private static function normalizeTraceState(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || strlen($value) > 512 || preg_match('/[\r\n]/', $value) === 1) {
            return null;
        }

        $members = array_map('trim', explode(',', $value));

        if (count($members) > 32) {
            return null;
        }

        foreach ($members as $member) {
            if (preg_match(
                '/^(?:[a-z][a-z0-9_\-*\/]{0,255}|[a-z0-9_\-*\/]{1,241}@[a-z][a-z0-9_\-*\/]{0,13})=([\x20-\x2b\x2d-\x3c\x3e-\x7e]{1,256})$/',
                $member,
                $matches,
            ) !== 1 || trim($matches[1]) !== $matches[1]) {
                return null;
            }
        }

        return implode(',', $members);
    }

    private static function normalizeId(?string $value, int $length): ?string
    {
        $value = self::normalizeHeader($value);

        return $value !== null
            && preg_match('/^[a-f0-9]{'.$length.'}$/', $value) === 1
            && $value !== str_repeat('0', $length)
                ? $value
                : null;
    }

    private static function isTraceId(string $value): bool
    {
        return self::normalizeId($value, 32) !== null;
    }

    private static function isSpanId(string $value): bool
    {
        return self::normalizeId($value, 16) !== null;
    }
}
