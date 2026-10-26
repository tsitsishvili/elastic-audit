<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature\Dashboard;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Tsitsishvili\ElasticAudit\Dashboard\Dashboard;
use Tsitsishvili\ElasticAudit\Dashboard\DashboardAccess;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

final class DashboardAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Dashboard::auth(fn () => true);
    }

    protected function tearDown(): void
    {
        Dashboard::auth(null);
        parent::tearDown();
    }

    public function test_same_middleware_as_the_metrics_dashboard_is_allowed(): void
    {
        $this->assertTrue($this->allows());
    }

    public function test_disabled_dashboard_is_denied(): void
    {
        config()->set('http_logs.dashboard.enabled', false);

        $this->assertFalse($this->allows());
    }

    public function test_shared_gate_denial_denies(): void
    {
        Dashboard::auth(fn () => false);

        $this->assertFalse($this->allows());
    }

    public function test_extra_middleware_that_aborts_with_403_denies(): void
    {
        $this->extraMiddleware(fn () => abort(403));

        $this->assertFalse($this->allows());
    }

    public function test_extra_middleware_that_redirects_to_login_denies(): void
    {
        $this->extraMiddleware(fn () => redirect('/login'));

        $this->assertFalse($this->allows());
    }

    public function test_extra_middleware_that_passes_allows(): void
    {
        $this->extraMiddleware(fn (Request $request, Closure $next) => $next($request));

        $this->assertTrue($this->allows());
    }

    public function test_extra_middleware_that_throws_denies(): void
    {
        $this->extraMiddleware(fn () => throw new AuthenticationException);

        $this->assertFalse($this->allows());
    }

    public function test_extra_middleware_that_replaces_the_response_with_a_failure_denies(): void
    {
        $this->extraMiddleware(fn (Request $request, Closure $next) => $next($request) ? new Response('', 403) : null);

        $this->assertFalse($this->allows());
    }

    public function test_extra_middleware_cannot_switch_the_default_guard(): void
    {
        $guard = Auth::getDefaultDriver();
        $this->extraMiddleware(function (Request $request, Closure $next) {
            Auth::shouldUse('admin');

            return $next($request);
        });

        $this->assertTrue($this->allows());
        $this->assertSame($guard, Auth::getDefaultDriver());
    }

    private function allows(): bool
    {
        return app(DashboardAccess::class)->allows(request(), DashboardAccess::HTTP);
    }

    private function extraMiddleware(Closure $handle): void
    {
        $this->app->instance('test.extra-middleware', new class($handle)
        {
            public function __construct(private readonly Closure $handle) {}

            public function handle(Request $request, Closure $next): mixed
            {
                return ($this->handle)($request, $next);
            }
        });
        $this->app['router']->aliasMiddleware('extra', 'test.extra-middleware');
        config()->set('http_logs.dashboard.middleware', ['web', 'extra']);
    }
}
