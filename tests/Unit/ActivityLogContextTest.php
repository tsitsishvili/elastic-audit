<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogContext;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEntityType;

class ActivityLogContextTest extends TestCase
{
    public function test_for_actor_builds_context_with_user(): void
    {
        $ctx = ActivityLogContext::forActor(
            actorType: 'user',
            actorId: 42,
            entityType: TestEntityType::Order,
            entityId: '99',
        );

        $this->assertSame('user', $ctx->actorType);
        $this->assertSame(42, $ctx->actorId);
        $this->assertSame('order', $ctx->entityType);
        $this->assertSame('99', $ctx->entityId);
        $this->assertSame(360, $ctx->retentionDays);
        $this->assertNotEmpty($ctx->requestId);
    }

    public function test_for_actor_accepts_null_actor_id_for_system_actors(): void
    {
        $ctx = ActivityLogContext::forActor(
            actorType: 'cron',
            actorId: null,
            entityType: TestEntityType::Order,
            entityId: '1',
        );

        $this->assertNull($ctx->actorId);
        $this->assertSame('cron', $ctx->actorType);
    }

    public function test_for_actor_accepts_custom_request_id(): void
    {
        $ctx = ActivityLogContext::forActor(
            actorType: 'user',
            actorId: 1,
            entityType: TestEntityType::Order,
            entityId: '1',
            requestId: 'my-request-id',
        );

        $this->assertSame('my-request-id', $ctx->requestId);
    }

    public function test_for_actor_generates_request_id_when_not_provided(): void
    {
        $ctx1 = ActivityLogContext::forActor('user', 1, TestEntityType::Order, '1');
        $ctx2 = ActivityLogContext::forActor('user', 1, TestEntityType::Order, '1');

        $this->assertNotSame($ctx1->requestId, $ctx2->requestId);
    }
}
