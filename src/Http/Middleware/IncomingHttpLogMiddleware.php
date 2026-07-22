<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Tsitsishvili\ElasticAudit\Contracts\EntityTypeContract;
use Tsitsishvili\ElasticAudit\Contracts\EventTypeContract;
use Tsitsishvili\ElasticAudit\Contracts\ProviderContract;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogContext;
use Tsitsishvili\ElasticAudit\Services\HttpLogger;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class IncomingHttpLogMiddleware
{
    /**
     * Request attribute that carries the response-capture latency from handle()
     * to terminate(). Its presence also marks that the success path completed,
     * so terminate() never double-logs a request already logged as an exception.
     */
    private const LATENCY_ATTRIBUTE = '_elastic_audit_http_latency_ms';

    public function __construct(
        private readonly HttpLogger $logger,
    ) {}

    /**
     * Log incoming third-party callbacks. All context is resolved from
     * $request->attributes set by server-side code only — never from URL segments,
     * which are user-controlled input.
     *
     * Set these attributes before this middleware runs (e.g. in the controller
     * or a preceding middleware):
     *   third_party_provider   — string matching a case in your Provider enum
     *   third_party_event_type — string matching a case in your HttpLogEventType enum
     *   third_party_entity_type / third_party_entity_id — optional entity context
     *   third_party_external_id — optional provider-side identifier (string)
     *   third_party_user_id     — optional acting user id (int or non-empty string)
     *
     * Register your enum classes in config:
     *   http_logs.enums.provider, .event_type, .entity_type
     *
     * Successful callbacks are logged in terminate(), after the response is sent,
     * so redaction and queue dispatch stay off the response's critical path.
     * Failed callbacks are logged inline here so the exception detail is preserved.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $start = hrtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            try {
                $this->logException($request, $start, $e);
            } catch (Throwable) {
                // Audit logging must never replace the callback's exception.
            }

            throw $e;
        }

        // Hand the response-capture latency to terminate(); its presence also
        // signals the success path so the exception path is never re-logged.
        $request->attributes->set(
            self::LATENCY_ATTRIBUTE,
            (int) round((hrtime(true) - $start) / 1_000_000),
        );

        return $response;
    }

    /**
     * Log the successful callback after the response has been flushed to the
     * caller. The exception path already logged inline in handle() and left no
     * latency attribute, so this is a no-op there and never double-logs.
     */
    public function terminate(Request $request, Response $response): void
    {
        $latencyMs = $request->attributes->get(self::LATENCY_ATTRIBUTE);

        if (! is_int($latencyMs)) {
            return;
        }

        try {
            $this->logResponse($request, $response, $latencyMs);
        } catch (Throwable) {
            // Audit logging must never affect the request lifecycle.
        }
    }

    private function logResponse(Request $request, Response $response, int $latencyMs): void
    {
        if (! config('http_logs.enabled', false)) {
            return;
        }

        $target = $this->resolveLogTarget($request);

        if ($target === null) {
            return;
        }

        [$provider, $eventType, $context] = $target;

        $this->logger->logIncoming(
            request: $request,
            provider: $provider,
            eventType: $eventType,
            context: $context,
            latencyMs: $latencyMs,
            httpStatusCode: $response->getStatusCode(),
            success: $response->getStatusCode() < 400,
            response: $response,
        );
    }

    private function logException(Request $request, int $start, Throwable $e): void
    {
        if (! config('http_logs.enabled', false)) {
            return;
        }

        $target = $this->resolveLogTarget($request);

        if ($target === null) {
            return;
        }

        [$provider, $eventType, $context] = $target;

        $this->logger->logIncoming(
            request: $request,
            provider: $provider,
            eventType: $eventType,
            context: $context,
            latencyMs: (int) round((hrtime(true) - $start) / 1_000_000),
            httpStatusCode: $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500,
            success: false,
            exception: $e,
        );
    }

    /**
     * Resolve the provider, event type, and log context from the trusted request
     * attributes, or null when capture should be skipped (unregistered enum
     * classes or attribute values that match no enum case).
     *
     * @return array{0: ProviderContract, 1: EventTypeContract, 2: HttpLogContext}|null
     */
    private function resolveLogTarget(Request $request): ?array
    {
        $provider  = $this->resolveProvider($request->attributes->get('third_party_provider'));
        $eventType = $this->resolveEventType($request->attributes->get('third_party_event_type'));

        if ($provider === null || $eventType === null) {
            return null;
        }

        $entityType = $this->resolveEntityType(
            $request->attributes->get('third_party_entity_type')
        );

        if ($entityType === null) {
            return null;
        }

        $context = HttpLogContext::forEntity(
            entityType: $entityType,
            entityId: $this->resolveEntityId($request->attributes->get('third_party_entity_id')),
            externalId: $this->resolveExternalId($request->attributes->get('third_party_external_id')),
            userId: $this->resolveUserId($request->attributes->get('third_party_user_id')),
        );

        return [$provider, $eventType, $context];
    }

    private function resolveProvider(mixed $value): ?ProviderContract
    {
        /** @var class-string<\BackedEnum&ProviderContract>|null $class */
        $class = config('http_logs.enums.provider');

        if (! $this->isBackedEnumContract($class, ProviderContract::class)) {
            return null;
        }

        $resolved = $this->resolveEnumValue($class, ProviderContract::class, $value);

        return $resolved instanceof ProviderContract ? $resolved : null;
    }

    private function resolveEventType(mixed $value): ?EventTypeContract
    {
        /** @var class-string<\BackedEnum&EventTypeContract>|null $class */
        $class = config('http_logs.enums.event_type');

        if (! $this->isBackedEnumContract($class, EventTypeContract::class)) {
            return null;
        }

        $resolved = $this->resolveEnumValue($class, EventTypeContract::class, $value);

        return $resolved instanceof EventTypeContract ? $resolved : null;
    }

    private function resolveEntityType(mixed $value): ?EntityTypeContract
    {
        /** @var class-string<\BackedEnum&EntityTypeContract>|null $class */
        $class = config('http_logs.enums.entity_type');

        if (! $this->isBackedEnumContract($class, EntityTypeContract::class)) {
            return null;
        }

        $defaultValue = config('http_logs.enums.entity_type_default', 'none');
        $resolved     = $this->resolveEnumValue(
            $class,
            EntityTypeContract::class,
            $value ?? $defaultValue,
        );

        if (! $resolved instanceof EntityTypeContract) {
            $resolved = $this->resolveEnumValue(
                $class,
                EntityTypeContract::class,
                $defaultValue,
            );
        }

        return $resolved instanceof EntityTypeContract ? $resolved : null;
    }

    /**
     * @param class-string $contract
     */
    private function isBackedEnumContract(mixed $class, string $contract): bool
    {
        if (! is_string($class)
            || ! enum_exists($class)
            || ! is_subclass_of($class, \BackedEnum::class)
            || ! is_subclass_of($class, $contract)) {
            return false;
        }

        return (new \ReflectionEnum($class))->getBackingType()?->getName() === 'string';
    }

    /**
     * Resolve only scalar backing values or a case of the configured enum.
     * Arbitrary objects are rejected instead of being cast through __toString().
     *
     * @param class-string<\BackedEnum> $class
     * @param class-string $contract
     */
    private function resolveEnumValue(string $class, string $contract, mixed $value): ?\BackedEnum
    {
        if ($value instanceof $class && $value instanceof $contract) {
            return $value;
        }

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $resolved = $class::tryFrom((string) $value);

        return $resolved instanceof $contract ? $resolved : null;
    }

    private function resolveEntityId(mixed $value): string
    {
        return is_string($value) || is_int($value)
            ? (string) $value
            : 'unknown';
    }

    private function resolveExternalId(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = (string) $value;

        return $value === '' ? null : $value;
    }

    private function resolveUserId(mixed $value): int|string|null
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        return is_string($value) && trim($value) === '' ? null : $value;
    }
}
