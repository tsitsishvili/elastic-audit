<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature\Dashboard;

use Tsitsishvili\ElasticAudit\Tests\TestCase;

class DashboardCustomPrefixTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // HTTP dashboard: empty group prefix should collapse to the subpath only.
        $app['config']->set('http_logs.dashboard.prefix', '');
        $app['config']->set('http_logs.dashboard.path', 'http');

        // Activity dashboard: a nested custom group prefix.
        $app['config']->set('activity_logs.dashboard.prefix', 'admin/logs');
        $app['config']->set('activity_logs.dashboard.path', 'activity');
    }

    public function test_empty_prefix_collapses_to_subpath_only(): void
    {
        $this->assertSame('/http', route('http-logs.overview', [], false));
    }

    public function test_nested_custom_prefix_is_applied(): void
    {
        $this->assertSame('/admin/logs/activity', route('activity-logs.overview', [], false));
    }
}
