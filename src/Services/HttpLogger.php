<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Tsitsishvili\ElasticAudit\Contracts\EventTypeContract;
use Tsitsishvili\ElasticAudit\Contracts\ProviderContract;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogContext;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogData;
use Tsitsishvili\ElasticAudit\DataTransferObjects\RedactedHttpPayload;
use Tsitsishvili\ElasticAudit\Enums\HttpDirection;
use Tsitsishvili\ElasticAudit\Events\AuditOperationFailed;
use Tsitsishvili\ElasticAudit\Jobs\LogHttpRequestJob;
use Tsitsishvili\ElasticAudit\Services\Redactors\HttpPayloadRedactorResolver;
use Tsitsishvili\ElasticAudit\Services\Redactors\SensitiveDataRedactor;
use Tsitsishvili\ElasticAudit\Support\AuditFailureReporter;
use Tsitsishvili\ElasticAudit\Support\AuditSourceResolver;
use Tsitsishvili\ElasticAudit\Support\CaptureSampling;

class HttpLogger
{
    private readonly AuditSourceResolver $sourceResolver;

    public function __construct(
        private readonly SensitiveDataRedactor $redactor,
        private readonly ?HttpPayloadRedactorResolver $redactorResolver = null,
        private readonly AuditFailureReporter $failureReporter = new AuditFailureReporter,
        ?AuditSourceResolver $sourceResolver = null,
    ) {
        $this->sourceResolver = $sourceResolver ?? AuditSourceResolver::fromContainer();
    }

    public function logIncoming(
        Request $request,
        ProviderContract $provider,
        EventTypeContract $eventType,
        HttpLogContext $context,
        int $latencyMs = 0,
        int $httpStatusCode = 200,
        bool $success = true,
        ?Response $response = null,
        ?Throwable $exception = null,
    ): void {
        if (! CaptureSampling::shouldCapture()) {
            return;
        }

        try {
            $redactor     = $this->redactorResolver?->forProvider($provider) ?? $this->redactor;
            $maxBytes     = (int) config('http_logs.body_max_bytes', 32768);
            $previewBytes = (int) config('http_logs.body_preview_bytes', 4096);

            $requestPayload = $redactor->buildPayload(
                headers: $request->headers->all(),
                rawBody: $this->captureRequestBody($request),
                maxBytes: $maxBytes,
                previewBytes: $previewBytes,
            );

            if ($response !== null) {
                $content = $response->getContent(); // string|false (false for streamed/binary responses)

                if (is_string($content)) {
                    $responsePayload = $redactor->buildPayload(
                        headers: $response->headers->all(),
                        rawBody: $content,
                        maxBytes: $maxBytes,
                        previewBytes: $previewBytes,
                    );
                } else {
                    // Streamed/binary responses expose no readable body; still redact and keep headers.
                    $responsePayload = RedactedHttpPayload::empty(
                        $redactor->redactHeaders($response->headers->all()),
                    );
                }
            } else {
                $responsePayload = RedactedHttpPayload::empty();
            }

            $safeUrl = $redactor->sanitizeUrl($request->fullUrl());

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
                errorClass: $exception !== null ? $exception::class : null,
                errorMessage: $exception !== null ? $redactor->sanitizeErrorMessage($exception->getMessage()) : null,
                traceParent: $request->headers->get('traceparent'),
                source: $this->sourceResolver->resolve($context->executionOrigin, $request),
            );

            LogHttpRequestJob::dispatch($data);
        } catch (Throwable $e) {
            $this->failureReporter->report(
                subsystem: AuditOperationFailed::SUBSYSTEM_HTTP,
                stage: AuditOperationFailed::STAGE_CAPTURE,
                exception: $e,
                context: [
                    'provider'   => $provider,
                    'event_type' => $eventType,
                    'request_id' => $context->requestId,
                ],
            );
        }
    }

    /**
     * Read at most the configured capture cap plus one byte. Content-Length is
     * only a fast rejection path; the bounded stream read remains authoritative
     * when the header is missing or incorrect.
     */
    private function captureRequestBody(Request $request): string
    {
        $cap = max(0, (int) config(
            'http_logs.body_capture_max_bytes',
            SensitiveDataRedactor::DEFAULT_CAPTURE_MAX_BYTES,
        ));

        $contentLength = $request->headers->get('Content-Length');

        if (is_string($contentLength)
            && ctype_digit(trim($contentLength))
            && (int) trim($contentLength) > $cap) {
            return '';
        }

        try {
            $body = $request->getContent(true);
        } catch (Throwable) {
            return '';
        }

        if (! is_resource($body)) {
            return '';
        }

        $raw = '';

        try {
            while (! feof($body) && strlen($raw) <= $cap) {
                $remaining  = $cap - strlen($raw);
                $readLength = $remaining >= 8192 ? 8192 : $remaining + 1;
                $chunk      = fread($body, $readLength);

                if (! is_string($chunk)) {
                    return '';
                }

                if ($chunk === '') {
                    return feof($body) ? $raw : '';
                }

                $raw .= $chunk;
            }
        } catch (Throwable) {
            return '';
        } finally {
            try {
                $metadata = stream_get_meta_data($body);

                if ($metadata['seekable'] === true) {
                    rewind($body);
                }
            } catch (Throwable) {
                // A broken body stream must not suppress the metadata-only log.
            }
        }

        return strlen($raw) > $cap ? '' : $raw;
    }
}
