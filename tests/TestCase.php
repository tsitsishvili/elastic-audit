<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests;

use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEntityType;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEventType;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestProvider;
use Tsitsishvili\ElasticAudit\ElasticAuditServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [ElasticAuditServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('http_logs.enums.provider', TestProvider::class);
        $app['config']->set('http_logs.enums.event_type', TestEventType::class);
        $app['config']->set('http_logs.enums.entity_type', TestEntityType::class);
        $app['config']->set('http_logs.enums.entity_type_default', 'none');
        $app['config']->set('http_logs.payment_provider_values', [TestProvider::Payment->value]);
        $app['config']->set('http_logs.queue', 'default');
    }
}
