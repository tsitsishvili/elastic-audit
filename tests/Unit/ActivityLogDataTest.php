<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogContext;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogData;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEntityType;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class ActivityLogDataTest extends TestCase
{
    private ActivityLogContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = ActivityLogContext::forActor(
            actorType: 'user',
            actorId: 5,
            entityType: TestEntityType::Order,
            entityId: '10',
            requestId: 'req-abc',
        );
    }

    public function test_make_populates_all_fields(): void
    {
        $data = ActivityLogData::make(
            action: 'order.updated',
            context: $this->context,
            changes: ['status' => ['old' => 'pending', 'new' => 'paid']],
            metadata: ['ip' => '1.2.3.4'],
            success: true,
        );

        $this->assertSame('order.updated', $data->action);
        $this->assertSame('user', $data->actorType);
        $this->assertSame(5, $data->actorId);
        $this->assertSame('order', $data->entityType);
        $this->assertSame('10', $data->entityId);
        $this->assertSame('req-abc', $data->requestId);
        $this->assertSame(['status' => ['old' => 'pending', 'new' => 'paid']], $data->changes);
        $this->assertSame(['ip' => '1.2.3.4'], $data->metadata);
        $this->assertTrue($data->success);
        $this->assertNull($data->errorClass);
        $this->assertNull($data->errorMessage);
        $this->assertNotEmpty($data->eventId);
        $this->assertNotEmpty($data->timestamp);
        $this->assertSame(1, ActivityLogData::SCHEMA_VERSION);
    }

    public function test_make_defaults_to_empty_changes_and_metadata(): void
    {
        $data = ActivityLogData::make(action: 'order.deleted', context: $this->context);

        $this->assertSame([], $data->changes);
        $this->assertSame([], $data->metadata);
    }

    public function test_make_accepts_error_fields(): void
    {
        $data = ActivityLogData::make(
            action: 'order.failed',
            context: $this->context,
            success: false,
            errorClass: 'RuntimeException',
            errorMessage: 'something broke',
        );

        $this->assertFalse($data->success);
        $this->assertSame('RuntimeException', $data->errorClass);
        $this->assertSame('something broke', $data->errorMessage);
    }

    public function test_each_make_call_produces_unique_event_id(): void
    {
        $a = ActivityLogData::make('order.updated', $this->context);
        $b = ActivityLogData::make('order.updated', $this->context);

        $this->assertNotSame($a->eventId, $b->eventId);
    }
}
