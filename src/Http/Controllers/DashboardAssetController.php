<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tsitsishvili\ElasticAudit\Dashboard\DashboardAssets;

final class DashboardAssetController
{
    private const CACHE_SECONDS = 31_536_000;

    public function __construct(
        private readonly DashboardAssets $assets,
    ) {}

    public function __invoke(string $asset): BinaryFileResponse
    {
        $path = $this->assets->path($asset);

        abort_if($path === null, 404);

        $response = response()->file($path, [
            'Content-Type'           => $this->contentType($path),
            'X-Content-Type-Options' => 'nosniff',
        ]);

        $response->setPublic();
        $response->setMaxAge(self::CACHE_SECONDS);
        $response->setImmutable();
        $response->setEtag(hash('sha256', $asset));

        return $response;
    }

    private function contentType(string $path): string
    {
        return match (pathinfo($path, PATHINFO_EXTENSION)) {
            'css'   => 'text/css; charset=UTF-8',
            'js'    => 'application/javascript; charset=UTF-8',
            'svg'   => 'image/svg+xml',
            'png'   => 'image/png',
            'webp'  => 'image/webp',
            'woff'  => 'font/woff',
            'woff2' => 'font/woff2',
            default => 'application/octet-stream',
        };
    }
}
