<?php

namespace Tests\E2E\General;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use CURLFile;
use Tests\E2E\Services\Functions\FunctionsBase;
use Utopia\Database\DateTime;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

class UsageTest extends Scope
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

        $usersTotal    = 0;
        $requestsTotal = 0;
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
            $usersTotal++;
            $requestsTotal++;

            if ($i < (self::CREATE / 2)) {
                $userId = $res['body']['$id'];
                $res = $this->client->call(Client::METHOD_DELETE, '/users/' . $userId, $headers);
                $this->assertEmpty($res['body']);
                $requestsTotal++;
                $usersTotal--;
            }
        }

        return [
            'projectId'     => $projectId,
            'headers'       => $headers,
            'usersTotal'    => $usersTotal,
            'requestsTotal' => $requestsTotal
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
        $usersTotal    = $data['usersTotal'];
        $requestsTotal = $data['requestsTotal'];

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
        $this->assertEquals(24, count($res['requestsTotal']));
        $this->assertEquals(24, count($res['usersTotal']));
        $this->assertEquals($usersTotal, $res['usersTotal'][array_key_last($res['usersTotal'])]['value']);
        $this->validateDates($res['usersTotal']);
        $this->assertEquals($requestsTotal, $res['requestsTotal'][array_key_last($res['requestsTotal'])]['value']);
        $this->validateDates($res['requestsTotal']);

        $res = $this->client->call(
            Client::METHOD_GET,
            '/users/usage?range=90d',
            $consoleHeaders
        );

        $res = $res['body'];
        $this->assertEquals('90d', $res['range']);
        $this->assertEquals(90, count($res['usersTotal']));
        $this->assertEquals(90, count($res['sessionsTotal']));
        $this->assertEquals((self::CREATE / 2), $res['usersTotal'][array_key_last($res['usersTotal'])]['value']);

        return [
            'projectId' => $projectId,
            'headers' => $headers,
            'consoleHeaders' => $consoleHeaders,
            'requestsTotal' => $requestsTotal,
        ];
    }

    /** @depends testUsersStats */
    public function testPrepareStorageStats(array $data): array
    {
        $headers = $data['headers'];
        $bucketsTotal = 0;
        $requestsTotal = $data['requestsTotal'];
        $storageTotal = 0;
        $filesTotal = 0;


        for ($i = 0; $i < self::CREATE; $i++) {
            $name = uniqid() . ' bucket';
            $res = $this->client->call(
                Client::METHOD_POST,
                '/storage/buckets',
                $headers,
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
            $bucketsTotal++;
            $requestsTotal++;

            if ($i < (self::CREATE / 2)) {
                $res = $this->client->call(
                    Client::METHOD_DELETE,
                    '/storage/buckets/' . $bucketId,
                    $headers
                );
                $this->assertEmpty($res['body']);
                $requestsTotal++;
                $bucketsTotal--;
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
            $filesTotal++;
            $requestsTotal++;

            $fileId = $res['body']['$id'];
            if ($i < (self::CREATE / 2)) {
                $res = $this->client->call(
                    Client::METHOD_DELETE,
                    '/storage/buckets/' . $bucketId . '/files/' . $fileId,
                    $headers
                );
                $this->assertEmpty($res['body']);
                $requestsTotal++;
                $filesTotal--;
                $storageTotal -=  $fileSize;
            }
        }

        return array_merge($data, [
            'bucketId' => $bucketId,
            'bucketsTotal' => $bucketsTotal,
            'requestsTotal' => $requestsTotal,
            'storageTotal' => $storageTotal,
            'filesTotal' => $filesTotal,
        ]);
    }

    /**
     * @depends testPrepareStorageStats
     */
    public function testStorageStats(array $data): array
    {
        $bucketId      = $data['bucketId'];
        $bucketsTotal  = $data['bucketsTotal'];
        $requestsTotal = $data['requestsTotal'];
        $storageTotal  = $data['storageTotal'];
        $filesTotal    = $data['filesTotal'];

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
        $this->assertEquals(30, count($res['requestsTotal']));
        $this->assertEquals(30, count($res['filesStorage']));
        $this->assertEquals($requestsTotal, $res['requestsTotal'][array_key_last($res['requestsTotal'])]['value']);
        $this->validateDates($res['requestsTotal']);
        $this->assertEquals($storageTotal, $res['filesStorage'][array_key_last($res['filesStorage'])]['value']);
        $this->validateDates($res['filesStorage']);

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
        $this->assertEquals($bucketsTotal, $res['bucketsTotal'][array_key_last($res['bucketsTotal'])]['value']);
        $this->validateDates($res['bucketsTotal']);
        $this->assertEquals($filesTotal, $res['filesTotal'][array_key_last($res['filesTotal'])]['value']);
        $this->validateDates($res['filesTotal']);

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
        $this->assertEquals($filesTotal, $res['filesTotal'][array_key_last($res['filesTotal'])]['value']);

        $data['requestsTotal'] = $requestsTotal;

        return $data;
    }

    /** @depends testStorageStats */
    public function testPrepareDatabaseStats(array $data): array
    {
        $headers = $data['headers'];

        $requestsTotal = $data['requestsTotal'];
        $databasesTotal = 0;
        $collectionsTotal = 0;
        $documentsTotal = 0;

        for ($i = 0; $i < self::CREATE; $i++) {
            $name = uniqid() . ' database';
            $res = $this->client->call(
                Client::METHOD_POST,
                '/databases',
                $headers,
                [
                    'databaseId' => 'unique()',
                    'name' => $name,
                ]
            );


            $this->assertEquals($name, $res['body']['name']);
            $this->assertNotEmpty($res['body']['$id']);
            $databaseId = $res['body']['$id'];

            $requestsTotal++;
            $databasesTotal++;

            if ($i < (self::CREATE / 2)) {
                $res = $this->client->call(
                    Client::METHOD_DELETE,
                    '/databases/' . $databaseId,
                    $headers
                );
                $this->assertEmpty($res['body']);

                $databasesTotal--;
                $requestsTotal++;
            }
        }

        for ($i = 0; $i < self::CREATE; $i++) {
            $name = uniqid() . ' collection';
            $res = $this->client->call(
                Client::METHOD_POST,
                '/databases/' . $databaseId . '/collections',
                $headers,
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

            $requestsTotal++;
            $collectionsTotal++;

            if ($i < (self::CREATE / 2)) {
                $res = $this->client->call(
                    Client::METHOD_DELETE,
                    '/databases/' . $databaseId . '/collections/' . $collectionId,
                    $headers
                );
                $this->assertEmpty($res['body']);
                $collectionsTotal--;
                $requestsTotal++;
            }
        }

        $res = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes' . '/string',
            $headers,
            [
                'key' => 'name',
                'size' => 255,
                'required' => true,
            ]
        );

        $this->assertEquals('name', $res['body']['key']);
        $requestsTotal++;

        sleep(self::WAIT);

        for ($i = 0; $i < self::CREATE; $i++) {
            $name = uniqid() . ' collection';
            $res = $this->client->call(
                Client::METHOD_POST,
                '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents',
                $headers,
                [
                    'documentId' => 'unique()',
                    'data' => ['name' => $name]
                ]
            );
            $this->assertEquals($name, $res['body']['name']);
            $this->assertNotEmpty($res['body']['$id']);
            $documentId = $res['body']['$id'];

            $requestsTotal++;
            $documentsTotal++;

            if ($i < (self::CREATE / 2)) {
                $res = $this->client->call(
                    Client::METHOD_DELETE,
                    '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId,
                    $headers
                );
                $this->assertEmpty($res['body']);
                $documentsTotal--;
                $requestsTotal++;
            }
        }

        return array_merge($data, [
            'databaseId' => $databaseId,
            'collectionId' => $collectionId,
            'requestsTotal' => $requestsTotal,
            'databasesTotal' => $databasesTotal,
            'collectionsTotal' => $collectionsTotal,
            'documentsTotal' => $documentsTotal,
        ]);
    }

    /** @depends testPrepareDatabaseStats */

    public function testDatabaseStats(array $data): array
    {

        $projectId = $data['projectId'];
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];
        $requestsTotal = $data['requestsTotal'];
        $databasesTotal = $data['databasesTotal'];
        $collectionsTotal = $data['collectionsTotal'];
        $documentsTotal = $data['documentsTotal'];

        sleep(self::WAIT);

        $res = $this->client->call(
            Client::METHOD_GET,
            '/project/usage?range=30d',
            $data['consoleHeaders']
        );
        $res = $res['body'];

        $this->assertEquals(9, count($res));
        $this->assertEquals(30, count($res['requestsTotal']));
        $this->assertEquals(30, count($res['filesStorage']));
        $this->assertEquals($requestsTotal, $res['requestsTotal'][array_key_last($res['requestsTotal'])]['value']);
        $this->validateDates($res['requestsTotal']);
        $this->assertEquals($databasesTotal, $res['databasesTotal'][array_key_last($res['databasesTotal'])]['value']);
        $this->validateDates($res['databasesTotal']);
        $this->assertEquals($documentsTotal, $res['documentsTotal'][array_key_last($res['documentsTotal'])]['value']);
        $this->validateDates($res['documentsTotal']);

        $res = $this->client->call(
            Client::METHOD_GET,
            '/databases/usage?range=30d',
            $data['consoleHeaders']
        );
        $res = $res['body'];

        $this->assertEquals($databasesTotal, $res['databasesTotal'][array_key_last($res['databasesTotal'])]['value']);
        $this->validateDates($res['databasesTotal']);
        $this->assertEquals($collectionsTotal, $res['collectionsTotal'][array_key_last($res['collectionsTotal'])]['value']);
        $this->validateDates($res['collectionsTotal']);
        $this->assertEquals($documentsTotal, $res['documentsTotal'][array_key_last($res['documentsTotal'])]['value']);
        $this->validateDates($res['documentsTotal']);

        $res = $this->client->call(
            Client::METHOD_GET,
            '/databases/' . $databaseId . '/usage?range=30d',
            $data['consoleHeaders']
        );
        $res = $res['body'];

        $this->assertEquals($collectionsTotal, $res['collectionsTotal'][array_key_last($res['collectionsTotal'])]['value']);
        $this->validateDates($res['collectionsTotal']);

        $this->assertEquals($documentsTotal, $res['documentsTotal'][array_key_last($res['documentsTotal'])]['value']);
        $this->validateDates($res['documentsTotal']);

        $res = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId . '/usage?range=30d', $data['consoleHeaders']);
        $res = $res['body'];

        $this->assertEquals($documentsTotal, $res['documentsTotal'][array_key_last($res['documentsTotal'])]['value']);
        $this->validateDates($res['documentsTotal']);

        $data['requestsTotal'] = $requestsTotal;

        return $data;
    }


    /** @depends testDatabaseStats */
    public function testPrepareFunctionsStats(array $data): array
    {
        $dateValidator = new DatetimeValidator();
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
        $this->assertEquals(true, (new DatetimeValidator())->isValid($deployment['body']['$createdAt']));
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

        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['$createdAt']));
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['$updatedAt']));
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
        $this->assertIsArray($response['body']['deploymentsTotal']);
        $this->assertIsArray($response['body']['deploymentsStorage']);
        $this->assertIsArray($response['body']['buildsTotal']);
        $this->assertIsArray($response['body']['buildsTime']);
        $this->assertIsArray($response['body']['executionsTotal']);
        $this->assertIsArray($response['body']['executionsTime']);

        $response = $response['body'];

        $this->assertEquals($executions, $response['executionsTotal'][array_key_last($response['executionsTotal'])]['value']);
        $this->validateDates($response['executionsTotal']);
        $this->assertEquals($executionTime, $response['executionsTime'][array_key_last($response['executionsTime'])]['value']);
        $this->validateDates($response['executionsTime']);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/functions/usage?range=30d',
            $data['consoleHeaders']
        );

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(9, count($response['body']));
        $this->assertEquals($response['body']['range'], '30d');
        $this->assertIsArray($response['body']['functionsTotal']);
        $this->assertIsArray($response['body']['deploymentsTotal']);
        $this->assertIsArray($response['body']['deploymentsStorage']);
        $this->assertIsArray($response['body']['buildsTotal']);
        $this->assertIsArray($response['body']['buildsTime']);
        $this->assertIsArray($response['body']['executionsTotal']);
        $this->assertIsArray($response['body']['executionsTime']);

        $response = $response['body'];

        $this->assertEquals($executions, $response['executionsTotal'][array_key_last($response['executionsTotal'])]['value']);
        $this->validateDates($response['executionsTotal']);
        $this->assertEquals($executionTime, $response['executionsTime'][array_key_last($response['executionsTime'])]['value']);
        $this->validateDates($response['executionsTime']);
        $this->assertGreaterThan(0, $response['buildsTime'][array_key_last($response['buildsTime'])]['value']);
        $this->validateDates($response['buildsTime']);
    }

    public function tearDown(): void
    {
        $this->projectId = '';
    }
}
