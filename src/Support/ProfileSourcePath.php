<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Support;

use Throwable;

/**
 * Applies the profile source-path policy.
 *
 * Frame names are not just identifiers. Since PHP 8.4 a closure is named after
 * the file it was declared in — `{closure:/srv/app/vendor/.../Connection.php(426)}`
 * — so honouring `profiles.include_paths` means sanitising the name itself, not
 * only the separate `file` field.
 */
final class ProfileSourcePath
{
    /**
     * Absolute POSIX (`/srv/app/...`) and Windows (`C:\srv\app\...`) paths.
     *
     * A bare backslash never starts a match, so namespace separators in a
     * class name such as `Illuminate\Database\Connection` are left alone. The
     * lookbehind keeps the drive-letter branch from firing mid-word, where the
     * `e:/` in `{closure:/srv/...}` would otherwise read as a drive.
     */
    private const ABSOLUTE_PATH = '#(?:(?<![A-Za-z0-9_])[A-Za-z]:[\\\\/]|/)[^\s()\[\]{}<>"|*?]+#';

    /**
     * Reduce an absolute path to one relative to the application root, falling
     * back to the basename for anything outside it.
     */
    public static function relative(string $path): string
    {
        $base = self::applicationRoot();

        return $base !== '' && str_starts_with($path, $base)
            ? mb_substr($path, strlen($base), 1024)
            : mb_substr(basename($path), 0, 1024);
    }

    /**
     * Rewrite any absolute path embedded in a frame name: relative when paths
     * are captured, removed entirely when they are not.
     */
    public static function sanitizeName(string $name, bool $includePaths): string
    {
        $sanitized = preg_replace_callback(
            self::ABSOLUTE_PATH,
            static fn (array $matches): string => $includePaths ? self::relative($matches[0]) : '',
            $name,
        );

        return $sanitized ?? $name;
    }

    /**
     * The helper exists whenever Laravel's helper file is loaded, which does not
     * mean the bound container can answer it. Profiling runs inside a catch-all
     * guard, so an exception here would silently discard the whole profile.
     */
    private static function applicationRoot(): string
    {
        if (! function_exists('base_path')) {
            return '';
        }

        try {
            return rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        } catch (Throwable) {
            return '';
        }
    }
}
