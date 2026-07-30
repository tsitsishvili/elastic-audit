<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Http;

use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;
use Tsitsishvili\ElasticAudit\Contracts\EventTypeContract;
use Tsitsishvili\ElasticAudit\Contracts\ProviderContract;
use Tsitsishvili\ElasticAudit\DataTransferObjects\AuditSource;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogContext;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogData;
use Tsitsishvili\ElasticAudit\Enums\HttpDirection;
use Tsitsishvili\ElasticAudit\Events\AuditOperationFailed;
use Tsitsishvili\ElasticAudit\Jobs\LogHttpRequestJob;
use Tsitsishvili\ElasticAudit\Services\Redactors\SensitiveDataRedactor;
use Tsitsishvili\ElasticAudit\Support\AuditFailureReporter;
use Tsitsishvili\ElasticAudit\Support\AuditSourceResolver;
use Tsitsishvili\ElasticAudit\Support\CaptureSampling;

/**
 * Guzzle handler-stack middleware that logs outgoing third-party HTTP traffic.
 *
 * Attached by HttpLogClientFactory to the PendingRequest returned from
 * HttpLog::make(). It captures requests executed by that PendingRequest;
 * Laravel's pool/batch APIs create separate child requests and are not covered.
 *
 * Logging is best-effort and must never affect the caller's request: every capture
 * path is gated and wrapped so a logging failure can neither throw nor swallow the
 * original provider exception.
 */
final class OutgoingHttpLogMiddleware
{
    private readonly bool $capture;

    public function __construct(
        private readonly ProviderContract $provider,
        private readonly EventTypeContract $eventType,
        private readonly HttpLogContext $context,
        private readonly SensitiveDataRedactor $redactor,
        ?bool $capture = null,
        private readonly AuditFailureReporter $failureReporter = new AuditFailureReporter,
        private readonly ?AuditSourceResolver $sourceResolver = null,
    ) {
        // Keep one decision for the lifetime of this audited PendingRequest so
        // Laravel retries cannot be sampled independently from each other.
        $this->capture = $capture ?? CaptureSampling::shouldCapture();
    }

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            if (! $this->capture) {
                return $handler($request, $options);
            }

            $method  = $request->getMethod();
            $url     = (string) $request->getUri();
            $headers = $request->getHeaders();
            $bodyRaw = '';

            try {
                $bodyRaw = $this->captureRequestBody($request);
            } catch (Throwable) {
                // Capturing the body must never break the request.
            }

            $startTime = hrtime(true);

            try {
                // Http::fake() callbacks that throw surface here synchronously.
                $promise = $handler($request, $options);
            } catch (Throwable $e) {
                $this->dispatch($method, $url, $headers, $bodyRaw, null, $e, $startTime);

                throw $e;
            }

            return $promise->then(
                function (ResponseInterface $response) use ($method, $url, $headers, $bodyRaw, $startTime) {
                    $this->dispatch($method, $url, $headers, $bodyRaw, $response, null, $startTime);

                    return $response;
                },
                function ($reason) use ($method, $url, $headers, $bodyRaw, $startTime) {
                    $exception = $reason instanceof Throwable
                        ? $reason
                        : new RuntimeException(is_string($reason) ? $reason : 'Request rejected');

                    $this->dispatch($method, $url, $headers, $bodyRaw, null, $exception, $startTime);

                    // Re-reject with the original reason so the caller sees the real failure.
                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    /**
     * Read the request body as a JSON-decodable string so the redactor can redact keys.
     * Form bodies are parsed back into an array (and re-encoded) so secrets are redacted
     * rather than leaked into the preview/hash; multipart bodies are skipped to avoid
     * pulling uploaded file contents into memory.
     */
    private function captureRequestBody(RequestInterface $request): string
    {
        $contentType = strtolower($request->getHeaderLine('Content-Type'));

        if (str_contains($contentType, 'multipart/form-data')) {
            return '';
        }

        return $this->readBody($request->getBody());
    }

    private function captureResponseBody(ResponseInterface $response): string
    {
        return $this->readBody($response->getBody());
    }

    /**
     * Read a message body without materializing oversized payloads: bodies over
     * the capture cap return '' (captured headers-only), and unknown-size
     * streams are read in chunks so at most cap+1 bytes are ever held.
     */
    private function readBody(StreamInterface $body): string
    {
        if (! $body->isSeekable()) {
            return '';
        }

        $cap = max(0, (int) config(
            'http_logs.body_capture_max_bytes',
            SensitiveDataRedactor::DEFAULT_CAPTURE_MAX_BYTES,
        ));

        $size = $body->getSize();

        if ($size !== null && $size > $cap) {
            return '';
        }

        $raw = '';

        try {
            $body->rewind();

            while (! $body->eof() && strlen($raw) <= $cap) {
                $remaining  = $cap - strlen($raw);
                $readLength = $remaining >= 8192 ? 8192 : $remaining + 1;
                $chunk      = $body->read($readLength);

                if ($chunk === '') {
                    return $this->streamReachedEof($body) ? $raw : '';
                }

                $raw .= $chunk;
            }
        } finally {
            try {
                $body->rewind();
            } catch (Throwable) {
                // A broken capture stream must not mask the provider result.
            }
        }

        return strlen($raw) > $cap ? '' : $raw;
    }

    /**
     * Stream reads can mutate EOF state even though PSR-7 does not annotate
     * eof() as impure.
     *
     * @phpstan-impure
     */
    private function streamReachedEof(StreamInterface $body): bool
    {
        return $body->eof();
    }

    private function dispatch(
        string $method,
        string $url,
        array $requestHeaders,
        string $requestBodyRaw,
        ?ResponseInterface $response,
        ?Throwable $exception,
        float $startTime,
    ): void {
        try {
            $latencyMs = (int) round((hrtime(true) - $startTime) / 1_000_000);

            $maxBytes     = (int) config('http_logs.body_max_bytes', 32768);
            $previewBytes = (int) config('http_logs.body_preview_bytes', 4096);

            $requestPayload = $this->redactor->buildPayload(
                headers: $requestHeaders,
                rawBody: $requestBodyRaw,
                maxBytes: $maxBytes,
                previewBytes: $previewBytes,
            );

            $responseBodyRaw = '';

            if ($response !== null) {
                try {
                    $responseBodyRaw = $this->captureResponseBody($response);
                } catch (Throwable) {
                    // Preserve the metadata-only log if response capture fails.
                }
            }

            $responseHeaders = $response !== null ? $response->getHeaders() : [];

            $responsePayload = $this->redactor->buildPayload(
                headers: $responseHeaders,
                rawBody: $responseBodyRaw,
                maxBytes: $maxBytes,
                previewBytes: $previewBytes,
            );

            $statusCode = $response?->getStatusCode();
            $success    = $exception === null && $statusCode !== null && $statusCode < 400;
            $timedOut   = $exception !== null && $this->isTimeout($exception);

            $safeUrl = $this->redactor->sanitizeUrl($url);

            $errorMessage = $exception !== null
                ? $this->redactor->sanitizeErrorMessage($exception->getMessage())
                : null;

            $data = HttpLogData::make(
                provider: $this->provider,
                eventType: $this->eventType,
                direction: HttpDirection::Outgoing,
                httpMethod: $method,
                httpUrl: $safeUrl,
                latencyMs: $latencyMs,
                context: $this->context,
                request: $requestPayload,
                response: $responsePayload,
                httpStatusCode: $statusCode,
                success: $success,
                attempt: 1,
                errorClass: $exception !== null ? $exception::class : null,
                errorMessage: $errorMessage,
                timedOut: $timedOut,
                traceParent: $this->firstHeader($requestHeaders, 'traceparent'),
                source: $this->resolveSource(),
            );

            LogHttpRequestJob::dispatch($data);
        } catch (Throwable $e) {
            $this->failureReporter->report(
                subsystem: AuditOperationFailed::SUBSYSTEM_HTTP,
                stage: AuditOperationFailed::STAGE_CAPTURE,
                exception: $e,
                context: [
                    'provider'   => $this->provider,
                    'event_type' => $this->eventType,
                    'request_id' => $this->context->requestId,
                ],
            );
        }
    }

    /**
     * Resolved per outgoing request rather than once per PendingRequest. An audited
     * client is often built once and reused across requests, jobs, and commands, so
     * each call must record the context that actually issued it — unlike the capture
     * decision, which is deliberately fixed for the client's lifetime.
     */
    private function resolveSource(): AuditSource
    {
        return ($this->sourceResolver ?? AuditSourceResolver::fromContainer())
            ->resolve($this->context->executionOrigin);
    }

    private function firstHeader(array $headers, string $name): ?string
    {
        foreach ($headers as $headerName => $values) {
            if (strcasecmp((string) $headerName, $name) !== 0) {
                continue;
            }

            if (is_array($values)) {
                return isset($values[0]) ? (string) $values[0] : null;
            }

            return (string) $values;
        }

        return null;
    }

    /**
     * Whether a failed request timed out (connect or read timeout), as opposed to
     * any other transport error (DNS, refused, TLS, …). Matched on the message of the
     * exception chain so it works for both the underlying Guzzle ConnectException
     * ("cURL error 28: Operation timed out…") and Laravel's ConnectionException wrapper.
     */
    private function isTimeout(Throwable $exception): bool
    {
        for ($e = $exception; $e !== null; $e = $e->getPrevious()) {
            if (preg_match('/cURL error 28|timed out/i', $e->getMessage()) === 1) {
                return true;
            }
        }

        return false;
    }
}
