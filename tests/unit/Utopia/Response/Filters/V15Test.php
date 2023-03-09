<?php

namespace Tests\Unit\Utopia\Response\Filters;

use Appwrite\Utopia\Response\Filters\V15;
use Appwrite\Utopia\Response;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use PHPUnit\Framework\TestCase;
use stdClass;

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

    /**
     * @dataProvider createdAtUpdatedAtProvider
     * @dataProvider permissionsProvider
     * @dataProvider bucketProvider
     */
    public function testBucketList(array $content, array $expected): void
    {
        $model = Response::MODEL_BUCKET_LIST;

        $content = [
            'buckets' => [$content],
            'total' => 1,
        ];

        $expected = [
            'buckets' => [$expected],
            'total' => 1,
        ];

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function buildProvider(): array
    {
        return [
            'build start and end time' => [
                [
                    'startTime' => '2020-06-24T06:47:30.000Z',
                    'endTime' => '2020-06-24T06:47:30.000Z',
                ],
                [
                    'startTime' => 1592981250,
                    'endTime' => 1592981250,
                ]
            ]
        ];
    }

    /**
     * @dataProvider buildProvider
     */
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

    /**
     * @dataProvider buildProvider
     */
    public function testBuildList(array $content, array $expected): void
    {
        $model = Response::MODEL_BUILD_LIST;

        $content = [
            'builds' => [$content],
            'total' => 1,
        ];

        $expected = [
            'builds' => [$expected],
            'total' => 1,
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
     * @dataProvider permissionsProvider
     * @dataProvider collectionProvider
     */
    public function testCollectionList(array $content, array $expected): void
    {
        $model = Response::MODEL_COLLECTION_LIST;

        $content = [
            'collections' => [$content],
            'total' => 1,
        ];

        $expected = [
            'collections' => [$expected],
            'total' => 1,
        ];

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
    public function testDatabaseList(array $content, array $expected): void
    {
        $model = Response::MODEL_DATABASE_LIST;

        $content = [
            'databases' => [$content],
            'total' => 1,
        ];

        $expected = [
            'databases' => [$expected],
            'total' => 1,
        ];

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
     */
    public function testDeploymentList(array $content, array $expected): void
    {
        $model = Response::MODEL_DEPLOYMENT_LIST;

        $content = [
            'deployments' => [$content],
            'total' => 1,
        ];

        $expected = [
            'deployments' => [$expected],
            'total' => 1,
        ];

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function documentProvider(): array
    {
        return [
            'basic document' => [
                [
                    '$id' => '5e5ea5c16897e',
                    '$collectionId' => '5e5ea5c15117e',
                    '$databaseId' => '5e5ea5c15117e',
                    '$createdAt' => '2020-06-24T06:47:30.000Z',
                    '$updatedAt' => '2020-06-24T06:47:30.000Z',
                    '$permissions' => [Permission::read(Role::any())]
                ],
                [
                    '$id' => '5e5ea5c16897e',
                    '$collection' => '5e5ea5c15117e',
                    '$createdAt' => 1592981250,
                    '$updatedAt' => 1592981250,
                    '$read' => ['role:all'],
                    '$write' => [],
                ],
            ],
        ];
    }

    /**
     * @dataProvider createdAtUpdatedAtProvider
     * @dataProvider permissionsProvider
     * @dataProvider documentProvider
     */
    public function testDocument(array $content, array $expected): void
    {
        $model = Response::MODEL_DOCUMENT;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider createdAtUpdatedAtProvider
     * @dataProvider permissionsProvider
     */
    public function testDocumentList(array $content, array $expected): void
    {
        $model = Response::MODEL_DOCUMENT_LIST;

        $content = [
            'documents' => [$content],
            'total' => 1,
        ];

        $expected = [
            'documents' => [$expected],
            'total' => 1,
        ];

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

    /**
     * @dataProvider createdAtUpdatedAtProvider
     */
    public function testDomainList(array $content, array $expected): void
    {
        $model = Response::MODEL_DOMAIN_LIST;

        $content = [
            'domains' => [$content],
            'total' => 1,
        ];

        $expected = [
            'domains' => [$expected],
            'total' => 1,
        ];

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function executionProvider(): array
    {
        return [
            'basic execution' => [
                [
                    '$id' => '5e5ea5c16897e',
                    '$createdAt' => '2020-06-24T06:47:30.000Z',
                    '$updatedAt' => '2020-06-24T06:47:30.000Z',
                    '$permissions' => [
                        "any"
                    ],
                    'functionId' => '5e5ea6g16897e',
                    'trigger' => 'http',
                    'status' => 'processing',
                    'statusCode' => 0,
                    'response' => '',
                    'stdout' => '',
                    'stderr' => '',
                    'duration' => 0.4
                ],
                [
                    '$id' => '5e5ea5c16897e',
                    '$createdAt' => 1592981250,
                    '$updatedAt' => 1592981250,
                    '$read' => [
                        "role:all"
                    ],
                    'functionId' => '5e5ea6g16897e',
                    'trigger' => 'http',
                    'status' => 'processing',
                    'statusCode' => 0,
                    'response' => '',
                    'stderr' => '',
                    'time' => 0.4
                ],
            ],
        ];
    }

    /**
     * @dataProvider createdAtUpdatedAtProvider
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
     * @dataProvider executionProvider
     */
    public function testExecutionList(array $content, array $expected): void
    {
        $model = Response::MODEL_EXECUTION_LIST;

        $content = [
            'executions' => [$content],
            'total' => 1,
        ];

        $expected = [
            'executions' => [$expected],
            'total' => 1,
        ];

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

    /**
     * @dataProvider createdAtUpdatedAtProvider
     * @dataProvider permissionsProvider
     */
    public function testFileList(array $content, array $expected): void
    {
        $model = Response::MODEL_FILE_LIST;

        $content = [
            'files' => [$content],
            'total' => 1,
        ];

        $expected = [
            'files' => [$expected],
            'total' => 1,
        ];

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
                    'enabled' => true,
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
            'enabled false' => [
                ['enabled' => false],
                ['status' => 'disabled'],
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

    /**
     * @dataProvider createdAtUpdatedAtProvider
     * @dataProvider functionProvider
     */
    public function testFuncList(array $content, array $expected): void
    {
        $model = Response::MODEL_FUNCTION_LIST;

        $content = [
            'functions' => [$content],
            'total' => 1,
        ];

        $expected = [
            'functions' => [$expected],
            'total' => 1,
        ];

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

    /**
     * @dataProvider createdAtUpdatedAtProvider
     * @dataProvider keyProvider
     */
    public function testKeyList(array $content, array $expected): void
    {
        $model = Response::MODEL_KEY_LIST;

        $content = [
            'keys' => [$content],
            'total' => 1,
        ];

        $expected = [
            'keys' => [$expected],
            'total' => 1,
        ];

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function logProvider(): array
    {
        return [
            'basic log' => [
                [
                    'event' => 'account.sessions.create',
                    'userId' => '610fc2f985ee0',
                    'userEmail' => 'john@appwrite.io',
                    'userName' => 'John Doe',
                    'mode' => 'admin',
                    'ip' => '127.0.0.1',
                    'time' => '2020-06-24T06:47:30.000Z',
                    'osCode' => 'Mac',
                    'osName' => 'Mac',
                    'osVersion' => 'Mac',
                    'clientType' => 'browser',
                    'clientCode' => 'CM',
                    'clientName' => 'Chrome Mobile iOS',
                    'clientVersion' => '84.0',
                    'clientEngine' => 'WebKit',
                    'clientEngineVersion' => '605.1.15',
                    'deviceName' => 'smartphone',
                    'deviceBrand' => 'Google',
                    'deviceModel' => 'Nexus 5',
                    'countryCode' => 'US',
                    'countryName' => 'United States'
                ],
                [
                    'event' => 'account.sessions.create',
                    'userId' => '610fc2f985ee0',
                    'userEmail' => 'john@appwrite.io',
                    'userName' => 'John Doe',
                    'mode' => 'admin',
                    'ip' => '127.0.0.1',
                    'time' => 1592981250,
                    'osCode' => 'Mac',
                    'osName' => 'Mac',
                    'osVersion' => 'Mac',
                    'clientType' => 'browser',
                    'clientCode' => 'CM',
                    'clientName' => 'Chrome Mobile iOS',
                    'clientVersion' => '84.0',
                    'clientEngine' => 'WebKit',
                    'clientEngineVersion' => '605.1.15',
                    'deviceName' => 'smartphone',
                    'deviceBrand' => 'Google',
                    'deviceModel' => 'Nexus 5',
                    'countryCode' => 'US',
                    'countryName' => 'United States'
                ]
            ],
        ];
    }

    /**
     * @dataProvider logProvider
     */
    public function testLog(array $content, array $expected): void
    {
        $model = Response::MODEL_LOG;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider logProvider
     */
    public function testLogList(array $content, array $expected): void
    {
        $model = Response::MODEL_LOG_LIST;

        $content = [
            'logs' => [$content],
            'total' => 1,
        ];

        $expected = [
            'logs' => [$expected],
            'total' => 1,
        ];

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

    /**
     * @dataProvider createdAtUpdatedAtProvider
     * @dataProvider membershipProvider
     */
    public function testMembershipList(array $content, array $expected): void
    {
        $model = Response::MODEL_MEMBERSHIP_LIST;

        $content = [
            'memberships' => [$content],
            'total' => 1,
        ];

        $expected = [
            'memberships' => [$expected],
            'total' => 1,
        ];

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
    public function testPlatformList(array $content, array $expected): void
    {
        $model = Response::MODEL_PLATFORM_LIST;

        $content = [
            'platforms' => [$content],
            'total' => 1,
        ];

        $expected = [
            'platforms' => [$expected],
            'total' => 1,
        ];

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

    /**
     * @dataProvider createdAtUpdatedAtProvider
     */
    public function testProjectList(array $content, array $expected): void
    {
        $model = Response::MODEL_PROJECT_LIST;

        $content = [
            'projects' => [$content],
            'total' => 1,
        ];

        $expected = [
            'projects' => [$expected],
            'total' => 1,
        ];

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
            'empty values' => [
                [
                    'providerAccessTokenExpiry' => '',
                ],
                [
                    'providerAccessTokenExpiry' => 0,
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

        $this->assertSame($expected, $result);
    }

    /**
     * @dataProvider sessionProvider
     */
    public function testSessionList(array $content, array $expected): void
    {
        $model = Response::MODEL_SESSION_LIST;

        $content = [
            'sessions' => [$content],
            'total' => 1,
        ];

        $expected = [
            'sessions' => [$expected],
            'total' => 1,
        ];

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

    /**
     * @dataProvider createdAtUpdatedAtProvider
     */
    public function testTeamList(array $content, array $expected): void
    {
        $model = Response::MODEL_TEAM_LIST;

        $content = [
            'teams' => [$content],
            'total' => 1,
        ];

        $expected = [
            'teams' => [$expected],
            'total' => 1,
        ];

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

    public function usageDatabasesProvider(): array
    {
        return [
            'basic usage databases' => [
                [
                    'databasesCount' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'documentsCount' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'collectionsCount' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'databasesCreate' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'databasesRead' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'databasesUpdate' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'databasesDelete' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'documentsCreate' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'documentsRead' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'documentsUpdate' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'documentsDelete' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'collectionsCreate' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'collectionsRead' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'collectionsUpdate' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'collectionsDelete' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                ],
                [
                    'databasesCount' => [
                        ['date' => 1592981250],
                    ],
                    'documentsCount' => [
                        ['date' => 1592981250],
                    ],
                    'collectionsCount' => [
                        ['date' => 1592981250],
                    ],
                    'databasesCreate' => [
                        ['date' => 1592981250],
                    ],
                    'databasesRead' => [
                        ['date' => 1592981250],
                    ],
                    'databasesUpdate' => [
                        ['date' => 1592981250],
                    ],
                    'databasesDelete' => [
                        ['date' => 1592981250],
                    ],
                    'documentsCreate' => [
                        ['date' => 1592981250],
                    ],
                    'documentsRead' => [
                        ['date' => 1592981250],
                    ],
                    'documentsUpdate' => [
                        ['date' => 1592981250],
                    ],
                    'documentsDelete' => [
                        ['date' => 1592981250],
                    ],
                    'collectionsCreate' => [
                        ['date' => 1592981250],
                    ],
                    'collectionsRead' => [
                        ['date' => 1592981250],
                    ],
                    'collectionsUpdate' => [
                        ['date' => 1592981250],
                    ],
                    'collectionsDelete' => [
                        ['date' => 1592981250],
                    ],
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

    public function usageDatabaseProvider(): array
    {
        return [
            'basic usage database' => [
                [
                    'documentsCount' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'collectionsCount' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'documentsCreate' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'documentsRead' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'documentsUpdate' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'documentsDelete' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'collectionsCreate' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'collectionsRead' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'collectionsUpdate' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'collectionsDelete' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                ],
                [
                    'documentsCount' => [
                        ['date' => 1592981250],
                    ],
                    'collectionsCount' => [
                        ['date' => 1592981250],
                    ],
                    'documentsCreate' => [
                        ['date' => 1592981250],
                    ],
                    'documentsRead' => [
                        ['date' => 1592981250],
                    ],
                    'documentsUpdate' => [
                        ['date' => 1592981250],
                    ],
                    'documentsDelete' => [
                        ['date' => 1592981250],
                    ],
                    'collectionsCreate' => [
                        ['date' => 1592981250],
                    ],
                    'collectionsRead' => [
                        ['date' => 1592981250],
                    ],
                    'collectionsUpdate' => [
                        ['date' => 1592981250],
                    ],
                    'collectionsDelete' => [
                        ['date' => 1592981250],
                    ],
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

    public function usageCollectionProvider(): array
    {
        return [
            'basic usage collection' => [
                [
                    'documentsCount' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'documentsCreate' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'documentsRead' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'documentsUpdate' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'documentsDelete' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                ],
                [
                    'documentsCount' => [
                        ['date' => 1592981250],
                    ],
                    'documentsCreate' => [
                        ['date' => 1592981250],
                    ],
                    'documentsRead' => [
                        ['date' => 1592981250],
                    ],
                    'documentsUpdate' => [
                        ['date' => 1592981250],
                    ],
                    'documentsDelete' => [
                        ['date' => 1592981250],
                    ],
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

    public function usageUsersProvider(): array
    {
        return [
            'basic usage users' => [
                [
                    'usersCount' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'usersCreate' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'usersRead' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'usersUpdate' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'usersDelete' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'sessionsCreate' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'sessionsProviderCreate' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'sessionsDelete' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                ],
                [
                    'usersCount' => [
                        ['date' => 1592981250],
                    ],
                    'usersCreate' => [
                        ['date' => 1592981250],
                    ],
                    'usersRead' => [
                        ['date' => 1592981250],
                    ],
                    'usersUpdate' => [
                        ['date' => 1592981250],
                    ],
                    'usersDelete' => [
                        ['date' => 1592981250],
                    ],
                    'sessionsCreate' => [
                        ['date' => 1592981250],
                    ],
                    'sessionsProviderCreate' => [
                        ['date' => 1592981250],
                    ],
                    'sessionsDelete' => [
                        ['date' => 1592981250],
                    ],
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

    public function usageBucketsProvider(): array
    {
        return [
            'basic usage buckets' => [
                [
                    'filesCount' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'filesStorage' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'filesCreate' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'filesRead' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'filesUpdate' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                    'filesDelete' => [
                        ['date' => '2020-06-24T06:47:30.000Z'],
                    ],
                ],
                [
                    'filesCount' => [
                        ['date' => 1592981250],
                    ],
                    'filesStorage' => [
                        ['date' => 1592981250],
                    ],
                    'filesCreate' => [
                        ['date' => 1592981250],
                    ],
                    'filesRead' => [
                        ['date' => 1592981250],
                    ],
                    'filesUpdate' => [
                        ['date' => 1592981250],
                    ],
                    'filesDelete' => [
                        ['date' => 1592981250],
                    ],
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

    public function userProvider(): array
    {
        return [
            'basic user' => [
                [
                    '$id' => '5e5ea5c16897e',
                    '$createdAt' => '2020-06-24T06:47:30.000Z',
                    '$updatedAt' => '2020-06-24T06:47:30.000Z',
                    'name' => 'John Doe',
                    'password' => '$argon2id$v=19$m=2048,t=4,p=3$aUZjLnliVWRINmFNTWMudg$5S+x+7uA31xFnrHFT47yFwcJeaP0w92L/4LdgrVRXxE',
                    'hash' => 'argon2',
                    'hashOptions' => [
                        'memoryCost' => 65536,
                        'timeCost' => 4,
                        'threads' => 3,
                    ],
                    'registration' => '2020-06-24T06:47:30.000Z',
                    'status' => true,
                    'passwordUpdate' => '2020-06-24T06:47:30.000Z',
                    'email' => 'john@appwrite.io',
                    'phone' => '+4930901820',
                    'emailVerification' => true,
                    'phoneVerification' => true,
                    'prefs' => new \stdClass(),
                ],
                [
                    '$id' => '5e5ea5c16897e',
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
                ],
            ],
        ];
    }

    /**
     * @dataProvider createdAtUpdatedAtProvider
     * @dataProvider userProvider
     */
    public function testUser(array $content, array $expected): void
    {
        $model = Response::MODEL_USER;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider createdAtUpdatedAtProvider
     * @dataProvider userProvider
     */
    public function testUserList(array $content, array $expected): void
    {
        $model = Response::MODEL_USER_LIST;

        $content = [
            'users' => [$content],
            'total' => 1,
        ];

        $expected = [
            'users' => [$expected],
            'total' => 1,
        ];

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider createdAtUpdatedAtProvider
     */
    public function testWebhook(array $content, array $expected): void
    {
        $model = Response::MODEL_WEBHOOK;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider createdAtUpdatedAtProvider
     */
    public function testWebhookList(array $content, array $expected): void
    {
        $model = Response::MODEL_WEBHOOK_LIST;

        $content = [
            'webhooks' => [$content],
            'total' => 1,
        ];

        $expected = [
            'webhooks' => [$expected],
            'total' => 1,
        ];

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }
}
