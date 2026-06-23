<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\DataTransferObjects;

final readonly class RedactedHttpPayload
{
    public function __construct(
        public array $headers,
        public ?array $body,
        public ?string $bodyPreview,
        public ?string $bodyHash,
        public bool $bodyTruncated,
    ) {}

    /**
     * Build a payload with no body — used when there is no content to capture
     * (e.g. a missing response) or when the body cannot be read (streamed or
     * binary responses). Headers, if any, should already be redacted by the caller.
     */
    public static function empty(array $headers = []): self
    {
        return new self(
            headers: $headers,
            body: null,
            bodyPreview: null,
            bodyHash: null,
            bodyTruncated: false,
        );
    }
}
