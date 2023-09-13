<?php

namespace Tests\Unit\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;
use Appwrite\Utopia\Request\Filters\V16;
use Appwrite\Utopia\Response\Model;
use PHPUnit\Framework\TestCase;

class V16Test extends TestCase
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

    public function createExecutionProvider(): array
    {
        return [
            'data' => [
                [
                    'data' => 'Lorem ipsum'
                ],
                [
                    'body' => 'Lorem ipsum'
                ],
            ],
            'no data' => [
                [],
                [
                    'body' => ''
                ],
            ],
        ];
    }

    /**
     * @dataProvider createExecutionProvider
     */
    public function testCreateExecution(array $content, array $expected): void
    {
        $model = 'functions.createExecution';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }
}
