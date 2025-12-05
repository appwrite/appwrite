<?php

namespace Tests\E2E\General;

use Appwrite\Platform\Modules\Compute\Specification;
use CURLFile;
use DateTime;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Services\Functions\FunctionsBase;
use Tests\E2E\Services\Sites\SitesBase;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\System\System;

class UsageTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use FunctionsBase;
    use SitesBase {
        FunctionsBase::createDeployment insteadof SitesBase;
        FunctionsBase::setupDeployment insteadof SitesBase;
        FunctionsBase::createVariable insteadof SitesBase;
        FunctionsBase::getVariable insteadof SitesBase;
        FunctionsBase::listVariables insteadof SitesBase;
        FunctionsBase::helperGetLatestCommit insteadof SitesBase;
        FunctionsBase::updateVariable insteadof SitesBase;
        FunctionsBase::deleteVariable insteadof SitesBase;
        FunctionsBase::getDeployment insteadof SitesBase;
        FunctionsBase::listDeployments insteadof SitesBase;
        FunctionsBase::deleteDeployment insteadof SitesBase;
        FunctionsBase::setupDuplicateDeployment insteadof SitesBase;
        FunctionsBase::createDuplicateDeployment insteadof SitesBase;
        FunctionsBase::createTemplateDeployment insteadof SitesBase;
        FunctionsBase::getUsage insteadof SitesBase;
        FunctionsBase::getTemplate insteadof SitesBase;
        FunctionsBase::getDeploymentDownload insteadof SitesBase;
        FunctionsBase::cancelDeployment insteadof SitesBase;
        FunctionsBase::listSpecifications insteadof SitesBase;
        SitesBase::createDeployment as createDeploymentSite;
        SitesBase::setupDeployment as setupDeploymentSite;
        SitesBase::createVariable as createVariableSite;
        SitesBase::getVariable as getVariableSite;
        SitesBase::listVariables as listVariablesSite;
        SitesBase::listVariables as listVariablesSite;
        SitesBase::updateVariable as updateVariableSite;
        SitesBase::updateVariable as updateVariableSite;
        SitesBase::deleteVariable as deleteVariableSite;
        SitesBase::deleteVariable as deleteVariableSite;
        SitesBase::getDeployment as getDeploymentSite;
        SitesBase::getDeployment as getDeploymentSite;
        SitesBase::listDeployments as listDeploymentsSite;
        SitesBase::listDeployments as listDeploymentsSite;
        SitesBase::deleteDeployment as deleteDeploymentSite;
        SitesBase::deleteDeployment as deleteDeploymentSite;
        SitesBase::setupDuplicateDeployment as setupDuplicateDeploymentSite;
        SitesBase::setupDuplicateDeployment as setupDuplicateDeploymentSite;
        SitesBase::createDuplicateDeployment as createDuplicateDeploymentSite;
        SitesBase::createDuplicateDeployment as createDuplicateDeploymentSite;
        SitesBase::createTemplateDeployment as createTemplateDeploymentSite;
        SitesBase::createTemplateDeployment as createTemplateDeploymentSite;
        SitesBase::getUsage as getUsageSite;
        SitesBase::getUsage as getUsageSite;
        SitesBase::getTemplate as getTemplateSite;
        SitesBase::getTemplate as getTemplateSite;
        SitesBase::getDeploymentDownload as getDeploymentDownloadSite;
        SitesBase::getDeploymentDownload as getDeploymentDownloadSite;
        SitesBase::cancelDeployment as cancelDeploymentSite;
        SitesBase::cancelDeployment as cancelDeploymentSite;
        SitesBase::listSpecifications as listSpecificationsSite;
    }

    private const WAIT = 5;
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

    public static function getYesterday(): string
    {
        $date = new DateTime();
        $date->modify('-1 day');
        return $date->format(self::$formatTz);
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
    public function testUsersStats(array $data): array
    {
        $requestsTotal = $data['requestsTotal'];

        $this->assertEventually(function () {
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
            $this->assertGreaterThanOrEqual(31, count($response['body']));
            $this->validateDates($response['body']['network']);
            $this->validateDates($response['body']['requests']);
            $this->validateDates($response['body']['users']);
            $this->assertArrayHasKey('executionsBreakdown', $response['body']);
            $this->assertArrayHasKey('bucketsBreakdown', $response['body']);
        });

        $this->assertEventually(function () {
            $response = $this->client->call(
                Client::METHOD_GET,
                '/users/usage?range=90d',
                $this->getConsoleHeaders()
            );

            $this->assertEquals('90d', $response['body']['range']);
            $this->assertEquals(90, count($response['body']['users']));
            $this->assertEquals(90, count($response['body']['sessions']));
            $this->assertEquals((self::CREATE / 2), $response['body']['users'][array_key_last($response['body']['users'])]['value']);
        });

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
    public function testStorageStats(array $data): array
    {
        $bucketId      = $data['bucketId'];
        $bucketsTotal  = $data['bucketsTotal'];
        $requestsTotal = $data['requestsTotal'];
        $storageTotal  = $data['storageTotal'];
        $filesTotal    = $data['filesTotal'];

        $this->assertEventually(function () use ($requestsTotal, $storageTotal) {
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

            $this->assertGreaterThanOrEqual(31, count($response['body']));
            $this->assertEquals(1, count($response['body']['requests']));
            $this->assertEquals($requestsTotal, $response['body']['requests'][array_key_last($response['body']['requests'])]['value']);
            $this->validateDates($response['body']['requests']);
            $this->assertEquals($storageTotal, $response['body']['filesStorageTotal']);
        });

        $this->assertEventually(function () use ($bucketsTotal, $filesTotal, $storageTotal) {
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
        });

        $this->assertEventually(function () use ($bucketId, $storageTotal, $filesTotal) {
            $response = $this->client->call(
                Client::METHOD_GET,
                '/storage/' . $bucketId . '/usage?range=30d',
                $this->getConsoleHeaders()
            );

            $this->assertEquals($storageTotal, $response['body']['storage'][array_key_last($response['body']['storage'])]['value']);
            $this->assertEquals($filesTotal, $response['body']['files'][array_key_last($response['body']['files'])]['value']);
        });

        return $data;
    }

    /** @depends testStorageStats */
    public function testPrepareDatabaseStatsCollectionsAPI(array $data): array
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

        sleep(self::WAIT);

        $requestsTotal += 1;

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

    /** @depends testPrepareDatabaseStatsCollectionsAPI */
    public function testDatabaseStatsCollectionsAPI(array $data): array
    {
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];
        $requestsTotal = $data['requestsTotal'];
        $databasesTotal = $data['databasesTotal'];
        $collectionsTotal = $data['collectionsTotal'];
        $documentsTotal = $data['documentsTotal'];

        $this->assertEventually(function () use ($requestsTotal, $databasesTotal, $documentsTotal) {
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

            $this->assertGreaterThanOrEqual(31, count($response['body']));
            $this->assertEquals(1, count($response['body']['requests']));
            $this->assertEquals(1, count($response['body']['network']));
            $this->assertEquals($requestsTotal, $response['body']['requests'][array_key_last($response['body']['requests'])]['value']);
            $this->validateDates($response['body']['requests']);
            $this->assertEquals($databasesTotal, $response['body']['databasesTotal']);
            $this->assertEquals($documentsTotal, $response['body']['documentsTotal']);
        });

        $this->assertEventually(function () use ($collectionsTotal, $databasesTotal, $documentsTotal) {
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
        });

        $this->assertEventually(function () use ($databaseId, $collectionsTotal, $documentsTotal) {
            $response = $this->client->call(
                Client::METHOD_GET,
                '/databases/' . $databaseId . '/usage?range=30d',
                $this->getConsoleHeaders()
            );

            $this->assertEquals($collectionsTotal, $response['body']['collections'][array_key_last($response['body']['collections'])]['value']);
            $this->validateDates($response['body']['collections']);

            $this->assertEquals($documentsTotal, $response['body']['documents'][array_key_last($response['body']['documents'])]['value']);
            $this->validateDates($response['body']['documents']);
        });

        $this->assertEventually(function () use ($databaseId, $collectionId, $documentsTotal) {
            $response = $this->client->call(
                Client::METHOD_GET,
                '/databases/' . $databaseId . '/collections/' . $collectionId . '/usage?range=30d',
                $this->getConsoleHeaders()
            );

            $this->assertEquals($documentsTotal, $response['body']['documents'][array_key_last($response['body']['documents'])]['value']);
            $this->validateDates($response['body']['documents']);
        });

        return $data;
    }

    /** @depends testDatabaseStatsCollectionsAPI */
    public function testPrepareDatabaseStatsTablesAPI(array $data): array
    {
        $rowsTotal = 0;
        $tablesTotal = 0;
        $databasesTotal = $data['databasesTotal'];
        $documentsTotal = $data['documentsTotal'];
        $collectionsTotal = $data['collectionsTotal'];

        $requestsTotal = $data['requestsTotal'];

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
            $name = uniqid() . ' table';

            $response = $this->client->call(
                Client::METHOD_POST,
                '/tablesdb/' . $databaseId . '/tables',
                array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id']
                ], $this->getHeaders()),
                [
                    'tableId' => 'unique()',
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
            $tablesTotal += 1;

            $tableId = $response['body']['$id'];

            if ($i < (self::CREATE / 2)) {
                $response = $this->client->call(
                    Client::METHOD_DELETE,
                    '/tablesdb/' . $databaseId . '/tables/' . $tableId,
                    array_merge([
                        'x-appwrite-project' => $this->getProject()['$id']
                    ], $this->getHeaders()),
                );

                $this->assertEmpty($response['body']);

                $tablesTotal -= 1;
                $requestsTotal += 1;
            }
        }

        $response = $this->client->call(
            Client::METHOD_POST,
            '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns' . '/string',
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

        sleep(self::WAIT);

        $requestsTotal += 1;

        for ($i = 0; $i < self::CREATE; $i++) {
            $name = uniqid() . ' table';

            $response = $this->client->call(
                Client::METHOD_POST,
                '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows',
                array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id']
                ], $this->getHeaders()),
                [
                    'rowId' => 'unique()',
                    'data' => ['name' => $name]
                ]
            );

            $this->assertEquals($name, $response['body']['name']);
            $this->assertNotEmpty($response['body']['$id']);

            $requestsTotal += 1;
            $rowsTotal += 1;

            $rowId = $response['body']['$id'];

            if ($i < (self::CREATE / 2)) {
                $response = $this->client->call(
                    Client::METHOD_DELETE,
                    '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows/' . $rowId,
                    array_merge([
                        'x-appwrite-project' => $this->getProject()['$id']
                    ], $this->getHeaders()),
                );

                $this->assertEmpty($response['body']);

                $rowsTotal -= 1;
                $requestsTotal += 1;
            }
        }

        return array_merge($data, [
            'databaseId' => $databaseId,
            'tableId' => $tableId,
            'requestsTotal' => $requestsTotal,
            'databasesTotal' => $databasesTotal,
            'tablesTotal' => $tablesTotal,
            'rowsTotal' => $rowsTotal,

            // For clarity
            'absoluteRowsTotal' => $rowsTotal + $data['documentsTotal'],
            'absoluteTablesTotal' => $tablesTotal + $data['collectionsTotal'],
        ]);
    }

    /** @depends testPrepareDatabaseStatsTablesAPI */
    #[Retry(count: 1)]
    public function testDatabaseStatsTablesAPI(array $data): array
    {
        $tableId = $data['tableId'];
        $databaseId = $data['databaseId'];
        $requestsTotal = $data['requestsTotal'];

        $absoluteRowsTotal = $data['absoluteRowsTotal'];
        $absoluteTablesTotal = $data['absoluteTablesTotal'];

        $rowsTotal = $data['rowsTotal'];
        $tablesTotal = $data['tablesTotal'];
        $databasesTotal = $data['databasesTotal'];

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

        $this->assertGreaterThanOrEqual(31, count($response['body']));
        $this->assertCount(1, $response['body']['requests']);
        $this->assertCount(1, $response['body']['network']);
        $this->assertEquals($requestsTotal, $response['body']['requests'][array_key_last($response['body']['requests'])]['value']);
        $this->validateDates($response['body']['requests']);
        $this->assertEquals($databasesTotal, $response['body']['databasesTotal']);

        // project level includes all i.e. documents + rows total.
        $this->assertEquals($absoluteRowsTotal, $response['body']['rowsTotal']);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/databases/usage?range=30d',
            $this->getConsoleHeaders()
        );

        $this->assertEquals($databasesTotal, $response['body']['databases'][array_key_last($response['body']['databases'])]['value']);
        $this->validateDates($response['body']['databases']);

        // database level includes all i.e. collections + tables total.
        $this->assertEquals($absoluteTablesTotal, $response['body']['tables'][array_key_last($response['body']['tables'])]['value']); // database level
        $this->validateDates($response['body']['tables']);

        // database level includes all i.e. documents + rows total.
        $this->assertEquals($absoluteRowsTotal, $response['body']['rows'][array_key_last($response['body']['rows'])]['value']);
        $this->validateDates($response['body']['rows']);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/databases/' . $databaseId . '/usage?range=30d',
            $this->getConsoleHeaders()
        );

        $this->assertEquals($tablesTotal, $response['body']['tables'][array_key_last($response['body']['tables'])]['value']);
        $this->validateDates($response['body']['tables']);

        $this->assertEquals($rowsTotal, $response['body']['rows'][array_key_last($response['body']['rows'])]['value']);
        $this->validateDates($response['body']['rows']);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/usage?range=30d',
            $this->getConsoleHeaders()
        );

        $this->assertEquals($rowsTotal, $response['body']['rows'][array_key_last($response['body']['rows'])]['value']);
        $this->validateDates($response['body']['rows']);

        return $data;
    }

    /** @depends testDatabaseStatsTablesAPI */
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
                'runtime' => 'node-22',
                'entrypoint' => 'index.js',
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
                'specification' => Specification::S_8VCPU_8GB
            ]
        );

        $functionId = $response['body']['$id'] ?? '';

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);

        $deploymentId = $this->setupDeployment($functionId, [
            'code' => $this->packageFunction('basic'),
            'activate' => true,
        ]);
        $this->assertNotEmpty($deploymentId);

        $response = $this->client->call(
            Client::METHOD_PATCH,
            '/functions/' . $functionId . '/deployment',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id']
            ], $this->getHeaders()),
            [
                'deploymentId' => $deploymentId,
            ],
        );

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);

        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['$createdAt']));
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['$updatedAt']));
        $this->assertEquals($deploymentId, $response['body']['deploymentId']);

        $response = $this->client->call(
            Client::METHOD_POST,
            '/functions/' . $functionId . '/executions',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id']
            ], $this->getHeaders()),
            [
                'async' => 'false',
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
                'async' => 'false',
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
    public function testFunctionsStats(array $data): array
    {
        $functionId = $data['functionId'];
        $executionTime = $data['executionTime'];
        $executions = $data['executions'];

        $this->assertEventually(function () use ($functionId, $executions, $executionTime) {
            $response = $this->client->call(
                Client::METHOD_GET,
                '/functions/' . $functionId . '/usage?range=30d',
                $this->getConsoleHeaders()
            );

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals(24, count($response['body']));
            $this->assertEquals('30d', $response['body']['range']);
            $this->assertIsArray($response['body']['deployments']);
            $this->assertIsArray($response['body']['deploymentsStorage']);
            $this->assertIsNumeric($response['body']['deploymentsStorageTotal']);
            $this->assertIsNumeric($response['body']['buildsMbSecondsTotal']);
            $this->assertIsNumeric($response['body']['executionsMbSecondsTotal']);
            $this->assertIsArray($response['body']['builds']);
            $this->assertIsArray($response['body']['buildsTime']);
            $this->assertIsArray($response['body']['buildsMbSeconds']);
            $this->assertIsArray($response['body']['executions']);
            $this->assertIsArray($response['body']['executionsTime']);
            $this->assertIsArray($response['body']['executionsMbSeconds']);
            $this->assertEquals($executions, $response['body']['executions'][array_key_last($response['body']['executions'])]['value']);
            $this->validateDates($response['body']['executions']);
            $this->assertEquals($executionTime, $response['body']['executionsTime'][array_key_last($response['body']['executionsTime'])]['value']);
            $this->validateDates($response['body']['executionsTime']);
        });

        $this->assertEventually(function () use ($executions, $executionTime) {
            $response = $this->client->call(
                Client::METHOD_GET,
                '/functions/usage?range=30d',
                $this->getConsoleHeaders()
            );

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals(25, count($response['body']));
            $this->assertEquals($response['body']['range'], '30d');
            $this->assertIsArray($response['body']['functions']);
            $this->assertIsArray($response['body']['deployments']);
            $this->assertIsArray($response['body']['deploymentsStorage']);
            $this->assertIsArray($response['body']['builds']);
            $this->assertIsArray($response['body']['buildsTime']);
            $this->assertIsArray($response['body']['buildsMbSeconds']);
            $this->assertIsArray($response['body']['executions']);
            $this->assertIsArray($response['body']['executionsTime']);
            $this->assertIsArray($response['body']['executionsMbSeconds']);
            $this->assertEquals($executions, $response['body']['executions'][array_key_last($response['body']['executions'])]['value']);
            $this->validateDates($response['body']['executions']);
            $this->assertEquals($executionTime, $response['body']['executionsTime'][array_key_last($response['body']['executionsTime'])]['value']);
            $this->validateDates($response['body']['executionsTime']);
            $this->assertGreaterThan(0, $response['body']['buildsTime'][array_key_last($response['body']['buildsTime'])]['value']);
            $this->validateDates($response['body']['buildsTime']);
        });

        return $data;
    }

    public function testPrepareSitesStats(): array
    {
        $siteId = $this->setupSite([
            'buildRuntime' => 'node-22',
            'fallbackFile' => '',
            'framework' => 'other',
            'name' => 'Test Site',
            'outputDirectory' => './',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            'siteId' => ID::unique()
        ]);

        $this->assertNotNull($siteId);

        $deployment = $this->createDeploymentSite($siteId, [
            'siteId' => $siteId,
            'code' => $this->packageSite('static'),
            'activate' => true,
        ]);

        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);
        $this->assertEquals('waiting', $deployment['body']['status']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($deployment['body']['$createdAt']));

        $deploymentIdActive = $deployment['body']['$id'] ?? '';

        $this->assertEventually(function () use ($siteId, $deploymentIdActive) {
            $deployment = $this->getDeploymentSite($siteId, $deploymentIdActive);

            $this->assertEquals('ready', $deployment['body']['status']);
        }, 50000, 500);

        $deployment = $this->createDeploymentSite($siteId, [
            'code' => $this->packageSite('static'),
            'activate' => 'false'
        ]);

        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);

        $deploymentIdInactive = $deployment['body']['$id'] ?? '';

        $this->assertEventually(function () use ($siteId, $deploymentIdInactive) {
            $deployment = $this->getDeploymentSite($siteId, $deploymentIdInactive);

            $this->assertEquals('ready', $deployment['body']['status']);
        }, 50000, 500);

        $site = $this->getSite($siteId);

        $this->assertEquals(200, $site['headers']['status-code']);
        $this->assertEquals($deploymentIdActive, $site['body']['deploymentId']);
        $this->assertNotEquals($deploymentIdInactive, $site['body']['deploymentId']);

        $data = [
            'siteId' => $siteId,
            'deployments' => 2,
            'deploymentsSuccess' => 2,
            'deploymentsFailed' => 0
        ];

        return $data;
    }

    /** @depends testPrepareSitesStats */
    public function testSitesStats(array $data)
    {
        $siteId = $data['siteId'];
        $executionTime = $data['executionTime'] ?? 0;
        $executions = $data['executions'] ?? 0;
        $deploymentsSuccess = $data['deploymentsSuccess'];
        $deploymentsFailed = $data['deploymentsFailed'];

        $this->assertEventually(function () use ($siteId, $deploymentsSuccess, $deploymentsFailed, $executions, $executionTime) {
            $response = $this->client->call(
                Client::METHOD_GET,
                '/sites/' . $siteId . '/usage?range=30d',
                $this->getConsoleHeaders()
            );

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals(30, count($response['body']));
            $this->assertEquals('30d', $response['body']['range']);
            $this->assertIsArray($response['body']['deployments']);
            $this->assertEquals($deploymentsSuccess, $response['body']['buildsSuccessTotal']);
            $this->assertEquals($deploymentsFailed, $response['body']['buildsFailedTotal']);
            $this->assertIsArray($response['body']['deploymentsStorage']);
            $this->assertIsNumeric($response['body']['deploymentsStorageTotal']);
            $this->assertIsNumeric($response['body']['buildsMbSecondsTotal']);
            $this->assertIsNumeric($response['body']['executionsMbSecondsTotal']);
            $this->assertIsArray($response['body']['builds']);
            $this->assertIsArray($response['body']['buildsTime']);
            $this->assertIsArray($response['body']['buildsMbSeconds']);
            $this->assertIsArray($response['body']['executions']);
            $this->assertIsArray($response['body']['executionsTime']);
            $this->assertIsArray($response['body']['executionsMbSeconds']);
            $this->assertIsArray($response['body']['buildsSuccess']);
            $this->assertIsArray($response['body']['buildsFailed']);
            $this->assertIsArray($response['body']['requests']);
            $this->assertIsArray($response['body']['inbound']);
            $this->assertIsArray($response['body']['outbound']);
            $this->assertEquals($executions, $response['body']['executions'][array_key_last($response['body']['executions'])]['value']);
            $this->validateDates($response['body']['executions']);
            $this->assertEquals($executionTime, $response['body']['executionsTime'][array_key_last($response['body']['executionsTime'])]['value']);
            $this->validateDates($response['body']['executionsTime']);
        });

        $this->assertEventually(function () use ($executions, $executionTime) {
            $response = $this->client->call(
                Client::METHOD_GET,
                '/sites/usage?range=30d',
                $this->getConsoleHeaders()
            );

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals(31, count($response['body']));
            $this->assertEquals($response['body']['range'], '30d');
            $this->assertIsArray($response['body']['sites']);
            $this->assertIsArray($response['body']['deployments']);
            $this->assertIsArray($response['body']['deploymentsStorage']);
            $this->assertIsArray($response['body']['builds']);
            $this->assertIsArray($response['body']['buildsTime']);
            $this->assertIsArray($response['body']['buildsMbSeconds']);
            $this->assertIsArray($response['body']['executions']);
            $this->assertIsArray($response['body']['executionsTime']);
            $this->assertIsArray($response['body']['executionsMbSeconds']);
            $this->assertIsArray($response['body']['buildsSuccess']);
            $this->assertIsArray($response['body']['buildsFailed']);
            $this->assertIsArray($response['body']['requests']);
            $this->assertIsArray($response['body']['inbound']);
            $this->assertIsArray($response['body']['outbound']);
            $this->assertEquals($executions, $response['body']['executions'][array_key_last($response['body']['executions'])]['value']);
            $this->validateDates($response['body']['executions']);
            $this->assertEquals($executionTime, $response['body']['executionsTime'][array_key_last($response['body']['executionsTime'])]['value']);
            $this->validateDates($response['body']['executionsTime']);
            $this->assertGreaterThan(0, $response['body']['buildsTime'][array_key_last($response['body']['buildsTime'])]['value']);
            $this->validateDates($response['body']['buildsTime']);
        });
    }

    /** @depends testFunctionsStats */
    public function testCustomDomainsFunctionStats(array $data): void
    {
        $functionId = $data['functionId'];

        $response = $this->client->call(Client::METHOD_PUT, '/functions/' . $functionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'name' => 'Test',
            'execute' => ['any']
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $rule = $this->client->call(
            Client::METHOD_POST,
            '/proxy/rules/function',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'domain' => 'test-' . ID::unique() . '.' . System::getEnv('_APP_DOMAIN_FUNCTIONS'),
                'functionId' => $functionId,
            ],
        );

        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertNotEmpty($rule['body']['$id']);
        $this->assertNotEmpty($rule['body']['domain']);

        $domain = $rule['body']['domain'];

        $this->assertEventually(function () use (&$response, $functionId) {
            $response = $this->client->call(
                Client::METHOD_GET,
                '/functions/' . $functionId . '/usage?range=30d',
                $this->getConsoleHeaders()
            );

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals(24, count($response['body']));
            $this->assertEquals('30d', $response['body']['range']);
        });

        $functionsMetrics = $response['body'];

        $this->assertEventually(function () use (&$response) {
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
        });

        $projectMetrics = $response['body'];

        // Create custom domain execution
        $proxyClient = new Client();
        $proxyClient->setEndpoint('http://' . $domain);

        $response = $proxyClient->call(Client::METHOD_GET, '/', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);

        $this->assertEventually(function () use ($functionId, $functionsMetrics, $projectMetrics) {
            // Compare new values with old values
            $response = $this->client->call(
                Client::METHOD_GET,
                '/functions/' . $functionId . '/usage?range=30d',
                $this->getConsoleHeaders()
            );

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals(24, count($response['body']));
            $this->assertEquals('30d', $response['body']['range']);

            // Check if the new values are greater than the old values
            $this->assertEquals($functionsMetrics['executionsTotal'] + 1, $response['body']['executionsTotal']);
            $this->assertGreaterThan($functionsMetrics['executionsTimeTotal'], $response['body']['executionsTimeTotal']);
            $this->assertGreaterThan($functionsMetrics['executionsMbSecondsTotal'], $response['body']['executionsMbSecondsTotal']);

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
            $this->assertEquals($projectMetrics['executionsTotal'] + 1, $response['body']['executionsTotal']);
            $this->assertGreaterThan($projectMetrics['executionsMbSecondsTotal'], $response['body']['executionsMbSecondsTotal']);
        });
    }

    public function tearDown(): void
    {
        $this->projectId = '';
    }
}
