<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;
use Appwrite\Utopia\Request\Filters\V20;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Query;

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

    public static function cursorQueriesProvider(): \Iterator
    {
        yield 'cursor after' => ['{"method":"cursorAfter","values":["movie0"]}'];
        yield 'cursor before' => ['{"method":"cursorBefore","values":["movie0"]}'];
    }

    #[DataProvider('cursorQueriesProvider')]
    public function testKeepsCursorIdForTheActionToResolve(string $cursor): void
    {
        $limit = Query::limit(2)->toString();

        $result = $this->filter->parse(['queries' => [$limit, $cursor]], 'databases.listDocuments');

        $this->assertSame([$limit, $cursor, Query::select(['*'])->toString()], $result['queries']);
    }

    public function testKeepsCursorIdBesideExplicitSelect(): void
    {
        $select = Query::select(['title'])->toString();
        $cursor = '{"method":"cursorAfter","values":["movie0"]}';

        $result = $this->filter->parse(['queries' => [$select, $cursor]], 'databases.listDocuments');

        $this->assertSame([$select, $cursor], $result['queries']);
    }
}
