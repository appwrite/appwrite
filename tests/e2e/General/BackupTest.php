<?php

namespace Tests\E2E\General;

use Appwrite\ID;
use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Services\Functions\FunctionsBase;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

class BackupTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use FunctionsBase;

    private const WAIT = 35;
    private const CREATE = 20;

    protected string $projectId;

    public function setUp(): void
    {
        parent::setUp();
    }

    protected static string $formatTz = 'Y-m-d\TH:i:s.vP';

    protected function getConsoleHeaders(): array
    {
        return (
            array_merge($this->getConsoleHeadersGet(), ['content-type' => 'application/json'])
        );
    }

    protected function getConsoleHeadersGet(): array
    {
        return [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-mode' => 'admin'
        ];
    }

    public function testShmuel()
    {
        $arr['databases']['db1'][] = 1;
        $arr['databases']['db1'][] = 3;
        $arr['databases']['db1'][] = 4;
        $arr['databases']['db2'][] = 5;
        $arr['databases']['db2'][] = 6;
        $arr['buckets']['b1'][] = 2;
        $arr['buckets']['b1'][] = 2;
        $files = [];
        foreach ($arr as $group => $v1) {
            foreach ($v1 as $k2 => $v2) {
                $files[][] = $group . '::' . $k2;
                var_dump($v2);
            }
        }
    }

    public function testCreateDatabase(){
        // Create database
        $database = $this->client->call(
            Client::METHOD_POST,
            '/databases',
            $this->getConsoleHeaders(),
            [
                'databaseId' => ID::custom('first'),
                'name' => 'Backup list database'
            ]
        );

        $this->assertNotEmpty($database['body']['$id']);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('Backup list database', $database['body']['name']);

        $databaseId = $database['body']['$id'];

        // Create Collection
        $presidents = $this->client->call(
            Client::METHOD_POST, '/databases/' . $databaseId . '/collections',
            $this->getConsoleHeaders(),
            [
                'collectionId' => ID::unique(),
                'name' => 'people',
                'documentSecurity' => true,
                'permissions' => [
                    Permission::create(Role::user($this->getUser()['$id']))
                ],
        ]);

        $this->assertEquals(201, $presidents['headers']['status-code']);
        $this->assertEquals($presidents['body']['name'], 'people');

        $animals = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/collections',
            $this->getConsoleHeaders(),
            [
                'collectionId' => ID::unique(),
                'name' => 'animals',
                'documentSecurity' => true,
                'permissions' => [
                    Permission::create(Role::user($this->getUser()['$id']))
                ],
            ]
        );
        $this->assertEquals(201, $animals['headers']['status-code']);
        $this->assertEquals($animals['body']['name'], 'animals');


        // Create Attributes
        $attribute = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/collections/' . $presidents['body']['$id'] . '/attributes/string',
            $this->getConsoleHeaders(),
            [
                'key' => 'first_name',
                'size' => 256,
                'required' => true
            ]
        );
        $this->assertEquals(202, $attribute['headers']['status-code']);

        $attribute = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/collections/' . $presidents['body']['$id'] . '/attributes/string',
            $this->getConsoleHeaders(),
            [
                'key' => 'last_name',
                'size' => 256,
                'required' => true
            ]
        );
        $this->assertEquals(202, $attribute['headers']['status-code']);

        $attribute = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/collections/' . $animals['body']['$id'] . '/attributes/string',
            $this->getConsoleHeaders(),
            [
                'key' => 'name',
                'size' => 256,
                'required' => true
            ]
        );
        $this->assertEquals(202, $attribute['headers']['status-code']);

        $attribute = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/collections/' . $animals['body']['$id'] . '/attributes/datetime',
            $this->getConsoleHeaders(),
            [
                'key' => 'date_of_birth',
                'required' => true
            ]
        );
        $this->assertEquals(202, $attribute['headers']['status-code']);

        // Wait for worker
        sleep(3);

        /**
         * Created Index
         */
        $index = $this->client->call(
            Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $presidents['body']['$id'] . '/indexes',
            $this->getConsoleHeaders(),
            [
                'key' => 'key_lastName',
                'type' => 'key',
                'attributes' => [
                    'last_name'
                ]
            ]
        );
        $this->assertEquals(202, $index['headers']['status-code']);
        $this->assertEquals('key_lastName', $index['body']['key']);

        $index = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/collections/' . $animals['body']['$id'] . '/indexes',
            $this->getConsoleHeaders(),
            [
                'key' => 'key_name',
                'type' => 'key',
                'attributes' => [
                    'name'
                ]
            ]
        );
        $this->assertEquals(202, $index['headers']['status-code']);
        $this->assertEquals('key_name', $index['body']['key']);

        $index = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/collections/' . $animals['body']['$id'] . '/indexes',
            $this->getConsoleHeaders(),
            [
                'key' => 'key_date_of_birth',
                'type' => 'key',
                'attributes' => [
                    'date_of_birth'
                ]
            ]
        );
        $this->assertEquals(202, $index['headers']['status-code']);
        $this->assertEquals('key_date_of_birth', $index['body']['key']);

        // Wait for database worker to finish creating index
        sleep(2);

        /**
         * Created Documents
         */
        $document1 = $this->client->call(
            Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $presidents['body']['$id'] . '/documents',
            $this->getConsoleHeaders(),
                [
                    'documentId' => ID::unique(),
                    'data' => [
                        'first_name' => 'Donald',
                        'last_name' => 'Trump'
                    ],
                    'permissions' => [
                        Permission::read(Role::user($this->getUser()['$id'])),
                    ]
                ]
            );

            $this->assertEquals(201, $document1['headers']['status-code']);

            $document2 = $this->client->call(
                Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $presidents['body']['$id'] . '/documents',
                $this->getConsoleHeaders(),
                [
                    'documentId' => ID::unique(),
                    'data' => [
                        'first_name' => 'George',
                        'last_name' => 'Bush'
                    ],
                    'permissions' => [
                        Permission::read(Role::user($this->getUser()['$id']))
                    ]
                ]
            );

            $this->assertEquals(201, $document2['headers']['status-code']);

            $document3 = $this->client->call(
                Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $presidents['body']['$id'] . '/documents',
                $this->getConsoleHeaders(),
                [
                    'documentId' => ID::unique(),
                    'data' => [
                        'first_name' => 'Joe',
                        'last_name' => 'Biden',
                        ],
                    'permissions' => [
                        Permission::read(Role::user($this->getUser()['$id'])),
                    ]
            ]
            );

            $this->assertEquals(201, $document3['headers']['status-code']);

            $documents = $this->client->call(
                Client::METHOD_GET,
                '/databases/' . $databaseId . '/collections/' . $presidents['body']['$id'] . '/documents',
                $this->getConsoleHeadersGet(),
                [
                    'queries' => [
                        Query::select(['first_name', 'last_name'])->toString(),
                        Query::or([
                            Query::equal('first_name', ['Donald']),
                            Query::equal('last_name', ['Bush'])
                        ])->toString(),
                        Query::limit(999)->toString(),
                        Query::offset(0)->toString()
                    ],
                ]
            );

            $this->assertEquals(200, $documents['headers']['status-code']);
            $this->assertCount(2, $documents['body']['documents']);


        return ['databaseId' => $databaseId];
    }

    /**
     * @depends testCreateDatabase
     */
    public function testDatabaseBackup(array $data): void
    {
        $databaseId = $data['databaseId'];

        /**
         * Test create new Backup policy
         */
        $response = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/backups-policy',
            $this->getConsoleHeaders(),
            [
                'policyId' => 'policy1',
                'name' => 'Hourly Backups',
                'enabled' => true,
                'retention' => 1,
                'hours' => 1
            ]
        );

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals('Hourly Backups', $response['body']['name']);
        $this->assertEquals('policy1', $response['body']['$id']);
        $this->assertEquals(1, $response['body']['hours']);
        $this->assertEquals(1, $response['body']['retention']);
        $this->assertEquals($databaseId, $response['body']['resourceId']);
        $this->assertEquals(true, $response['body']['enabled']);
        $this->assertEquals('backup-database', $response['body']['resourceType']);

        /**
         * Test for Duplicate
         */
        $duplicate = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/backups-policy',
            $this->getConsoleHeaders(),
            [
                'policyId' => 'policy1',
                'name' => 'Hourly Backups',
                'enabled' => true,
                'retention' => 6,
                'hours' => 4
            ]
        );

        $this->assertEquals(409, $duplicate['headers']['status-code']);

        /**
         * Test for Policy not found
         */
        $database = $this->client->call(
            Client::METHOD_GET,
            '/databases/'. $databaseId .'/backups-policy/notfound',
            $this->getConsoleHeadersGet(),
        );

        $this->assertEquals(404, $database['headers']['status-code']);

        $policy = $this->client->call(
            Client::METHOD_GET,
            '/databases/'. $databaseId .'/backups-policy/policy1',
            $this->getConsoleHeadersGet(),
        );

        $this->assertEquals(200, $policy['headers']['status-code']);
        $this->assertEquals('policy1', $policy['body']['$id']);
        $this->assertEquals('Hourly Backups', $policy['body']['name']);
        $this->assertEquals(true, $policy['body']['enabled']);

        /**
         * Test for update Policy
         */
        $policy = $this->client->call(
            Client::METHOD_PATCH,
            '/databases/' . $databaseId . '/backups-policy/policy1',
            $this->getConsoleHeaders(),
            [
                'name' => 'Daily backups',
                'enabled' => false,
                'retention' => 10,
                'hours' => 3
            ]
        );

        $this->assertEquals(200, $policy['headers']['status-code']);
        $this->assertEquals('policy1', $policy['body']['$id']);
        $this->assertEquals('Daily backups', $policy['body']['name']);
        $this->assertEquals(false, $policy['body']['enabled']);

        $policyId = $policy['body']['$id'];

        /**
         * Test create new Backup policy again
         */
        $response = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/backups-policy',
            $this->getConsoleHeaders(),
            [
                'policyId' => 'my-policy',
                'name' => 'New Hourly Backups',
                'enabled' => true,
                'retention' => 1,
                'hours' => 1
            ]
        );

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals('New Hourly Backups', $response['body']['name']);
        $this->assertEquals('my-policy', $response['body']['$id']);
        $this->assertEquals(1, $response['body']['hours']);
        $this->assertEquals(1, $response['body']['retention']);
        $this->assertEquals($databaseId, $response['body']['resourceId']);
        $this->assertEquals(true, $response['body']['enabled']);
        $this->assertEquals('backup-database', $response['body']['resourceType']);

        /**
         * Test to get backup policies list
         */

        $policies = $this->client->call(
            Client::METHOD_GET,
            '/databases/'. $databaseId .'/backups-policy',
            $this->getConsoleHeadersGet(),
            [
                'queries' => [
                    Query::orderDesc()->toString()
                ]
            ]
        );

        $this->assertEquals(200, $policies['headers']['status-code']);
        $this->assertEquals(2, count($policies['body']['backupPolicies']));

        /**
         * Test Delete policy
         */
        $response = $this->client->call(
            Client::METHOD_DELETE,
            '/databases/' . $databaseId. '/backups-policy/' . $policyId,
            $this->getConsoleHeaders()
        );

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEquals('', $response['body']);

        /**
         * Test to get backup policies list after delete
         */
        $policies = $this->client->call(
            Client::METHOD_GET,
            '/databases/'. $databaseId .'/backups-policy',
            $this->getConsoleHeadersGet(),
            [
                'queries' => [
                    Query::orderDesc()->toString()
                ]
            ]
        );

        $this->assertEquals(200, $policies['headers']['status-code']);
        $this->assertEquals(1, count($policies['body']['backupPolicies']));
    }

    public function testProjectBackup(): void
    {

        /**
         * Test for SUCCESS
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => \Utopia\Database\Helpers\ID::unique(),
            'name' => 'Test Bucket',
            'fileSecurity' => true,
            'maximumFileSize' => 2000000, //2MB
            'allowedFileExtensions' => ['jpg', 'png', 'jfif'],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucket['body']['$id']);

        $bucketId = $bucket['body']['$id'];


        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../resources/logo.png'), 'image/png', 'logo.png'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $file['headers']['status-code']);
        $this->assertNotEmpty($file['body']['$id']);
        $dateValidator = new DatetimeValidator();
        $this->assertEquals(true, $dateValidator->isValid($file['body']['$createdAt']));
        $this->assertEquals('logo.png', $file['body']['name']);
        $this->assertEquals('image/png', $file['body']['mimeType']);
        $this->assertEquals(47218, $file['body']['sizeOriginal']);
        $this->assertTrue(md5_file(realpath(__DIR__ . '/../../resources/logo.png')) == $file['body']['signature']);



//        /**
//         * Test create new Backup policy
//         */
//        $response = $this->client->call(
//            Client::METHOD_POST,
//            '/project/backups-policy',
//            $this->getConsoleHeaders(),
//            [
//                'policyId' => 'policy2',
//                'name' => 'Hourly Backups',
//                'enabled' => true,
//                'retention' => 6,
//                'hours' => 4,
//            ]
//        );
//
//        $this->assertEquals(201, $response['headers']['status-code']);
//        $this->assertNotEmpty($response['body']);
//        $this->assertEquals('Hourly Backups', $response['body']['name']);
//        $this->assertEquals('policy2', $response['body']['$id']);
//        $this->assertEquals(4, $response['body']['hours']);
//        $this->assertEquals(6, $response['body']['retention']);
//        $this->assertEquals($this->getProject()['$id'], $response['body']['resourceId']);
//        $this->assertEquals(true, $response['body']['enabled']);
//        $this->assertEquals('backup-project', $response['body']['resourceType']);
//
//        /**
//         * Test for Duplicate
//         */
//        $duplicate = $this->client->call(
//            Client::METHOD_POST,
//            '/project/backups-policy',
//            $this->getConsoleHeaders(),
//            [
//                'policyId' => 'policy2',
//                'name' => 'Hourly Backups',
//                'enabled' => true,
//                'retention' => 6,
//                'hours' => 4,
//            ]
//        );
//
//        $this->assertEquals(409, $duplicate['headers']['status-code']);
//
//        /**
//         * Test for Policy not found
//         */
//        $database = $this->client->call(
//            Client::METHOD_GET,
//            '/project/backups-policy/notfound',
//            $this->getConsoleHeaders()
//        );
//
//        $this->assertEquals(404, $database['headers']['status-code']);
//
//        $policy = $this->client->call(
//            Client::METHOD_GET,
//            '/project/backups-policy/policy2',
//            $this->getConsoleHeadersGet()
//        );
//
//        $this->assertEquals(200, $policy['headers']['status-code']);
//        $this->assertEquals('policy2', $policy['body']['$id']);
//        $this->assertEquals('Hourly Backups', $policy['body']['name']);
//        $this->assertEquals(true, $policy['body']['enabled']);
//
//        /**
//         * Test for update Policy
//         */
//        $policy = $this->client->call(
//            Client::METHOD_PATCH,
//            '/project/backups-policy/policy2',
//            $this->getConsoleHeaders(),
//            [
//                'name' => 'Daily backups',
//                'enabled' => false,
//                'retention' => 10,
//                'hours' => 3
//            ]
//        );
//
//        $policyId = $policy['body']['$id'];
//
//        $this->assertEquals(200, $policy['headers']['status-code']);
//        $this->assertEquals('policy2', $policy['body']['$id']);
//        $this->assertEquals('Daily backups', $policy['body']['name']);
//        $this->assertEquals(false, $policy['body']['enabled']);
//
//        /**
//         * Test create Second policy
//         */
//        $response = $this->client->call(
//            Client::METHOD_POST,
//            '/project/backups-policy',
//            $this->getConsoleHeaders(),
//            [
//                'policyId' => 'my-policy2',
//                'name' => 'New Hourly Backups',
//                'enabled' => true,
//                'retention' => 1,
//                'hours' => 1,
//            ]
//        );
//
//        $this->assertEquals(201, $response['headers']['status-code']);
//        $this->assertNotEmpty($response['body']);
//        $this->assertEquals('New Hourly Backups', $response['body']['name']);
//        $this->assertEquals('my-policy2', $response['body']['$id']);
//        $this->assertEquals(1, $response['body']['hours']);
//        $this->assertEquals(1, $response['body']['retention']);
//        $this->assertEquals($this->getProject()['$id'], $response['body']['resourceId']);
//        $this->assertEquals(true, $response['body']['enabled']);
//        $this->assertEquals('backup-project', $response['body']['resourceType']);
//
//        /**
//         * Test get backup policies list
//         */
//        $policies = $this->client->call(
//            Client::METHOD_GET,
//            '/project/backups-policy',
//            $this->getConsoleHeadersGet(),
//            [
//                'queries' => [
//                    Query::orderDesc()->toString()
//                ]
//            ]
//        );
//        $this->assertEquals(200, $policies['headers']['status-code']);
//        $this->assertEquals(2, count($policies['body']['backupPolicies']));
//
//        /**
//         * Test Delete policy
//         */
//        $response = $this->client->call(
//            Client::METHOD_DELETE,
//            "/project/backups-policy/{$policyId}",
//            $this->getConsoleHeaders(),
//            $this->getHeaders()
//        );
//
//        $this->assertEquals(204, $response['headers']['status-code']);
//        $this->assertEquals('', $response['body']);
//
//        /**
//         * Test get backup policies list after delete
//         */
//        $policies = $this->client->call(
//            Client::METHOD_GET,
//            '/project/backups-policy',
//            $this->getConsoleHeadersGet(),
//            [
//                'queries' => [
//                    Query::orderDesc()->toString()
//                ]
//            ]
//        );
//        $this->assertEquals(200, $policies['headers']['status-code']);
//        $this->assertEquals(1, count($policies['body']['backupPolicies']));
    }

    public function tearDown(): void
    {
        $this->projectId = '';
    }
}
