<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature;

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ExecutionOrigin;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogContext;
use Tsitsishvili\ElasticAudit\Facades\HttpLog;
use Tsitsishvili\ElasticAudit\Http\OutgoingHttpLogMiddleware;
use Tsitsishvili\ElasticAudit\Jobs\LogHttpRequestJob;
use Tsitsishvili\ElasticAudit\Services\Redactors\SensitiveDataRedactor;
use Tsitsishvili\ElasticAudit\Support\AuditSourceResolver;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEntityType;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEventType;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestProvider;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class HttpLogClientVerbsTest extends TestCase
{
    private HttpLogContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        config(['http_logs.enabled' => true]);

        $this->context = HttpLogContext::forEntity(
            entityType: TestEntityType::Order,
            entityId: '1',
        );
    }

    public function test_get_dispatches_log_job(): void
    {
        Bus::fake();
        Http::fake(['https://api.example/*' => Http::response(['ok' => true], 200)]);

        HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
            ->get('https://api.example/orders');

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->httpMethod === 'GET';
        });
    }

    public function test_outgoing_source_is_captured_before_the_log_job_is_dispatched(): void
    {
        config([
            'app.name' => 'orders-api',
            'app.env'  => 'production',
        ]);
        Bus::fake();
        Http::fake(['https://api.example/*' => Http::response([], 200)]);

        $context = HttpLogContext::forEntity(
            entityType: TestEntityType::Order,
            entityId: '1',
            executionOrigin: ExecutionOrigin::manual('order.provider_sync'),
        );

        HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $context)
            ->get('https://api.example/orders');

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job): bool {
            return $job->data->source?->serviceName === 'orders-api'
                && $job->data->source->serviceEnvironment === 'production'
                && $job->data->source->execution->type === ExecutionOrigin::TYPE_MANUAL
                && $job->data->source->execution->name === 'order.provider_sync';
        });
    }

    public function test_outgoing_source_reflects_the_context_that_sent_the_request(): void
    {
        Bus::fake();
        Http::fake(['https://api.example/*' => Http::response([], 200)]);

        // An audited client is often built once and reused. Each call must record the
        // context that issued it, not the one that happened to build the client.
        $client = HttpLog::make(
            TestProvider::Delivery,
            TestEventType::DeliveryOrderCreate,
            $this->context,
        );

        $this->app->make(AuditSourceResolver::class)->enterQueueJob('App\\Jobs\\SyncOrder');

        $client->get('https://api.example/orders');

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job): bool {
            return $job->data->source?->execution->type === ExecutionOrigin::TYPE_QUEUE
                && $job->data->source->execution->name === 'App\\Jobs\\SyncOrder';
        });
    }

    public function test_put_dispatches_log_job(): void
    {
        Bus::fake();
        Http::fake(['https://api.example/*' => Http::response([], 200)]);

        HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
            ->put('https://api.example/orders/1', ['status' => 'active']);

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->httpMethod === 'PUT';
        });
    }

    public function test_patch_dispatches_log_job(): void
    {
        Bus::fake();
        Http::fake(['https://api.example/*' => Http::response([], 200)]);

        HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
            ->patch('https://api.example/orders/1', ['note' => 'updated']);

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->httpMethod === 'PATCH';
        });
    }

    public function test_delete_dispatches_log_job(): void
    {
        Bus::fake();
        Http::fake(['https://api.example/*' => Http::response([], 200)]);

        HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
            ->delete('https://api.example/orders/1');

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->httpMethod === 'DELETE';
        });
    }

    public function test_with_headers_is_chainable_and_sends_request(): void
    {
        Bus::fake();
        Http::fake(['https://api.example/*' => Http::response([], 200)]);

        HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
            ->withHeaders(['X-Request-Id' => 'abc'])
            ->post('https://api.example/orders', []);

        Bus::assertDispatched(LogHttpRequestJob::class);
    }

    public function test_with_token_is_chainable_and_sends_request(): void
    {
        Bus::fake();
        Http::fake(['https://api.example/*' => Http::response([], 200)]);

        HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
            ->withToken('my-token')
            ->post('https://api.example/orders', []);

        Bus::assertDispatched(LogHttpRequestJob::class);
    }

    public function test_with_options_is_chainable_and_sends_request(): void
    {
        Bus::fake();
        Http::fake(['https://api.example/*' => Http::response([], 200)]);

        HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
            ->withOptions(['verify' => false])
            ->post('https://api.example/orders', []);

        Bus::assertDispatched(LogHttpRequestJob::class);
    }

    public function test_timeout_is_chainable_and_sends_request(): void
    {
        Bus::fake();
        Http::fake(['https://api.example/*' => Http::response([], 200)]);

        HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
            ->timeout(10)
            ->post('https://api.example/orders', []);

        Bus::assertDispatched(LogHttpRequestJob::class);
    }

    public function test_retry_is_chainable_and_sends_request(): void
    {
        Bus::fake();
        Http::fake(['https://api.example/*' => Http::response([], 200)]);

        HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
            ->retry(3, 100)
            ->post('https://api.example/orders', []);

        Bus::assertDispatched(LogHttpRequestJob::class);
    }

    public function test_post_sends_json_by_default(): void
    {
        Bus::fake();
        Http::fake(['https://api.example/*' => Http::response([], 200)]);

        HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
            ->post('https://api.example/orders', ['name' => 'test']);

        Http::assertSent(fn (Request $request) => $request->isJson());
    }

    public function test_as_form_sends_form_encoded_request(): void
    {
        Bus::fake();
        Http::fake(['https://api.example/*' => Http::response([], 200)]);

        HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
            ->asForm()
            ->post('https://api.example/orders', ['name' => 'test', 'qty' => 2]);

        Http::assertSent(fn (Request $request) => $request->isForm()
            && $request->body() === 'name=test&qty=2');

        Bus::assertDispatched(LogHttpRequestJob::class);
    }

    public function test_as_form_request_body_is_still_logged(): void
    {
        Bus::fake();
        Http::fake(['https://api.example/*' => Http::response([], 200)]);

        HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
            ->asForm()
            ->post('https://api.example/orders', ['name' => 'test']);

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->request->body === ['name' => 'test'];
        });
    }

    public function test_as_form_redacts_sensitive_body_keys(): void
    {
        Bus::fake();
        Http::fake(['https://api.example/*' => Http::response([], 200)]);

        HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
            ->asForm()
            ->post('https://api.example/orders', ['name' => 'test', 'api_key' => 'secret']);

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->request->body === ['name' => 'test', 'api_key' => '[REDACTED]'];
        });
    }

    public function test_as_json_after_as_form_restores_json(): void
    {
        Bus::fake();
        Http::fake(['https://api.example/*' => Http::response([], 200)]);

        HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
            ->asForm()
            ->asJson()
            ->post('https://api.example/orders', ['name' => 'test']);

        Http::assertSent(fn (Request $request) => $request->isJson());
    }

    public function test_head_dispatches_log_job(): void
    {
        Bus::fake();
        Http::fake(['https://api.example/*' => Http::response([], 200)]);

        HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
            ->head('https://api.example/orders');

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->httpMethod === 'HEAD';
        });
    }

    public function test_native_http_client_methods_work_and_are_logged(): void
    {
        Bus::fake();
        Http::fake(['https://api.example/*' => Http::response([], 200)]);

        // make() returns Laravel's real PendingRequest, so the full native fluent API is
        // available — and logging (a Guzzle middleware) applies regardless of which methods are used.
        HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
            ->acceptJson()
            ->withUserAgent('delivery-agent/1.0')
            ->withHeaders(['X-Test' => '1'])
            ->post('https://api.example/orders', ['name' => 'test']);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Accept', 'application/json')
            && $request->hasHeader('User-Agent', 'delivery-agent/1.0')
            && $request->hasHeader('X-Test', '1'));

        Bus::assertDispatched(LogHttpRequestJob::class);
    }

    public function test_query_string_is_stripped_from_stored_url(): void
    {
        Bus::fake();
        Http::fake(['https://api.example/*' => Http::response([], 200)]);

        HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
            ->get('https://api.example/orders?api_key=secret&page=1');

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->httpUrl === 'https://api.example/orders';
        });
    }

    public function test_stored_url_removes_userinfo_query_and_fragment(): void
    {
        Bus::fake();
        Http::fake(['*' => Http::response([], 200)]);

        HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
            ->get('https://user:password@api.example/orders?api_key=secret#fragment');

        Bus::assertDispatched(
            LogHttpRequestJob::class,
            fn (LogHttpRequestJob $job): bool => $job->data->httpUrl === 'https://api.example/orders',
        );
    }

    public function test_4xx_response_marks_success_as_false(): void
    {
        Bus::fake();
        Http::fake(['https://api.example/*' => Http::response(['error' => 'not found'], 404)]);

        HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
            ->get('https://api.example/orders/999');

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->success === false && $job->data->httpStatusCode === 404;
        });
    }

    public function test_5xx_response_marks_success_as_false(): void
    {
        Bus::fake();
        Http::fake(['https://api.example/*' => Http::response([], 500)]);

        HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
            ->post('https://api.example/orders', []);

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->success === false && $job->data->httpStatusCode === 500;
        });
    }

    public function test_2xx_response_marks_success_as_true(): void
    {
        Bus::fake();
        Http::fake(['https://api.example/*' => Http::response(['id' => 1], 201)]);

        HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
            ->post('https://api.example/orders', ['name' => 'test']);

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->success === true && $job->data->httpStatusCode === 201;
        });
    }

    public function test_timeout_request_is_flagged(): void
    {
        Bus::fake();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10000 ms'));

        try {
            HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
                ->get('https://api.example/orders');
        } catch (ConnectionException) {
            // The original transport exception is re-thrown to the caller.
        }

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->timedOut === true && $job->data->success === false;
        });
    }

    public function test_non_timeout_connection_failure_is_not_flagged_as_timeout(): void
    {
        Bus::fake();
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect: Connection refused'));

        try {
            HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
                ->get('https://api.example/orders');
        } catch (ConnectionException) {
            // Re-thrown to the caller.
        }

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->timedOut === false && $job->data->success === false;
        });
    }

    public function test_sample_rate_zero_skips_logging(): void
    {
        config(['http_logs.sample_rate' => 0.0]);
        Bus::fake();
        Http::fake(['https://api.example/*' => Http::response([], 200)]);

        HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
            ->post('https://api.example/orders', []);

        Bus::assertNotDispatched(LogHttpRequestJob::class);
    }

    public function test_sample_rate_one_always_logs(): void
    {
        config(['http_logs.sample_rate' => 1.0]);
        Bus::fake();
        Http::fake(['https://api.example/*' => Http::response([], 200)]);

        HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
            ->post('https://api.example/orders', []);

        Bus::assertDispatched(LogHttpRequestJob::class);
    }

    public function test_retries_share_one_sampling_decision(): void
    {
        config(['http_logs.sample_rate' => 0.5]);
        Bus::fake();
        Http::fakeSequence()->push([], 500)->push([], 200);
        mt_srand(1); // First roll captures; the second roll would skip.

        try {
            $response = HttpLog::make(
                TestProvider::Delivery,
                TestEventType::DeliveryOrderCreate,
                $this->context,
            )->retry(2, 0)->get('https://api.example/retry');
        } finally {
            mt_srand();
        }

        $this->assertSame(200, $response->status());
        Bus::assertDispatchedTimes(LogHttpRequestJob::class, 2);
    }

    public function test_oversized_response_body_is_captured_headers_only(): void
    {
        config(['http_logs.body_capture_max_bytes' => 64]);
        Bus::fake();
        Http::fake([
            'https://api.example/*' => Http::response(
                json_encode(['data' => str_repeat('a', 500)]),
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
            ->get('https://api.example/orders');

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->response->body === null
                && $job->data->response->bodyPreview === null
                && $job->data->response->bodyHash === null
                && $job->data->response->headers !== [];
        });
    }

    public function test_oversized_request_body_is_captured_headers_only(): void
    {
        config(['http_logs.body_capture_max_bytes' => 64]);
        Bus::fake();
        Http::fake(['https://api.example/*' => Http::response([], 200)]);

        HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
            ->post('https://api.example/orders', ['data' => str_repeat('a', 500)]);

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->request->body === null
                && $job->data->request->bodyPreview === null
                && $job->data->request->bodyHash === null;
        });
    }

    public function test_unknown_size_stream_reads_at_most_capture_cap_plus_one_byte(): void
    {
        config(['http_logs.body_capture_max_bytes' => 64]);
        Bus::fake();
        Http::fake(['*' => Http::response([], 200)]);

        $base        = Utils::streamFor(str_repeat('a', 500));
        $readLengths = [];
        $stream      = FnStream::decorate($base, [
            'getSize' => static fn (): ?int => null,
            'read'    => function (int $length) use ($base, &$readLengths): string {
                $readLengths[] = $length;

                return $base->read($length);
            },
        ]);

        HttpLog::make(TestProvider::Delivery, TestEventType::DeliveryOrderCreate, $this->context)
            ->withBody($stream)
            ->post('https://api.example/orders');

        $this->assertNotEmpty($readLengths);
        $this->assertLessThanOrEqual(65, max($readLengths));
        Bus::assertDispatched(
            LogHttpRequestJob::class,
            fn (LogHttpRequestJob $job): bool => $job->data->request->body === null
                && $job->data->request->bodyHash === null,
        );
    }

    public function test_logging_exception_does_not_propagate_to_caller(): void
    {
        Bus::fake();
        Http::fake(['https://api.example/*' => Http::response([], 200)]);

        $failingRedactor = $this->createStub(SensitiveDataRedactor::class);
        $failingRedactor->method('buildPayload')->willThrowException(new \RuntimeException('redactor failure'));

        // Attach the logging middleware directly with a redactor that always throws, to prove
        // a logging failure neither breaks the request nor lets a job slip through.
        $client = $this->app->make(Factory::class)
            ->createPendingRequest()
            ->withMiddleware(new OutgoingHttpLogMiddleware(
                provider: TestProvider::Delivery,
                eventType: TestEventType::DeliveryOrderCreate,
                context: $this->context,
                redactor: $failingRedactor,
            ));

        $response = $client->post('https://api.example/orders', []);

        $this->assertSame(200, $response->status());
        Bus::assertNotDispatched(LogHttpRequestJob::class);
    }
}
