<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use Tsitsishvili\ElasticAudit\Services\Redactors\PaymentRedactor;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class PaymentRedactorTest extends TestCase
{
    private PaymentRedactor $redactor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redactor = new PaymentRedactor();
    }

    public function test_metadata_mode_returns_null_body_and_null_preview(): void
    {
        config(['http_logs.payment_body_mode' => 'metadata']);

        $rawBody = json_encode(['card_number' => '4111111111111111', 'amount' => 100]);

        $payload = $this->redactor->buildPayload([], $rawBody, 32768, 4096);

        $this->assertNull($payload->body);
        $this->assertNull($payload->bodyPreview);
        $this->assertNull($payload->bodyHash);
        $this->assertFalse($payload->bodyTruncated);
    }

    public function test_metadata_mode_still_redacts_headers(): void
    {
        config(['http_logs.payment_body_mode' => 'metadata']);

        $payload = $this->redactor->buildPayload(
            ['Authorization' => 'Bearer tok', 'Content-Type' => 'application/json'],
            '{}',
            32768,
            4096
        );

        $this->assertSame('[REDACTED]', $payload->headers['Authorization']);
        $this->assertSame('application/json', $payload->headers['Content-Type']);
    }

    public function test_preview_mode_delegates_to_base_redactor(): void
    {
        config(['http_logs.payment_body_mode' => 'preview']);

        $rawBody = json_encode(['card_number' => '4111111111111111', 'amount' => 100]);

        $payload = $this->redactor->buildPayload([], $rawBody, 32768, 4096);

        $this->assertNotNull($payload->bodyPreview);
        $this->assertStringNotContainsString('4111111111111111', $payload->bodyPreview);
        $this->assertStringContainsString('[REDACTED]', $payload->bodyPreview);
    }

    public function test_preview_mode_body_does_not_contain_card_number(): void
    {
        config(['http_logs.payment_body_mode' => 'preview']);

        $rawBody = json_encode(['card_number' => '4111111111111111', 'order_id' => 42]);

        $payload = $this->redactor->buildPayload([], $rawBody, 32768, 4096);

        $this->assertIsArray($payload->body);
        $this->assertSame('[REDACTED]', $payload->body['card_number']);
        $this->assertSame(42, $payload->body['order_id']);
    }
}
