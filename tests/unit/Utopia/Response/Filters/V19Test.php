<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;
use Appwrite\Utopia\Response\Filters\V19;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class V19Test extends TestCase
{
    protected Filter $filter;

    public function setUp(): void
    {
        $this->filter = new V19();
    }

    public function tearDown(): void
    {
    }

    public static function functionProvider(): \Iterator
    {
        yield 'change deploymentId to deployment' => [
            [
                'deploymentId' => 'deployment123',
            ],
            [
                'deployment' => 'deployment123',
            ]
        ];
        yield 'handle empty deploymentId' => [
            [
                'name' => 'test-function',
            ],
            [
                'deployment' => '',
                'name' => 'test-function',
            ]
        ];
    }

    #[DataProvider('functionProvider')]
    public function testFunction(array $content, array $expected): void
    {
        $model = Response::MODEL_FUNCTION;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public static function functionListProvider(): \Iterator
    {
        yield 'convert list of functions' => [
            [
                'total' => 2,
                'functions' => [
                    [
                        'deploymentId' => 'deployment123',
                        'name' => 'function-1',
                    ],
                    [
                        'deploymentId' => 'deployment456',
                        'name' => 'function-2',
                    ],
                ],
            ],
            [
                'total' => 2,
                'functions' => [
                    [
                        'deployment' => 'deployment123',
                        'name' => 'function-1',
                    ],
                    [
                        'deployment' => 'deployment456',
                        'name' => 'function-2',
                    ],
                ],
            ]
        ];
        yield 'handle empty function list' => [
            [
                'total' => 0,
                'functions' => [],
            ],
            [
                'total' => 0,
                'functions' => [],
            ]
        ];
    }

    #[DataProvider('functionListProvider')]
    public function testFunctionList(array $content, array $expected): void
    {
        $model = Response::MODEL_FUNCTION_LIST;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public static function deploymentProvider(): \Iterator
    {
        yield 'rename sourceSize to size and buildDuration to buildTime' => [
            [
                'sourceSize' => 1024,
                'buildDuration' => 60,
                'id' => 'deployment123',
            ],
            [
                'size' => 1024,
                'buildTime' => 60,
                'id' => 'deployment123',
            ]
        ];
        yield 'handle missing sourceSize and buildDuration' => [
            [
                'id' => 'deployment123',
            ],
            [
                'size' => '',
                'buildTime' => '',
                'id' => 'deployment123',
            ]
        ];
    }

    #[DataProvider('deploymentProvider')]
    public function testDeployment(array $content, array $expected): void
    {
        $model = Response::MODEL_DEPLOYMENT;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public static function proxyRuleProvider(): \Iterator
    {
        yield 'rename deployment resource fields' => [
            [
                'deploymentResourceType' => 'function',
                'deploymentResourceId' => 'func123',
                'domain' => 'example.com',
            ],
            [
                'resourceType' => 'function',
                'resourceId' => 'func123',
                'domain' => 'example.com',
            ]
        ];
        yield 'handle missing deployment resource fields' => [
            [
                'domain' => 'example.com',
            ],
            [
                'resourceType' => '',
                'resourceId' => '',
                'domain' => 'example.com',
            ]
        ];
    }

    #[DataProvider('proxyRuleProvider')]
    public function testProxyRule(array $content, array $expected): void
    {
        $model = Response::MODEL_PROXY_RULE;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public static function migrationProvider(): \Iterator
    {
        yield 'remove resourceId field' => [
            [
                'resourceId' => 'resource123',
                'status' => 'completed',
            ],
            [
                'status' => 'completed',
            ]
        ];
        yield 'handle content without resourceId' => [
            [
                'status' => 'completed',
            ],
            [
                'status' => 'completed',
            ]
        ];
    }

    #[DataProvider('migrationProvider')]
    public function testMigration(array $content, array $expected): void
    {
        $model = Response::MODEL_MIGRATION;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public static function providerRepositoryProvider(): \Iterator
    {
        yield 'remove runtime field' => [
            [
                'runtime' => 'nodejs',
                'name' => 'test-repo',
            ],
            [
                'name' => 'test-repo',
            ]
        ];
        yield 'handle content without runtime' => [
            [
                'name' => 'test-repo',
            ],
            [
                'name' => 'test-repo',
            ]
        ];
    }

    #[DataProvider('providerRepositoryProvider')]
    public function testProviderRepository(array $content, array $expected): void
    {
        $model = Response::MODEL_PROVIDER_REPOSITORY;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public static function templateVariableProvider(): \Iterator
    {
        yield 'remove secret field' => [
            [
                'secret' => 'secret-value',
                'name' => 'test-variable',
            ],
            [
                'name' => 'test-variable',
            ]
        ];
        yield 'handle content without secret' => [
            [
                'name' => 'test-variable',
            ],
            [
                'name' => 'test-variable',
            ]
        ];
    }

    #[DataProvider('templateVariableProvider')]
    public function testTemplateVariable(array $content, array $expected): void
    {
        $model = Response::MODEL_TEMPLATE_VARIABLE;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public static function usageFunctionProvider(): \Iterator
    {
        yield 'remove build-related fields' => [
            [
                'buildsSuccessTotal' => 10,
                'buildsFailedTotal' => 2,
                'buildsTimeAverage' => 30,
                'buildsSuccess' => 5,
                'buildsFailed' => 1,
                'executions' => 100,
            ],
            [
                'executions' => 100,
            ]
        ];
        yield 'handle content without build fields' => [
            [
                'executions' => 100,
            ],
            [
                'executions' => 100,
            ]
        ];
    }

    #[DataProvider('usageFunctionProvider')]
    public function testUsageFunction(array $content, array $expected): void
    {
        $model = Response::MODEL_USAGE_FUNCTION;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public static function usageFunctionsProvider(): \Iterator
    {
        yield 'remove build-related fields' => [
            [
                'buildsSuccessTotal' => 20,
                'buildsFailedTotal' => 4,
                'buildsSuccess' => 10,
                'buildsFailed' => 2,
                'executions' => 200,
            ],
            [
                'executions' => 200,
            ]
        ];
        yield 'handle content without build fields' => [
            [
                'executions' => 200,
            ],
            [
                'executions' => 200,
            ]
        ];
    }

    #[DataProvider('usageFunctionsProvider')]
    public function testUsageFunctions(array $content, array $expected): void
    {
        $model = Response::MODEL_USAGE_FUNCTIONS;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public static function variableProvider(): \Iterator
    {
        yield 'remove secret field' => [
            [
                'secret' => 'secret-value',
                'name' => 'test-variable',
            ],
            [
                'name' => 'test-variable',
            ]
        ];
        yield 'handle content without secret' => [
            [
                'name' => 'test-variable',
            ],
            [
                'name' => 'test-variable',
            ]
        ];
    }

    #[DataProvider('variableProvider')]
    public function testVariable(array $content, array $expected): void
    {
        $model = Response::MODEL_VARIABLE;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }
}
