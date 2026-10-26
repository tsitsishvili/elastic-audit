<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature\Dashboard;

use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
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

    public function test_target_with_extra_middleware_is_denied(): void
    {
        $this->app['router']->aliasMiddleware('deny', Authenticate::class);
        config()->set('http_logs.dashboard.middleware', ['web', 'deny']);

        $this->assertFalse($this->allows());
    }

    public function test_metrics_with_stricter_middleware_than_target_is_denied(): void
    {
        config()->set('elastic_audit_metrics.dashboard.middleware', ['web', Authenticate::class.':admin']);

        $this->assertFalse($this->allows());
    }

    public function test_parameterised_middleware_counts_as_different(): void
    {
        config()->set('elastic_audit_metrics.dashboard.middleware', ['web', Authenticate::class.':admin']);
        config()->set('http_logs.dashboard.middleware', ['web', Authenticate::class]);

        $this->assertFalse($this->allows());
    }

    public function test_allows_runs_no_middleware(): void
    {
        $called = false;
        $this->app->instance('test.recorder', new class($called)
        {
            public function __construct(private bool &$called) {}

            public function handle(Request $request, Closure $next): mixed
            {
                $this->called = true;

                return $next($request);
            }
        });
        $this->app['router']->aliasMiddleware('recorder', 'test.recorder');
        config()->set('http_logs.dashboard.middleware', ['web', 'recorder']);

        $this->assertFalse($this->allows());
        $this->assertFalse($called);
    }

    public function test_metrics_dashboard_itself_is_allowed_when_gate_passes(): void
    {
        config()->set('elastic_audit_metrics.dashboard.middleware', ['web', Authenticate::class.':admin']);

        $this->assertTrue(app(DashboardAccess::class)->allows(request(), DashboardAccess::METRICS));
    }

    private function allows(): bool
    {
        return app(DashboardAccess::class)->allows(request(), DashboardAccess::HTTP);
    }
}
