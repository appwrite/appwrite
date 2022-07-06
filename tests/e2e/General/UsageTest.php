<?php

namespace Tests\E2E\General;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use CURLFile;

class UsageTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    protected array $headers = [];
    protected string $projectId;

    protected function setUp(): void
    {
        parent::setUp();
        $project = $this->getProject(true);
        $this->projectId = $project['$id'];
        $this->headers['x-appwrite-project'] = $project['$id'];
        $this->headers['x-appwrite-key'] = $project['apiKey'];
        $this->headers['content-type'] = 'application/json';
    }

    public function testUsersStats(): void
    {
        $usersCount = 0;
        $requestsCount = 0;
        for ($i = 0; $i < 10; $i++) {
            $email = uniqid() . 'user@usage.test';
            $password = 'password';
            $name = uniqid() . 'User';
            $res = $this->client->call(Client::METHOD_POST, '/users', $this->headers, [
                'userId' => 'unique()',
                'email' => $email,
                'password' => $password,
                'name' => $name,
            ]);
            $this->assertEquals($email, $res['body']['email']);
            $this->assertNotEmpty($res['body']['$id']);
            $usersCount++;
            $requestsCount++;

            if ($i < 5) {
                $userId = $res['body']['$id'];
                $res = $this->client->call(Client::METHOD_GET, '/users/' . $userId, $this->headers);
                $this->assertEquals($userId, $res['body']['$id']);
                $res = $this->client->call(Client::METHOD_DELETE, '/users/' . $userId, $this->headers);
                $this->assertEmpty($res['body']);
                $requestsCount += 2;
                $usersCount--;
            }
        }


        sleep(35);

        // console request
        $headers = [
            'origin' => 'http://localhost',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ];

        $res = $this->client->call(Client::METHOD_GET, '/projects/' . $this->projectId . '/usage?range=30d', $headers);
        $res = $res['body'];

        $this->assertEquals(8, count($res));
        $this->assertEquals(30, count($res['requests']));
        $this->assertEquals(30, count($res['users']));
        $this->assertEquals($usersCount, $res['users'][array_key_last($res['users'])]['value']);
        $this->assertEquals($requestsCount, $res['requests'][array_key_last($res['requests'])]['value']);

        $res = $this->client->call(Client::METHOD_GET, '/users/usage?range=30d', array_merge($headers, [
            'x-appwrite-project' => $this->projectId,
            'x-appwrite-mode' => 'admin'
        ]));
        $res = $res['body'];
        $this->assertEquals(10, $res['usersCreate'][array_key_last($res['usersCreate'])]['value']);
        $this->assertEquals(5, $res['usersRead'][array_key_last($res['usersRead'])]['value']);
        $this->assertEquals(5, $res['usersDelete'][array_key_last($res['usersDelete'])]['value']);
    }

    public function testStorageStats(): void
    {
        $bucketId = '';
        $bucketsCount = 0;
        $requestsCount = 0;
        $storageTotal = 0;
        $bucketsCreate = 0;
        $bucketsDelete = 0;
        $bucketsRead = 0;
        $filesCount = 0;
        $filesRead = 0;
        $filesCreate = 0;
        $filesDelete = 0;

        for ($i = 0; $i < 10; $i++) {
            $name = uniqid() . ' bucket';
            $res = $this->client->call(Client::METHOD_POST, '/storage/buckets', $this->headers, [
                'bucketId' => 'unique()',
                'name' => $name,
                'permission' => 'bucket'
            ]);
            $this->assertEquals($name, $res['body']['name']);
            $this->assertNotEmpty($res['body']['$id']);
            $bucketId = $res['body']['$id'];

            $bucketsCreate++;
            $bucketsCount++;
            $requestsCount++;

            if ($i < 5) {
                $res = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId, $this->headers);
                $this->assertEquals($bucketId, $res['body']['$id']);
                $bucketsRead++;

                $res = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId, $this->headers);
                $this->assertEmpty($res['body']);
                $bucketsDelete++;

                $requestsCount += 2;
                $bucketsCount--;
            }
        }

        // upload some files
        $files = [
            [
                'path' => realpath(__DIR__ . '/../../resources/logo.png'),
                'name' => 'logo.png',
            ],
            [
                'path' => realpath(__DIR__ . '/../../resources/file.png'),
                'name' => 'file.png',
            ],
            [
                'path' => realpath(__DIR__ . '/../../resources/disk-a/kitten-3.gif'),
                'name' => 'kitten-3.gif',
            ],
            [
                'path' => realpath(__DIR__ . '/../../resources/disk-a/kitten-1.jpg'),
                'name' => 'kitten-1.jpg',
            ],
            ];

        for ($i = 0; $i < 10; $i++) {
            $file = $files[$i % count($files)];
            $res = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge($this->headers, ['content-type' => 'multipart/form-data']), [
                'fileId' => 'unique()',
                'file' => new CURLFile($file['path'], '', $file['name']),
            ]);
            $this->assertNotEmpty($res['body']['$id']);

            $fileSize = $res['body']['sizeOriginal'];
            $storageTotal += $fileSize;
            $filesCount++;
            $filesCreate++;
            $requestsCount++;

            $fileId = $res['body']['$id'];
            if ($i < 5) {
                $res = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, $this->headers);
                $this->assertEquals($fileId, $res['body']['$id']);
                $filesRead++;

                $res = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, $this->headers);
                $this->assertEmpty($res['body']);
                $filesDelete++;
                $requestsCount += 2;
                $filesCount--;
                $storageTotal -=  $fileSize;
            }
        }

        sleep(35);

        // console request
        $headers = [
            'origin' => 'http://localhost',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ];

        $res = $this->client->call(Client::METHOD_GET, '/projects/' . $this->projectId . '/usage?range=30d', $headers);
        $res = $res['body'];

        $this->assertEquals(8, count($res));
        $this->assertEquals(30, count($res['requests']));
        $this->assertEquals(30, count($res['storage']));
        $this->assertEquals($requestsCount, $res['requests'][array_key_last($res['requests'])]['value']);
        $this->assertEquals($storageTotal, $res['storage'][array_key_last($res['storage'])]['value']);

        $res = $this->client->call(Client::METHOD_GET, '/storage/usage?range=30d', array_merge($headers, [
            'x-appwrite-project' => $this->projectId,
            'x-appwrite-mode' => 'admin'
        ]));
        $res = $res['body'];
        $this->assertEquals($storageTotal, $res['filesStorage'][array_key_last($res['filesStorage'])]['value']);
        $this->assertEquals($bucketsCount, $res['bucketsCount'][array_key_last($res['bucketsCount'])]['value']);
        $this->assertEquals($bucketsRead, $res['bucketsRead'][array_key_last($res['bucketsRead'])]['value']);
        $this->assertEquals($bucketsCreate, $res['bucketsCreate'][array_key_last($res['bucketsCreate'])]['value']);
        $this->assertEquals($bucketsDelete, $res['bucketsDelete'][array_key_last($res['bucketsDelete'])]['value']);
        $this->assertEquals($filesCount, $res['filesCount'][array_key_last($res['filesCount'])]['value']);
        $this->assertEquals($filesRead, $res['filesRead'][array_key_last($res['filesRead'])]['value']);
        $this->assertEquals($filesCreate, $res['filesCreate'][array_key_last($res['filesCreate'])]['value']);
        $this->assertEquals($filesDelete, $res['filesDelete'][array_key_last($res['filesDelete'])]['value']);

        $res = $this->client->call(Client::METHOD_GET, '/storage/' . $bucketId . '/usage?range=30d', array_merge($headers, [
            'x-appwrite-project' => $this->projectId,
            'x-appwrite-mode' => 'admin'
        ]));
        $res = $res['body'];
        $this->assertEquals($storageTotal, $res['filesStorage'][array_key_last($res['filesStorage'])]['value']);
        $this->assertEquals($filesCount, $res['filesCount'][array_key_last($res['filesCount'])]['value']);
        $this->assertEquals($filesRead, $res['filesRead'][array_key_last($res['filesRead'])]['value']);
        $this->assertEquals($filesCreate, $res['filesCreate'][array_key_last($res['filesCreate'])]['value']);
        $this->assertEquals($filesDelete, $res['filesDelete'][array_key_last($res['filesDelete'])]['value']);
    }

    public function testDatabaseStats(): void
    {
        $databaseId = '';
        $collectionId = '';

        $requestsCount = 0;
        $databasesCount = 0;
        $databasesCreate = 0;
        $databasesRead = 0;
        $databasesDelete = 0;

        $collectionsCount = 0;
        $collectionsCreate = 0;
        $collectionsRead = 0;
        $collectionsUpdate = 0;
        $collectionsDelete = 0;

        $documentsCount = 0;
        $documentsCreate = 0;
        $documentsRead = 0;
        $documentsDelete = 0;

        for ($i = 0; $i < 10; $i++) {
            $name = uniqid() . ' database';
            $res = $this->client->call(Client::METHOD_POST, '/databases', $this->headers, [
                'databaseId' => 'unique()',
                'name' => $name,
            ]);
            $this->assertEquals($name, $res['body']['name']);
            $this->assertNotEmpty($res['body']['$id']);
            $databaseId = $res['body']['$id'];

            $requestsCount++;
            $databasesCount++;
            $databasesCreate++;

            if ($i < 5) {
                $res = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId, $this->headers);
                $this->assertEquals($databaseId, $res['body']['$id']);
                $databasesRead++;

                $res = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $this->headers);
                $this->assertEmpty($res['body']);
                $databasesDelete++;

                $databasesCount--;
                $requestsCount += 2;
            }
        }

        for ($i = 0; $i < 10; $i++) {
            $name = uniqid() . ' collection';
            $res = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', $this->headers, [
                'collectionId' => 'unique()',
                'name' => $name,
                'permission' => 'collection',
                'read' => ['role:all'],
                'write' => ['role:all']
            ]);
            $this->assertEquals($name, $res['body']['name']);
            $this->assertNotEmpty($res['body']['$id']);
            $collectionId = $res['body']['$id'];

            $requestsCount++;
            $collectionsCount++;
            $collectionsCreate++;

            if ($i < 5) {
                $res = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId, $this->headers);
                $this->assertEquals($collectionId, $res['body']['$id']);
                $collectionsRead++;

                $res = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId, $this->headers);
                $this->assertEmpty($res['body']);
                $collectionsDelete++;

                $collectionsCount--;
                $requestsCount += 2;
            }
        }

        $res = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes' . '/string', $this->headers, [
            'key' => 'name',
            'size' => 255,
            'required' => true,
        ]);
        $this->assertEquals('name', $res['body']['key']);
        $collectionsUpdate++;
        $requestsCount++;
        sleep(10);

        for ($i = 0; $i < 10; $i++) {
            $name = uniqid() . ' collection';
            $res = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', $this->headers, [
                'documentId' => 'unique()',
                'data' => ['name' => $name]
            ]);
            $this->assertEquals($name, $res['body']['name']);
            $this->assertNotEmpty($res['body']['$id']);
            $documentId = $res['body']['$id'];

            $requestsCount++;
            $documentsCount++;
            $documentsCreate++;

            if ($i < 5) {
                $res = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, $this->headers);
                $this->assertEquals($documentId, $res['body']['$id']);
                $documentsRead++;

                $res = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, $this->headers);
                $this->assertEmpty($res['body']);
                $documentsDelete++;

                $documentsCount--;
                $requestsCount += 2;
            }
        }

        sleep(35);

        // check datbase stats
        $headers = [
            'origin' => 'http://localhost',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ];
        $res = $this->client->call(Client::METHOD_GET, '/projects/' . $this->projectId . '/usage?range=30d', $headers);
        $res = $res['body'];

        $this->assertEquals(8, count($res));
        $this->assertEquals(30, count($res['requests']));
        $this->assertEquals(30, count($res['storage']));
        $this->assertEquals($requestsCount, $res['requests'][array_key_last($res['requests'])]['value']);
        $this->assertEquals($collectionsCount, $res['collections'][array_key_last($res['collections'])]['value']);
        $this->assertEquals($documentsCount, $res['documents'][array_key_last($res['documents'])]['value']);

        $res = $this->client->call(Client::METHOD_GET, '/databases/usage?range=30d', array_merge($headers, [
            'x-appwrite-project' => $this->projectId,
            'x-appwrite-mode' => 'admin'
        ]));
        $res = $res['body'];
        $this->assertEquals($databasesCount, $res['databasesCount'][array_key_last($res['databasesCount'])]['value']);
        $this->assertEquals($collectionsCount, $res['collectionsCount'][array_key_last($res['collectionsCount'])]['value']);
        $this->assertEquals($documentsCount, $res['documentsCount'][array_key_last($res['documentsCount'])]['value']);

        $this->assertEquals($databasesCreate, $res['databasesCreate'][array_key_last($res['databasesCreate'])]['value']);
        $this->assertEquals($databasesRead, $res['databasesRead'][array_key_last($res['databasesRead'])]['value']);
        $this->assertEquals($databasesDelete, $res['databasesDelete'][array_key_last($res['databasesDelete'])]['value']);

        $this->assertEquals($collectionsCreate, $res['collectionsCreate'][array_key_last($res['collectionsCreate'])]['value']);
        $this->assertEquals($collectionsRead, $res['collectionsRead'][array_key_last($res['collectionsRead'])]['value']);
        $this->assertEquals($collectionsUpdate, $res['collectionsUpdate'][array_key_last($res['collectionsUpdate'])]['value']);
        $this->assertEquals($collectionsDelete, $res['collectionsDelete'][array_key_last($res['collectionsDelete'])]['value']);

        $this->assertEquals($documentsCreate, $res['documentsCreate'][array_key_last($res['documentsCreate'])]['value']);
        $this->assertEquals($documentsRead, $res['documentsRead'][array_key_last($res['documentsRead'])]['value']);
        $this->assertEquals($documentsDelete, $res['documentsDelete'][array_key_last($res['documentsDelete'])]['value']);

        $res = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/usage?range=30d', array_merge($headers, [
            'x-appwrite-project' => $this->projectId,
            'x-appwrite-mode' => 'admin'
        ]));
        $res = $res['body'];
        $this->assertEquals($collectionsCount, $res['collectionsCount'][array_key_last($res['collectionsCount'])]['value']);
        $this->assertEquals($documentsCount, $res['documentsCount'][array_key_last($res['documentsCount'])]['value']);

        $this->assertEquals($collectionsCreate, $res['collectionsCreate'][array_key_last($res['collectionsCreate'])]['value']);
        $this->assertEquals($collectionsRead, $res['collectionsRead'][array_key_last($res['collectionsRead'])]['value']);
        $this->assertEquals($collectionsUpdate, $res['collectionsUpdate'][array_key_last($res['collectionsUpdate'])]['value']);
        $this->assertEquals($collectionsDelete, $res['collectionsDelete'][array_key_last($res['collectionsDelete'])]['value']);

        $this->assertEquals($documentsCreate, $res['documentsCreate'][array_key_last($res['documentsCreate'])]['value']);
        $this->assertEquals($documentsRead, $res['documentsRead'][array_key_last($res['documentsRead'])]['value']);
        $this->assertEquals($documentsDelete, $res['documentsDelete'][array_key_last($res['documentsDelete'])]['value']);

        $res = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/usage?range=30d', array_merge($headers, [
            'x-appwrite-project' => $this->projectId,
            'x-appwrite-mode' => 'admin'
        ]));
        $res = $res['body'];
        $this->assertEquals($documentsCount, $res['documentsCount'][array_key_last($res['documentsCount'])]['value']);

        $this->assertEquals($documentsCreate, $res['documentsCreate'][array_key_last($res['documentsCreate'])]['value']);
        $this->assertEquals($documentsRead, $res['documentsRead'][array_key_last($res['documentsRead'])]['value']);
        $this->assertEquals($documentsDelete, $res['documentsDelete'][array_key_last($res['documentsDelete'])]['value']);
    }

    public function testFunctionsStats(): void
    {
        // create some functions
        // create some successful deployments and some
        // failed deployments
        // execute some functions
        // some failed executions
        // test the stats
    }

    protected function tearDown(): void
    {
        $this->usersCount = 0;
        $this->requestsCount = 0;
        $this->projectId = '';
        $this->headers = [];
    }
}
