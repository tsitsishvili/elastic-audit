<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature;

use InvalidArgumentException;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogContext;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogContext;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEntityType;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class RetentionDaysConfigTest extends TestCase
{
    public function test_http_context_reads_retention_days_from_config(): void
    {
        config(['http_logs.retention_days' => 30]);

        $context = HttpLogContext::forEntity(TestEntityType::Order, '1');

        $this->assertSame(30, $context->retentionDays);
    }

    public function test_explicit_http_retention_days_override_config(): void
    {
        config(['http_logs.retention_days' => 30]);

        $context = HttpLogContext::forEntity(TestEntityType::Order, '1', retentionDays: 7);

        $this->assertSame(7, $context->retentionDays);
    }

    public function test_http_context_uses_permanent_default_from_config(): void
    {
        config(['http_logs.retain_forever' => true]);

        $context = HttpLogContext::forEntity(TestEntityType::Order, '1');

        $this->assertNull($context->retentionDays);
    }

    public function test_explicit_http_retention_days_override_permanent_default(): void
    {
        config(['http_logs.retain_forever' => true]);

        $context = HttpLogContext::forEntity(TestEntityType::Order, '1', retentionDays: 7);

        $this->assertSame(7, $context->retentionDays);
    }

    public function test_http_context_can_be_retained_forever_individually(): void
    {
        $context = HttpLogContext::forEntity(
            TestEntityType::Order,
            '1',
            retainForever: true,
        );

        $this->assertNull($context->retentionDays);
    }

    public function test_http_context_defaults_to_360_without_config_override(): void
    {
        $context = HttpLogContext::forEntity(TestEntityType::Order, '1');

        $this->assertSame(360, $context->retentionDays);
    }

    public function test_activity_context_reads_retention_days_from_config(): void
    {
        config(['activity_logs.retention_days' => 90]);

        $context = ActivityLogContext::forActor('user', 1, 'order', '9');

        $this->assertSame(90, $context->retentionDays);
    }

    public function test_explicit_activity_retention_days_override_config(): void
    {
        config(['activity_logs.retention_days' => 90]);

        $context = ActivityLogContext::forActor('user', 1, 'order', '9', retentionDays: 14);

        $this->assertSame(14, $context->retentionDays);
    }

    public function test_activity_context_uses_permanent_default_from_config(): void
    {
        config(['activity_logs.retain_forever' => true]);

        $context = ActivityLogContext::forActor('user', 1, 'order', '9');

        $this->assertNull($context->retentionDays);
    }

    public function test_activity_context_can_be_retained_forever_individually(): void
    {
        $context = ActivityLogContext::forActor(
            'user',
            1,
            'order',
            '9',
            retainForever: true,
        );

        $this->assertNull($context->retentionDays);
    }

    public function test_context_rejects_conflicting_retention_options(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retentionDays and retainForever cannot both be set');

        ActivityLogContext::forActor(
            'user',
            1,
            'order',
            '9',
            retentionDays: 30,
            retainForever: true,
        );
    }

    public function test_http_context_rejects_non_positive_retention(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retention_days must be between 1 and 32767');

        HttpLogContext::forEntity(TestEntityType::Order, '1', retentionDays: 0);
    }

    public function test_activity_context_rejects_retention_outside_mapping_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retention_days must be between 1 and 32767');

        ActivityLogContext::forActor('user', 1, 'order', '9', retentionDays: 32768);
    }

    public function test_invalid_configured_retention_is_rejected(): void
    {
        config(['http_logs.retention_days' => -1]);

        $this->expectException(InvalidArgumentException::class);

        HttpLogContext::forEntity(TestEntityType::Order, '1');
    }

    public function test_fractional_configured_retention_is_not_silently_truncated(): void
    {
        config(['activity_logs.retention_days' => 1.5]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retention_days must be an integer');

        ActivityLogContext::forActor('user', 1, 'order', '9');
    }
}
