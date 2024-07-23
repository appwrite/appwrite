<?php

namespace Tests\Unit\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filters\V16;
use Cron\CronExpression;
use PHPUnit\Framework\TestCase;
use Utopia\Database\DateTime;

class V16Test extends TestCase
{
    /**
     * @var Filter
     */
    protected $filter = null;

    public function setUp(): void
    {
        $this->filter = new V16();
    }

    public function tearDown(): void
    {
    }

    public function deploymentProvider(): array
    {
        return [
            'buildStdout and buildStderr' => [
                [
                    'buildLogs' => 'Compiling source files...',
                ],
                [
                    'buildStdout' => 'Compiling source files...',
                    'buildStderr' => '',
                ],
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

    public function executionProvider(): array
    {
        return [
            'statusCode' => [
                [
                    'responseStatusCode' => 200,
                ],
                [
                    'statusCode' => 200,
                ],
            ],
            'response' => [
                [
                    'responseBody' => 'Sample response.',
                ],
                [
                    'response' => 'Sample response.',
                ],
            ],
            'stdout' => [
                [
                    'logs' => 'Sample log.',
                ],
                [
                    'stdout' => 'Sample log.',
                ],
            ],
            'stderr' => [
                [
                    'errors' => 'Sample error.',
                ],
                [
                    'stderr' => 'Sample error.',
                ],
            ],
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

    public function functionProvider(): array
    {
        return [
            'empty schedule' => [
                [
                    'schedule' => '',
                ],
                [
                    'schedule' => '',
                    'schedulePrevious' => '',
                    'scheduleNext' => '',
                ],
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

    public function testFunctionSchedulePreviousScheduleNext(): void
    {
        $model = Response::MODEL_FUNCTION;

        $content = [
            'schedule' => '0 * * * *',
        ];

        $cron = new CronExpression($content['schedule']);

        $expected = [
            'schedule' => '0 * * * *',
            'scheduleNext' => DateTime::formatTz(DateTime::format($cron->getNextRunDate())),
            'schedulePrevious' => DateTime::formatTz(DateTime::format($cron->getPreviousRunDate())),
        ];

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function projectProvider(): array
    {
        return [
            'oAuthProviders' => [
                [
                    'oAuthProviders' => [
                        [
                            'key' => 'github',
                            'name' => 'GitHub',
                            'appId' => 'client_id',
                            'secret' => 'client_secret',
                            'enabled' => true,
                        ],
                    ],
                ],
                [
                    'oAuthProviders' => [
                        [
                            'name' => 'Github',
                            'appId' => 'client_id',
                            'secret' => 'client_secret',
                            'enabled' => true,
                        ],
                    ],
                    'domains' => [],
                ],
            ],
            'domains' => [
                [
                ],
                [
                    'domains' => [],
                ],
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

    public function variableProvider(): array
    {
        return [
            'functionId' => [
                [
                    'resourceId' => '5e5ea5c16897e',
                ],
                [
                    'functionId' => '5e5ea5c16897e',
                ],
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
