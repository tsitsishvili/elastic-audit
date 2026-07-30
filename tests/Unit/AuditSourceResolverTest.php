<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ExecutionOrigin;
use Tsitsishvili\ElasticAudit\Support\AuditSourceResolver;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class AuditSourceResolverTest extends TestCase
{
    public function test_resolves_laravel_application_identity(): void
    {
        config([
            'app.name' => 'billing-api',
            'app.env'  => 'staging',
        ]);

        $source = (new AuditSourceResolver($this->app))->resolve();

        $this->assertSame('billing-api', $source->serviceName);
        $this->assertSame('staging', $source->serviceEnvironment);
        $this->assertSame(ExecutionOrigin::TYPE_UNKNOWN, $source->execution->type);
    }

    public function test_falls_back_to_a_default_service_name_without_configuration(): void
    {
        $source = (new AuditSourceResolver(new Container))->resolve();

        $this->assertSame('app', $source->serviceName);
        $this->assertNull($source->serviceEnvironment);
    }

    public function test_console_process_ignores_the_synthetic_console_request(): void
    {
        // Laravel's SetRequestForConsole bootstrapper binds a routeless request built
        // from app.url in every Artisan process; it must not read as HTTP execution.
        $this->assertTrue($this->app->runningInConsole());
        $this->assertTrue($this->app->bound('request'));

        $origin = (new AuditSourceResolver($this->app))->resolve()->execution;

        $this->assertSame(ExecutionOrigin::TYPE_UNKNOWN, $origin->type);
        $this->assertNull($origin->name);
    }

    public function test_routeless_request_supplied_by_the_caller_is_still_http(): void
    {
        $origin = (new AuditSourceResolver($this->app))
            ->resolve(request: Request::create('/webhooks/unmatched', 'POST'))
            ->execution;

        $this->assertSame(ExecutionOrigin::TYPE_HTTP, $origin->type);
        $this->assertNull($origin->name);
    }

    public function test_routeless_bound_request_outside_console_is_still_http(): void
    {
        // A web process serving an unmatched route: the bound request is real.
        $container = new Container;
        $container->instance('request', Request::create('/webhooks/unmatched', 'POST'));

        $origin = (new AuditSourceResolver($container))->resolve()->execution;

        $this->assertSame(ExecutionOrigin::TYPE_HTTP, $origin->type);
        $this->assertNull($origin->name);
    }

    public function test_resolves_named_http_route_and_controller_action(): void
    {
        $container = new Container;
        $request   = Request::create('/orders/42', 'PATCH');
        $route     = new Route(
            ['PATCH'],
            'orders/{order}',
            'App\\Http\\Controllers\\OrderController@update',
        );
        $route->name('orders.update');
        $request->setRouteResolver(fn (): Route => $route);
        $container->instance('request', $request);

        $origin = (new AuditSourceResolver($container))->resolve()->execution;

        $this->assertSame(ExecutionOrigin::TYPE_HTTP, $origin->type);
        $this->assertSame('orders.update', $origin->name);
        $this->assertSame('App\\Http\\Controllers\\OrderController@update', $origin->action);
    }

    public function test_uses_route_template_when_route_has_no_name(): void
    {
        $request = Request::create('/orders/42', 'PATCH');
        $route   = new Route(['PATCH'], 'orders/{order}', fn (): null => null);
        $request->setRouteResolver(fn (): Route => $route);

        $origin = (new AuditSourceResolver(new Container))
            ->resolve(request: $request)
            ->execution;

        $this->assertSame('orders/{order}', $origin->name);
        $this->assertNull($origin->action);
    }

    public function test_queue_job_takes_precedence_and_is_cleared(): void
    {
        $resolver = new AuditSourceResolver(new Container);
        $resolver->enterConsoleCommand('queue:work');
        $resolver->enterQueueJob('App\\Jobs\\SyncInvoice');

        $this->assertSame('queue', $resolver->resolve()->execution->type);
        $this->assertSame('App\\Jobs\\SyncInvoice', $resolver->resolve()->execution->name);

        $resolver->leaveQueueJob();

        $this->assertSame('console', $resolver->resolve()->execution->type);
        $this->assertSame('queue:work', $resolver->resolve()->execution->name);
    }

    public function test_explicit_origin_overrides_automatic_origin(): void
    {
        $resolver = new AuditSourceResolver(new Container);
        $resolver->enterQueueJob('App\\Jobs\\SyncInvoice');

        $source = $resolver->resolve(ExecutionOrigin::manual('invoice.raw_update'));

        $this->assertSame(ExecutionOrigin::TYPE_MANUAL, $source->execution->type);
        $this->assertSame('invoice.raw_update', $source->execution->name);
    }
}
