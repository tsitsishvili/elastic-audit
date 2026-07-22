<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogContext;
use Tsitsishvili\ElasticAudit\Jobs\LogActivityJob;
use Tsitsishvili\ElasticAudit\Services\ActivityLogIndexer;
use Tsitsishvili\ElasticAudit\Services\ActivityLogger;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class ActivityLoggerTest extends TestCase
{
    private ActivityLogger $logger;

    private ActivityLogContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger  = new ActivityLogger();
        $this->context = ActivityLogContext::forActor(
            actorType: 'user',
            actorId: 5,
            entityType: 'order',
            entityId: '10',
        );
    }

    public function test_record_dispatches_job_when_enabled(): void
    {
        config(['activity_logs.enabled' => true]);
        Bus::fake();

        $this->logger->record(
            action: 'order.updated',
            context: $this->context,
            changes: ['status' => ['old' => 'pending', 'new' => 'paid']],
        );

        Bus::assertDispatched(LogActivityJob::class, function (LogActivityJob $job) {
            return $job->data->action === 'order.updated'
                && $job->data->actorId === 5
                && $job->data->changes === ['status' => ['old' => 'pending', 'new' => 'paid']];
        });
    }

    public function test_record_redacts_sensitive_changes_and_metadata(): void
    {
        config(['activity_logs.enabled' => true]);
        Bus::fake();

        $this->logger->record(
            action: 'user.updated',
            context: $this->context,
            changes: [
                'password' => ['old' => 'old-hash', 'new' => 'new-hash'],
                'status'   => ['old' => 'pending', 'new' => 'active'],
            ],
            metadata: ['ip' => '1.2.3.4', 'api_key' => 'secret-key'],
        );

        Bus::assertDispatched(LogActivityJob::class, function (LogActivityJob $job) {
            return $job->data->changes['password'] === [
                'old' => '[REDACTED]',
                'new' => '[REDACTED]',
            ]
                && $job->data->changes['status'] === ['old' => 'pending', 'new' => 'active']
                && $job->data->metadata['api_key'] === '[REDACTED]'
                && $job->data->metadata['ip'] === '1.2.3.4';
        });
    }

    public function test_record_is_no_op_when_disabled(): void
    {
        config(['activity_logs.enabled' => false]);
        Bus::fake();

        $this->logger->record(action: 'order.updated', context: $this->context);

        Bus::assertNotDispatched(LogActivityJob::class);
    }

    public function test_record_does_not_propagate_internal_exceptions(): void
    {
        config(['activity_logs.enabled' => true]);

        $this->expectNotToPerformAssertions();

        Bus::shouldReceive('dispatch')->andThrow(new \RuntimeException('bus broken'));

        $this->logger->record(action: 'order.updated', context: $this->context);
    }

    public function test_record_passes_metadata_and_success_flag(): void
    {
        config(['activity_logs.enabled' => true]);
        Bus::fake();

        $this->logger->record(
            action: 'order.failed',
            context: $this->context,
            metadata: ['reason' => 'timeout'],
            success: false,
            errorClass: 'TimeoutException',
            errorMessage: 'Request timed out',
        );

        Bus::assertDispatched(LogActivityJob::class, function (LogActivityJob $job) {
            return $job->data->success === false
                && $job->data->errorClass === 'TimeoutException'
                && $job->data->metadata === ['reason' => 'timeout'];
        });
    }

    public function test_record_sanitizes_and_caps_error_messages_before_dispatch(): void
    {
        config(['activity_logs.enabled' => true]);
        Bus::fake();

        $this->logger->record(
            action: 'order.failed',
            context: $this->context,
            success: false,
            errorClass: 'ProviderException',
            errorMessage: 'password=plain-secret Authorization: Bearer token-123 '
                . 'https://user:pass@example.test/orders?api_key=query-secret '
                . str_repeat('ü', 2000),
        );

        Bus::assertDispatched(LogActivityJob::class, function (LogActivityJob $job): bool {
            $message = $job->data->errorMessage;

            return is_string($message)
                && strlen($message) <= 2048
                && mb_check_encoding($message, 'UTF-8')
                && ! str_contains($message, 'plain-secret')
                && ! str_contains($message, 'token-123')
                && ! str_contains($message, 'query-secret')
                && ! str_contains($message, 'user:pass')
                && str_contains($message, '[REDACTED]');
        });
    }

    public function test_record_is_queued_only_after_database_commit(): void
    {
        config([
            'activity_logs.enabled'                   => true,
            'queue.default'                           => 'sync',
            'database.default'                        => 'activity_logs_test',
            'database.connections.activity_logs_test' => [
                'driver'   => 'sqlite',
                'database' => ':memory:',
                'prefix'   => '',
            ],
        ]);

        $indexed = false;
        $indexer = $this->createMock(ActivityLogIndexer::class);
        $indexer->expects($this->once())->method('index')->willReturnCallback(function () use (&$indexed): void {
            $indexed = true;
        });
        $this->app->instance(ActivityLogIndexer::class, $indexer);

        $connection = DB::connection('activity_logs_test');
        $connection->beginTransaction();

        $this->logger->record(action: 'order.updated', context: $this->context);

        $this->assertFalse($indexed);

        $connection->commit();

        $this->assertTrue($indexed);
    }

    public function test_record_is_not_queued_when_database_transaction_rolls_back(): void
    {
        config([
            'activity_logs.enabled'                   => true,
            'queue.default'                           => 'sync',
            'database.default'                        => 'activity_logs_test',
            'database.connections.activity_logs_test' => [
                'driver'   => 'sqlite',
                'database' => ':memory:',
                'prefix'   => '',
            ],
        ]);

        $indexer = $this->createMock(ActivityLogIndexer::class);
        $indexer->expects($this->never())->method('index');
        $this->app->instance(ActivityLogIndexer::class, $indexer);

        $connection = DB::connection('activity_logs_test');
        $connection->beginTransaction();

        $this->logger->record(action: 'order.updated', context: $this->context);

        $connection->rollBack();
    }
}
