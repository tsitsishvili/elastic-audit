<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Support;

use Illuminate\Container\Container;
use Throwable;

/**
 * Decides which profiler frames belong to the application.
 *
 * A profile is mostly framework and vendor code. Automatic function timing is
 * only useful when it reports the consuming application's own methods, so
 * frames are matched against configured namespace prefixes — defaulting to the
 * namespace Laravel already knows the application by.
 */
final class ApplicationFrames
{
    /** Frames that are never application code regardless of configuration. */
    private const ALWAYS_EXCLUDED = [
        'Tsitsishvili\\ElasticAudit\\',
    ];

    /** @var list<string>|null */
    private static ?array $cachedDefault = null;

    /**
     * @return list<string>
     */
    public static function prefixes(): array
    {
        $configured = (array) PackageConfig::get('elastic_audit_metrics.capture.functions.namespaces', []);
        $prefixes   = [];

        foreach ($configured as $prefix) {
            if (is_string($prefix) && $prefix !== '') {
                $prefixes[] = $prefix;
            }
        }

        return $prefixes !== [] ? $prefixes : self::detectedNamespace();
    }

    /**
     * @param  list<string>  $prefixes
     */
    public static function isApplicationFrame(string $function, array $prefixes): bool
    {
        foreach (self::ALWAYS_EXCLUDED as $excluded) {
            if (str_starts_with($function, $excluded)) {
                return false;
            }
        }

        foreach ($prefixes as $prefix) {
            if (str_starts_with($function, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Testing seam; the detected namespace cannot change within a process. */
    public static function flush(): void
    {
        self::$cachedDefault = null;
    }

    /**
     * The application's root namespace, as Laravel resolves it from composer.json.
     *
     * @return list<string>
     */
    private static function detectedNamespace(): array
    {
        if (self::$cachedDefault !== null) {
            return self::$cachedDefault;
        }

        try {
            // getNamespace() lives on Application, not the bare Container that
            // a package test or a console bootstrap may have bound.
            $application = Container::getInstance();
            $namespace   = method_exists($application, 'getNamespace')
                ? (string) $application->getNamespace()
                : '';
        } catch (Throwable) {
            $namespace = '';
        }

        return self::$cachedDefault = $namespace !== '' ? [$namespace] : [];
    }
}
