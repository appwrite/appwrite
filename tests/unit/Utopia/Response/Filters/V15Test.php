<?php

namespace Tests\Unit\Utopia\Response\Filters;

use Appwrite\Utopia\Response\Filters\V15;
use Appwrite\Utopia\Response;
use Utopia\Database\Permission;
use Utopia\Database\Role;
use PHPUnit\Framework\TestCase;

class V15Test extends TestCase
{
    /**
     * @var Filter
     */
    protected $filter = null;

    public function setUp(): void
    {
        $this->filter = new V15();
    }

    public function tearDown(): void
    {
    }

    public function createdAtUpdatedAtProvider(): array
    {
        return [
            'basic datetimes' => [
                [
                    '$createdAt' => '2020-06-24T06:47:30.000Z',
                    '$updatedAt' => '2020-06-24T06:47:30.000Z',
                ],
                [
                    '$createdAt' => 1592981250,
                    '$updatedAt' => 1592981250,
                ],
            ],
            'null datetime' => [
                [
                    '$createdAt' => null,
                    '$updatedAt' => null,
                ],
                [
                    '$createdAt' => 0,
                    '$updatedAt' => 0,
                ],
            ],
            'empty datetime' => [
                [
                    '$createdAt' => '',
                    '$updatedAt' => '',
                ],
                [
                    '$createdAt' => 0,
                    '$updatedAt' => 0,
                ],
            ],
        ];
    }

    public function permissionsProvider(): array
    {
        return [
            'basic permissions' => [
                [
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::write(Role::user('608f9da25e7e1')),
                    ],
                ],
                [
                    '$read' => ['role:all'],
                    '$write' => ['user:608f9da25e7e1'],
                ],
            ],
            'all roles' => [
                [
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::read(Role::guests()),
                        Permission::read(Role::users()),
                        Permission::read(Role::user('asdf')),
                        Permission::read(Role::team('qwer')),
                        Permission::read(Role::team('qwer', 'uiop')),
                        Permission::read(Role::member('zxcv')),
                    ],
                ],
                [
                    '$read' => [
                        'role:all',
                        'role:guest',
                        'role:member',
                        'user:asdf',
                        'team:qwer',
                        'team:qwer/uiop',
                        'member:zxcv',
                    ],
                    '$write' => [],
                ],
            ],
            'create conversion' => [
                [
                    '$permissions' => [Permission::create(Role::user('a'))],
                ],
                [
                    '$read' => [],
                    '$write' => ['user:a'],
                ],
            ],
            'update conversion' => [
                [
                    '$permissions' => [Permission::update(Role::user('a'))],
                ],
                [
                    '$read' => [],
                    '$write' => ['user:a'],
                ],
            ],
            'delete conversion' => [
                [
                    '$permissions' => [Permission::delete(Role::user('a'))],
                ],
                [
                    '$read' => [],
                    '$write' => ['user:a'],
                ],
            ],
            'write conversion' => [
                [
                    '$permissions' => [Permission::write(Role::user('a'))],
                ],
                [
                    '$read' => [],
                    '$write' => ['user:a'],
                ],
            ],
        ];
    }

    public function testAccount(): void
    {
        $model = Response::MODEL_ACCOUNT;

        $content = [
            '$id' => '6264711f995c5b012b48',
            '$createdAt' => '2020-06-24T06:47:30.000Z',
            '$updatedAt' => '2020-06-24T06:47:30.000Z',
            'name' => 'John Doe',
            'registration' => '2020-06-24T06:47:30.000Z',
            'status' => true,
            'passwordUpdate' => '2020-06-24T06:47:30.000Z',
            'email' => 'john@appwrite.io',
            'phone' => '+4930901820',
            'emailVerification' => true,
            'phoneVerification' => true,
            'prefs' => new \stdClass(),
        ];

        $expected = [
            '$id' => '6264711f995c5b012b48',
            '$createdAt' => 1592981250,
            '$updatedAt' => 1592981250,
            'name' => 'John Doe',
            'registration' => 1592981250,
            'status' => true,
            'passwordUpdate' => 1592981250,
            'email' => 'john@appwrite.io',
            'phone' => '+4930901820',
            'emailVerification' => true,
            'phoneVerification' => true,
            'prefs' => new \stdClass(),
        ];

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function bucketProvider(): array
    {
        return [
            'basic bucket' => [
                [
                    '$id' => '5e5ea5c16897e',
                    '$createdAt' => '2020-06-24T06:47:30.000Z',
                    '$updatedAt' => '2020-06-24T06:47:30.000Z',
                    'fileSecurity' => true,
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::write(Role::user('608f9da25e7e1')),
                    ],
                    'name' => 'Documents',
                    'enabled' => false,
                    'maximumFileSize' => 100,
                    'allowedFileExtensions' => [
                        'jpg',
                        'png'
                    ],
                    'encryption' => false,
                    'antivirus' => false,
                ],
                [
                    '$id' => '5e5ea5c16897e',
                    '$createdAt' => 1592981250,
                    '$updatedAt' => 1592981250,
                    '$read' => ['role:all'],
                    '$write' => ['user:608f9da25e7e1'],
                    'permission' => 'file',
                    'name' => 'Documents',
                    'enabled' => false,
                    'maximumFileSize' => 100,
                    'allowedFileExtensions' => [
                        'jpg',
                        'png'
                    ],
                    'encryption' => false,
                    'antivirus' => false,
                ],
            ],
            'false fileSecurity' => [
                ['fileSecurity' => false],
                ['permission' => 'bucket'],
            ],
        ];
    }

    /**
     * @dataProvider createdAtUpdatedAtProvider
     * @dataProvider permissionsProvider
     * @dataProvider bucketProvider
     */
    public function testBucket(array $content, array $expected): void
    {
        $model = Response::MODEL_BUCKET;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function testBuild(): void
    {
        $model = Response::MODEL_BUILD;

        $content = [
            'startTime' => '2020-06-24T06:47:30.000Z',
            'endTime' => '2020-06-24T06:47:30.000Z',
        ];

        $expected = [
            'startTime' => 1592981250,
            'endTime' => 1592981250,
        ];

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function collectionProvider(): array
    {
        return [
            'basic collection' => [
                [
                    '$id' => '5e5ea5c16897e',
                    '$createdAt' => '2020-06-24T06:47:30.000Z',
                    '$updatedAt' => '2020-06-24T06:47:30.000Z',
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::write(Role::user('608f9da25e7e1')),
                    ],
                    'documentSecurity' => true,
                    'databaseId' => '5e5ea5c16897e',
                    'name' => 'My Collection',
                    'enabled' => false,
                    'attributes' => [
                        'key' => 'isEnabled',
                        'type' => 'boolean',
                        'status' => 'available',
                        'required' => true,
                        'array' => false,
                        'default' => false
                    ],
                    'indexes' => [
                        'key' => 'index1',
                        'type' => 'primary',
                        'status' => 'available',
                        'attributes' => [],
                        'orders' => []
                    ],
                ],
                [
                    '$id' => '5e5ea5c16897e',
                    '$createdAt' => 1592981250,
                    '$updatedAt' => 1592981250,
                    '$read' => [
                        'role:all'
                    ],
                    '$write' => [
                        'user:608f9da25e7e1'
                    ],
                    'databaseId' => '5e5ea5c16897e',
                    'name' => 'My Collection',
                    'enabled' => false,
                    'permission' => 'document',
                    'attributes' => [
                        'key' => 'isEnabled',
                        'type' => 'boolean',
                        'status' => 'available',
                        'required' => true,
                        'array' => false,
                        'default' => false
                    ],
                    'indexes' => [
                        'key' => 'index1',
                        'type' => 'primary',
                        'status' => 'available',
                        'attributes' => [],
                        'orders' => []
                    ],
                ],
            ],
            'false documentSecurity' => [
                ['documentSecurity' => false],
                ['permission' => 'collection'],
            ],

        ];
    }

    /**
     * @dataProvider createdAtUpdatedAtProvider
     * @dataProvider permissionsProvider
     * @dataProvider collectionProvider
     */
    public function testCollection(array $content, array $expected): void
    {
        $model = Response::MODEL_COLLECTION;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider createdAtUpdatedAtProvider
     */
    public function testDatabase(array $content, array $expected): void
    {
        $model = Response::MODEL_DATABASE;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider createdAtUpdatedAtProvider
     */
    public function testDeployment(array $content, array $expected): void
    {
        $model = Response::MODEL_DEPLOYMENT;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider createdAtUpdatedAtProvider
     * @dataProvider permissionsProvider
     */
    public function testDocument(array $content, array $expected): void
    {
        $model = Response::MODEL_DOCUMENT;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider createdAtUpdatedAtProvider
     */
    public function testDomain(array $content, array $expected): void
    {
        $model = Response::MODEL_DOMAIN;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function executionProvider(): array
    {
        return [
            'basic execution' => [
                ['stdout' => ''],
                [],
            ],
        ];
    }

    /**
     * @dataProvider createdAtUpdatedAtProvider
     * @dataProvider permissionsProvider
     * @dataProvider executionProvider
     */
    public function testExecution(array $content, array $expected): void
    {
        $model = Response::MODEL_EXECUTION;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider createdAtUpdatedAtProvider
     * @dataProvider permissionsProvider
     */
    public function testFile(array $content, array $expected): void
    {
        $model = Response::MODEL_FILE;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function functionProvider(): array
    {
        return [
            'basic function' => [
                [
                    '$id' => '5e5ea5c16897e',
                    '$createdAt' => '2020-06-24T06:47:30.000Z',
                    '$updatedAt' => '2020-06-24T06:47:30.000Z',
                    'execute' => [
                        Role::users()->toString(),
                    ],
                    'name' => 'My Function',
                    'status' => 'enabled',
                    'runtime' => 'python-3.8',
                    'deployment' => '5e5ea5c16897e',
                    'vars' => [
                        [
                            '$id' => '631bd31717e034f14aa8',
                            '$createdAt' => '2020-06-24T06:47:30.000Z',
                            '$updatedAt' => '2020-06-24T06:47:30.000Z',
                            'key' => 'key',
                            'value' => 'value',
                            'functionId' => '5e5ea5c16897e',
                        ]
                    ],
                    'events' => [
                        'account.create'
                    ],
                    'schedule' => '5 4 * * *',
                    'scheduleNext' => '2020-06-24T06:48:12.000Z',
                    'schedulePrevious' => '2020-06-24T06:47:17.000Z',
                    'timeout' => 1592981237
                ],
                [
                    '$id' => '5e5ea5c16897e',
                    '$createdAt' => 1592981250,
                    '$updatedAt' => 1592981250,
                    'execute' => [
                        'role:member'
                    ],
                    'name' => 'My Function',
                    'status' => 'enabled',
                    'runtime' => 'python-3.8',
                    'deployment' => '5e5ea5c16897e',
                    'vars' => [
                        'key' => 'value'
                    ],
                    'events' => [
                        'account.create'
                    ],
                    'schedule' => '5 4 * * *',
                    'scheduleNext' => 1592981292,
                    'schedulePrevious' => 1592981237,
                    'timeout' => 1592981237
                ],
            ],
        ];
    }

    /**
     * @dataProvider createdAtUpdatedAtProvider
     * @dataProvider functionProvider
     */
    public function testFunc(array $content, array $expected): void
    {
        $model = Response::MODEL_FUNCTION;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function keyProvider(): array
    {
        return [
            'basic key' => [
                ['expire' => '2020-06-24T06:47:30.000Z'],
                ['expire' => 1592981250],
            ],
        ];
    }

    /**
     * @dataProvider createdAtUpdatedAtProvider
     * @dataProvider keyProvider
     */
    public function testKey(array $content, array $expected): void
    {
        $model = Response::MODEL_KEY;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function logProvider(): array
    {
        return [
            'basic log' => [
                ['time' => '2020-06-24T06:47:30.000Z'],
                ['time' => 1592981250],
            ],
        ];
    }

    /**
     * @dataProvider createdAtUpdatedAtProvider
     * @dataProvider logProvider
     */
    public function testLog(array $content, array $expected): void
    {
        $model = Response::MODEL_LOG;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function membershipProvider(): array
    {
        return [
            'basic membership' => [
                [
                    'invited' => '2020-06-24T06:47:30.000Z',
                    'joined' => '2020-06-24T06:47:30.000Z',
                ],
                [
                    'invited' => 1592981250,
                    'joined' => 1592981250,
                ],
            ],
        ];
    }

    /**
     * @dataProvider createdAtUpdatedAtProvider
     * @dataProvider membershipProvider
     */
    public function testMembership(array $content, array $expected): void
    {
        $model = Response::MODEL_MEMBERSHIP;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function metricProvider(): array
    {
        return [
            'basic metric' => [
                [
                    'date' => '2020-06-24T06:47:30.000Z',
                ],
                [
                    'date' => 1592981250,
                ],
            ],
        ];
    }

    /**
     * @dataProvider metricProvider
     */
    public function testMetric(array $content, array $expected): void
    {
        $model = Response::MODEL_METRIC;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider createdAtUpdatedAtProvider
     */
    public function testPlatform(array $content, array $expected): void
    {
        $model = Response::MODEL_PLATFORM;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider createdAtUpdatedAtProvider
     */
    public function testProject(array $content, array $expected): void
    {
        $model = Response::MODEL_PROJECT;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function sessionProvider(): array
    {
        return [
            'basic session' => [
                [
                    '$createdAt' => '2020-06-24T06:47:30.000Z',
                    'expire' => '2020-06-24T06:47:30.000Z',
                    'providerAccessTokenExpiry' => '2020-06-24T06:47:30.000Z',
                ],
                [
                    '$createdAt' => 1592981250,
                    'expire' => 1592981250,
                    'providerAccessTokenExpiry' => 1592981250,
                ],
            ],
        ];
    }

    /**
     * @dataProvider sessionProvider
     */
    public function testSession(array $content, array $expected): void
    {
        $model = Response::MODEL_SESSION;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider createdAtUpdatedAtProvider
     */
    public function testTeam(array $content, array $expected): void
    {
        $model = Response::MODEL_TEAM;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function tokenProvider(): array
    {
        return [
            'basic token' => [
                [
                    '$createdAt' => '2020-06-24T06:47:30.000Z',
                    'expire' => '2020-06-24T06:47:30.000Z',
                ],
                [
                    '$createdAt' => 1592981250,
                    'expire' => 1592981250,
                ],
            ],
        ];
    }

    /**
     * @dataProvider tokenProvider
     */
    public function testToken(array $content, array $expected): void
    {
        $model = Response::MODEL_TOKEN;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function usageFunctionsProvider(): array
    {
        return [
            'basic usage functions' => [
                [
                    'executionsTotal' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'executionsFailure' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'executionsSuccess' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'executionsTime' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'buildsTotal' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'buildsFailure' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'buildsSuccess' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'buildsTime' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                ],
                [
                    'functionsExecutions' => [
                        ['date' => 1592981250],
                    ],
                    'functionsFailures' => [
                        ['date' => 1592981250],
                    ],
                    'functionsCompute' => [
                        ['date' => 1592981250],
                    ],
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
        return [
            'basic usage project' => [
                [
                    'collections' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'documents' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'executions' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'network' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'requests' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'storage' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'users' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                ],
                [
                    'collections' => [
                        ['date' => 1592981250],
                    ],
                    'documents' => [
                        ['date' => 1592981250],
                    ],
                    'functions' => [
                        ['date' => 1592981250],
                    ],
                    'network' => [
                        ['date' => 1592981250],
                    ],
                    'requests' => [
                        ['date' => 1592981250],
                    ],
                    'storage' => [
                        ['date' => 1592981250],
                    ],
                    'users' => [
                        ['date' => 1592981250],
                    ],
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
        return [
            'basic usage storage' => [
                [
                    'bucketsCount' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'bucketsCreate' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'bucketsDelete' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'bucketsRead' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'bucketsUpdate' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'filesCount' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'filesCreate' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'filesDelete' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'filesRead' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'storage' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'filesUpdate' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                ],
                [
                    'bucketsCount' => [
                        ['date' => 1592981250],
                    ],
                    'bucketsCreate' => [
                        ['date' => 1592981250],
                    ],
                    'bucketsDelete' => [
                        ['date' => 1592981250],
                    ],
                    'bucketsRead' => [
                        ['date' => 1592981250],
                    ],
                    'bucketsUpdate' => [
                        ['date' => 1592981250],
                    ],
                    'filesCount' => [
                        ['date' => 1592981250],
                    ],
                    'filesCreate' => [
                        ['date' => 1592981250],
                    ],
                    'filesDelete' => [
                        ['date' => 1592981250],
                    ],
                    'filesRead' => [
                        ['date' => 1592981250],
                    ],
                    'filesStorage' => [
                        ['date' => 1592981250],
                    ],
                    'filesUpdate' => [
                        ['date' => 1592981250],
                    ],
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
}
