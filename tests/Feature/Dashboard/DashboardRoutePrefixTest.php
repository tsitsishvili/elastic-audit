<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature\Dashboard;

use Tsitsishvili\ElasticAudit\Tests\TestCase;

class DashboardRoutePrefixTest extends TestCase
{
    public function test_default_group_prefix_composes_subpaths(): void
    {
        $this->assertSame('/logger/http-logs', route('http-logs.overview', [], false));
        $this->assertSame('/logger/activity', route('activity-logs.overview', [], false));
    }
}
