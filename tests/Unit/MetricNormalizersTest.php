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
}
