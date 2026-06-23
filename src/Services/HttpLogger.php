<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tsitsishvili\ElasticAudit\Contracts\EventTypeContract;
use Tsitsishvili\ElasticAudit\Contracts\ProviderContract;
use Tsitsishvili\ElasticAudit\DataTransferObjects\RedactedHttpPayload;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogContext;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogData;
use Tsitsishvili\ElasticAudit\Enums\HttpDirection;
use Tsitsishvili\ElasticAudit\Jobs\LogHttpRequestJob;
use Tsitsishvili\ElasticAudit\Services\Redactors\SensitiveDataRedactor;
use Throwable;

class HttpLogger
{
    public function __construct(
        private readonly SensitiveDataRedactor $redactor,
    ) {}

    public function logIncoming(
        Request $request,
        ProviderContract $provider,
        EventTypeContract $eventType,
        HttpLogContext $context,
        int $latencyMs = 0,
        int $httpStatusCode = 200,
        bool $success = true,
        ?Response $response = null,
    ): void {
        if (! config('http_logs.enabled', false)) {
            return;
        }

        $sampleRate = (float) config('http_logs.sample_rate', 1.0);
        if ($sampleRate < 1.0 && (float) mt_rand() / mt_getrandmax() >= $sampleRate) {
            return;
        }

        try {
            $maxBytes     = (int) config('http_logs.body_max_bytes', 32768);
            $previewBytes = (int) config('http_logs.body_preview_bytes', 4096);

            $requestPayload = $this->redactor->buildPayload(
                headers: $request->headers->all(),
                rawBody: $request->getContent(),
                maxBytes: $maxBytes,
                previewBytes: $previewBytes,
            );

            if ($response !== null) {
                $content = $response->getContent(); // string|false (false for streamed/binary responses)

                if (is_string($content)) {
                    $responsePayload = $this->redactor->buildPayload(
                        headers: $response->headers->all(),
                        rawBody: $content,
                        maxBytes: $maxBytes,
                        previewBytes: $previewBytes,
                    );
                } else {
                    // Streamed/binary responses expose no readable body; still redact and keep headers.
                    $responsePayload = RedactedHttpPayload::empty(
                        $this->redactor->redactHeaders($response->headers->all()),
                    );
                }
            } else {
                $responsePayload = RedactedHttpPayload::empty();
            }

            // Strip query string from stored URL — query params may carry API keys
            $safeUrl = (string) strtok($request->fullUrl(), '?');

            $data = HttpLogData::make(
                provider: $provider,
                eventType: $eventType,
                direction: HttpDirection::Incoming,
                httpMethod: $request->method(),
                httpUrl: $safeUrl,
                latencyMs: $latencyMs,
                context: $context,
                request: $requestPayload,
                response: $responsePayload,
                httpStatusCode: $httpStatusCode,
                success: $success,
            );

            LogHttpRequestJob::dispatch($data);
        } catch (Throwable) {
            // Never let logging failures propagate
        }
    }
}
