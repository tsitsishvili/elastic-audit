<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services;

final class EndpointNormalizer
{
    private const MAX_PATH_BYTES = 2048;

    /**
     * @return array{host: ?string, path: ?string}
     */
    public function normalize(string $url, bool $includePath = true): array
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return ['host' => null, 'path' => null];
        }

        $host = isset($parts['host'])
            ? mb_strcut(strtolower((string) $parts['host']), 0, 253, 'UTF-8')
            : null;
        $path = null;

        if ($includePath) {
            $rawPath = (string) ($parts['path'] ?? '/');
            $path    = preg_replace(
                '/(?<=\/)(?:\d+|[0-9a-f]{8}-[0-9a-f-]{27,}|[0-9a-f]{16,})(?=\/|$)/i',
                '{id}',
                $rawPath,
            ) ?? $rawPath;
            $path = mb_strcut($path, 0, self::MAX_PATH_BYTES, 'UTF-8');
        }

        return ['host' => $host, 'path' => $path];
    }
}
