<?php

namespace Tests\Unit\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filters\V19;
use PHPUnit\Framework\TestCase;

class V19Test extends TestCase
{
    /**
     * @var Filter
     */
    protected $filter = null;

    public function setUp(): void
    {
        $this->filter = new V19();
    }

    public function tearDown(): void
    {
    }

    public function functionProvider(): array
    {
        return [
            'change deploymentId to deployment' => [
                [
                    'deploymentId' => 'deployment123',
                ],
                [
                    'deployment' => 'deployment123',
                ]
            ],
            'handle empty deploymentId' => [
                [
                    'name' => 'test-function',
                ],
                [
                    'deployment' => '',
                    'name' => 'test-function',
                ]
            ],
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

    public function functionListProvider(): array
    {
        return [
            'convert list of functions' => [
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
            ],
            'handle empty function list' => [
                [
                    'total' => 0,
                    'functions' => [],
                ],
                [
                    'total' => 0,
                    'functions' => [],
                ]
            ],
        ];
    }

    /**
     * @dataProvider functionListProvider
     */
    public function testFunctionList(array $content, array $expected): void
    {
        $model = Response::MODEL_FUNCTION_LIST;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function deploymentProvider(): array
    {
        return [
            'rename sourceSize to size and buildDuration to buildTime' => [
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
            ],
            'handle missing sourceSize and buildDuration' => [
                [
                    'id' => 'deployment123',
                ],
                [
                    'size' => '',
                    'buildTime' => '',
                    'id' => 'deployment123',
                ]
            ],
        ];
    }

    /**
     * @dataProvider deploymentProvider
     */
    public function testDeployment(array $content, array $expected): void
    {
        $model = Response::MODEL_DEPLOYMENT;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function proxyRuleProvider(): array
    {
        return [
            'rename deployment resource fields' => [
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
            ],
            'handle missing deployment resource fields' => [
                [
                    'domain' => 'example.com',
                ],
                [
                    'resourceType' => '',
                    'resourceId' => '',
                    'domain' => 'example.com',
                ]
            ],
        ];
    }

    /**
     * @dataProvider proxyRuleProvider
     */
    public function testProxyRule(array $content, array $expected): void
    {
        $model = Response::MODEL_PROXY_RULE;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function migrationProvider(): array
    {
        return [
            'remove resourceId field' => [
                [
                    'resourceId' => 'resource123',
                    'status' => 'completed',
                ],
                [
                    'status' => 'completed',
                ]
            ],
            'handle content without resourceId' => [
                [
                    'status' => 'completed',
                ],
                [
                    'status' => 'completed',
                ]
            ],
        ];
    }

    /**
     * @dataProvider migrationProvider
     */
    public function testMigration(array $content, array $expected): void
    {
        $model = Response::MODEL_MIGRATION;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function projectProvider(): array
    {
        return [
            'remove devKeys field' => [
                [
                    'devKeys' => ['key1', 'key2'],
                    'name' => 'test-project',
                ],
                [
                    'name' => 'test-project',
                ]
            ],
            'handle content without devKeys' => [
                [
                    'name' => 'test-project',
                ],
                [
                    'name' => 'test-project',
                ]
            ],
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

    public function providerRepositoryProvider(): array
    {
        return [
            'remove runtime field' => [
                [
                    'runtime' => 'nodejs',
                    'name' => 'test-repo',
                ],
                [
                    'name' => 'test-repo',
                ]
            ],
            'handle content without runtime' => [
                [
                    'name' => 'test-repo',
                ],
                [
                    'name' => 'test-repo',
                ]
            ],
        ];
    }

    /**
     * @dataProvider providerRepositoryProvider
     */
    public function testProviderRepository(array $content, array $expected): void
    {
        $model = Response::MODEL_PROVIDER_REPOSITORY;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function templateVariableProvider(): array
    {
        return [
            'remove secret field' => [
                [
                    'secret' => 'secret-value',
                    'name' => 'test-variable',
                ],
                [
                    'name' => 'test-variable',
                ]
            ],
            'handle content without secret' => [
                [
                    'name' => 'test-variable',
                ],
                [
                    'name' => 'test-variable',
                ]
            ],
        ];
    }

    /**
     * @dataProvider templateVariableProvider
     */
    public function testTemplateVariable(array $content, array $expected): void
    {
        $model = Response::MODEL_TEMPLATE_VARIABLE;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function usageFunctionProvider(): array
    {
        return [
            'remove build-related fields' => [
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
            ],
            'handle content without build fields' => [
                [
                    'executions' => 100,
                ],
                [
                    'executions' => 100,
                ]
            ],
        ];
    }

    /**
     * @dataProvider usageFunctionProvider
     */
    public function testUsageFunction(array $content, array $expected): void
    {
        $model = Response::MODEL_USAGE_FUNCTION;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function usageFunctionsProvider(): array
    {
        return [
            'remove build-related fields' => [
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
            ],
            'handle content without build fields' => [
                [
                    'executions' => 200,
                ],
                [
                    'executions' => 200,
                ]
            ],
        ];
    }

    /**
     * @dataProvider usageFunctionsProvider
     */
    public function testUsageFunctions(array $content, array $expected): void
    {
        $model = Response::MODEL_USAGE_FUNCTIONS;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function variableProvider(): array
    {
        return [
            'remove secret field' => [
                [
                    'secret' => 'secret-value',
                    'name' => 'test-variable',
                ],
                [
                    'name' => 'test-variable',
                ]
            ],
            'handle content without secret' => [
                [
                    'name' => 'test-variable',
                ],
                [
                    'name' => 'test-variable',
                ]
            ],
        ];
    }

    /**
     * @dataProvider variableProvider
     */
    public function testVariable(array $content, array $expected): void
    {
        $model = Response::MODEL_VARIABLE;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }
}
