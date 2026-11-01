<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchWriteAlias;

class ElasticsearchWriteAliasTest extends TestCase
{
    private const ALIAS = 'app_logs_write';

    public function test_resolves_the_single_explicit_write_index(): void
    {
        $response = $this->aliasResponse(['a-000001' => false, 'a-000002' => true]);

        $this->assertSame('a-000002', ElasticsearchWriteAlias::writeIndex($response, self::ALIAS));
    }

    public function test_resolves_a_single_implicit_write_index(): void
    {
        $response = $this->aliasResponse(['a-000001' => null]);

        $this->assertSame('a-000001', ElasticsearchWriteAlias::writeIndex($response, self::ALIAS));
    }

    public function test_does_not_resolve_multiple_implicit_indexes(): void
    {
        $response = $this->aliasResponse(['a-000001' => null, 'a-000002' => null]);

        $this->assertNull(ElasticsearchWriteAlias::writeIndex($response, self::ALIAS));
    }

    public function test_does_not_resolve_a_single_explicitly_non_writable_index(): void
    {
        $response = $this->aliasResponse(['a-000001' => false]);

        $this->assertNull(ElasticsearchWriteAlias::writeIndex($response, self::ALIAS));
    }

    public function test_does_not_resolve_multiple_explicit_write_indexes(): void
    {
        $response = $this->aliasResponse(['a-000001' => true, 'a-000002' => true]);

        $this->assertNull(ElasticsearchWriteAlias::writeIndex($response, self::ALIAS));
    }

    public function test_stale_indexes_are_every_index_except_the_write_index(): void
    {
        $response = $this->aliasResponse(['a-000001' => false, 'a-000002' => false, 'a-000003' => true]);

        $this->assertSame(['a-000001', 'a-000002'], ElasticsearchWriteAlias::staleIndexes($response, self::ALIAS));
    }

    public function test_a_normalized_alias_has_no_stale_indexes(): void
    {
        $response = $this->aliasResponse(['a-000002' => null]);

        $this->assertSame([], ElasticsearchWriteAlias::staleIndexes($response, self::ALIAS));
    }

    public function test_an_unresolvable_alias_has_no_stale_indexes(): void
    {
        $response = $this->aliasResponse(['a-000001' => null, 'a-000002' => null]);

        $this->assertSame([], ElasticsearchWriteAlias::staleIndexes($response, self::ALIAS));
    }

    public function test_detects_an_explicit_write_flag_on_the_write_index(): void
    {
        $this->assertTrue(ElasticsearchWriteAlias::hasExplicitWriteFlag(
            $this->aliasResponse(['a-000001' => true]),
            self::ALIAS,
        ));
        $this->assertFalse(ElasticsearchWriteAlias::hasExplicitWriteFlag(
            $this->aliasResponse(['a-000001' => null]),
            self::ALIAS,
        ));
    }

    public function test_normalization_detaches_stale_indexes_and_clears_the_flag_after_an_explicit_rollover(): void
    {
        $response = $this->aliasResponse(['a-000001' => false, 'a-000002' => true]);

        $this->assertSame([
            ['remove' => ['index' => 'a-000001', 'alias' => self::ALIAS]],
            ['add' => ['index' => 'a-000002', 'alias' => self::ALIAS]],
        ], ElasticsearchWriteAlias::normalizationActions($response, self::ALIAS));
    }

    public function test_normalization_only_clears_the_flag_on_a_flagged_single_index(): void
    {
        $response = $this->aliasResponse(['a-000001' => true]);

        $this->assertSame([
            ['add' => ['index' => 'a-000001', 'alias' => self::ALIAS]],
        ], ElasticsearchWriteAlias::normalizationActions($response, self::ALIAS));
    }

    public function test_normalization_is_a_no_op_for_a_normalized_alias(): void
    {
        $response = $this->aliasResponse(['a-000001' => null]);

        $this->assertSame([], ElasticsearchWriteAlias::normalizationActions($response, self::ALIAS));
    }

    public function test_normalization_is_a_no_op_for_an_unresolvable_alias(): void
    {
        $response = $this->aliasResponse(['a-000001' => true, 'a-000002' => true]);

        $this->assertSame([], ElasticsearchWriteAlias::normalizationActions($response, self::ALIAS));
    }

    /**
     * Build a GET _alias response. A null flag omits is_write_index, as
     * Elasticsearch does for an alias attached without the flag.
     *
     * @param  array<string, bool|null>  $indexes
     */
    private function aliasResponse(array $indexes): array
    {
        $response = [];

        foreach ($indexes as $index => $flag) {
            $response[$index] = [
                'aliases' => [
                    self::ALIAS => $flag === null ? [] : ['is_write_index' => $flag],
                ],
            ];
        }

        return $response;
    }
}
