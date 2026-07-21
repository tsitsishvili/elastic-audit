<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogContext;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEntityType;

class HttpLogContextTest extends TestCase
{
    public function test_for_entity_accepts_integer_user_id(): void
    {
        $context = HttpLogContext::forEntity(
            entityType: TestEntityType::Order,
            entityId: '1',
            userId: 42,
        );

        $this->assertSame(42, $context->userId);
    }

    public function test_for_entity_accepts_uuid_user_id(): void
    {
        $context = HttpLogContext::forEntity(
            entityType: TestEntityType::Order,
            entityId: '1',
            userId: '550e8400-e29b-41d4-a716-446655440000',
        );

        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $context->userId);
    }
}
