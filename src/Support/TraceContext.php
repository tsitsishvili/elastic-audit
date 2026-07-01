<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Support;

final readonly class TraceContext
{
    public function __construct(
        public ?string $traceId = null,
        public ?string $spanId = null,
        public ?string $traceParent = null,
    ) {}

    /**
     * Resolve W3C trace context from an HTTP traceparent header.
     */
    public static function fromTraceParent(?string $traceParent): self
    {
        $traceParent = self::normalizeHeader($traceParent);

        if ($traceParent === null) {
            return new self();
        }

        $parts = explode('-', $traceParent);

        if (count($parts) < 4) {
            return new self(traceParent: $traceParent);
        }

        $traceId = strtolower($parts[1]);
        $spanId  = strtolower($parts[2]);

        if (! self::isTraceId($traceId) || ! self::isSpanId($spanId)) {
            return new self(traceParent: $traceParent);
        }

        return new self(
            traceId: $traceId,
            spanId: $spanId,
            traceParent: $traceParent,
        );
    }

    /**
     * Prefer explicit caller-provided fields, falling back to parsed traceparent.
     */
    public static function merge(
        ?string $traceId = null,
        ?string $spanId = null,
        ?string $traceParent = null,
    ): self {
        $parsed = self::fromTraceParent($traceParent);

        return new self(
            traceId: self::normalizeHeader($traceId) ?? $parsed->traceId,
            spanId: self::normalizeHeader($spanId) ?? $parsed->spanId,
            traceParent: self::normalizeHeader($traceParent) ?? $parsed->traceParent,
        );
    }

    private static function normalizeHeader(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : strtolower($value);
    }

    private static function isTraceId(string $value): bool
    {
        return preg_match('/^[a-f0-9]{32}$/', $value) === 1
            && $value !== str_repeat('0', 32);
    }

    private static function isSpanId(string $value): bool
    {
        return preg_match('/^[a-f0-9]{16}$/', $value) === 1
            && $value !== str_repeat('0', 16);
    }
}
