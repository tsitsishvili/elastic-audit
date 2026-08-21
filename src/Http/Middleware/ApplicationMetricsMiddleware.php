<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Tsitsishvili\ElasticAudit\DataTransferObjects\MetricData;
use Tsitsishvili\ElasticAudit\Services\MetricsRecorder;
use Tsitsishvili\ElasticAudit\Support\AuditSourceResolver;
use Tsitsishvili\ElasticAudit\Support\TraceContext;

final class ApplicationMetricsMiddleware
{
    private const TOKEN_ATTRIBUTE = '_elastic_audit_metrics_token';

    private const UNMATCHED_ROUTE = 'unmatched';

    public function __construct(
        private readonly MetricsRecorder $metrics,
        private readonly AuditSourceResolver $sourceResolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->metrics->begin(
            category: 'http',
            type: MetricData::TYPE_HTTP_SERVER,
            name: strtoupper($request->method()).' '.self::UNMATCHED_ROUTE,
            source: $this->sourceResolver->resolve(request: $request),
            upstream: TraceContext::fromTraceParent(
                $request->headers->get('traceparent'),
                $request->headers->get('tracestate'),
            ),
        );

        $request->attributes->set(self::TOKEN_ATTRIBUTE, $token);

        try {
            return $next($request);
        } catch (Throwable $exception) {
            $this->finish($request, null, MetricData::OUTCOME_FAILURE);

            throw $exception;
        }
    }

    /**
     * Finish after all route terminable middleware so their work belongs to the
     * same request trace. Laravel calls global terminable middleware last.
     */
    public function terminate(Request $request, Response $response): void
    {
        $outcome = $response->getStatusCode() >= 500
            ? MetricData::OUTCOME_FAILURE
            : MetricData::OUTCOME_SUCCESS;

        $this->finish($request, $response, $outcome);
    }

    private function finish(Request $request, ?Response $response, string $outcome): void
    {
        $token = $request->attributes->get(self::TOKEN_ATTRIBUTE);
        $request->attributes->remove(self::TOKEN_ATTRIBUTE);

        if (! is_string($token)) {
            return;
        }

        [$routeName, $controller] = $this->route($request);
        $method                   = strtoupper($request->method());

        $this->metrics->finish(
            token: $token,
            name: trim("{$method} {$routeName}"),
            outcome: $outcome,
            source: $this->sourceResolver->resolve(request: $request),
            http: [
                'method'      => $method,
                'route'       => $routeName,
                'controller'  => $controller,
                'status_code' => $response?->getStatusCode(),
            ],
        );
    }

    /** @return array{string, ?string} */
    private function route(Request $request): array
    {
        $route = $request->route();

        if ($route instanceof Route) {
            $name       = $route->getName() ?: $route->uri();
            $controller = $route->getActionName();

            return [(string) $name, $controller !== 'Closure' ? $controller : null];
        }

        return [self::UNMATCHED_ROUTE, null];
    }
}
