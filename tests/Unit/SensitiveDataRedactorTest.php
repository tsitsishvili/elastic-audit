<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use Tsitsishvili\ElasticAudit\DataTransferObjects\RedactionRules;
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

    public function test_redacts_vendor_prefixed_headers_by_word(): void
    {
        $headers = [
            'X-Asd-Signature'   => 'sig-abc',
            'Postman-Token'     => 'tok-xyz',
            'X-Csrf-Token'      => 'csrf-123',
            'X-Hub-Signature'   => 'sha256=...',
            'X-Hmac'            => 'mac-1',
            'X-Client-Secret'   => 'shhh',
            'X-Api-Key'         => 'ak-1',
            'X-Functions-Key'   => 'func-key',
            'Idempotency-Key'   => 'idem-1',
            'X-Request-Id'      => 'req-1',
            'Content-Type'      => 'application/json',
        ];

        $result = $this->redactor->redactHeaders($headers);

        $this->assertSame('[REDACTED]', $result['X-Asd-Signature']);
        $this->assertSame('[REDACTED]', $result['Postman-Token']);
        $this->assertSame('[REDACTED]', $result['X-Csrf-Token']);
        $this->assertSame('[REDACTED]', $result['X-Hub-Signature']);
        $this->assertSame('[REDACTED]', $result['X-Hmac']);
        $this->assertSame('[REDACTED]', $result['X-Client-Secret']);
        $this->assertSame('[REDACTED]', $result['X-Api-Key']);
        $this->assertSame('[REDACTED]', $result['X-Functions-Key']);
        // 'key' as a trailing word redacts idempotency keys too.
        $this->assertSame('[REDACTED]', $result['Idempotency-Key']);
        $this->assertSame('req-1', $result['X-Request-Id']);
        $this->assertSame('application/json', $result['Content-Type']);
    }

    public function test_does_not_redact_headers_with_secret_words_embedded_mid_word(): void
    {
        $headers = [
            'X-Monkey-Business' => 'banana', // 'key' embedded in 'monkey'
            'X-Monkey'          => 'banana',
            'X-Keyword'         => 'sale',    // 'key' is a prefix, not a word
            'X-Token-Type'      => 'bearer',  // 'token' is not the final word
            'X-Request-Id'      => 'req-1',
        ];

        $result = $this->redactor->redactHeaders($headers);

        $this->assertSame('banana', $result['X-Monkey-Business']);
        $this->assertSame('banana', $result['X-Monkey']);
        $this->assertSame('sale', $result['X-Keyword']);
        $this->assertSame('bearer', $result['X-Token-Type']);
        $this->assertSame('req-1', $result['X-Request-Id']);
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

        $this->assertSame('[REDACTED]', $result['username']);
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

    public function test_redacts_credential_fields(): void
    {
        $body = [
            'username'   => 'john',
            'user_name'  => 'john.doe',
            'login'      => 'jdoe',
            'password'   => 'secret123',
            'pwd'        => 'secret123',
            'passwd'     => 'secret123',
            'passphrase' => 'correct horse battery staple',
            'pin'        => '1234',
            'otp'        => '987654',
            'order_id'   => 7,
        ];

        $result = $this->redactor->redactBody($body);

        foreach (['username', 'user_name', 'login', 'password', 'pwd',
            'passwd', 'passphrase', 'pin', 'otp'] as $key) {
            $this->assertSame('[REDACTED]', $result[$key], "Key '{$key}' was not redacted");
        }

        $this->assertSame(7, $result['order_id']);
    }

    public function test_redacts_compound_body_keys_by_word(): void
    {
        $body = [
            'password_confirmation' => 'secret',
            'new_password'          => 'secret',
            'webhook_secret'        => 'shhh',
            'client_secret'         => 'shhh',
            'csrf_token'            => 'tok',
            'refresh_token'         => 'tok',
            'webhook_signature'     => 'sig',
            'hmac_signature'        => 'mac',
            'authorization_code'    => 'code',
            'credentials'           => ['a' => 'b'],
            'order_id'              => 5,
        ];

        $result = $this->redactor->redactBody($body);

        foreach (['password_confirmation', 'new_password', 'webhook_secret',
            'client_secret', 'csrf_token', 'refresh_token', 'webhook_signature',
            'hmac_signature', 'authorization_code', 'credentials'] as $key) {
            $this->assertSame('[REDACTED]', $result[$key], "Key '{$key}' was not redacted");
        }

        $this->assertSame(5, $result['order_id']);
    }

    public function test_redacts_camel_case_body_keys(): void
    {
        $body = [
            'accessToken'    => 'at',  // word-matched after normalization
            'webhookSecret'  => 'sh',
            'cardNumber'     => '4111111111111111', // exact key after normalization
            'apiKey'         => 'ak',
            'orderId'        => 5,
        ];

        $result = $this->redactor->redactBody($body);

        $this->assertSame('[REDACTED]', $result['accessToken']);
        $this->assertSame('[REDACTED]', $result['webhookSecret']);
        $this->assertSame('[REDACTED]', $result['cardNumber']);
        $this->assertSame('[REDACTED]', $result['apiKey']);
        $this->assertSame(5, $result['orderId']);
    }

    public function test_keeps_token_qualifier_prefixes_visible(): void
    {
        // 'token' is a trailing-word match, so OAuth metadata stays readable
        // while the secret-bearing token fields are still redacted.
        $body = [
            'token_type'       => 'Bearer',
            'token_expires_in' => 3600,
            'tokenType'        => 'Bearer',
            'access_token'     => 'at',
            'tokens'           => ['a', 'b'],
        ];

        $result = $this->redactor->redactBody($body);

        $this->assertSame('Bearer', $result['token_type']);
        $this->assertSame(3600, $result['token_expires_in']);
        $this->assertSame('Bearer', $result['tokenType']);
        $this->assertSame('[REDACTED]', $result['access_token']);
        $this->assertSame('[REDACTED]', $result['tokens']);
    }

    public function test_does_not_redact_ambiguous_words_of_short_keys(): void
    {
        // Short exact-match keys (pin, pan, bin, key, cvv) must not leak into
        // ordinary field names via word matching.
        $body = [
            'shipping_address' => '12 Main St', // contains 'pin'
            'company_name'     => 'Acme',       // contains 'pan'
            'keyword'          => 'sale',       // 'key' is a prefix, not a word
            'monkey'           => 'george',     // 'key' embedded mid-word
            'binary_flag'      => true,         // contains 'bin'
            'order_id'         => 7,
        ];

        $result = $this->redactor->redactBody($body);

        $this->assertSame('12 Main St', $result['shipping_address']);
        $this->assertSame('Acme', $result['company_name']);
        $this->assertSame('sale', $result['keyword']);
        $this->assertSame('george', $result['monkey']);
        $this->assertTrue($result['binary_flag']);
        $this->assertSame(7, $result['order_id']);
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

    // ── Allow / block overrides ──────────────────────────────────────────────

    public function test_allow_list_keeps_named_body_keys(): void
    {
        $redactor = new SensitiveDataRedactor(body: new RedactionRules(allow: ['email', 'card_holder']));

        $result = $redactor->redactBody([
            'email'       => 'user@example.com',
            'card_holder' => 'John Doe',
            'password'    => 'secret',
        ]);

        $this->assertSame('user@example.com', $result['email']);
        $this->assertSame('John Doe', $result['card_holder']);
        $this->assertSame('[REDACTED]', $result['password']);
    }

    public function test_allow_list_matches_after_normalization(): void
    {
        $redactor = new SensitiveDataRedactor(body: new RedactionRules(allow: ['access_token']));

        $result = $redactor->redactBody([
            'accessToken' => 'at',  // normalizes to access_token
            'csrf_token'  => 'ct',
        ]);

        $this->assertSame('at', $result['accessToken']);
        $this->assertSame('[REDACTED]', $result['csrf_token']);
    }

    public function test_allow_list_keeps_named_headers(): void
    {
        $redactor = new SensitiveDataRedactor(headers: new RedactionRules(allow: ['x-api-key']));

        $result = $redactor->redactHeaders([
            'X-Api-Key'     => 'ak',
            'Authorization' => 'Bearer t',
        ]);

        $this->assertSame('ak', $result['X-Api-Key']);
        $this->assertSame('[REDACTED]', $result['Authorization']);
    }

    public function test_block_list_redacts_additional_body_keys(): void
    {
        $redactor = new SensitiveDataRedactor(body: new RedactionRules(block: ['reference']));

        $result = $redactor->redactBody([
            'customer_reference' => 'CR-1', // word 'reference'
            'reference'          => 'R-1',
            'order_id'           => 7,
        ]);

        $this->assertSame('[REDACTED]', $result['customer_reference']);
        $this->assertSame('[REDACTED]', $result['reference']);
        $this->assertSame(7, $result['order_id']);
    }

    public function test_block_list_redacts_additional_headers(): void
    {
        $redactor = new SensitiveDataRedactor(headers: new RedactionRules(block: ['x-internal-trace']));

        $result = $redactor->redactHeaders([
            'X-Internal-Trace' => 'trace-1',
            'X-Request-Id'     => 'req-1',
        ]);

        $this->assertSame('[REDACTED]', $result['X-Internal-Trace']);
        $this->assertSame('req-1', $result['X-Request-Id']);
    }

    public function test_allow_takes_precedence_over_block_and_defaults(): void
    {
        $redactor = new SensitiveDataRedactor(body: new RedactionRules(allow: ['email'], block: ['email']));

        $result = $redactor->redactBody(['email' => 'user@example.com']);

        $this->assertSame('user@example.com', $result['email']);
    }

    public function test_header_and_body_rules_are_independent(): void
    {
        // Header allow/block must not affect body keys, and vice versa.
        $redactor = new SensitiveDataRedactor(
            headers: new RedactionRules(allow: ['authorization'], block: ['x-trace']),
            body: new RedactionRules(allow: ['email'], block: ['reference']),
        );

        $headers = $redactor->redactHeaders([
            'Authorization' => 'Bearer t', // allowed for headers → kept
            'X-Trace'       => 'tr',        // blocked for headers → redacted
            'X-Reference'   => 'r',         // body block must not apply → kept
        ]);
        $body = $redactor->redactBody([
            'email'              => 'user@example.com', // allowed for body → kept
            'customer_reference' => 'CR-1',             // blocked for body → redacted
            'authorization'      => 'a',                // header allow must not apply → redacted
        ]);

        $this->assertSame('Bearer t', $headers['Authorization']);
        $this->assertSame('[REDACTED]', $headers['X-Trace']);
        $this->assertSame('r', $headers['X-Reference']);

        $this->assertSame('user@example.com', $body['email']);
        $this->assertSame('[REDACTED]', $body['customer_reference']);
        $this->assertSame('[REDACTED]', $body['authorization']);
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
