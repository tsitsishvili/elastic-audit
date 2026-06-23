<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use Tsitsishvili\ElasticAudit\Services\Redactors\SensitiveDataRedactor;
use PHPUnit\Framework\TestCase;

class SensitiveDataRedactorTest extends TestCase
{
    private SensitiveDataRedactor $redactor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redactor = new SensitiveDataRedactor();
    }

    // ── Header redaction ────────────────────────────────────────────────────

    public function test_redacts_sensitive_headers(): void
    {
        $headers = [
            'Authorization'       => 'Bearer secret-token',
            'Cookie'              => 'session=abc123',
            'Set-Cookie'          => 'id=xyz',
            'X-Api-Key'           => 'my-key',
            'Api-Key'             => 'another-key',
            'Proxy-Authorization' => 'Basic xyz',
            'Content-Type'        => 'application/json',
            'Accept'              => '*/*',
        ];

        $result = $this->redactor->redactHeaders($headers);

        $this->assertSame('[REDACTED]', $result['Authorization']);
        $this->assertSame('[REDACTED]', $result['Cookie']);
        $this->assertSame('[REDACTED]', $result['Set-Cookie']);
        $this->assertSame('[REDACTED]', $result['X-Api-Key']);
        $this->assertSame('[REDACTED]', $result['Api-Key']);
        $this->assertSame('[REDACTED]', $result['Proxy-Authorization']);
        $this->assertSame('application/json', $result['Content-Type']);
        $this->assertSame('*/*', $result['Accept']);
    }

    public function test_redacts_headers_case_insensitively(): void
    {
        $headers = [
            'authorization' => 'Bearer token',
            'COOKIE'        => 'session=abc',
            'x-api-key'     => 'key',
        ];

        $result = $this->redactor->redactHeaders($headers);

        $this->assertSame('[REDACTED]', $result['authorization']);
        $this->assertSame('[REDACTED]', $result['COOKIE']);
        $this->assertSame('[REDACTED]', $result['x-api-key']);
    }

    public function test_preserves_header_key_when_redacting(): void
    {
        $result = $this->redactor->redactHeaders(['Authorization' => 'Bearer token']);

        $this->assertArrayHasKey('Authorization', $result);
        $this->assertSame('[REDACTED]', $result['Authorization']);
    }

    // ── Body redaction ───────────────────────────────────────────────────────

    public function test_redacts_sensitive_body_keys(): void
    {
        $body = [
            'username'      => 'john',
            'password'      => 'secret123',
            'token'         => 'tok_abc',
            'access_token'  => 'at_xyz',
            'refresh_token' => 'rt_xyz',
            'api_key'       => 'ak_xyz',
            'secret'        => 'shhh',
            'card_number'   => '4111111111111111',
            'pan'           => '4111111111111111',
            'cvv'           => '123',
            'cvc'           => '456',
            'personal_id'   => '01234567890',
            'order_id'      => 42,
        ];

        $result = $this->redactor->redactBody($body);

        $this->assertSame('john', $result['username']);
        $this->assertSame('[REDACTED]', $result['password']);
        $this->assertSame('[REDACTED]', $result['token']);
        $this->assertSame('[REDACTED]', $result['access_token']);
        $this->assertSame('[REDACTED]', $result['refresh_token']);
        $this->assertSame('[REDACTED]', $result['api_key']);
        $this->assertSame('[REDACTED]', $result['secret']);
        $this->assertSame('[REDACTED]', $result['card_number']);
        $this->assertSame('[REDACTED]', $result['pan']);
        $this->assertSame('[REDACTED]', $result['cvv']);
        $this->assertSame('[REDACTED]', $result['cvc']);
        $this->assertSame('[REDACTED]', $result['personal_id']);
        $this->assertSame(42, $result['order_id']);
    }

    public function test_redacts_pii_fields(): void
    {
        $body = [
            'phone'           => '+995555123456',
            'phone_number'    => '+995555654321',
            'mobile'          => '+995599000000',
            'recipient'       => '+995599111111',
            'email'           => 'user@example.com',
            'card_holder'     => 'John Doe',
            'cardholder'      => 'Jane Doe',
            'bin'             => '411111',
            'expiry'          => '12/26',
            'expiry_date'     => '12/2026',
            'exp_month'       => '12',
            'exp_year'        => '2026',
            'exp_date'        => '1226',
            'id_number'       => 'AB123456',
            'passport_number' => 'GE999999',
            'national_id'     => '01234567890',
            'order_id'        => 99,
        ];

        $result = $this->redactor->redactBody($body);

        foreach (['phone', 'phone_number', 'mobile', 'recipient', 'email',
            'card_holder', 'cardholder', 'bin', 'expiry', 'expiry_date',
            'exp_month', 'exp_year', 'exp_date', 'id_number', 'passport_number', 'national_id'] as $key) {
            $this->assertSame('[REDACTED]', $result[$key], "Key '{$key}' was not redacted");
        }

        $this->assertSame(99, $result['order_id']);
    }

    public function test_redacts_body_keys_case_insensitively(): void
    {
        $body = [
            'PASSWORD'    => 'secret',
            'TOKEN'       => 'tok',
            'Card_Number' => '4111111111111111',
            'PHONE'       => '+99500000000',
        ];

        $result = $this->redactor->redactBody($body);

        $this->assertSame('[REDACTED]', $result['PASSWORD']);
        $this->assertSame('[REDACTED]', $result['TOKEN']);
        $this->assertSame('[REDACTED]', $result['Card_Number']);
        $this->assertSame('[REDACTED]', $result['PHONE']);
    }

    public function test_redacts_nested_body_arrays(): void
    {
        $body = [
            'payment' => [
                'card_number' => '4111111111111111',
                'holder'      => 'John Doe',
                'nested'      => [
                    'cvv' => '123',
                ],
            ],
            'order_id' => 99,
        ];

        $result = $this->redactor->redactBody($body);

        $this->assertSame('[REDACTED]', $result['payment']['card_number']);
        $this->assertSame('John Doe', $result['payment']['holder']);
        $this->assertSame('[REDACTED]', $result['payment']['nested']['cvv']);
        $this->assertSame(99, $result['order_id']);
    }

    public function test_returns_non_array_body_unchanged(): void
    {
        $this->assertSame('raw string', $this->redactor->redactBody('raw string'));
        $this->assertNull($this->redactor->redactBody(null));
        $this->assertSame(42, $this->redactor->redactBody(42));
    }

    // ── buildPayload — preview and hash derived from redacted content ────────

    public function test_body_preview_does_not_contain_raw_sensitive_values(): void
    {
        $rawBody = json_encode([
            'password' => 'super-secret-password',
            'order_id' => 123,
        ]);

        $payload = $this->redactor->buildPayload([], $rawBody, 32768, 4096);

        $this->assertNotNull($payload->bodyPreview);
        $this->assertStringNotContainsString('super-secret-password', $payload->bodyPreview);
        $this->assertStringContainsString('[REDACTED]', $payload->bodyPreview);
        $this->assertStringContainsString('123', $payload->bodyPreview);
    }

    public function test_body_hash_is_derived_from_redacted_content(): void
    {
        $rawBody = json_encode(['password' => 'secret', 'order_id' => 1]);

        $payload = $this->redactor->buildPayload([], $rawBody, 32768, 4096);

        $redactedBody = json_encode(['password' => '[REDACTED]', 'order_id' => 1]);
        $expectedHash = 'sha256:' . hash('sha256', $redactedBody);

        $this->assertSame($expectedHash, $payload->bodyHash);
    }

    public function test_build_payload_skips_binary_bodies(): void
    {
        $payload = $this->redactor->buildPayload([], "binary\0data", 32768, 4096);

        $this->assertNull($payload->body);
        $this->assertNull($payload->bodyPreview);
        $this->assertNull($payload->bodyHash);
        $this->assertSame([], $payload->headers);
    }

    // ── truncateAndHash (utility) ────────────────────────────────────────────

    public function test_skips_binary_bodies_containing_null_bytes(): void
    {
        $payload = $this->redactor->truncateAndHash("some\0binary\0data", 32768, 4096);

        $this->assertNull($payload->body);
        $this->assertNull($payload->bodyPreview);
        $this->assertNull($payload->bodyHash);
        $this->assertFalse($payload->bodyTruncated);
    }

    public function test_skips_invalid_utf8_bodies(): void
    {
        $payload = $this->redactor->truncateAndHash("\xff\xfe invalid utf8", 32768, 4096);

        $this->assertNull($payload->bodyHash);
    }

    public function test_produces_preview_hash_and_truncation_flag_for_valid_body(): void
    {
        $body    = json_encode(['order_id' => 123, 'status' => 'ok']);
        $payload = $this->redactor->truncateAndHash($body, 32768, 10);

        $this->assertNotNull($payload->bodyHash);
        $this->assertStringStartsWith('sha256:', $payload->bodyHash);
        $this->assertNotNull($payload->bodyPreview);
        $this->assertSame(10, mb_strlen($payload->bodyPreview));
        $this->assertFalse($payload->bodyTruncated);
    }

    public function test_sets_body_truncated_when_body_exceeds_max_bytes(): void
    {
        $payload = $this->redactor->truncateAndHash(str_repeat('a', 100), 50, 10);

        $this->assertTrue($payload->bodyTruncated);
    }

    // ── sanitizeErrorMessage ─────────────────────────────────────────────────

    public function test_sanitize_error_message_strips_query_string_from_url(): void
    {
        $message = 'cURL error: Could not connect to https://api.example.com/pay?api_key=secret123&amount=50';

        $result = $this->redactor->sanitizeErrorMessage($message);

        $this->assertStringNotContainsString('secret123', $result);
        $this->assertStringContainsString('?[REDACTED]', $result);
        $this->assertStringContainsString('https://api.example.com/pay', $result);
    }

    public function test_sanitize_error_message_leaves_clean_messages_unchanged(): void
    {
        $message = 'Connection refused to host api.example.com on port 443';

        $this->assertSame($message, $this->redactor->sanitizeErrorMessage($message));
    }
}
