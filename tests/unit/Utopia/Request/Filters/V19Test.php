<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;
use Appwrite\Utopia\Request\Filters\V19;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class V19Test extends TestCase
{
    /**
     * @var Filter
     */
    protected $filter;

    public function setUp(): void
    {
        $this->filter = new V19();
    }

    public function tearDown(): void
    {
    }

    public static function functionsCreateProvider(): \Iterator
    {
        yield 'remove template fields' => [
            [
                'name' => 'test-function',
                'runtime' => 'node-18.0',
                'templateRepository' => 'github.com/appwrite/templates',
                'templateOwner' => 'appwrite',
                'templateRootDirectory' => 'functions/node',
                'templateVersion' => '1.0.0'
            ],
            [
                'name' => 'test-function',
                'runtime' => 'node-18.0'
            ]
        ];
    }

    public static function functionsListExecutionsProvider(): \Iterator
    {
        yield 'remove search field' => [
            [
                'functionId' => 'test-function',
                'search' => 'test query',
                'limit' => 10
            ],
            [
                'functionId' => 'test-function',
                'limit' => 10
            ]
        ];
    }

    #[DataProvider('functionsCreateProvider')]
    public function testFunctionsCreate(array $content, array $expected): void
    {
        $model = 'functions.create';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    #[DataProvider('functionsListExecutionsProvider')]
    public function testFunctionsListExecutions(array $content, array $expected): void
    {
        $model = 'functions.listExecutions';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }
}
