<?php

namespace Tests\Unit\Utopia\Response\Filters;

use Appwrite\Utopia\Response\Filters\V16;
use Appwrite\Utopia\Response\Model;
use Appwrite\Utopia\Response;
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
            'providers' => [
                [
                    'providers' => [
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
                    'providers' => [
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

    public function usageBucketsProvider(): array
    {
        $metrics = [
            [
                'value' => 123,
                'date' => Model::TYPE_DATETIME_EXAMPLE,
            ]
        ];
        return [
            'filesCount and deleted usage' => [
                [
                    'filesTotal' => $metrics,
                ],
                [
                    'filesCount' => $metrics,
                    'filesCreate' => [],
                    'filesRead' => [],
                    'filesUpdate' => [],
                    'filesDelete' => [],
                ],
            ],
        ];
    }

    /**
     * @dataProvider usageBucketsProvider
     */
    public function testUsageBuckets(array $content, array $expected): void
    {
        $model = Response::MODEL_USAGE_BUCKETS;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function usageCollectionProvider(): array
    {
        $metrics = [
            [
                'value' => 123,
                'date' => Model::TYPE_DATETIME_EXAMPLE,
            ]
        ];
        return [
            'documentsCount and deleted usage' => [
                [
                    'documentsTotal' => $metrics,
                ],
                [
                    'documentsCount' => $metrics,
                    'documentsCreate' => [],
                    'documentsRead' => [],
                    'documentsUpdate' => [],
                    'documentsDelete' => [],
                ],
            ],
        ];
    }

    /**
     * @dataProvider usageCollectionProvider
     */
    public function testUsageCollection(array $content, array $expected): void
    {
        $model = Response::MODEL_USAGE_COLLECTION;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function usageDatabaseProvider(): array
    {
        $metrics = [
            [
                'value' => 123,
                'date' => Model::TYPE_DATETIME_EXAMPLE,
            ]
        ];
        return [
            'collectionsCount and deleted usage' => [
                [
                    'collectionsTotal' => $metrics,
                ],
                [
                    'collectionsCount' => $metrics,
                    'documentsCreate' => [],
                    'documentsRead' => [],
                    'documentsUpdate' => [],
                    'documentsDelete' => [],
                    'collectionsCreate' => [],
                    'collectionsRead' => [],
                    'collectionsUpdate' => [],
                    'collectionsDelete' => [],
                ],
            ],
            'documentsCount and deleted usage' => [
                [
                    'documentsTotal' => $metrics,
                ],
                [
                    'documentsCount' => $metrics,
                    'documentsCreate' => [],
                    'documentsRead' => [],
                    'documentsUpdate' => [],
                    'documentsDelete' => [],
                    'collectionsCreate' => [],
                    'collectionsRead' => [],
                    'collectionsUpdate' => [],
                    'collectionsDelete' => [],
                ],
            ],
        ];
    }

    /**
     * @dataProvider usageDatabaseProvider
     */
    public function testUsageDatabase(array $content, array $expected): void
    {
        $model = Response::MODEL_USAGE_DATABASE;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function usageDatabasesProvider(): array
    {
        $metrics = [
            [
                'value' => 123,
                'date' => Model::TYPE_DATETIME_EXAMPLE,
            ]
        ];
        return [
            'databasesCount and deleted usage' => [
                [
                    'databasesTotal' => $metrics,
                ],
                [
                    'databasesCount' => $metrics,
                    'documentsCreate' => [],
                    'documentsRead' => [],
                    'documentsUpdate' => [],
                    'documentsDelete' => [],
                    'collectionsCreate' => [],
                    'collectionsRead' => [],
                    'collectionsUpdate' => [],
                    'collectionsDelete' => [],
                    'databasesCreate' => [],
                    'databasesRead' => [],
                    'databasesUpdate' => [],
                    'databasesDelete' => [],
                ],
            ],
            'collectionsCount and deleted usage' => [
                [
                    'collectionsTotal' => $metrics,
                ],
                [
                    'collectionsCount' => $metrics,
                    'documentsCreate' => [],
                    'documentsRead' => [],
                    'documentsUpdate' => [],
                    'documentsDelete' => [],
                    'collectionsCreate' => [],
                    'collectionsRead' => [],
                    'collectionsUpdate' => [],
                    'collectionsDelete' => [],
                    'databasesCreate' => [],
                    'databasesRead' => [],
                    'databasesUpdate' => [],
                    'databasesDelete' => [],
                ],
            ],
            'documentsCount and deleted usage' => [
                [
                    'documentsTotal' => $metrics,
                ],
                [
                    'documentsCount' => $metrics,
                    'documentsCreate' => [],
                    'documentsRead' => [],
                    'documentsUpdate' => [],
                    'documentsDelete' => [],
                    'collectionsCreate' => [],
                    'collectionsRead' => [],
                    'collectionsUpdate' => [],
                    'collectionsDelete' => [],
                    'databasesCreate' => [],
                    'databasesRead' => [],
                    'databasesUpdate' => [],
                    'databasesDelete' => [],
                ],
            ],
        ];
    }

    /**
     * @dataProvider usageDatabasesProvider
     */
    public function testUsageDatabases(array $content, array $expected): void
    {
        $model = Response::MODEL_USAGE_DATABASES;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function usageFunctionProvider(): array
    {
        return [
            'deleted usage' => [
                [
                ],
                [
                    'buildsFailure' => [],
                    'buildsSuccess' => [],
                    'executionsFailure' => [],
                    'executionsSuccess' => [],
                ],
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
            'deleted usage' => [
                [
                ],
                [
                    'buildsFailure' => [],
                    'buildsSuccess' => [],
                    'executionsFailure' => [],
                    'executionsSuccess' => [],
                ],
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

    public function usageProjectProvider(): array
    {
        $metrics = [
            [
                'value' => 123,
                'date' => Model::TYPE_DATETIME_EXAMPLE,
            ]
        ];
        return [
            'requests usage' => [
                [
                    'requestsTotal' => $metrics,
                ],
                [
                    'requests' => $metrics,
                ],
            ],
            'executions usage' => [
                [
                    'executionsTotal' => $metrics,
                ],
                [
                    'executions' => $metrics,
                ],
            ],
            'documents usage' => [
                [
                    'documentsTotal' => $metrics,
                ],
                [
                    'documents' => $metrics,
                ],
            ],
            'databases usage' => [
                [
                    'databasesTotal' => $metrics,
                ],
                [
                    'databases' => $metrics,
                ],
            ],
            'users usage' => [
                [
                    'usersTotal' => $metrics,
                ],
                [
                    'users' => $metrics,
                ],
            ],
            'storage usage' => [
                [
                    'filesStorage' => $metrics,
                ],
                [
                    'storage' => $metrics,
                ],
            ],
            'buckets usage' => [
                [
                    'bucketsTotal' => $metrics,
                ],
                [
                    'buckets' => $metrics,
                ],
            ],
        ];
    }

    /**
     * @dataProvider usageProjectProvider
     */
    public function testUsageProject(array $content, array $expected): void
    {
        $model = Response::MODEL_USAGE_PROJECT;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function usageStorageProvider(): array
    {
        $metrics = [
            [
                'value' => 123,
                'date' => Model::TYPE_DATETIME_EXAMPLE,
            ]
        ];
        return [
            'bucketsCount usage' => [
                [
                    'bucketsTotal' => $metrics,
                ],
                [
                    'bucketsCount' => $metrics,
                    'bucketsCreate' => [],
                    'bucketsRead' => [],
                    'bucketsUpdate' => [],
                    'bucketsDelete' => [],
                    'filesCreate' => [],
                    'filesRead' => [],
                    'filesUpdate' => [],
                    'filesDelete' => [],
                ],
            ],
            'filesCount usage' => [
                [
                    'filesTotal' => $metrics,
                ],
                [
                    'filesCount' => $metrics,
                    'bucketsCreate' => [],
                    'bucketsRead' => [],
                    'bucketsUpdate' => [],
                    'bucketsDelete' => [],
                    'filesCreate' => [],
                    'filesRead' => [],
                    'filesUpdate' => [],
                    'filesDelete' => [],
                ],
            ],
            'storage usage' => [
                [
                    'filesStorage' => $metrics,
                ],
                [
                    'storage' => $metrics,
                    'bucketsCreate' => [],
                    'bucketsRead' => [],
                    'bucketsUpdate' => [],
                    'bucketsDelete' => [],
                    'filesCreate' => [],
                    'filesRead' => [],
                    'filesUpdate' => [],
                    'filesDelete' => [],
                ],
            ],
        ];
    }

    /**
     * @dataProvider usageStorageProvider
     */
    public function testUsageStorage(array $content, array $expected): void
    {
        $model = Response::MODEL_USAGE_STORAGE;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function usageUsersProvider(): array
    {
        $metrics = [
            [
                'value' => 123,
                'date' => Model::TYPE_DATETIME_EXAMPLE,
            ]
        ];
        return [
            'usersCount usage' => [
                [
                    'usersTotal' => $metrics,
                ],
                [
                    'usersCount' => $metrics,
                    'usersCreate' => [],
                    'usersRead' => [],
                    'usersUpdate' => [],
                    'usersDelete' => [],
                    'sessionsProviderCreate' => [],
                    'sessionsDelete' => [],
                ],
            ],
            'sessionsCreate usage' => [
                [
                    'sessionsTotal' => $metrics,
                ],
                [
                    'sessionsCreate' => $metrics,
                    'usersCreate' => [],
                    'usersRead' => [],
                    'usersUpdate' => [],
                    'usersDelete' => [],
                    'sessionsProviderCreate' => [],
                    'sessionsDelete' => [],
                ],
            ],
        ];
    }

    /**
     * @dataProvider usageUsersProvider
     */
    public function testUsageUsers(array $content, array $expected): void
    {
        $model = Response::MODEL_USAGE_USERS;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function usageVariableProvider(): array
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
     * @dataProvider usageVariableProvider
     */
    public function testVariable(array $content, array $expected): void
    {
        $model = Response::MODEL_VARIABLE;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }
}
