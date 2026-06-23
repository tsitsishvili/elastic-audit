<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tsitsishvili\ElasticAudit\Dashboard\Dashboard;

class AuthorizeDashboard
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Dashboard::check($request), 403);

        return $next($request);
    }
}
