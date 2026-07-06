<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;
use Appwrite\Utopia\Request\Filters\V16;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class V16Test extends TestCase
{
    /**
     * @var Filter
     */
    protected $filter;

    public function setUp(): void
    {
        $this->filter = new V16();
    }

    public function tearDown(): void
    {
    }

    public static function createExecutionProvider(): \Iterator
    {
        yield 'data' => [
            [
                'data' => 'Lorem ipsum'
            ],
            [
                'body' => 'Lorem ipsum'
            ],
        ];
        yield 'no data' => [
            [],
            [
                'body' => ''
            ],
        ];
    }

    #[DataProvider('createExecutionProvider')]
    public function testCreateExecution(array $content, array $expected): void
    {
        $model = 'functions.createExecution';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }
}
