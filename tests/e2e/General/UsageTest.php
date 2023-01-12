<?php

namespace Tests\E2E\General;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use CURLFile;
use Tests\E2E\Services\Functions\FunctionsBase;
use Utopia\Database\DateTime;
use Utopia\Database\Permission;
use Utopia\Database\Role;

class UsageTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use FunctionsBase;

    private const WAIT = 30;
    private const CREATE = 20;

    protected string $projectId;

    public function setUp(): void
    {
        parent::setUp();
    }

    protected static string $formatTz = 'Y-m-d\TH:i:s.vP';

    protected function validateDates(array $metrics): void
    {
        foreach ($metrics as $metric) {
            $this->assertIsObject(\DateTime::createFromFormat("Y-m-d\TH:i:s.vP", $metric['date']));
        }
    }

    public function testPrepareUsersStats(): array
    {
        $project = $this->getProject(true);
        $projectId = $project['$id'];
        $headers['x-appwrite-project'] = $project['$id'];
        $headers['x-appwrite-key'] = $project['apiKey'];
        $headers['content-type'] = 'application/json';

        $usersCount    = 0;
        $requestsCount = 0;
        for ($i = 0; $i < self::CREATE; $i++) {
            $email = uniqid() . 'user@usage.test';
            $password = 'password';
            $name = uniqid() . 'User';
            $res = $this->client->call(
                Client::METHOD_POST,
                '/users',
                $headers,
                [
                'userId'   => 'unique()',
                'email'    => $email,
                'password' => $password,
                'name'     => $name,
                ]
            );

            $this->assertEquals($email, $res['body']['email']);
            $this->assertNotEmpty($res['body']['$id']);
            $usersCount++;
            $requestsCount++;

            if ($i < (self::CREATE / 2)) {
                $userId = $res['body']['$id'];
                $res = $this->client->call(Client::METHOD_DELETE, '/users/' . $userId, $headers);
                $this->assertEmpty($res['body']);
                $requestsCount++;
                $usersCount--;
            }
        }

        return [
            'projectId'     => $projectId,
            'headers'       => $headers,
            'usersCount'    => $usersCount,
            'requestsCount' => $requestsCount
        ];
    }

    /**
     * @depends testPrepareUsersStats
     */
    public function testUsersStats(array $data): array
    {
        sleep(self::WAIT);

        $projectId     = $data['projectId'];
        $headers       = $data['headers'];
        $usersCount    = $data['usersCount'];
        $requestsCount = $data['requestsCount'];

        $consoleHeaders = [
            'origin' => 'http://localhost',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => $projectId,
            'x-appwrite-mode' => 'admin',
        ];

        $res = $this->client->call(
            Client::METHOD_GET,
            '/project/usage?range=24h',
            $consoleHeaders
        );
        $res = $res['body'];

        $this->assertEquals('24h', $res['range']);
        $this->assertEquals(9, count($res));
        $this->assertEquals(24, count($res['requests']));
        $this->assertEquals(24, count($res['users']));
        $this->assertEquals($usersCount, $res['users'][array_key_last($res['users'])]['value']);
        $this->validateDates($res['users']);
        $this->assertEquals($requestsCount, $res['requests'][array_key_last($res['requests'])]['value']);
        $this->validateDates($res['requests']);

        $res = $this->client->call(
            Client::METHOD_GET,
            '/users/usage?range=90d',
            $consoleHeaders
        );

        $res = $res['body'];
        $this->assertEquals('90d', $res['range']);
        $this->assertEquals(90, count($res['usersCount']));
        $this->assertEquals(90, count($res['sessionsCount']));
        $this->assertEquals((self::CREATE / 2), $res['usersCount'][array_key_last($res['usersCount'])]['value']);

        return [
            'projectId' => $projectId,
            'headers' => $headers,
            'consoleHeaders' => $consoleHeaders,
            'requestsCount' => $requestsCount,
        ];
    }

    /** @depends testUsersStats */
    public function testPrepareStorageStats(array $data): array
    {
        $headers = $data['headers'];
        $bucketsCount = 0;
        $requestsCount = $data['requestsCount'];
        $storageTotal = 0;
        $filesCount = 0;


        for ($i = 0; $i < self::CREATE; $i++) {
            $name = uniqid() . ' bucket';
            $res = $this->client->call(
                Client::METHOD_POST,
                '/storage/buckets',
                array_merge(
                    $headers,
                    [
                    'content-type' => 'multipart/form-data'
                    ]
                ),
                [
                'bucketId' => 'unique()',
                'name' => $name,
                'fileSecurity' => false,
                'permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                    ]
            );
            $this->assertEquals($name, $res['body']['name']);
            $this->assertNotEmpty($res['body']['$id']);
            $bucketId = $res['body']['$id'];
            $bucketsCount++;
            $requestsCount++;

            if ($i < (self::CREATE / 2)) {
                $res = $this->client->call(
                    Client::METHOD_DELETE,
                    '/storage/buckets/' . $bucketId,
                    $headers
                );
                $this->assertEmpty($res['body']);
                $requestsCount++;
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

        for ($i = 0; $i < self::CREATE; $i++) {
            $file = $files[$i % count($files)];

            $res = $this->client->call(
                Client::METHOD_POST,
                '/storage/buckets/' . $bucketId . '/files',
                array_merge($headers, ['content-type' => 'multipart/form-data']),
                [
                'fileId' => 'unique()',
                'file' => new CURLFile($file['path'], '', $file['name']),
                ]
            );

            $this->assertNotEmpty($res['body']['$id']);

            $fileSize = $res['body']['sizeOriginal'];
            $storageTotal += $fileSize;
            $filesCount++;
            $requestsCount++;

            $fileId = $res['body']['$id'];
            if ($i < (self::CREATE / 2)) {
                $res = $this->client->call(
                    Client::METHOD_DELETE,
                    '/storage/buckets/' . $bucketId . '/files/' . $fileId,
                    $headers
                );
                $this->assertEmpty($res['body']);
                $requestsCount++;
                $filesCount--;
                $storageTotal -=  $fileSize;
            }
        }

        return array_merge($data, [
            'bucketId' => $bucketId,
            'bucketsCount' => $bucketsCount,
            'requestsCount' => $requestsCount,
            'storageTotal' => $storageTotal,
            'filesCount' => $filesCount,
        ]);
    }

    /**
     * @depends testPrepareStorageStats
     */
    public function testStorageStats(array $data): array
    {
        $bucketId      = $data['bucketId'];
        $bucketsCount  = $data['bucketsCount'];
        $requestsCount = $data['requestsCount'];
        $storageTotal  = $data['storageTotal'];
        $filesCount    = $data['filesCount'];

        sleep(self::WAIT);

        $res = $this->client->call(
            Client::METHOD_GET,
            '/project/usage?range=30d',
            array_merge(
                $data['headers'],
                $data['consoleHeaders']
            )
        );
        $res = $res['body'];

        $this->assertEquals(9, count($res));
        $this->assertEquals(30, count($res['requests']));
        $this->assertEquals(30, count($res['storage']));
        $this->assertEquals($requestsCount, $res['requests'][array_key_last($res['requests'])]['value']);
        $this->validateDates($res['requests']);
        $this->assertEquals($storageTotal, $res['storage'][array_key_last($res['storage'])]['value']);
        $this->validateDates($res['storage']);

        $res = $this->client->call(
            Client::METHOD_GET,
            '/storage/usage?range=30d',
            array_merge(
                $data['headers'],
                $data['consoleHeaders']
            )
        );

        $res = $res['body'];
        $this->assertEquals($storageTotal, $res['filesStorage'][array_key_last($res['filesStorage'])]['value']);
        $this->validateDates($res['filesStorage']);
        $this->assertEquals($bucketsCount, $res['bucketsCount'][array_key_last($res['bucketsCount'])]['value']);
        $this->validateDates($res['bucketsCount']);
        $this->assertEquals($filesCount, $res['filesCount'][array_key_last($res['filesCount'])]['value']);
        $this->validateDates($res['filesCount']);

        $res = $this->client->call(
            Client::METHOD_GET,
            '/storage/' . $bucketId . '/usage?range=30d',
            array_merge(
                $data['headers'],
                $data['consoleHeaders']
            )
        );

        $res = $res['body'];
        $this->assertEquals($storageTotal, $res['filesStorage'][array_key_last($res['filesStorage'])]['value']);
        $this->assertEquals($filesCount, $res['filesCount'][array_key_last($res['filesCount'])]['value']);

        $data['requestsCount'] = $requestsCount;

        return $data;
    }

    /** @depends testStorageStats */
    public function testPrepareDatabaseStats(array $data): array
    {
        $headers = $data['headers'];

        $requestsCount = $data['requestsCount'];
        $databasesCount = 0;
        $collectionsCount = 0;
        $documentsCount = 0;

        for ($i = 0; $i < self::CREATE; $i++) {
            $name = uniqid() . ' database';
            $res = $this->client->call(
                Client::METHOD_POST,
                '/databases',
                array_merge($headers, ['content-type' => 'multipart/form-data']),
                [
                'databaseId' => 'unique()',
                'name' => $name,
                ]
            );


            $this->assertEquals($name, $res['body']['name']);
            $this->assertNotEmpty($res['body']['$id']);
            $databaseId = $res['body']['$id'];

            $requestsCount++;
            $databasesCount++;

            if ($i < (self::CREATE / 2)) {
                $res = $this->client->call(
                    Client::METHOD_DELETE,
                    '/databases/' . $databaseId,
                    $headers
                );
                $this->assertEmpty($res['body']);

                $databasesCount--;
                $requestsCount++;
            }
        }

        for ($i = 0; $i < self::CREATE; $i++) {
            $name = uniqid() . ' collection';
            $res = $this->client->call(
                Client::METHOD_POST,
                '/databases/' . $databaseId . '/collections',
                array_merge($headers, ['content-type' => 'multipart/form-data']),
                [
                'collectionId' => 'unique()',
                'name' => $name,
                'documentSecurity' => false,
                'permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                ]
            );

            $this->assertEquals($name, $res['body']['name']);
            $this->assertNotEmpty($res['body']['$id']);
            $collectionId = $res['body']['$id'];

            $requestsCount++;
            $collectionsCount++;

            if ($i < (self::CREATE / 2)) {
                $res = $this->client->call(
                    Client::METHOD_DELETE,
                    '/databases/' . $databaseId . '/collections/' . $collectionId,
                    $headers
                );
                $this->assertEmpty($res['body']);
                $collectionsCount--;
                $requestsCount++;
            }
        }

        $res = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes' . '/string',
            array_merge($headers, ['content-type' => 'multipart/form-data']),
            [
            'key' => 'name',
            'size' => 255,
            'required' => true,
            ]
        );

        $this->assertEquals('name', $res['body']['key']);
        $requestsCount++;

        sleep(self::WAIT);

        for ($i = 0; $i < self::CREATE; $i++) {
            $name = uniqid() . ' collection';
            $res = $this->client->call(
                Client::METHOD_POST,
                '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents',
                array_merge($headers, ['content-type' => 'multipart/form-data']),
                [
                'documentId' => 'unique()',
                'data' => ['name' => $name]
                ]
            );
            $this->assertEquals($name, $res['body']['name']);
            $this->assertNotEmpty($res['body']['$id']);
            $documentId = $res['body']['$id'];

            $requestsCount++;
            $documentsCount++;

            if ($i < (self::CREATE / 2)) {
                $res = $this->client->call(
                    Client::METHOD_DELETE,
                    '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId,
                    $headers
                );
                $this->assertEmpty($res['body']);
                $documentsCount--;
                $requestsCount++;
            }
        }

        return array_merge($data, [
            'databaseId' => $databaseId,
            'collectionId' => $collectionId,
            'requestsCount' => $requestsCount,
            'databasesCount' => $databasesCount,
            'collectionsCount' => $collectionsCount,
            'documentsCount' => $documentsCount,
        ]);
    }

    /** @depends testPrepareDatabaseStats */

    public function testDatabaseStats(array $data): array
    {

        $projectId = $data['projectId'];
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];
        $requestsCount = $data['requestsCount'];
        $databasesCount = $data['databasesCount'];
        $collectionsCount = $data['collectionsCount'];
        $documentsCount = $data['documentsCount'];

        sleep(self::WAIT);

        $res = $this->client->call(
            Client::METHOD_GET,
            '/project/usage?range=30d',
            $data['consoleHeaders']
        );
        $res = $res['body'];

        $this->assertEquals(9, count($res));
        $this->assertEquals(30, count($res['requests']));
        $this->assertEquals(30, count($res['storage']));
        $this->assertEquals($requestsCount, $res['requests'][array_key_last($res['requests'])]['value']);
        $this->validateDates($res['requests']);
        $this->assertEquals($databasesCount, $res['databases'][array_key_last($res['databases'])]['value']);
        $this->validateDates($res['databases']);
        $this->assertEquals($documentsCount, $res['documents'][array_key_last($res['documents'])]['value']);
        $this->validateDates($res['documents']);

        $res = $this->client->call(
            Client::METHOD_GET,
            '/databases/usage?range=30d',
            $data['consoleHeaders']
        );
        $res = $res['body'];

        $this->assertEquals($databasesCount, $res['databasesCount'][array_key_last($res['databasesCount'])]['value']);
        $this->validateDates($res['databasesCount']);
        $this->assertEquals($collectionsCount, $res['collectionsCount'][array_key_last($res['collectionsCount'])]['value']);
        $this->validateDates($res['collectionsCount']);
        $this->assertEquals($documentsCount, $res['documentsCount'][array_key_last($res['documentsCount'])]['value']);
        $this->validateDates($res['documentsCount']);

        $res = $this->client->call(
            Client::METHOD_GET,
            '/databases/' . $databaseId . '/usage?range=30d',
            $data['consoleHeaders']
        );
        $res = $res['body'];

        $this->assertEquals($collectionsCount, $res['collectionsCount'][array_key_last($res['collectionsCount'])]['value']);
        $this->validateDates($res['collectionsCount']);

        $this->assertEquals($documentsCount, $res['documentsCount'][array_key_last($res['documentsCount'])]['value']);
        $this->validateDates($res['documentsCount']);

        $res = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/usage?range=30d', $data['consoleHeaders']);
        $res = $res['body'];

        $this->assertEquals($documentsCount, $res['documentsCount'][array_key_last($res['documentsCount'])]['value']);
        $this->validateDates($res['documentsCount']);

        $data['requestsCount'] = $requestsCount;

        return $data;
    }


    /** @depends testDatabaseStats */
    public function testPrepareFunctionsStats(array $data): array
    {
        $headers = $data['headers'];
        $executionTime = 0;
        $executions = 0;
        $failures = 0;

        $response1 = $this->client->call(
            Client::METHOD_POST,
            '/functions',
            $headers,
            [
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
            ]
        );

        $functionId = $response1['body']['$id'] ?? '';

        $this->assertEquals(201, $response1['headers']['status-code']);
        $this->assertNotEmpty($response1['body']['$id']);

        $code = realpath(__DIR__ . '/../../resources/functions') . "/php/code.tar.gz";
        $this->packageCode('php');

        $deployment = $this->client->call(
            Client::METHOD_POST,
            '/functions/' . $functionId . '/deployments',
            array_merge($headers, ['content-type' => 'multipart/form-data',]),
            [
            'entrypoint' => 'index.php',
            'code' => new CURLFile($code, 'application/x-gzip', \basename($code)),
            'activate' => true
            ]
        );

        $deploymentId = $deployment['body']['$id'] ?? '';

        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);
        $this->assertEquals(true, DateTime::isValid($deployment['body']['$createdAt']));
        $this->assertEquals('index.php', $deployment['body']['entrypoint']);

        // Wait for deployment to build.
        sleep(self::WAIT + 20);

        $response = $this->client->call(
            Client::METHOD_PATCH,
            '/functions/' . $functionId . '/deployments/' . $deploymentId,
            $headers
        );

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals(true, DateTime::isValid($response['body']['$createdAt']));
        $this->assertEquals(true, DateTime::isValid($response['body']['$updatedAt']));
        $this->assertEquals($deploymentId, $response['body']['deployment']);

        $execution = $this->client->call(
            Client::METHOD_POST,
            '/functions/' . $functionId . '/executions',
            $headers,
            [
            'async' => false,
            ]
        );

        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertNotEmpty($execution['body']['$id']);
        $this->assertEquals($functionId, $execution['body']['functionId']);

        $executionTime += (int) ($execution['body']['duration'] * 1000);

        if ($execution['body']['status'] == 'failed') {
            $failures++;
        } elseif ($execution['body']['status'] == 'completed') {
            $executions++;
        }

        $execution = $this->client->call(
            Client::METHOD_POST,
            '/functions/' . $functionId . '/executions',
            $headers,
            [
            'async' => false,
            ]
        );

        $this->assertEquals(201, $execution['headers']['status-code']);
        $this->assertNotEmpty($execution['body']['$id']);
        $this->assertEquals($functionId, $execution['body']['functionId']);
        if ($execution['body']['status'] == 'failed') {
            $failures++;
        } elseif ($execution['body']['status'] == 'completed') {
            $executions++;
        }
        $executionTime += (int) ($execution['body']['duration'] * 1000);

        $execution = $this->client->call(
            Client::METHOD_POST,
            '/functions/' . $functionId . '/executions',
            $headers,
            [
            'async' => true,
            ]
        );

        $this->assertEquals(202, $execution['headers']['status-code']);
        $this->assertNotEmpty($execution['body']['$id']);
        $this->assertEquals($functionId, $execution['body']['functionId']);

        sleep(self::WAIT);

        $execution = $this->client->call(
            Client::METHOD_GET,
            '/functions/' . $functionId . '/executions/' . $execution['body']['$id'],
            $headers
        );

        if ($execution['body']['status'] == 'failed') {
            $failures++;
        } elseif ($execution['body']['status'] == 'completed') {
            $executions++;
        }

        $executionTime += (int) ($execution['body']['duration'] * 1000);

        return array_merge($data, [
            'functionId' => $functionId,
            'executionTime' => $executionTime,
            'executions' => $executions,
            'failures' => $failures,
        ]);
    }

    /** @depends testPrepareFunctionsStats */
    public function testFunctionsStats(array $data): void
    {
        $functionId = $data['functionId'];
        $executionTime = $data['executionTime'];
        $executions = $data['executions'];

        sleep(self::WAIT);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/functions/' . $functionId . '/usage?range=30d',
            $data['consoleHeaders']
        );

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(8, count($response['body']));
        $this->assertEquals('30d', $response['body']['range']);
        $this->assertIsArray($response['body']['deployments']);
        $this->assertIsArray($response['body']['deploymentsStorage']);
        $this->assertIsArray($response['body']['builds']);
        $this->assertIsArray($response['body']['buildsCompute']);
        $this->assertIsArray($response['body']['executions']);
        $this->assertIsArray($response['body']['executionsCompute']);

        $response = $response['body'];

        $this->assertEquals($executions, $response['executions'][array_key_last($response['executions'])]['value']);
        $this->validateDates($response['executions']);

        $this->assertEquals($executionTime, $response['executionsCompute'][array_key_last($response['executionsCompute'])]['value']);
        $this->validateDates($response['executionsCompute']);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/functions/usage?range=30d',
            $data['consoleHeaders']
        );

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(9, count($response['body']));
        $this->assertEquals($response['body']['range'], '30d');
        $this->assertIsArray($response['body']['functions']);
        $this->assertIsArray($response['body']['deployments']);
        $this->assertIsArray($response['body']['deploymentsStorage']);
        $this->assertIsArray($response['body']['builds']);
        $this->assertIsArray($response['body']['buildsCompute']);
        $this->assertIsArray($response['body']['executions']);
        $this->assertIsArray($response['body']['executionsCompute']);

        $response = $response['body'];

        $this->assertEquals($executions, $response['executions'][array_key_last($response['executions'])]['value']);
        $this->validateDates($response['executions']);
        $this->assertEquals($executionTime, $response['executionsCompute'][array_key_last($response['executionsCompute'])]['value']);
        $this->validateDates($response['executionsCompute']);
        $this->assertGreaterThan(0, $response['buildsCompute'][array_key_last($response['buildsCompute'])]['value']);
        $this->validateDates($response['buildsCompute']);
    }

    public function tearDown(): void
    {
        $this->usersCount = 0;
        $this->requestsCount = 0;
        $projectId = '';
        $headers = [];
    }
}
