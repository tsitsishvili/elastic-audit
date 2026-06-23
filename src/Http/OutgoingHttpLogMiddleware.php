<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Http;

use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Tsitsishvili\ElasticAudit\Contracts\EventTypeContract;
use Tsitsishvili\ElasticAudit\Contracts\ProviderContract;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogContext;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogData;
use Tsitsishvili\ElasticAudit\Enums\HttpDirection;
use Tsitsishvili\ElasticAudit\Jobs\LogHttpRequestJob;
use Tsitsishvili\ElasticAudit\Services\Redactors\SensitiveDataRedactor;
use Throwable;

/**
 * Guzzle handler-stack middleware that logs outgoing third-party HTTP traffic.
 *
 * Attached by HttpLogClientFactory to the PendingRequest returned from
 * HttpLog::make(). Capturing at the transport layer means the full native
 * Laravel HTTP client API is available to callers and no request can bypass logging.
 *
 * Logging is best-effort and must never affect the caller's request: every capture
 * path is gated and wrapped so a logging failure can neither throw nor swallow the
 * original provider exception.
 */
final class OutgoingHttpLogMiddleware
{
    public function __construct(
        private readonly ProviderContract $provider,
        private readonly EventTypeContract $eventType,
        private readonly HttpLogContext $context,
        private readonly SensitiveDataRedactor $redactor,
    ) {}

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            if (! $this->shouldLog()) {
                return $handler($request, $options);
            }

            $method  = $request->getMethod();
            $url     = (string) $request->getUri();
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
                $this->dispatch($method, $url, $bodyRaw, null, $e, $startTime);

                throw $e;
            }

            return $promise->then(
                function (ResponseInterface $response) use ($method, $url, $bodyRaw, $startTime) {
                    $this->dispatch($method, $url, $bodyRaw, $response, null, $startTime);

                    return $response;
                },
                function ($reason) use ($method, $url, $bodyRaw, $startTime) {
                    $exception = $reason instanceof Throwable
                        ? $reason
                        : new RuntimeException(is_string($reason) ? $reason : 'Request rejected');

                    $this->dispatch($method, $url, $bodyRaw, null, $exception, $startTime);

                    // Re-reject with the original reason so the caller sees the real failure.
                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    private function shouldLog(): bool
    {
        if (! config('http_logs.enabled', false)) {
            return false;
        }

        $sampleRate = (float) config('http_logs.sample_rate', 1.0);

        return ! ($sampleRate < 1.0 && (float) mt_rand() / mt_getrandmax() >= $sampleRate);
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

        $body = $request->getBody();

        if (! $body->isSeekable()) {
            return '';
        }

        $raw = (string) $body;
        $body->rewind();

        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($raw, $parsed);

            return (string) json_encode($parsed);
        }

        return $raw;
    }

    private function captureResponseBody(ResponseInterface $response): string
    {
        $body = $response->getBody();

        if (! $body->isSeekable()) {
            return '';
        }

        $raw = (string) $body;
        $body->rewind();

        return $raw;
    }

    private function dispatch(
        string $method,
        string $url,
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
                headers: [],
                rawBody: $requestBodyRaw,
                maxBytes: $maxBytes,
                previewBytes: $previewBytes,
            );

            $responseBodyRaw = $response !== null ? $this->captureResponseBody($response) : '';
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

            // Strip query string from stored URL — query params may carry API keys
            $safeUrl = (string) strtok($url, '?');

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
            );

            LogHttpRequestJob::dispatch($data);
        } catch (Throwable) {
            // Never let logging failures affect the provider call result
        }
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
