<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Dashboard;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Router;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Tsitsishvili\ElasticAudit\Http\Middleware\AuthorizeDashboard;

/**
 * Answers "would this viewer get into that dashboard?" from inside the metrics dashboard.
 *
 * Only meaningful for a request that already passed the metrics dashboard's middleware:
 * that middleware is assumed passed and is not run again.
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

    /** Enabled, passes Dashboard::check(), and passes the dashboard's own middleware beyond the metrics dashboard's. */
    public function allows(Request $request, string $dashboard): bool
    {
        if (! $this->enabled($dashboard)) {
            return false;
        }

        $auth    = $this->container->make(AuthManager::class);
        $guard   = $auth->getDefaultDriver();
        $reached = false;

        try {
            if (! Dashboard::check($request)) {
                return false;
            }

            $metrics = $this->middleware(self::METRICS);
            $extra   = array_values(array_filter(
                $this->middleware($dashboard),
                fn ($middleware) => ! in_array($middleware, $metrics, true),
            ));

            $response = (new Pipeline($this->container))
                ->send(clone $request)
                ->through($extra)
                ->then(function () use (&$reached): Response {
                    $reached = true;

                    return new Response;
                });
        } catch (Throwable) {
            return false;
        } finally {
            // `auth:<guard>` calls shouldUse(), which would switch the guard for the rest of this request.
            $auth->setDefaultDriver($guard);
        }

        return $reached && $response instanceof Response && $response->isSuccessful();
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
