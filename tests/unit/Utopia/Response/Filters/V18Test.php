<?php

namespace Tests\Unit\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filters\V18;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public static function functionProvider(): array
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
            ],
        ];
    }

    #[DataProvider('functionProvider')]
    public function testFunction(array $content, array $expected): void
    {
        $model = Response::MODEL_FUNCTION;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }


    public static function executionProvider(): array
    {
        return [
            'remove scheduledAt' => [
                [
                    'scheduledAt' => '2024-07-13T09:00:00.000Z',
                ],
                [
                ]
            ],
            'update 404 status' => [
                [
                    'statusCode' => '404',
                    'status' => 'completed'
                ],
                [
                    'statusCode' => '404',
                    'status' => 'failed'
                ]
            ],
            'update 400 status' => [
                [
                    'statusCode' => '400',
                    'status' => 'completed'
                ],
                [
                    'statusCode' => '400',
                    'status' => 'failed'
                ]
            ],
            'dont update 200 status' => [
                [
                    'statusCode' => '200',
                    'status' => 'completed'
                ],
                [
                    'statusCode' => '200',
                    'status' => 'completed'
                ]
            ],
            'dont update 500 status' => [
                [
                    'statusCode' => '500',
                    'status' => 'failed'
                ],
                [
                    'statusCode' => '500',
                    'status' => 'failed'
                ]
            ]
        ];
    }

    #[DataProvider('executionProvider')]
    public function testExecution(array $content, array $expected): void
    {
        $model = Response::MODEL_EXECUTION;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public static function projectProvider(): array
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

    #[DataProvider('projectProvider')]
    public function testProject(array $content, array $expected): void
    {
        $model = Response::MODEL_PROJECT;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public static function runtimeProvider(): array
    {
        return [
            'remove key' => [
                [
                    'key' => 'example_key',
                ],
                [
                ]
            ]
        ];
    }

    #[DataProvider('runtimeProvider')]
    public function testRuntime(array $content, array $expected): void
    {
        $model = Response::MODEL_RUNTIME;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }
}
