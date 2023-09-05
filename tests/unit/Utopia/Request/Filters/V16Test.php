<?php

namespace Tests\Unit\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;
use Appwrite\Utopia\Request\Filters\V16;
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

    public function queriesProvider(): array
    {
        return [
            'no queries' => [
                [],
                [],
            ],
            'empty queries' => [
                [
                    'queries' => [],
                ],
                [
                    'queries' => [],
                ],
            ],
            'without select query' => [
                [
                    'queries' => [
                        'limit(12)',
                        'offset(0)',
                        'orderDesc("")',
                    ],
                ],
                [
                    'queries' => [
                        'limit(12)',
                        'offset(0)',
                        'orderDesc("")',
                    ],
                ],
            ],
            'with single select query' => [
                [
                    'queries' => [
                        'limit(12)',
                        'offset(0)',
                        'orderDesc("")',
                        'select(["attr1"])',
                    ],
                ],
                [
                    'queries' => [
                        'limit(12)',
                        'offset(0)',
                        'orderDesc("")',
                        'select(["$id","$createdAt","$updatedAt","$permissions","attr1"])',
                    ],
                ],
            ],
            'with multi select query' => [
                [
                    'queries' => [
                        'limit(12)',
                        'offset(0)',
                        'orderDesc("")',
                        'select(["attr1","attr2"])',
                    ],
                ],
                [
                    'queries' => [
                        'limit(12)',
                        'offset(0)',
                        'orderDesc("")',
                        'select(["$id","$createdAt","$updatedAt","$permissions","attr1","attr2"])',
                    ],
                ],
            ],
        ];
    }

    /**
     * @dataProvider queriesProvider
     */
    public function testQueries(array $content, array $expected): void
    {
        $model = 'databases.listDocuments';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }
}
