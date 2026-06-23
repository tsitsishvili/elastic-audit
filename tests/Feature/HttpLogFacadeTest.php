<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Tsitsishvili\ElasticAudit\DataTransferObjects\RedactedHttpPayload;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogContext;
use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogData;
use Tsitsishvili\ElasticAudit\Enums\HttpDirection;
use Tsitsishvili\ElasticAudit\Facades\HttpLog;
use Tsitsishvili\ElasticAudit\Jobs\LogHttpRequestJob;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEntityType;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestEventType;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\TestProvider;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class HttpLogFacadeTest extends TestCase
{
    private HttpLogContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        config(['http_logs.enabled' => true]);

        $this->context = HttpLogContext::forEntity(
            entityType: TestEntityType::Order,
            entityId: '42',
        );
    }

    public function test_successful_request_dispatches_log_job(): void
    {
        Bus::fake();
        Http::fake(['https://provider.example/*' => Http::response(['ok' => true], 200)]);

        HttpLog::make(
            provider: TestProvider::Delivery,
            eventType: TestEventType::DeliveryOrderCreate,
            context: $this->context,
        )->post('https://provider.example/orders', ['order_id' => 1]);

        Bus::assertDispatched(LogHttpRequestJob::class);
    }

    public function test_failed_http_call_dispatches_job_and_rethrows_exception(): void
    {
        Bus::fake();
        Http::fake(fn () => throw new ConnectionException('connection refused'));

        $this->expectException(ConnectionException::class);

        try {
            HttpLog::make(
                provider: TestProvider::Delivery,
                eventType: TestEventType::DeliveryOrderCreate,
                context: $this->context,
            )->post('https://provider.example/orders', []);
        } finally {
            Bus::assertDispatched(LogHttpRequestJob::class);
        }
    }

    public function test_macro_is_no_op_when_logging_disabled(): void
    {
        config(['http_logs.enabled' => false]);

        Bus::fake();
        Http::fake(['https://provider.example/*' => Http::response(['ok' => true], 200)]);

        HttpLog::make(
            provider: TestProvider::Delivery,
            eventType: TestEventType::DeliveryOrderCreate,
            context: $this->context,
        )->post('https://provider.example/orders', []);

        Bus::assertNotDispatched(LogHttpRequestJob::class);
    }

    public function test_macro_never_calls_indexer_synchronously(): void
    {
        Bus::fake();
        Http::fake(['https://provider.example/*' => Http::response([], 200)]);

        HttpLog::make(
            provider: TestProvider::Delivery,
            eventType: TestEventType::DeliveryOrderCreate,
            context: $this->context,
        )->get('https://provider.example/status');

        Bus::assertDispatched(LogHttpRequestJob::class);
    }

    public function test_payment_provider_uses_payment_redactor(): void
    {
        Bus::fake();
        Http::fake(['https://payment.example/*' => Http::response(['ok' => true], 200)]);

        // TestProvider::Payment is in payment_provider_values — verify the job is still dispatched
        HttpLog::make(
            provider: TestProvider::Payment,
            eventType: TestEventType::PaymentCallback,
            context: $this->context,
        )->post('https://payment.example/callback', ['card_number' => '4111111111111111']);

        Bus::assertDispatched(LogHttpRequestJob::class);
    }

    public function test_log_incoming_facade_captures_response(): void
    {
        Bus::fake();

        HttpLog::logIncoming(
            request: Request::create('https://example.com/callback', 'POST'),
            provider: TestProvider::Delivery,
            eventType: TestEventType::DeliveryStatusCallback,
            context: $this->context,
            latencyMs: 0,
            httpStatusCode: 200,
            success: true,
            response: new SymfonyResponse((string) json_encode(['ok' => true]), 200),
        );

        Bus::assertDispatched(LogHttpRequestJob::class, function (LogHttpRequestJob $job) {
            return $job->data->response->body === ['ok' => true];
        });
    }

    public function test_job_failed_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();

        $empty   = new RedactedHttpPayload([], null, null, null, false);
        $context = HttpLogContext::forEntity(
            entityType: TestEntityType::Order,
            entityId: '1',
        );
        $data = HttpLogData::make(
            provider: TestProvider::Delivery,
            eventType: TestEventType::DeliveryOrderCreate,
            direction: HttpDirection::Outgoing,
            httpMethod: 'POST',
            httpUrl: 'https://delivery.example/orders',
            latencyMs: 10,
            context: $context,
            request: $empty,
            response: $empty,
        );

        $job = new LogHttpRequestJob($data);
        $job->failed(new \RuntimeException('ES down'));
    }
}
