<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tsitsishvili\ElasticAudit\Events\AuditOperationFailed;
use Tsitsishvili\ElasticAudit\Support\AuditFailureReporter;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestProvider;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class AuditFailureReporterTest extends TestCase
{
    public function test_reporter_logs_and_dispatches_only_sanitized_scalar_context(): void
    {
        Event::fake([AuditOperationFailed::class]);
        Log::spy();

        $reporter = app(AuditFailureReporter::class);

        $reporter->report(
            subsystem: AuditOperationFailed::SUBSYSTEM_HTTP,
            stage: AuditOperationFailed::STAGE_CAPTURE,
            exception: new RuntimeException(
                'password=plain-secret Authorization: Bearer token-123 https://example.test?api_key=query-secret',
            ),
            context: [
                'provider'    => TestProvider::Delivery,
                'event_id'    => 'event-1',
                'payload'     => ['password' => 'must-not-appear'],
                'invalid key' => 'must-not-appear',
                'subsystem'   => 'spoofed',
            ],
        );

        Event::assertDispatched(
            AuditOperationFailed::class,
            function (AuditOperationFailed $event): bool {
                return $event->subsystem === AuditOperationFailed::SUBSYSTEM_HTTP
                    && $event->stage === AuditOperationFailed::STAGE_CAPTURE
                    && $event->context === [
                        'provider' => TestProvider::Delivery->value,
                        'event_id' => 'event-1',
                    ]
                    && ! str_contains($event->message, 'plain-secret')
                    && ! str_contains($event->message, 'token-123')
                    && ! str_contains($event->message, 'query-secret');
            },
        );

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Elastic Audit operation failed'
                && $context['subsystem'] === AuditOperationFailed::SUBSYSTEM_HTTP
                && $context['provider'] === TestProvider::Delivery->value
                && ! array_key_exists('payload', $context)
                && ! str_contains((string) $context['error'], 'plain-secret'),
        );
    }

    public function test_listener_exceptions_never_propagate(): void
    {
        Log::spy();
        Event::listen(AuditOperationFailed::class, static function (): void {
            throw new RuntimeException('monitor unavailable');
        });

        app(AuditFailureReporter::class)->report(
            subsystem: AuditOperationFailed::SUBSYSTEM_ACTIVITY,
            stage: AuditOperationFailed::STAGE_INDEXING,
            exception: new RuntimeException('Elasticsearch unavailable'),
        );

        $this->addToAssertionCount(1);
    }
}
