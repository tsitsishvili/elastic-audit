<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Tsitsishvili\ElasticAudit\Enums\HttpDirection;
use Tsitsishvili\ElasticAudit\Http\Middleware\IncomingHttpLogMiddleware;
use Tsitsishvili\ElasticAudit\Jobs\LogHttpRequestJob;
use Tsitsishvili\ElasticAudit\Services\HttpLogger;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\IntBackedProvider;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEntityType;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEventType;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestProvider;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class IncomingHttpLogMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['http_logs.enabled' => true]);
    }

    public function test_middleware_skips_job_when_provider_attribute_is_missing(): void
    {
        Bus::fake();

        Route::post('/_test/callback', fn () => response()->json(['received' => true]))
            ->middleware(IncomingHttpLogMiddleware::class);

        $this->postJson('/_test/callback', ['status' => 'delivered'])->assertOk();

        Bus::assertNotDispatched(LogHttpRequestJob::class);
    }

    public function test_middleware_dispatches_job_when_request_attributes_are_set(): void
    {
        Bus::fake();

        Route::post('/_test/callback-with-attrs', function () {
            request()->attributes->set('third_party_provider', TestProvider::Delivery->value);
            request()->attributes->set('third_party_event_type', TestEventType::DeliveryStatusCallback->value);
            request()->attributes->set('third_party_entity_type', TestEntityType::Order->value);
            request()->attributes->set('third_party_entity_id', '99');

            return response()->json(['received' => true]);
        })->middleware(IncomingHttpLogMiddleware::class);

        $this->postJson('/_test/callback-with-attrs', ['status' => 'delivered'])->assertOk();

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->direction === HttpDirection::Incoming
                && $job->data->provider === TestProvider::Delivery;
        });
    }

    public function test_middleware_is_no_op_when_logging_disabled(): void
    {
        config(['http_logs.enabled' => false]);

        Bus::fake();

        Route::post('/_test/callback-disabled', fn () => response()->json(['ok' => true]))
            ->middleware(IncomingHttpLogMiddleware::class);

        $this->postJson('/_test/callback-disabled', [])->assertOk();

        Bus::assertNotDispatched(LogHttpRequestJob::class);
    }

    public function test_middleware_ignores_provider_in_route_url_segments(): void
    {
        // Provider must come from request->attributes (server-side), never from URL parameters.
        Bus::fake();

        Route::post('/_test/callback/{third_party_provider}', fn () => response()->json(['ok' => true]))
            ->middleware(IncomingHttpLogMiddleware::class);

        $this->postJson('/_test/callback/delivery', [])->assertOk();

        Bus::assertNotDispatched(LogHttpRequestJob::class);
    }

    public function test_dispatched_job_records_nonzero_latency(): void
    {
        Bus::fake();

        Route::post('/_test/callback-latency', function () {
            request()->attributes->set('third_party_provider', TestProvider::Delivery->value);
            request()->attributes->set('third_party_event_type', TestEventType::DeliveryStatusCallback->value);

            return response()->json(['ok' => true]);
        })->middleware(IncomingHttpLogMiddleware::class);

        $this->postJson('/_test/callback-latency', [])->assertOk();

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->latencyMs >= 0;
        });
    }

    public function test_middleware_skips_when_provider_enum_class_not_configured(): void
    {
        config(['http_logs.enums.provider' => null]);
        Bus::fake();

        Route::post('/_test/callback-no-provider-class', function () {
            request()->attributes->set('third_party_provider', TestProvider::Delivery->value);
            request()->attributes->set('third_party_event_type', TestEventType::DeliveryStatusCallback->value);

            return response()->json(['ok' => true]);
        })->middleware(IncomingHttpLogMiddleware::class);

        $this->postJson('/_test/callback-no-provider-class', [])->assertOk();

        Bus::assertNotDispatched(LogHttpRequestJob::class);
    }

    public function test_middleware_skips_when_provider_enum_class_does_not_exist(): void
    {
        config(['http_logs.enums.provider' => 'App\\Enums\\ElasticAudit\\MissingProvider']);
        Bus::fake();

        Route::post('/_test/callback-missing-provider-class', function () {
            request()->attributes->set('third_party_provider', TestProvider::Delivery->value);
            request()->attributes->set('third_party_event_type', TestEventType::DeliveryStatusCallback->value);

            return response()->json(['ok' => true]);
        })->middleware(IncomingHttpLogMiddleware::class);

        $this->postJson('/_test/callback-missing-provider-class', [])->assertOk();

        Bus::assertNotDispatched(LogHttpRequestJob::class);
    }

    public function test_middleware_does_not_throw_for_integer_backed_enum_configuration(): void
    {
        config(['http_logs.enums.provider' => IntBackedProvider::class]);
        Bus::fake();

        Route::post('/_test/callback-integer-provider', function () {
            request()->attributes->set('third_party_provider', 1);
            request()->attributes->set('third_party_event_type', TestEventType::DeliveryStatusCallback->value);

            return response()->json(['ok' => true]);
        })->middleware(IncomingHttpLogMiddleware::class);

        $this->postJson('/_test/callback-integer-provider')->assertOk();

        Bus::assertNotDispatched(LogHttpRequestJob::class);
    }

    public function test_middleware_skips_when_event_type_enum_class_not_configured(): void
    {
        config(['http_logs.enums.event_type' => null]);
        Bus::fake();

        Route::post('/_test/callback-no-event-class', function () {
            request()->attributes->set('third_party_provider', TestProvider::Delivery->value);
            request()->attributes->set('third_party_event_type', TestEventType::DeliveryStatusCallback->value);

            return response()->json(['ok' => true]);
        })->middleware(IncomingHttpLogMiddleware::class);

        $this->postJson('/_test/callback-no-event-class', [])->assertOk();

        Bus::assertNotDispatched(LogHttpRequestJob::class);
    }

    public function test_middleware_skips_when_provider_value_does_not_match_enum(): void
    {
        Bus::fake();

        Route::post('/_test/callback-invalid-provider', function () {
            request()->attributes->set('third_party_provider', 'unknown_provider');
            request()->attributes->set('third_party_event_type', TestEventType::DeliveryStatusCallback->value);

            return response()->json(['ok' => true]);
        })->middleware(IncomingHttpLogMiddleware::class);

        $this->postJson('/_test/callback-invalid-provider', [])->assertOk();

        Bus::assertNotDispatched(LogHttpRequestJob::class);
    }

    public function test_middleware_skips_when_entity_type_enum_class_not_configured(): void
    {
        config(['http_logs.enums.entity_type' => null]);
        Bus::fake();

        Route::post('/_test/callback-no-entity-class', function () {
            request()->attributes->set('third_party_provider', TestProvider::Delivery->value);
            request()->attributes->set('third_party_event_type', TestEventType::DeliveryStatusCallback->value);

            return response()->json(['ok' => true]);
        })->middleware(IncomingHttpLogMiddleware::class);

        $this->postJson('/_test/callback-no-entity-class', [])->assertOk();

        Bus::assertNotDispatched(LogHttpRequestJob::class);
    }

    public function test_middleware_falls_back_to_default_entity_type_when_attribute_value_is_invalid(): void
    {
        Bus::fake();

        Route::post('/_test/callback-invalid-entity', function () {
            request()->attributes->set('third_party_provider', TestProvider::Delivery->value);
            request()->attributes->set('third_party_event_type', TestEventType::DeliveryStatusCallback->value);
            request()->attributes->set('third_party_entity_type', 'no_such_entity_type');
            request()->attributes->set('third_party_entity_id', '5');

            return response()->json(['ok' => true]);
        })->middleware(IncomingHttpLogMiddleware::class);

        $this->postJson('/_test/callback-invalid-entity', [])->assertOk();

        Bus::assertDispatched(LogHttpRequestJob::class);
    }

    public function test_middleware_records_external_id_and_string_user_id_from_attributes(): void
    {
        Bus::fake();

        Route::post('/_test/callback-with-ids', function () {
            request()->attributes->set('third_party_provider', TestProvider::Delivery->value);
            request()->attributes->set('third_party_event_type', TestEventType::DeliveryStatusCallback->value);
            request()->attributes->set('third_party_external_id', 'ext-abc-123');
            request()->attributes->set('third_party_user_id', '42');

            return response()->json(['ok' => true]);
        })->middleware(IncomingHttpLogMiddleware::class);

        $this->postJson('/_test/callback-with-ids', [])->assertOk();

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->externalId === 'ext-abc-123'
                && $job->data->userId === '42';
        });
    }

    public function test_middleware_records_uuid_user_id_from_attributes(): void
    {
        Bus::fake();

        Route::post('/_test/callback-with-uuid-user-id', function () {
            request()->attributes->set('third_party_provider', TestProvider::Delivery->value);
            request()->attributes->set('third_party_event_type', TestEventType::DeliveryStatusCallback->value);
            request()->attributes->set('third_party_user_id', '550e8400-e29b-41d4-a716-446655440000');

            return response()->json(['ok' => true]);
        })->middleware(IncomingHttpLogMiddleware::class);

        $this->postJson('/_test/callback-with-uuid-user-id', [])->assertOk();

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->userId === '550e8400-e29b-41d4-a716-446655440000';
        });
    }

    public function test_middleware_records_integer_user_id_from_attributes(): void
    {
        Bus::fake();

        Route::post('/_test/callback-with-integer-user-id', function () {
            request()->attributes->set('third_party_provider', TestProvider::Delivery->value);
            request()->attributes->set('third_party_event_type', TestEventType::DeliveryStatusCallback->value);
            request()->attributes->set('third_party_user_id', 42);

            return response()->json(['ok' => true]);
        })->middleware(IncomingHttpLogMiddleware::class);

        $this->postJson('/_test/callback-with-integer-user-id', [])->assertOk();

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->userId === 42;
        });
    }

    public function test_middleware_leaves_external_id_and_user_id_null_when_attributes_absent(): void
    {
        Bus::fake();

        Route::post('/_test/callback-without-ids', function () {
            request()->attributes->set('third_party_provider', TestProvider::Delivery->value);
            request()->attributes->set('third_party_event_type', TestEventType::DeliveryStatusCallback->value);

            return response()->json(['ok' => true]);
        })->middleware(IncomingHttpLogMiddleware::class);

        $this->postJson('/_test/callback-without-ids', [])->assertOk();

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->externalId === null
                && $job->data->userId === null;
        });
    }

    public function test_middleware_ignores_non_string_and_non_integer_user_id_attribute(): void
    {
        Bus::fake();

        Route::post('/_test/callback-bad-user-id', function () {
            request()->attributes->set('third_party_provider', TestProvider::Delivery->value);
            request()->attributes->set('third_party_event_type', TestEventType::DeliveryStatusCallback->value);
            request()->attributes->set('third_party_user_id', ['invalid']);

            return response()->json(['ok' => true]);
        })->middleware(IncomingHttpLogMiddleware::class);

        $this->postJson('/_test/callback-bad-user-id', [])->assertOk();

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->userId === null;
        });
    }

    public function test_middleware_treats_empty_user_id_as_absent(): void
    {
        Bus::fake();

        Route::post('/_test/callback-empty-user-id', function () {
            request()->attributes->set('third_party_provider', TestProvider::Delivery->value);
            request()->attributes->set('third_party_event_type', TestEventType::DeliveryStatusCallback->value);
            request()->attributes->set('third_party_user_id', '');

            return response()->json(['ok' => true]);
        })->middleware(IncomingHttpLogMiddleware::class);

        $this->postJson('/_test/callback-empty-user-id')->assertOk();

        Bus::assertDispatched(
            LogHttpRequestJob::class,
            fn (LogHttpRequestJob $job): bool => $job->data->userId === null,
        );
    }

    public function test_middleware_treats_whitespace_user_id_as_absent(): void
    {
        Bus::fake();

        Route::post('/_test/callback-whitespace-user-id', function () {
            request()->attributes->set('third_party_provider', TestProvider::Delivery->value);
            request()->attributes->set('third_party_event_type', TestEventType::DeliveryStatusCallback->value);
            request()->attributes->set('third_party_user_id', " \t ");

            return response()->json(['ok' => true]);
        })->middleware(IncomingHttpLogMiddleware::class);

        $this->postJson('/_test/callback-whitespace-user-id')->assertOk();

        Bus::assertDispatched(
            LogHttpRequestJob::class,
            fn (LogHttpRequestJob $job): bool => $job->data->userId === null,
        );
    }

    public function test_middleware_logs_the_response_body(): void
    {
        Bus::fake();

        Route::post('/_test/callback-with-response', function () {
            request()->attributes->set('third_party_provider', TestProvider::Delivery->value);
            request()->attributes->set('third_party_event_type', TestEventType::DeliveryStatusCallback->value);

            return response()->json(['received' => true, 'id' => 123]);
        })->middleware(IncomingHttpLogMiddleware::class);

        $this->postJson('/_test/callback-with-response', ['status' => 'delivered'])->assertOk();

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->response->body === ['received' => true, 'id' => 123]
                && str_contains((string) $job->data->response->bodyPreview, 'received');
        });
    }

    public function test_middleware_logs_failed_callback_before_rethrowing_exception(): void
    {
        Bus::fake();
        $this->withoutExceptionHandling();

        Route::post('/_test/callback-throws', function () {
            request()->attributes->set('third_party_provider', TestProvider::Delivery->value);
            request()->attributes->set('third_party_event_type', TestEventType::DeliveryStatusCallback->value);
            request()->attributes->set('third_party_entity_type', TestEntityType::Order->value);
            request()->attributes->set('third_party_entity_id', '99');

            throw new \RuntimeException('Handler failed at https://example.com/callback?token=secret');
        })->middleware(IncomingHttpLogMiddleware::class);

        try {
            $this->postJson('/_test/callback-throws', ['status' => 'failed']);
            $this->fail('Expected callback exception to be rethrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Handler failed at https://example.com/callback?token=secret', $e->getMessage());
        }

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->success === false
                && $job->data->httpStatusCode === 500
                && $job->data->errorClass === \RuntimeException::class
                && ! str_contains((string) $job->data->errorMessage, 'token=secret');
        });
    }

    public function test_failed_callback_is_logged_once_even_when_terminate_runs(): void
    {
        // Exception handling stays ON, so the kernel renders a response for the
        // thrown error AND runs terminate(). handle() logs the failure inline and
        // leaves no latency attribute, so terminate() must not log it a second time.
        Bus::fake();

        Route::post('/_test/callback-throws-handled', function () {
            request()->attributes->set('third_party_provider', TestProvider::Delivery->value);
            request()->attributes->set('third_party_event_type', TestEventType::DeliveryStatusCallback->value);

            throw new \RuntimeException('boom');
        })->middleware(IncomingHttpLogMiddleware::class);

        $this->postJson('/_test/callback-throws-handled')->assertStatus(500);

        Bus::assertDispatchedTimes(LogHttpRequestJob::class, 1);
    }

    public function test_middleware_accepts_configured_enum_instances(): void
    {
        Bus::fake();

        Route::post('/_test/callback-enum-instances', function () {
            request()->attributes->set('third_party_provider', TestProvider::Delivery);
            request()->attributes->set('third_party_event_type', TestEventType::DeliveryStatusCallback);
            request()->attributes->set('third_party_entity_type', TestEntityType::Order);

            return response()->json(['ok' => true], 202);
        })->middleware(IncomingHttpLogMiddleware::class);

        $this->postJson('/_test/callback-enum-instances')->assertAccepted();

        Bus::assertDispatched(LogHttpRequestJob::class);
    }

    public function test_middleware_rejects_arbitrary_attribute_objects_without_affecting_response(): void
    {
        Bus::fake();

        Route::post('/_test/callback-object-attributes', function () {
            request()->attributes->set('third_party_provider', new \stdClass);
            request()->attributes->set('third_party_event_type', TestEventType::DeliveryStatusCallback);

            return response()->json(['ok' => true], 202);
        })->middleware(IncomingHttpLogMiddleware::class);

        $this->postJson('/_test/callback-object-attributes')->assertAccepted();

        Bus::assertNotDispatched(LogHttpRequestJob::class);
    }

    public function test_logging_failure_does_not_change_successful_callback_response(): void
    {
        $logger = $this->createMock(HttpLogger::class);
        $logger->expects($this->once())
            ->method('logIncoming')
            ->willThrowException(new \RuntimeException('logger failed'));
        $this->app->instance(HttpLogger::class, $logger);

        Route::post('/_test/callback-logger-fails', function () {
            request()->attributes->set('third_party_provider', TestProvider::Delivery);
            request()->attributes->set('third_party_event_type', TestEventType::DeliveryStatusCallback);

            return response()->json(['ok' => true], 202);
        })->middleware(IncomingHttpLogMiddleware::class);

        $this->postJson('/_test/callback-logger-fails')->assertAccepted();
    }

    public function test_logging_failure_does_not_replace_original_callback_exception(): void
    {
        $logger = $this->createMock(HttpLogger::class);
        $logger->expects($this->once())
            ->method('logIncoming')
            ->willThrowException(new \RuntimeException('logger failed'));
        $this->app->instance(HttpLogger::class, $logger);

        $original = new \DomainException('handler failed');
        $this->withoutExceptionHandling();

        Route::post('/_test/callback-original-exception', function () use ($original) {
            request()->attributes->set('third_party_provider', TestProvider::Delivery);
            request()->attributes->set('third_party_event_type', TestEventType::DeliveryStatusCallback);

            throw $original;
        })->middleware(IncomingHttpLogMiddleware::class);

        try {
            $this->postJson('/_test/callback-original-exception');
            $this->fail('Expected the callback exception.');
        } catch (\Throwable $caught) {
            $this->assertSame($original, $caught);
        }
    }

    public function test_middleware_preserves_http_exception_status_in_log(): void
    {
        Bus::fake();
        $this->withoutExceptionHandling();

        Route::post('/_test/callback-http-exception', function () {
            request()->attributes->set('third_party_provider', TestProvider::Delivery);
            request()->attributes->set('third_party_event_type', TestEventType::DeliveryStatusCallback);

            throw new UnprocessableEntityHttpException('invalid callback');
        })->middleware(IncomingHttpLogMiddleware::class);

        try {
            $this->postJson('/_test/callback-http-exception');
            $this->fail('Expected the callback exception.');
        } catch (UnprocessableEntityHttpException) {
            // The original HTTP exception must remain visible to the caller.
        }

        Bus::assertDispatched(
            LogHttpRequestJob::class,
            fn (LogHttpRequestJob $job): bool => $job->data->httpStatusCode === 422
                && $job->data->success === false,
        );
    }
}
