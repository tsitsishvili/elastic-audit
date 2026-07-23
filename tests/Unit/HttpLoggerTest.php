<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogContext;
use Tsitsishvili\ElasticAudit\Events\AuditOperationFailed;
use Tsitsishvili\ElasticAudit\Jobs\LogHttpRequestJob;
use Tsitsishvili\ElasticAudit\Services\HttpLogger;
use Tsitsishvili\ElasticAudit\Services\Redactors\HttpPayloadRedactorResolver;
use Tsitsishvili\ElasticAudit\Services\Redactors\PaymentRedactor;
use Tsitsishvili\ElasticAudit\Services\Redactors\SensitiveDataRedactor;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEntityType;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEventType;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestProvider;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class HttpLoggerTest extends TestCase
{
    private HttpLogger $logger;

    private HttpLogContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger  = new HttpLogger(new SensitiveDataRedactor);
        $this->context = HttpLogContext::forEntity(
            entityType: TestEntityType::Order,
            entityId: '7',
        );
    }

    public function test_log_incoming_is_no_op_when_disabled(): void
    {
        config(['http_logs.enabled' => false]);
        Bus::fake();

        $this->logger->logIncoming(
            request: Request::create('https://example.com/callback', 'POST'),
            provider: TestProvider::Delivery,
            eventType: TestEventType::DeliveryStatusCallback,
            context: $this->context,
        );

        Bus::assertNotDispatched(LogHttpRequestJob::class);
    }

    public function test_log_incoming_dispatches_job_when_enabled(): void
    {
        config(['http_logs.enabled' => true]);
        Bus::fake();

        $this->logger->logIncoming(
            request: Request::create('https://example.com/callback?token=secret', 'POST', [], [], [], [], 'body'),
            provider: TestProvider::Delivery,
            eventType: TestEventType::DeliveryStatusCallback,
            context: $this->context,
            latencyMs: 25,
            httpStatusCode: 200,
            success: true,
        );

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->latencyMs === 25
                && $job->data->httpStatusCode === 200
                && $job->data->success === true
                && ! str_contains($job->data->httpUrl, '?');
        });
    }

    public function test_log_incoming_strips_query_string_from_url(): void
    {
        config(['http_logs.enabled' => true]);
        Bus::fake();

        $this->logger->logIncoming(
            request: Request::create('https://example.com/callback?api_key=12345', 'POST'),
            provider: TestProvider::Delivery,
            eventType: TestEventType::DeliveryStatusCallback,
            context: $this->context,
        );

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->httpUrl === 'https://example.com/callback';
        });
    }

    public function test_log_incoming_redacts_form_urlencoded_request_body(): void
    {
        config(['http_logs.enabled' => true]);
        Bus::fake();

        $request = Request::create(
            'https://example.com/callback',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            'password=super-secret-password&order_id=123',
        );

        $this->logger->logIncoming(
            request: $request,
            provider: TestProvider::Delivery,
            eventType: TestEventType::DeliveryStatusCallback,
            context: $this->context,
        );

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->request->body === ['password' => '[REDACTED]', 'order_id' => '123']
                && ! str_contains((string) $job->data->request->bodyPreview, 'super-secret-password');
        });
    }

    public function test_log_incoming_uses_payment_redactor_for_configured_provider(): void
    {
        config([
            'http_logs.enabled'                 => true,
            'http_logs.payment_body_mode'       => 'metadata',
            'http_logs.payment_provider_values' => [TestProvider::Payment],
        ]);
        Bus::fake();

        $default  = new SensitiveDataRedactor;
        $payment  = new PaymentRedactor;
        $resolver = new HttpPayloadRedactorResolver($default, $payment);
        $logger   = new HttpLogger($default, $resolver);

        $logger->logIncoming(
            request: Request::create(
                'https://example.com/payment-callback',
                'POST',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode(['card_number' => '4111111111111111']),
            ),
            provider: TestProvider::Payment,
            eventType: TestEventType::DeliveryStatusCallback,
            context: $this->context,
        );

        Bus::assertDispatched(
            LogHttpRequestJob::class,
            fn (LogHttpRequestJob $job): bool => $job->data->request->body === null
                && $job->data->request->bodyPreview === null
                && $job->data->request->bodyHash === null,
        );
    }

    public function test_log_incoming_is_no_op_when_sample_rate_is_zero(): void
    {
        config(['http_logs.enabled' => true]);
        config(['http_logs.sample_rate' => 0.0]);
        Bus::fake();

        $this->logger->logIncoming(
            request: Request::create('https://example.com/callback', 'POST'),
            provider: TestProvider::Delivery,
            eventType: TestEventType::DeliveryStatusCallback,
            context: $this->context,
        );

        Bus::assertNotDispatched(LogHttpRequestJob::class);
    }

    public function test_log_incoming_always_dispatches_when_sample_rate_is_one(): void
    {
        config(['http_logs.enabled' => true]);
        config(['http_logs.sample_rate' => 1.0]);
        Bus::fake();

        $this->logger->logIncoming(
            request: Request::create('https://example.com/callback', 'POST'),
            provider: TestProvider::Delivery,
            eventType: TestEventType::DeliveryStatusCallback,
            context: $this->context,
        );

        Bus::assertDispatched(LogHttpRequestJob::class);
    }

    public function test_log_incoming_does_not_propagate_internal_exceptions(): void
    {
        config(['http_logs.enabled' => true]);

        $badRedactor = $this->createStub(SensitiveDataRedactor::class);
        $badRedactor->method('buildPayload')->willThrowException(new \RuntimeException('internal failure'));

        $logger = new HttpLogger($badRedactor);
        Event::fake([AuditOperationFailed::class]);

        $logger->logIncoming(
            request: Request::create('https://example.com/callback', 'POST'),
            provider: TestProvider::Delivery,
            eventType: TestEventType::DeliveryStatusCallback,
            context: $this->context,
        );

        Event::assertDispatched(
            AuditOperationFailed::class,
            fn (AuditOperationFailed $event): bool => $event->subsystem === AuditOperationFailed::SUBSYSTEM_HTTP
                && $event->stage === AuditOperationFailed::STAGE_CAPTURE
                && $event->context['request_id'] === $this->context->requestId,
        );
    }

    public function test_log_incoming_captures_response_body_and_headers(): void
    {
        config(['http_logs.enabled' => true]);
        Bus::fake();

        $response = new SymfonyResponse(
            (string) json_encode(['status' => 'ok', 'order_id' => 7]),
            200,
            ['Content-Type' => 'application/json'],
        );

        $this->logger->logIncoming(
            request: Request::create('https://example.com/callback', 'POST'),
            provider: TestProvider::Delivery,
            eventType: TestEventType::DeliveryStatusCallback,
            context: $this->context,
            latencyMs: 5,
            httpStatusCode: 200,
            success: true,
            response: $response,
        );

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->response->body === ['status' => 'ok', 'order_id' => 7]
                && str_contains((string) $job->data->response->bodyPreview, '"status":"ok"')
                && $job->data->response->bodyHash !== null;
        });
    }

    public function test_log_incoming_redacts_secrets_in_response_body(): void
    {
        config(['http_logs.enabled' => true]);
        Bus::fake();

        $response = new SymfonyResponse(
            (string) json_encode(['token' => 'super-secret', 'status' => 'ok']),
            200,
        );

        $this->logger->logIncoming(
            request: Request::create('https://example.com/callback', 'POST'),
            provider: TestProvider::Delivery,
            eventType: TestEventType::DeliveryStatusCallback,
            context: $this->context,
            response: $response,
        );

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->response->body['token'] === '[REDACTED]'
                && ! str_contains((string) $job->data->response->bodyPreview, 'super-secret');
        });
    }

    public function test_log_incoming_handles_streamed_response_without_body(): void
    {
        config(['http_logs.enabled' => true]);
        Bus::fake();

        $response = new StreamedResponse(function () {
            echo 'streamed-content';
        }, 200);

        $this->logger->logIncoming(
            request: Request::create('https://example.com/callback', 'POST'),
            provider: TestProvider::Delivery,
            eventType: TestEventType::DeliveryStatusCallback,
            context: $this->context,
            response: $response,
        );

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->response->body === null
                && $job->data->response->bodyPreview === null;
        });
    }

    public function test_log_incoming_redacts_sensitive_headers_on_streamed_response(): void
    {
        config(['http_logs.enabled' => true]);
        Bus::fake();

        $response = new StreamedResponse(function () {
            echo 'streamed-content';
        }, 200, ['Set-Cookie' => 'session=abc123; Path=/; HttpOnly']);

        $this->logger->logIncoming(
            request: Request::create('https://example.com/callback', 'POST'),
            provider: TestProvider::Delivery,
            eventType: TestEventType::DeliveryStatusCallback,
            context: $this->context,
            response: $response,
        );

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->response->headers['set-cookie'] === '[REDACTED]';
        });
    }

    public function test_log_incoming_without_response_stores_empty_payload(): void
    {
        config(['http_logs.enabled' => true]);
        Bus::fake();

        $this->logger->logIncoming(
            request: Request::create('https://example.com/callback', 'POST'),
            provider: TestProvider::Delivery,
            eventType: TestEventType::DeliveryStatusCallback,
            context: $this->context,
        );

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->response->body === null
                && $job->data->response->bodyPreview === null
                && $job->data->response->bodyHash === null
                && $job->data->response->headers === [];
        });
    }

    public function test_incoming_content_length_over_capture_cap_skips_body_read(): void
    {
        config([
            'http_logs.enabled'                => true,
            'http_logs.body_capture_max_bytes' => 64,
        ]);
        Bus::fake();

        $request = new class(server: ['HTTP_HOST' => 'example.com', 'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/callback', 'CONTENT_LENGTH' => '500', 'CONTENT_TYPE' => 'application/json']) extends Request
        {
            public bool $contentRead = false;

            public function getContent(bool $asResource = false)
            {
                $this->contentRead = true;

                throw new \RuntimeException('Body should not be read.');
            }
        };

        $this->logger->logIncoming(
            request: $request,
            provider: TestProvider::Delivery,
            eventType: TestEventType::DeliveryStatusCallback,
            context: $this->context,
        );

        $this->assertFalse($request->contentRead);
        Bus::assertDispatched(
            LogHttpRequestJob::class,
            fn (LogHttpRequestJob $job): bool => $job->data->request->body === null
                && $job->data->request->bodyHash === null,
        );
    }

    public function test_incoming_unknown_length_stream_is_bounded_and_rewound(): void
    {
        config([
            'http_logs.enabled'                => true,
            'http_logs.body_capture_max_bytes' => 64,
        ]);
        Bus::fake();

        $resource = fopen('php://temp', 'r+');
        $this->assertIsResource($resource);
        fwrite($resource, str_repeat('a', 500));
        rewind($resource);

        $request = new Request(
            server: [
                'HTTP_HOST'      => 'example.com',
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI'    => '/callback',
                'CONTENT_TYPE'   => 'text/plain',
            ],
            content: $resource,
        );

        $this->logger->logIncoming(
            request: $request,
            provider: TestProvider::Delivery,
            eventType: TestEventType::DeliveryStatusCallback,
            context: $this->context,
        );

        $this->assertSame(0, ftell($resource));
        Bus::assertDispatched(
            LogHttpRequestJob::class,
            fn (LogHttpRequestJob $job): bool => $job->data->request->body === null
                && $job->data->request->bodyPreview === null
                && $job->data->request->bodyHash === null,
        );
    }
}
