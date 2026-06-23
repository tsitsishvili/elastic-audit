<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services\Redactors;

use Tsitsishvili\ElasticAudit\DataTransferObjects\RedactedHttpPayload;

class PaymentRedactor extends SensitiveDataRedactor
{
    public function buildPayload(array $headers, string $rawBody, int $maxBytes, int $previewBytes): RedactedHttpPayload
    {
        if (config('http_logs.payment_body_mode') === 'metadata') {
            return new RedactedHttpPayload(
                headers: $this->redactHeaders($headers),
                body: null,
                bodyPreview: null,
                bodyHash: null,
                bodyTruncated: false,
            );
        }

        return parent::buildPayload($headers, $rawBody, $maxBytes, $previewBytes);
    }
}
