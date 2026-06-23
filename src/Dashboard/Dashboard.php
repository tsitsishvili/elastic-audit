<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Dashboard;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Holds the authorization callback that decides who may view the dashboard.
 *
 * Mirrors Laravel Horizon's `Horizon::auth()` approach: by default the dashboard is
 * only reachable in the local environment. Applications override access from a
 * service provider:
 *
 *   Dashboard::auth(fn ($request) => $request->user()?->isAdmin() === true);
 */
class Dashboard
{
    protected static ?Closure $authUsing = null;

    /**
     * Register the callback used to authorize dashboard access.
     *
     * Pass null to reset back to the default (local-environment-only) behaviour.
     */
    public static function auth(?Closure $callback): void
    {
        static::$authUsing = $callback;
    }

    /**
     * Determine whether the current request may access the dashboard.
     */
    public static function check(Request $request): bool
    {
        if (static::$authUsing !== null) {
            return (bool) call_user_func(static::$authUsing, $request);
        }

        return App::environment('local');
    }
}
