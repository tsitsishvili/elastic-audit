<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Dashboard;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use InvalidArgumentException;
use Throwable;
use Tsitsishvili\ElasticAudit\Http\Middleware\AuthorizeDashboard;

/**
 * Answers "would this viewer get into that dashboard?" from inside the metrics dashboard, without running any middleware.
 *
 * Access is granted only when the target runs the identical middleware stack the viewer already passed on the
 * metrics route, so the answer is exact and side-effect free; any difference fails closed.
 */
final class DashboardAccess
{
    public const HTTP = 'http';         // config key http_logs.dashboard

    public const ACTIVITY = 'activity'; // config key activity_logs.dashboard

    public const METRICS = 'metrics';   // config key elastic_audit_metrics.dashboard

    private const CONFIG = [
        self::HTTP     => 'http_logs.dashboard',
        self::ACTIVITY => 'activity_logs.dashboard',
        self::METRICS  => 'elastic_audit_metrics.dashboard',
    ];

    public function __construct(private readonly Router $router, private readonly Container $container) {}

    /** The dashboard's `enabled` flag. */
    public function enabled(string $dashboard): bool
    {
        // Same test the service provider uses to decide whether to register the routes.
        return ($this->config($dashboard)['enabled'] ?? false) !== false;
    }

    /** Enabled, runs exactly the metrics dashboard's middleware, and passes Dashboard::check(). */
    public function allows(Request $request, string $dashboard): bool
    {
        try {
            return $this->enabled($dashboard)
                && $this->middleware($dashboard) === $this->middleware(self::METRICS)
                && Dashboard::check($request);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function config(string $dashboard): array
    {
        $key = self::CONFIG[$dashboard] ?? throw new InvalidArgumentException("Unknown dashboard [{$dashboard}].");

        return (array) $this->container->make('config')->get($key, []);
    }

    /** @return array<int, mixed> The dashboard's route middleware, resolved to class strings the way the router does. */
    private function middleware(string $dashboard): array
    {
        $configured = (array) ($this->config($dashboard)['middleware'] ?? ['web']);

        return $this->router->resolveMiddleware(array_merge($configured, [AuthorizeDashboard::class]));
    }
}
