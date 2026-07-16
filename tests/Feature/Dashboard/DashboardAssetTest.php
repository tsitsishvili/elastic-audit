<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature\Dashboard;

use Tsitsishvili\ElasticAudit\Dashboard\DashboardAssets;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class DashboardAssetTest extends TestCase
{
    public function test_manifest_asset_is_served_without_publishing(): void
    {
        $asset = $this->app->make(DashboardAssets::class)
            ->manifest()['resources/css/elastic-audit.css']['file'];

        $response = $this->get(route('elastic-audit.assets', [
            'asset' => $asset,
        ], false));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'text/css; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $cacheControl = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=31536000', $cacheControl);
        $this->assertStringContainsString('immutable', $cacheControl);
        $this->assertNotEmpty($response->headers->get('Etag'));
    }

    public function test_manifest_javascript_asset_has_an_explicit_content_type(): void
    {
        $asset = $this->app->make(DashboardAssets::class)
            ->manifest()['resources/js/alpine.js']['file'];

        $this->get(route('elastic-audit.assets', [
            'asset' => $asset,
        ], false))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8');
    }

    public function test_asset_not_listed_in_manifest_is_not_served(): void
    {
        $this->get(route('elastic-audit.assets', [
            'asset' => 'manifest.json',
        ], false))->assertNotFound();
    }
}
