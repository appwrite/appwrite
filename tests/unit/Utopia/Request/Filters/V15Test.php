<?php

namespace Tests\Unit\Utopia\Database\Request\Filters;

use Appwrite\Utopia\Request\Filter;
use Appwrite\Utopia\Request\Filters\V15;
use Appwrite\Utopia\Response\Model;
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

    public function limitOffsetProvider(): array
    {
        return [
            'basic test' => [
                ['limit' => '12', 'offset' => '0'],
                ['queries' => ['limit(12)', 'offset(0)']]
            ],
        ];
    }

    /**
     * @dataProvider limitOffsetProvider
     */
    public function testGetAccountLogs(array $content, array $expected): void
    {
        $model = 'account.logs';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function testGetAccountInitials(): void
    {
        $model = 'account.initials';

        $content = ['color' => 'deadbeef'];
        $expected = [];
        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function limitOffsetCursorOrderTypeProvider(): array
    {
        return [
            'basic test' => [
                [
                    'limit' => '12',
                    'offset' => '0',
                    'cursor' => 'abcd',
                    'cursorDirection' => 'before',
                    'orderType' => 'asc',
                ],
                [
                    'queries' => [
                        'limit(12)',
                        'offset(0)',
                        'cursorBefore("abcd")',
                        'orderAsc("")'
                    ]
                ],
            ],
        ];
    }

    public function cursorProvider(): array
    {
        return [
            'cursorDirection after' => [
                [
                    'cursor' => 'abcd',
                    'cursorDirection' => 'after',
                ],
                [
                    'queries' => [
                        'cursorAfter("abcd")',
                    ]
                ],
            ],
            'cursorDirection invalid' => [
                [
                    'cursor' => 'abcd',
                    'cursorDirection' => 'invalid',
                ],
                [
                    'queries' => [
                        'cursorAfter("abcd")',
                    ]
                ],
            ],
        ];
    }

    public function orderTypeProvider(): array
    {
        return [
            'orderType desc' => [
                [
                    'orderType' => 'DESC',
                ],
                [
                    'queries' => [
                        'orderDesc("")',
                    ]
                ],
            ],
            'orderType invalid' => [
                [
                    'orderType' => 'invalid',
                ],
                [
                    'queries' => [
                        'orderAsc("")',
                    ]
                ],
            ],
        ];
    }

    /**
     * @dataProvider limitOffsetCursorOrderTypeProvider
     * @dataProvider limitOffsetProvider
     * @dataProvider cursorProvider
     * @dataProvider orderTypeProvider
     */
    public function testListDatabases(array $content, array $expected): void
    {
        $model = 'databases.list';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider limitOffsetProvider
     */
    public function testListDatabaseLogs(array $content, array $expected): void
    {
        $model = 'databases.listLogs';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function collectionPermissionProvider(): array
    {
        return [
            'permission collection' => [
                ['permission' => 'collection'],
                ['documentSecurity' => false],
            ],
            'permission document' => [
                ['permission' => 'document'],
                ['documentSecurity' => true],
            ],
            'permission empty' => [
                [],
                [],
            ],
            'permission invalid' => [
                ['permission' => 'invalid'],
                ['documentSecurity' => false],
            ],
        ];
    }

    public function readWriteProvider(): array
    {
        return [
            'read all types' => [
                [
                    'read' => [
                        'role:all',
                        'role:guest',
                        'role:member',
                        'user:a',
                        'team:b',
                        'team:c/member',
                        'member:z',
                    ],
                ],
                [
                    'permissions' => [
                        'read("any")',
                        'read("guests")',
                        'read("users")',
                        'read("user:a")',
                        'read("team:b")',
                        'read("team:c/member")',
                        'read("member:z")',
                    ],
                ],
            ],
            'read invalid' => [
                ['read' => ['invalid', 'invalid:a']],
                ['permissions' => ['read("invalid:a")']],
            ],
            'write all types' => [
                [
                    'write' => [
                        'role:all',
                        'role:guest',
                        'role:member',
                        'user:a',
                        'team:b',
                        'team:c/member',
                        'member:z',
                    ],
                ],
                [
                    'permissions' => [
                        'write("users")',
                        'write("users")',
                        'write("user:a")',
                        'write("team:b")',
                        'write("team:c/member")',
                        'write("member:z")',
                    ],
                ],
            ],
            'write invalid' => [
                ['write' => ['invalid', 'invalid:a']],
                ['permissions' => ['write("invalid:a")']],
            ]
        ];
    }

    /**
     * @dataProvider collectionPermissionProvider
     * @dataProvider readWriteProvider
     */
    public function testCreateCollection(array $content, array $expected): void
    {
        $model = 'databases.createCollection';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider limitOffsetCursorOrderTypeProvider
     * @dataProvider limitOffsetProvider
     * @dataProvider cursorProvider
     * @dataProvider orderTypeProvider
     */
    public function testListCollections(array $content, array $expected): void
    {
        $model = 'databases.listCollections';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider limitOffsetProvider
     */
    public function testListCollectionLogs(array $content, array $expected): void
    {
        $model = 'databases.listCollectionLogs';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider collectionPermissionProvider
     * @dataProvider readWriteProvider
     */
    public function testUpdateCollection(array $content, array $expected): void
    {
        $model = 'databases.updateCollection';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider readWriteProvider
     */
    public function testCreateDocument(array $content, array $expected): void
    {
        $model = 'databases.createDocument';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function ordersProvider(): array
    {
        return [
            'basic test' => [
                [
                    'orderAttributes' => ['lastName', 'firstName'],
                    'orderTypes' => ['DESC', 'ASC'],
                ],
                [
                    'queries' => [
                        'orderDesc("lastName")',
                        'orderAsc("firstName")',
                    ]
                ],
            ],
            'orderType only' => [
                [
                    'orderTypes' => ['DESC'],
                ],
                [
                    'queries' => [
                        'orderDesc("")',
                    ]
                ],
            ],
            'orderType invalid' => [
                [
                    'orderAttributes' => ['lastName'],
                    'orderTypes' => ['invalid'],
                ],
                [
                    'queries' => [
                        'orderAsc("lastName")',
                    ]
                ],
            ],
        ];
    }

    public function filtersProvider(): array
    {
        return [
            'all filters' => [
                [
                    'queries' => [
                        'lastName.equal("Smith", "Jackson")',
                        'firstName.notEqual("John")',
                        'age.lesser(50)',
                        'age.lesserEqual(51)',
                        'age.greater(20)',
                        'age.greaterEqual(21)',
                        'address.search("pla")',
                    ],
                ],
                [
                    'queries' => [
                        'equal("lastName", ["Smith", "Jackson"])',
                        'notEqual("firstName", ["John"])',
                        'lessThan("age", [50])',
                        'lessThanEqual("age", [51])',
                        'greaterThan("age", [20])',
                        'greaterThanEqual("age", [21])',
                        'search("address", ["pla"])',
                    ]
                ],
            ],
        ];
    }

    /**
     * @dataProvider limitOffsetProvider
     * @dataProvider cursorProvider
     * @dataProvider ordersProvider
     * @dataProvider filtersProvider
     */
    public function testListDocuments(array $content, array $expected): void
    {
        $model = 'databases.listDocuments';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider limitOffsetProvider
     */
    public function testListDocumentLogs(array $content, array $expected): void
    {
        $model = 'databases.listDocumentLogs';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider readWriteProvider
     */
    public function testUpdateDocument(array $content, array $expected): void
    {
        $model = 'databases.updateDocument';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function executeProvider(): array
    {
        return [
            'all roles' => [
                [
                    'execute' => [
                        'role:all',
                        'role:guest',
                        'role:member',
                        'user:a',
                        'team:b',
                        'team:c/member',
                        'member:z',
                    ],
                ],
                [
                    'execute' => [
                        'users',
                        'users',
                        'user:a',
                        'team:b',
                        'team:c/member',
                        'member:z',
                    ]
                ],
            ],
        ];
    }

    /**
     * @dataProvider executeProvider
     */
    public function testCreateFunction(array $content, array $expected): void
    {
        $model = 'functions.create';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider limitOffsetCursorOrderTypeProvider
     * @dataProvider limitOffsetProvider
     * @dataProvider cursorProvider
     * @dataProvider orderTypeProvider
     */
    public function testListFunctions(array $content, array $expected): void
    {
        $model = 'functions.list';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider executeProvider
     */
    public function testUpdateFunction(array $content, array $expected): void
    {
        $model = 'functions.update';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider limitOffsetCursorOrderTypeProvider
     * @dataProvider limitOffsetProvider
     * @dataProvider cursorProvider
     * @dataProvider orderTypeProvider
     */
    public function testListDeployments(array $content, array $expected): void
    {
        $model = 'functions.listDeployments';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider limitOffsetProvider
     * @dataProvider cursorProvider
     */
    public function testListExecutions(array $content, array $expected): void
    {
        $model = 'functions.listExecutions';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider limitOffsetCursorOrderTypeProvider
     * @dataProvider limitOffsetProvider
     * @dataProvider cursorProvider
     * @dataProvider orderTypeProvider
     */
    public function testListProjects(array $content, array $expected): void
    {
        $model = 'projects.list';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function expireProvider(): array
    {
        return [
            'empty' => [
                [],
                [],
            ],
            'zero' => [
                ['expire' => '0'],
                ['expire' => null],
            ],
            'value' => [
                ['expire' => '1602743880'],
                ['expire' => Model::TYPE_DATETIME_EXAMPLE],
            ],
        ];
    }

    /**
     * @dataProvider expireProvider
     */
    public function testCreateKey(array $content, array $expected)
    {
        $model = 'projects.createKey';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider expireProvider
     */
    public function testUpdateKey(array $content, array $expected)
    {
        $model = 'projects.updateKey';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function bucketPermissionProvider(): array
    {
        return [
            'permission bucket' => [
                ['permission' => 'bucket'],
                ['fileSecurity' => false],
            ],
            'permission document' => [
                ['permission' => 'file'],
                ['fileSecurity' => true],
            ],
            'permission empty' => [
                [],
                [],
            ],
            'permission invalid' => [
                ['permission' => 'invalid'],
                ['fileSecurity' => false],
            ],
        ];
    }

    /**
     * @dataProvider bucketPermissionProvider
     * @dataProvider readWriteProvider
     */
    public function testCreateBucket(array $content, array $expected)
    {
        $model = 'storage.createBucket';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider limitOffsetCursorOrderTypeProvider
     * @dataProvider limitOffsetProvider
     * @dataProvider cursorProvider
     * @dataProvider orderTypeProvider
     */
    public function testListBuckets(array $content, array $expected): void
    {
        $model = 'storage.listBuckets';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider bucketPermissionProvider
     * @dataProvider readWriteProvider
     */
    public function testUpdateBucket(array $content, array $expected)
    {
        $model = 'storage.updateBucket';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider readWriteProvider
     */
    public function testCreateFile(array $content, array $expected)
    {
        $model = 'storage.createFile';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider limitOffsetCursorOrderTypeProvider
     * @dataProvider limitOffsetProvider
     * @dataProvider cursorProvider
     * @dataProvider orderTypeProvider
     */
    public function testListFiles(array $content, array $expected): void
    {
        $model = 'storage.listFiles';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider readWriteProvider
     */
    public function testUpdateFile(array $content, array $expected)
    {
        $model = 'storage.updateFile';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider limitOffsetCursorOrderTypeProvider
     * @dataProvider limitOffsetProvider
     * @dataProvider cursorProvider
     * @dataProvider orderTypeProvider
     */
    public function testListTeams(array $content, array $expected): void
    {
        $model = 'teams.list';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider limitOffsetCursorOrderTypeProvider
     * @dataProvider limitOffsetProvider
     * @dataProvider cursorProvider
     * @dataProvider orderTypeProvider
     */
    public function testGetTeamMemberships(array $content, array $expected): void
    {
        $model = 'teams.getMemberships';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider limitOffsetProvider
     */
    public function testListTeamLogs(array $content, array $expected): void
    {
        $model = 'teams.listLogs';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider limitOffsetCursorOrderTypeProvider
     * @dataProvider limitOffsetProvider
     * @dataProvider cursorProvider
     * @dataProvider orderTypeProvider
     */
    public function testListUsers(array $content, array $expected): void
    {
        $model = 'users.list';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider limitOffsetProvider
     */
    public function testGetUserLogs(array $content, array $expected): void
    {
        $model = 'users.getLogs';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }
}
