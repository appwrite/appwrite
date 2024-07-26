<?php

namespace Tests\Unit\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filters\V18;
use PHPUnit\Framework\TestCase;

class V18Test extends TestCase
{
    /**
     * @var Filter
     */
    protected $filter = null;

    public function setUp(): void
    {
        $this->filter = new V18();
    }

    public function tearDown(): void
    {
    }

    public function functionProvider(): array
    {
        return [
            'remove scopes' => [
                [
                    'scopes' => [
                        'example_scope',
                        'example_scope2',
                    ],
                ],
                [
                ]
            ]
        ];
    }

    /**
     * @dataProvider functionProvider
     */
    public function testFunction(array $content, array $expected): void
    {
        $model = Response::MODEL_FUNCTION;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }


    public function executionProvider(): array
    {
        return [
            'remove scheduledAt' => [
                [
                    'scheduledAt' => '2024-07-13T09:00:00.000Z',
                ],
                [
                ]
            ]
        ];
    }

    /**
     * @dataProvider executionProvider
     */
    public function testExecution(array $content, array $expected): void
    {
        $model = Response::MODEL_EXECUTION;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function projectProvider(): array
    {
        return [
            'remove authMockNumbers and authSessionAlerts' => [
                [
                    'authMockNumbers' => [
                        'example_mock_number',
                        'example_mock_number2',
                    ],
                    'authSessionAlerts' => [
                        'example_alert',
                        'example_alert2',
                    ],
                ],
                [
                ]
            ]
        ];
    }

    /**
     * @dataProvider projectProvider
     */
    public function testProject(array $content, array $expected): void
    {
        $model = Response::MODEL_PROJECT;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }
}
