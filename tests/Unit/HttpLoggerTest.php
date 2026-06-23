<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogContext;
use Tsitsishvili\ElasticAudit\Jobs\LogHttpRequestJob;
use Tsitsishvili\ElasticAudit\Services\Redactors\SensitiveDataRedactor;
use Tsitsishvili\ElasticAudit\Services\HttpLogger;
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

        $this->logger  = new HttpLogger(new SensitiveDataRedactor());
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

        $this->expectNotToPerformAssertions();

        $logger->logIncoming(
            request: Request::create('https://example.com/callback', 'POST'),
            provider: TestProvider::Delivery,
            eventType: TestEventType::DeliveryStatusCallback,
            context: $this->context,
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
}
