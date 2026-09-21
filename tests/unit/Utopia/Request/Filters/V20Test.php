<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;
use Appwrite\Utopia\Request\Filters\V20;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class V20Test extends TestCase
{
    protected Filter $filter;

    protected function setUp(): void
    {
        $this->filter = new V20();
    }

    public static function invalidQueriesProvider(): \Iterator
    {
        yield 'string' => ['invalid'];
        yield 'integer' => [1];
        yield 'object' => [new \stdClass()];
    }

    #[DataProvider('invalidQueriesProvider')]
    public function testPreservesInvalidQueriesForEndpointValidation(mixed $queries): void
    {
        $content = ['queries' => $queries];

        $this->assertSame($content, $this->filter->parse($content, 'databases.listDocuments'));
        $this->assertSame($content, $this->filter->parse($content, 'databases.getDocument'));
    }
}
