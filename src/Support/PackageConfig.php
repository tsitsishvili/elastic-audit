<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Support;

use Illuminate\Container\Container;

final class PackageConfig
{
    /**
     * Read config through the bound container while keeping DTO factories
     * constructible outside a Laravel application, such as in plain unit tests.
     */
    public static function get(string $key, mixed $default): mixed
    {
        $container = Container::getInstance();

        if (! $container->bound('config')) {
            return $default;
        }

        return $container->make('config')->get($key, $default);
    }
}
