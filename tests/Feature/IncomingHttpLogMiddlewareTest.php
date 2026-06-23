<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Tsitsishvili\ElasticAudit\Enums\HttpDirection;
use Tsitsishvili\ElasticAudit\Http\Middleware\IncomingHttpLogMiddleware;
use Tsitsishvili\ElasticAudit\Jobs\LogHttpRequestJob;
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
}
