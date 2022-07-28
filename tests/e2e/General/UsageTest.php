<?php

namespace Tests\E2E\General;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use CURLFile;
use Tests\E2E\Services\Functions\FunctionsBase;

class UsageTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use FunctionsBase;

    protected array $headers = [];
    protected string $projectId;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testUsersStats(): array
    {
        $project = $this->getProject(true);
        $projectId = $project['$id'];
        $headers['x-appwrite-project'] = $project['$id'];
        $headers['x-appwrite-key'] = $project['apiKey'];
        $headers['content-type'] = 'application/json';

        $usersCount = 0;
        $requestsCount = 0;
        for ($i = 0; $i < 10; $i++) {
            $email = uniqid() . 'user@usage.test';
            $password = 'password';
            $name = uniqid() . 'User';
            $res = $this->client->call(Client::METHOD_POST, '/users', $headers, [
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
                $res = $this->client->call(Client::METHOD_GET, '/users/' . $userId, $headers);
                $this->assertEquals($userId, $res['body']['$id']);
                $res = $this->client->call(Client::METHOD_DELETE, '/users/' . $userId, $headers);
                $this->assertEmpty($res['body']);
                $requestsCount += 2;
                $usersCount--;
            }
        }


        sleep(35);

        // console request
        $cheaders = [
            'origin' => 'http://localhost',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ];

        $res = $this->client->call(Client::METHOD_GET, '/projects/' . $projectId . '/usage?range=30d', $cheaders);
        $res = $res['body'];

        $this->assertEquals(8, count($res));
        $this->assertEquals(30, count($res['requests']));
        $this->assertEquals(30, count($res['users']));
        $this->assertEquals($usersCount, $res['users'][array_key_last($res['users'])]['value']);
        $this->assertEquals($requestsCount, $res['requests'][array_key_last($res['requests'])]['value']);

        $res = $this->client->call(Client::METHOD_GET, '/users/usage?range=30d', array_merge($cheaders, [
            'x-appwrite-project' => $projectId,
            'x-appwrite-mode' => 'admin'
        ]));
        $res = $res['body'];
        $this->assertEquals(10, $res['usersCreate'][array_key_last($res['usersCreate'])]['value']);
        $this->assertEquals(5, $res['usersRead'][array_key_last($res['usersRead'])]['value']);
        $this->assertEquals(5, $res['usersDelete'][array_key_last($res['usersDelete'])]['value']);

        return ['projectId' => $projectId, 'headers' => $headers, 'requestsCount' => $requestsCount];
    }

    /** @depends testUsersStats */
    public function testStorageStats(array $data): array
    {
        $projectId = $data['projectId'];
        $headers = $data['headers'];

        $bucketId = '';
        $bucketsCount = 0;
        $requestsCount = $data['requestsCount'];
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
            $res = $this->client->call(Client::METHOD_POST, '/storage/buckets', $headers, [
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
                $res = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId, $headers);
                $this->assertEquals($bucketId, $res['body']['$id']);
                $bucketsRead++;

                $res = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId, $headers);
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
            $res = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge($headers, ['content-type' => 'multipart/form-data']), [
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
                $res = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, $headers);
                $this->assertEquals($fileId, $res['body']['$id']);
                $filesRead++;

                $res = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, $headers);
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

        $res = $this->client->call(Client::METHOD_GET, '/projects/' . $projectId . '/usage?range=30d', $headers);
        $res = $res['body'];

        $this->assertEquals(8, count($res));
        $this->assertEquals(30, count($res['requests']));
        $this->assertEquals(30, count($res['storage']));
        $this->assertEquals($requestsCount, $res['requests'][array_key_last($res['requests'])]['value']);
        $this->assertEquals($storageTotal, $res['storage'][array_key_last($res['storage'])]['value']);

        $res = $this->client->call(Client::METHOD_GET, '/storage/usage?range=30d', array_merge($headers, [
            'x-appwrite-project' => $projectId,
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
            'x-appwrite-project' => $projectId,
            'x-appwrite-mode' => 'admin'
        ]));
        $res = $res['body'];
        $this->assertEquals($storageTotal, $res['filesStorage'][array_key_last($res['filesStorage'])]['value']);
        $this->assertEquals($filesCount, $res['filesCount'][array_key_last($res['filesCount'])]['value']);
        $this->assertEquals($filesRead, $res['filesRead'][array_key_last($res['filesRead'])]['value']);
        $this->assertEquals($filesCreate, $res['filesCreate'][array_key_last($res['filesCreate'])]['value']);
        $this->assertEquals($filesDelete, $res['filesDelete'][array_key_last($res['filesDelete'])]['value']);

        $data['requestsCount'] = $requestsCount;
        return $data;
    }

    /** @depends testStorageStats */
    public function testDatabaseStats(array $data): array
    {
        $headers = $data['headers'];
        $projectId = $data['projectId'];

        $databaseId = '';
        $collectionId = '';

        $requestsCount = $data['requestsCount'];
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
            $res = $this->client->call(Client::METHOD_POST, '/databases', $headers, [
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
                $res = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId, $headers);
                $this->assertEquals($databaseId, $res['body']['$id']);
                $databasesRead++;

                $res = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId, $headers);
                $this->assertEmpty($res['body']);
                $databasesDelete++;

                $databasesCount--;
                $requestsCount += 2;
            }
        }

        for ($i = 0; $i < 10; $i++) {
            $name = uniqid() . ' collection';
            $res = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', $headers, [
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
                $res = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId, $headers);
                $this->assertEquals($collectionId, $res['body']['$id']);
                $collectionsRead++;

                $res = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId, $headers);
                $this->assertEmpty($res['body']);
                $collectionsDelete++;

                $collectionsCount--;
                $requestsCount += 2;
            }
        }

        $res = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes' . '/string', $headers, [
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
            $res = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', $headers, [
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
                $res = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, $headers);
                $this->assertEquals($documentId, $res['body']['$id']);
                $documentsRead++;

                $res = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, $headers);
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
        $res = $this->client->call(Client::METHOD_GET, '/projects/' . $projectId . '/usage?range=30d', $headers);
        $res = $res['body'];

        $this->assertEquals(8, count($res));
        $this->assertEquals(30, count($res['requests']));
        $this->assertEquals(30, count($res['storage']));
        $this->assertEquals($requestsCount, $res['requests'][array_key_last($res['requests'])]['value']);
        $this->assertEquals($collectionsCount, $res['collections'][array_key_last($res['collections'])]['value']);
        $this->assertEquals($documentsCount, $res['documents'][array_key_last($res['documents'])]['value']);

        $res = $this->client->call(Client::METHOD_GET, '/databases/usage?range=30d', array_merge($headers, [
            'x-appwrite-project' => $projectId,
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
            'x-appwrite-project' => $projectId,
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
            'x-appwrite-project' => $projectId,
            'x-appwrite-mode' => 'admin'
        ]));
        $res = $res['body'];
        $this->assertEquals($documentsCount, $res['documentsCount'][array_key_last($res['documentsCount'])]['value']);

        $this->assertEquals($documentsCreate, $res['documentsCreate'][array_key_last($res['documentsCreate'])]['value']);
        $this->assertEquals($documentsRead, $res['documentsRead'][array_key_last($res['documentsRead'])]['value']);
        $this->assertEquals($documentsDelete, $res['documentsDelete'][array_key_last($res['documentsDelete'])]['value']);

        $data['requestsCount'] = $requestsCount;
        return $data;
    }

    /** @depends testDatabaseStats */
    public function testFunctionsStats(array $data): void
    {
        $functionId = '';
        $requestsCount = $data['requestsCount'];
        $executionTime = 0;
        $executions = 0;
        $failures = 0;
        $compute = 0;


        $response1 = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => 'unique()',
            'name' => 'Test',
            'runtime' => 'php-8.0',
            'vars' => [
                'funcKey1' => 'funcValue1',
                'funcKey2' => 'funcValue2',
                'funcKey3' => 'funcValue3',
            ],
            'events' => [
                'users.*.create',
                'users.*.delete',
            ],
            'schedule' => '0 0 1 1 *',
            'timeout' => 10,
        ]);

        $functionId = $response1['body']['$id'] ?? '';

        $this->assertEquals(201, $response1['headers']['status-code']);
        $this->assertNotEmpty($response1['body']['$id']);

        $requestsCount++;

        $folder = 'php';
        $code = realpath(__DIR__ . '/../../resources/functions') . "/$folder/code.tar.gz";
        $this->packageCode($folder);

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'entrypoint' => 'index.php',
            'code' => new CURLFile($code, 'application/x-gzip', \basename($code)),
            'activate' => true
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';

        $this->assertEquals(201, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);
        $this->assertIsInt($deployment['body']['$createdAt']);
        $this->assertEquals('index.php', $deployment['body']['entrypoint']);

        // Wait for deployment to build.
        sleep(30);

        $response = $this->client->call(Client::METHOD_PATCH, '/functions/' . $functionId . '/deployments/' . $deploymentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertIsInt($response['body']['$createdAt']);
        $this->assertIsInt($response['body']['$updatedAt']);
        $this->assertEquals($deploymentId, $response['body']['deployment']);

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'async' => false,
        ]);

        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertNotEmpty($execution['body']['$id']);
        $this->assertEquals($functionId, $execution['body']['functionId']);
        $compute += (int) ($execution['body']['time'] * 1000);
        $executionTime += (int) ($execution['body']['time'] * 1000);
        $executions++;

        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'async' => true,
        ]);

        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertNotEmpty($execution['body']['$id']);
        $this->assertEquals($functionId, $execution['body']['functionId']);
        $executionId = $execution['body']['$id'];
        $executions++;

        //wait for execution to complete
        sleep(10);

        $execution = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/executions/' . $executionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $execution['headers']['status-code']);
        $this->assertEquals($executionId, $execution['body']['$id']);
        $this->assertEquals($functionId, $execution['body']['functionId']);
        $this->assertEquals('completed', $execution['body']['status']);

        $compute += (int) ($execution['body']['time'] * 1000);
        $executionTime += (int) ($execution['body']['time'] * 1000);

        sleep(45);

        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '30d'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(4, count($response['body']));
        $this->assertEquals('30d', $response['body']['range']);
        $this->assertIsArray($response['body']['functionsExecutions']);
        $this->assertIsArray($response['body']['functionsFailures']);
        $this->assertIsArray($response['body']['functionsCompute']);
        $response = $response['body'];

        $this->assertEquals($executions, $response['functionsExecutions'][array_key_last($response['functionsExecutions'])]['value']);
        $this->assertGreaterThan($compute, $response['functionsCompute'][array_key_last($response['functionsCompute'])]['value']);
        $this->assertEquals($failures, $response['functionsFailures'][array_key_last($response['functionsFailures'])]['value']);

        $response = $this->client->call(Client::METHOD_GET, '/functions/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '30d'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(6, count($response['body']));
        $this->assertEquals($response['body']['range'], '30d');
        $this->assertIsArray($response['body']['functionsExecutions']);
        $this->assertIsArray($response['body']['functionsFailures']);
        $this->assertIsArray($response['body']['functionsCompute']);
        $this->assertIsArray($response['body']['functionsExecutionTime']);
        $this->assertIsArray($response['body']['functionsBuildTime']);
        $response = $response['body'];

        $this->assertEquals($executions, $response['functionsExecutions'][array_key_last($response['functionsExecutions'])]['value']);
        $this->assertGreaterThan($compute, $response['functionsCompute'][array_key_last($response['functionsCompute'])]['value']);
        $this->assertEquals($executionTime, $response['functionsExecutionTime'][array_key_last($response['functionsExecutionTime'])]['value']);
        $this->assertGreaterThan(0, $response['functionsBuildTime'][array_key_last($response['functionsBuildTime'])]['value']);
        $this->assertEquals($failures, $response['functionsFailures'][array_key_last($response['functionsFailures'])]['value']);
    }

    protected function tearDown(): void
    {
        $this->usersCount = 0;
        $this->requestsCount = 0;
        $projectId = '';
        $headers = [];
    }
}
