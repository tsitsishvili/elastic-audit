<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tsitsishvili\ElasticAudit\Services\EndpointNormalizer;
use Tsitsishvili\ElasticAudit\Services\SqlStatementNormalizer;

class MetricNormalizersTest extends TestCase
{
    public function test_sql_normalizer_removes_comments_and_literals_without_bindings(): void
    {
        $normalizer = new SqlStatementNormalizer;
        $statement  = $normalizer->normalize(
            "/* actor=secret */ SELECT * FROM users WHERE email = 'private--comment@example.test' AND id = 123 -- tail",
        );

        $this->assertSame('SELECT * FROM users WHERE email = ? AND id = ?', $statement);
        $this->assertSame('select', $normalizer->operation($statement));
        $this->assertSame(64, strlen($normalizer->fingerprint($statement)));
        $this->assertStringNotContainsString('private--comment@example.test', $statement);
    }

    public function test_sql_normalizer_removes_double_quoted_data_for_ambiguous_drivers(): void
    {
        $normalizer = new SqlStatementNormalizer;

        $mysql = $normalizer->normalize(
            'SELECT * FROM users WHERE email = "private@example.test"',
            driver: 'mysql',
        );
        $sqlite = $normalizer->normalize(
            'SELECT "private@example.test" AS email',
            driver: 'sqlite',
        );

        $this->assertSame('SELECT * FROM users WHERE email = ?', $mysql);
        $this->assertSame('SELECT ? AS email', $sqlite);
        $this->assertStringNotContainsString('private@example.test', $mysql.$sqlite);
    }

    public function test_postgresql_hash_operators_and_quoted_identifiers_are_preserved(): void
    {
        $normalizer = new SqlStatementNormalizer;
        $statement  = $normalizer->normalize(
            'SELECT data #>> \'{user,email}\' FROM "events--archive" WHERE id = 42',
            driver: 'pgsql',
        );
        $dollarQuoted = $normalizer->normalize(
            'SELECT $private$private@example.test$private$ AS email',
            driver: 'pgsql',
        );

        $this->assertSame('SELECT data #>> ? FROM "events--archive" WHERE id = ?', $statement);
        $this->assertSame('SELECT ? AS email', $dollarQuoted);
    }

    public function test_endpoint_normalizer_discards_credentials_query_fragment_and_ids(): void
    {
        $endpoint = (new EndpointNormalizer)->normalize(
            'https://user:secret@Api.Example.test/orders/123/550e8400-e29b-41d4-a716-446655440000?token=secret#part',
        );

        $this->assertSame('api.example.test', $endpoint['host']);
        $this->assertSame('/orders/{id}/{id}', $endpoint['path']);
    }

    public function test_a_trailing_backslash_cannot_leak_the_next_literal_where_backslash_is_not_an_escape(): void
    {
        $normalizer = new SqlStatementNormalizer;
        $sql        = "UPDATE files SET path = 'C:\\' WHERE token = 'sk_live_secret'";

        foreach (['pgsql', 'sqlite', 'sqlsrv'] as $driver) {
            $this->assertSame(
                'UPDATE files SET path = ? WHERE token = ?',
                $normalizer->normalize($sql, driver: $driver),
                $driver,
            );
        }

        // MySQL does escape with backslashes, as do PostgreSQL E'' strings.
        $this->assertSame(
            'SELECT ? AS note, ? AS token',
            $normalizer->normalize("SELECT 'it\\'s' AS note, 'sk_live_secret' AS token", driver: 'mysql'),
        );
        $this->assertSame(
            'SELECT E? AS note, ? AS token',
            $normalizer->normalize("SELECT E'it\\'s' AS note, 'sk_live_secret' AS token", driver: 'pgsql'),
        );
    }

    public function test_endpoint_normalizer_masks_tokens_prefixed_ids_and_emails_in_paths(): void
    {
        $normalizer = new EndpointNormalizer;

        $this->assertSame(
            '/{id}/sendMessage',
            $normalizer->normalize('https://api.telegram.org/bot123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw/sendMessage')['path'],
        );
        $this->assertSame(
            '/v1/customers/{id}',
            $normalizer->normalize('https://api.stripe.com/v1/customers/cus_N8x9aBcDeF')['path'],
        );
        $this->assertSame(
            '/users/{id}/orders',
            $normalizer->normalize('https://crm.example.test/users/jane@example.test/orders')['path'],
        );
    }
}
