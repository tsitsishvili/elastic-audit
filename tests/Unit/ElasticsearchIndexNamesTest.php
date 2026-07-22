<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchIndexNames;

class ElasticsearchIndexNamesTest extends TestCase
{
    public function test_accepts_a_valid_index_alias(): void
    {
        $this->assertNull(ElasticsearchIndexNames::validationError('example-app_http-logs.v4'));
    }

    #[DataProvider('invalidNames')]
    public function test_rejects_invalid_index_aliases(string $name): void
    {
        $this->assertNotNull(ElasticsearchIndexNames::validationError($name));
    }

    public static function invalidNames(): array
    {
        return [
            'empty'              => [''],
            'space'              => ['example app'],
            'uppercase'          => ['ExampleApp'],
            'leading underscore' => ['_internal'],
            'forbidden slash'    => ['example/app'],
            'reserved dot'       => ['.'],
            'too long'           => [str_repeat('a', 256)],
        ];
    }

    public function test_assert_valid_explains_the_invalid_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP logs read alias [Example App] is invalid');

        ElasticsearchIndexNames::assertValid('Example App', 'HTTP logs read alias');
    }
}
