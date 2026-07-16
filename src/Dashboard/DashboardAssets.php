<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Dashboard;

use RuntimeException;

final class DashboardAssets
{
    private const DIRECTORY = __DIR__ . '/../../public/vendor/elastic-audit';

    /** @var array<string, array{file: string}>|null */
    private ?array $manifest = null;

    /**
     * Read the build manifest shipped with the Composer package.
     *
     * @return array<string, array{file: string}>
     */
    public function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $manifestPath = self::DIRECTORY . '/manifest.json';

        if (! is_file($manifestPath)) {
            throw new RuntimeException('Elastic Audit dashboard assets are missing. Run `npm run build` before packaging the library.');
        }

        $manifest = json_decode(
            (string) file_get_contents($manifestPath),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return $this->manifest = is_array($manifest) ? $manifest : [];
    }

    /**
     * Resolve a requested asset only when its exact path is listed in the manifest.
     */
    public function path(string $asset): ?string
    {
        $allowedAssets = array_column($this->manifest(), 'file');

        if (! in_array($asset, $allowedAssets, true)) {
            return null;
        }

        $path = self::DIRECTORY . '/' . $asset;

        return is_file($path) ? $path : null;
    }
}
