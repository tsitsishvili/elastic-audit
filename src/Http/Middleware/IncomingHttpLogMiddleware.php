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

class IncomingHttpLogMiddleware
{
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
     *   third_party_user_id     — optional acting user id (int)
     *
     * Register your enum classes in config:
     *   http_logs.enums.provider, .event_type, .entity_type
     */
    public function handle(Request $request, Closure $next): Response
    {
        $start    = hrtime(true);
        $response = $next($request);

        if (! config('http_logs.enabled', false)) {
            return $response;
        }

        $latencyMs = (int) round((hrtime(true) - $start) / 1_000_000);

        $provider  = $this->resolveProvider($request->attributes->get('third_party_provider'));
        $eventType = $this->resolveEventType($request->attributes->get('third_party_event_type'));

        if ($provider === null || $eventType === null) {
            return $response;
        }

        $entityType = $this->resolveEntityType(
            $request->attributes->get('third_party_entity_type')
        );

        if ($entityType === null) {
            return $response;
        }

        $entityId = (string) $request->attributes->get('third_party_entity_id', 'unknown');

        $context = HttpLogContext::forEntity(
            entityType: $entityType,
            entityId: $entityId,
            externalId: $this->resolveExternalId($request->attributes->get('third_party_external_id')),
            userId: $this->resolveUserId($request->attributes->get('third_party_user_id')),
        );

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

        return $response;
    }

    private function resolveProvider(mixed $value): ?ProviderContract
    {
        /** @var class-string<\BackedEnum&ProviderContract>|null $class */
        $class = config('http_logs.enums.provider');

        if ($class === null || $value === null) {
            return null;
        }

        $resolved = $class::tryFrom((string) $value);

        return $resolved instanceof ProviderContract ? $resolved : null;
    }

    private function resolveEventType(mixed $value): ?EventTypeContract
    {
        /** @var class-string<\BackedEnum&EventTypeContract>|null $class */
        $class = config('http_logs.enums.event_type');

        if ($class === null || $value === null) {
            return null;
        }

        $resolved = $class::tryFrom((string) $value);

        return $resolved instanceof EventTypeContract ? $resolved : null;
    }

    private function resolveEntityType(mixed $value): ?EntityTypeContract
    {
        /** @var class-string<\BackedEnum&EntityTypeContract>|null $class */
        $class = config('http_logs.enums.entity_type');

        if ($class === null) {
            return null;
        }

        $defaultValue = config('http_logs.enums.entity_type_default', 'none');
        $resolved     = $class::tryFrom((string) ($value ?? $defaultValue));

        if (! $resolved instanceof EntityTypeContract) {
            $resolved = $class::tryFrom((string) $defaultValue);
        }

        return $resolved instanceof EntityTypeContract ? $resolved : null;
    }

    private function resolveExternalId(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        return $value === '' ? null : $value;
    }

    private function resolveUserId(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
