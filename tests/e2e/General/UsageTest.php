<?php

namespace Tests\E2E\General;

use Appwrite\Tests\Retry;
use CURLFile;
use DateTime;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Services\Functions\FunctionsBase;
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

    protected function getConsoleHeaders(): array
    {
        return [
            'origin' => 'http://localhost',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-mode' => 'admin',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ];
    }

    protected function validateDates(array $metrics): void
    {
        foreach ($metrics as $metric) {
            $this->assertIsObject(\DateTime::createFromFormat("Y-m-d\TH:i:s.vP", $metric['date']));
        }
    }

    public static function getToday(): string
    {
        $date = new DateTime();
        return $date->format(self::$formatTz);
    }

    public static function getTomorrow(): string
    {
        $date = new DateTime();
        $date->modify('+1 day');
        return $date->format(self::$formatTz);
    }

    public function testPrepareUsersStats(): array
    {
        $usersTotal = 0;
        $requestsTotal = 0;

        for ($i = 0; $i < self::CREATE; $i++) {
            $params = [
                'userId' => 'unique()',
                'email' => uniqid() . 'user@usage.test',
                'password' => 'password',
                'name' => uniqid() . 'User',
            ];

            $response = $this->client->call(
                Client::METHOD_POST,
                '/users',
                array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id']
                ], $this->getHeaders()),
                $params
            );

            $this->assertEquals(201, $response['headers']['status-code']);
            $this->assertEquals($params['email'], $response['body']['email']);
            $this->assertNotEmpty($response['body']['$id']);

            $usersTotal += 1;
            $requestsTotal += 1;

            if ($i < (self::CREATE / 2)) {
                $userId = $response['body']['$id'];

                $response = $this->client->call(
                    Client::METHOD_DELETE,
                    '/users/' . $userId,
                    array_merge([
                        'content-type' => 'application/json',
                        'x-appwrite-project' => $this->getProject()['$id']
                    ], $this->getHeaders())
                );

                $this->assertEquals(204, $response['headers']['status-code']);
                $this->assertEmpty($response['body']);

                $requestsTotal += 1;
                $usersTotal -= 1;
            }
        }

        return [
            'usersTotal' => $usersTotal,
            'requestsTotal' => $requestsTotal
        ];
    }

    /**
     * @depends testPrepareUsersStats
     */
    #[Retry(count: 1)]
    public function testUsersStats(array $data): array
    {
        sleep(self::WAIT);

        $requestsTotal = $data['requestsTotal'];

        $response = $this->client->call(
            Client::METHOD_GET,
            '/project/usage',
            $this->getConsoleHeaders(),
            [
                'period' => '1h',
                'startDate' => self::getToday(),
                'endDate' => self::getTomorrow(),
            ]
        );

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(12, count($response['body']));
        $this->validateDates($response['body']['network']);
        $this->validateDates($response['body']['requests']);
        $this->validateDates($response['body']['users']);
        $this->assertArrayHasKey('executionsBreakdown', $response['body']);
        $this->assertArrayHasKey('bucketsBreakdown', $response['body']);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/users/usage?range=90d',
            $this->getConsoleHeaders()
        );

        $this->assertEquals('90d', $response['body']['range']);
        $this->assertEquals(90, count($response['body']['users']));
        $this->assertEquals(90, count($response['body']['sessions']));
        $this->assertEquals((self::CREATE / 2), $response['body']['users'][array_key_last($response['body']['users'])]['value']);

        return array_merge($data, [
            'requestsTotal' => $requestsTotal
        ]);
    }

    /** @depends testUsersStats */
    public function testPrepareStorageStats(array $data): array
    {
        $requestsTotal = $data['requestsTotal'];

        $bucketsTotal = 0;
        $storageTotal = 0;
        $filesTotal = 0;

        for ($i = 0; $i < self::CREATE; $i++) {
            $name = uniqid() . ' bucket';

            $response = $this->client->call(
                Client::METHOD_POST,
                '/storage/buckets',
                array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id']
                ], $this->getHeaders()),
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

            $this->assertEquals(201, $response['headers']['status-code']);
            $this->assertEquals($name, $response['body']['name']);
            $this->assertNotEmpty($response['body']['$id']);

            $bucketsTotal += 1;
            $requestsTotal += 1;

            $bucketId = $response['body']['$id'];

            if ($i < (self::CREATE / 2)) {
                $response = $this->client->call(
                    Client::METHOD_DELETE,
                    '/storage/buckets/' . $bucketId,
                    array_merge([
                        'content-type' => 'application/json',
                        'x-appwrite-project' => $this->getProject()['$id']
                    ], $this->getHeaders()),
                );

                $this->assertEquals(204, $response['headers']['status-code']);
                $this->assertEmpty($response['body']);

                $requestsTotal += 1;
                $bucketsTotal -= 1;
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

            $response = $this->client->call(
                Client::METHOD_POST,
                '/storage/buckets/' . $bucketId . '/files',
                array_merge([
                    'content-type' => 'multipart/form-data',
                    'x-appwrite-project' => $this->getProject()['$id']
                ], $this->getHeaders()),
                [
                    'fileId' => 'unique()',
                    'file' => new CURLFile($file['path'], '', $file['name']),
                ]
            );

            $this->assertEquals(201, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['$id']);

            $fileSize = $response['body']['sizeOriginal'];

            $storageTotal += $fileSize;
            $filesTotal += 1;
            $requestsTotal += 1;

            $fileId = $response['body']['$id'];

            if ($i < (self::CREATE / 2)) {
                $response = $this->client->call(
                    Client::METHOD_DELETE,
                    '/storage/buckets/' . $bucketId . '/files/' . $fileId,
                    array_merge([
                        'x-appwrite-project' => $this->getProject()['$id']
                    ], $this->getHeaders()),
                );

                $this->assertEquals(204, $response['headers']['status-code']);
                $this->assertEmpty($response['body']);

                $requestsTotal += 1;
                $filesTotal -= 1;
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
    #[Retry(count: 1)]
    public function testStorageStats(array $data): array
    {
        $bucketId      = $data['bucketId'];
        $bucketsTotal  = $data['bucketsTotal'];
        $requestsTotal = $data['requestsTotal'];
        $storageTotal  = $data['storageTotal'];
        $filesTotal    = $data['filesTotal'];

        sleep(self::WAIT);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/project/usage',
            $this->getConsoleHeaders(),
            [
                'period' => '1d',
                'startDate' => self::getToday(),
                'endDate' => self::getTomorrow(),
            ]
        );

        $this->assertEquals(12, count($response['body']));
        $this->assertEquals(1, count($response['body']['requests']));
        $this->assertEquals($requestsTotal, $response['body']['requests'][array_key_last($response['body']['requests'])]['value']);
        $this->validateDates($response['body']['requests']);
        $this->assertEquals($storageTotal, $response['body']['filesStorageTotal']);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/storage/usage?range=30d',
            $this->getConsoleHeaders()
        );

        $this->assertEquals($storageTotal, $response['body']['storage'][array_key_last($response['body']['storage'])]['value']);
        $this->validateDates($response['body']['storage']);
        $this->assertEquals($bucketsTotal, $response['body']['buckets'][array_key_last($response['body']['buckets'])]['value']);
        $this->validateDates($response['body']['buckets']);
        $this->assertEquals($filesTotal, $response['body']['files'][array_key_last($response['body']['files'])]['value']);
        $this->validateDates($response['body']['files']);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/storage/' . $bucketId . '/usage?range=30d',
            $this->getConsoleHeaders()
        );

        $this->assertEquals($storageTotal, $response['body']['storage'][array_key_last($response['body']['storage'])]['value']);
        $this->assertEquals($filesTotal, $response['body']['files'][array_key_last($response['body']['files'])]['value']);

        return $data;
    }

    /** @depends testStorageStats */
    public function testPrepareDatabaseStats(array $data): array
    {
        $requestsTotal = $data['requestsTotal'];

        $databasesTotal = 0;
        $collectionsTotal = 0;
        $documentsTotal = 0;

        for ($i = 0; $i < self::CREATE; $i++) {
            $name = uniqid() . ' database';

            $response = $this->client->call(
                Client::METHOD_POST,
                '/databases',
                array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id']
                ], $this->getHeaders()),
                [
                    'databaseId' => 'unique()',
                    'name' => $name,
                ]
            );

            $this->assertEquals($name, $response['body']['name']);
            $this->assertNotEmpty($response['body']['$id']);

            $requestsTotal += 1;
            $databasesTotal += 1;

            $databaseId = $response['body']['$id'];

            if ($i < (self::CREATE / 2)) {
                $response = $this->client->call(
                    Client::METHOD_DELETE,
                    '/databases/' . $databaseId,
                    array_merge([
                        'x-appwrite-project' => $this->getProject()['$id']
                    ], $this->getHeaders()),
                );

                $this->assertEmpty($response['body']);

                $databasesTotal -= 1;
                $requestsTotal += 1;
            }
        }

        for ($i = 0; $i < self::CREATE; $i++) {
            $name = uniqid() . ' collection';

            $response = $this->client->call(
                Client::METHOD_POST,
                '/databases/' . $databaseId . '/collections',
                array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id']
                ], $this->getHeaders()),
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

            $this->assertEquals($name, $response['body']['name']);
            $this->assertNotEmpty($response['body']['$id']);

            $requestsTotal += 1;
            $collectionsTotal += 1;

            $collectionId = $response['body']['$id'];

            if ($i < (self::CREATE / 2)) {
                $response = $this->client->call(
                    Client::METHOD_DELETE,
                    '/databases/' . $databaseId . '/collections/' . $collectionId,
                    array_merge([
                        'x-appwrite-project' => $this->getProject()['$id']
                    ], $this->getHeaders()),
                );

                $this->assertEmpty($response['body']);

                $collectionsTotal -= 1;
                $requestsTotal += 1;
            }
        }

        $response = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes' . '/string',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id']
            ], $this->getHeaders()),
            [
                'key' => 'name',
                'size' => 255,
                'required' => true,
            ]
        );

        $this->assertEquals('name', $response['body']['key']);

        $requestsTotal += 1;

        sleep(self::WAIT);

        for ($i = 0; $i < self::CREATE; $i++) {
            $name = uniqid() . ' collection';

            $response = $this->client->call(
                Client::METHOD_POST,
                '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents',
                array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id']
                ], $this->getHeaders()),
                [
                    'documentId' => 'unique()',
                    'data' => ['name' => $name]
                ]
            );

            $this->assertEquals($name, $response['body']['name']);
            $this->assertNotEmpty($response['body']['$id']);

            $requestsTotal += 1;
            $documentsTotal += 1;

            $documentId = $response['body']['$id'];

            if ($i < (self::CREATE / 2)) {
                $response = $this->client->call(
                    Client::METHOD_DELETE,
                    '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId,
                    array_merge([
                        'x-appwrite-project' => $this->getProject()['$id']
                    ], $this->getHeaders()),
                );

                $this->assertEmpty($response['body']);

                $documentsTotal -= 1;
                $requestsTotal += 1;
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
    #[Retry(count: 1)]
    public function testDatabaseStats(array $data): array
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];
        $requestsTotal = $data['requestsTotal'];
        $databasesTotal = $data['databasesTotal'];
        $collectionsTotal = $data['collectionsTotal'];
        $documentsTotal = $data['documentsTotal'];

        sleep(self::WAIT);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/project/usage',
            $this->getConsoleHeaders(),
            [
                'period' => '1d',
                'startDate' => self::getToday(),
                'endDate' => self::getTomorrow(),
            ]
        );

        $this->assertEquals(12, count($response['body']));
        $this->assertEquals(1, count($response['body']['requests']));
        $this->assertEquals(1, count($response['body']['network']));
        $this->assertEquals($requestsTotal, $response['body']['requests'][array_key_last($response['body']['requests'])]['value']);
        $this->validateDates($response['body']['requests']);
        $this->assertEquals($databasesTotal, $response['body']['databasesTotal']);
        $this->assertEquals($documentsTotal, $response['body']['documentsTotal']);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/databases/usage?range=30d',
            $this->getConsoleHeaders()
        );

        $this->assertEquals($databasesTotal, $response['body']['databases'][array_key_last($response['body']['databases'])]['value']);
        $this->validateDates($response['body']['databases']);
        $this->assertEquals($collectionsTotal, $response['body']['collections'][array_key_last($response['body']['collections'])]['value']);
        $this->validateDates($response['body']['collections']);
        $this->assertEquals($documentsTotal, $response['body']['documents'][array_key_last($response['body']['documents'])]['value']);
        $this->validateDates($response['body']['documents']);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/databases/' . $databaseId . '/usage?range=30d',
            $this->getConsoleHeaders()
        );

        $this->assertEquals($collectionsTotal, $response['body']['collections'][array_key_last($response['body']['collections'])]['value']);
        $this->validateDates($response['body']['collections']);

        $this->assertEquals($documentsTotal, $response['body']['documents'][array_key_last($response['body']['documents'])]['value']);
        $this->validateDates($response['body']['documents']);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/databases/' . $databaseId . '/collections/' . $collectionId . '/usage?range=30d',
            $this->getConsoleHeaders()
        );

        $this->assertEquals($documentsTotal, $response['body']['documents'][array_key_last($response['body']['documents'])]['value']);
        $this->validateDates($response['body']['documents']);

        return $data;
    }


    /** @depends testDatabaseStats */
    public function testPrepareFunctionsStats(array $data): array
    {
        $executionTime = 0;
        $executions = 0;
        $failures = 0;

        $response = $this->client->call(
            Client::METHOD_POST,
            '/functions',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id']
            ], $this->getHeaders()),
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

        $functionId = $response['body']['$id'] ?? '';

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);

        $code = realpath(__DIR__ . '/../../resources/functions') . "/php/code.tar.gz";
        $this->packageCode('php');

        $response = $this->client->call(
            Client::METHOD_POST,
            '/functions/' . $functionId . '/deployments',
            array_merge([
                'content-type' => 'multipart/form-data',
                'x-appwrite-project' => $this->getProject()['$id']
            ], $this->getHeaders()),
            [
                'entrypoint' => 'index.php',
                'code' => new CURLFile($code, 'application/x-gzip', \basename($code)),
                'activate' => true
            ]
        );

        $deploymentId = $response['body']['$id'] ?? '';

        $this->assertEquals(202, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['$createdAt']));
        $this->assertEquals('index.php', $response['body']['entrypoint']);

        // Wait for deployment to build.
        sleep(self::WAIT + 20);

        $response = $this->client->call(
            Client::METHOD_PATCH,
            '/functions/' . $functionId . '/deployments/' . $deploymentId,
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id']
            ], $this->getHeaders()),
        );

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);

        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['$createdAt']));
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['$updatedAt']));
        $this->assertEquals($deploymentId, $response['body']['deployment']);

        $response = $this->client->call(
            Client::METHOD_POST,
            '/functions/' . $functionId . '/executions',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id']
            ], $this->getHeaders()),
            [
                'async' => false,
            ]
        );

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($functionId, $response['body']['functionId']);

        $executionTime += (int) ($response['body']['duration'] * 1000);

        if ($response['body']['status'] == 'failed') {
            $failures += 1;
        } elseif ($response['body']['status'] == 'completed') {
            $executions += 1;
        }

        $response = $this->client->call(
            Client::METHOD_POST,
            '/functions/' . $functionId . '/executions',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id']
            ], $this->getHeaders()),
            [
                'async' => false,
            ]
        );

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($functionId, $response['body']['functionId']);

        if ($response['body']['status'] == 'failed') {
            $failures += 1;
        } elseif ($response['body']['status'] == 'completed') {
            $executions += 1;
        }
        $executionTime += (int) ($response['body']['duration'] * 1000);

        $response = $this->client->call(
            Client::METHOD_POST,
            '/functions/' . $functionId . '/executions',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id']
            ], $this->getHeaders()),
            [
                'async' => true,
            ]
        );

        $this->assertEquals(202, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($functionId, $response['body']['functionId']);

        sleep(self::WAIT);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/functions/' . $functionId . '/executions/' . $response['body']['$id'],
            array_merge([
                'x-appwrite-project' => $this->getProject()['$id']
            ], $this->getHeaders()),
        );

        if ($response['body']['status'] == 'failed') {
            $failures += 1;
        } elseif ($response['body']['status'] == 'completed') {
            $executions += 1;
        }

        $executionTime += (int) ($response['body']['duration'] * 1000);

        return array_merge($data, [
            'functionId' => $functionId,
            'executionTime' => $executionTime,
            'executions' => $executions,
            'failures' => $failures,
        ]);
    }

    /** @depends testPrepareFunctionsStats */
    #[Retry(count: 1)]
    public function testFunctionsStats(array $data): void
    {
        $functionId = $data['functionId'];
        $executionTime = $data['executionTime'];
        $executions = $data['executions'];

        sleep(self::WAIT);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/functions/' . $functionId . '/usage?range=30d',
            $this->getConsoleHeaders()
        );

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(15, count($response['body']));
        $this->assertEquals('30d', $response['body']['range']);
        $this->assertIsArray($response['body']['deployments']);
        $this->assertIsArray($response['body']['deploymentsStorage']);
        $this->assertIsArray($response['body']['builds']);
        $this->assertIsArray($response['body']['buildsTime']);
        $this->assertIsArray($response['body']['executions']);
        $this->assertIsArray($response['body']['executionsTime']);
        $this->assertEquals($executions, $response['body']['executions'][array_key_last($response['body']['executions'])]['value']);
        $this->validateDates($response['body']['executions']);
        $this->assertEquals($executionTime, $response['body']['executionsTime'][array_key_last($response['body']['executionsTime'])]['value']);
        $this->validateDates($response['body']['executionsTime']);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/functions/usage?range=30d',
            $this->getConsoleHeaders()
        );

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(17, count($response['body']));
        $this->assertEquals($response['body']['range'], '30d');
        $this->assertIsArray($response['body']['functions']);
        $this->assertIsArray($response['body']['deployments']);
        $this->assertIsArray($response['body']['deploymentsStorage']);
        $this->assertIsArray($response['body']['builds']);
        $this->assertIsArray($response['body']['buildsTime']);
        $this->assertIsArray($response['body']['executions']);
        $this->assertIsArray($response['body']['executionsTime']);
        $this->assertEquals($executions, $response['body']['executions'][array_key_last($response['body']['executions'])]['value']);
        $this->validateDates($response['body']['executions']);
        $this->assertEquals($executionTime, $response['body']['executionsTime'][array_key_last($response['body']['executionsTime'])]['value']);
        $this->validateDates($response['body']['executionsTime']);
        $this->assertGreaterThan(0, $response['body']['buildsTime'][array_key_last($response['body']['buildsTime'])]['value']);
        $this->validateDates($response['body']['buildsTime']);
    }

    public function tearDown(): void
    {
        $this->projectId = '';
    }
}
