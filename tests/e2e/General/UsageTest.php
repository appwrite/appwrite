<?php

declare(strict_types=1);

namespace Tests\E2E\General;

use Appwrite\Platform\Modules\Compute\Specification;
use Appwrite\Tests\Retry;
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
use WebSocket\Client as WebSocketClient;

final class UsageTest extends Scope
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

    private const CREATE = 10;

    /**
     * Cumulative counter of project-counted API requests this test class has issued so far.
     * Each setup helper that makes a `client->call()` against the test project (i.e. anything
     * authenticated with `x-appwrite-key` and routed to the test project, since console-mode
     * requests are excluded server-side at app/controllers/shared/api.php:1025) increments
     * this. Assertions use it as a lower bound (assertGreaterThanOrEqual) so internal helpers
     * we can't easily count (assertEventually probes via getHeaders, setupDeployment polling,
     * etc.) don't break the test.
     */
    protected static int $globalRequestsTotal = 0;

    /**
     * Per-project static caches so each test pulls its setup from a shared, lazily-initialised
     * resource pool instead of threading state through `#[Depends]`. Mirrors the pattern in
     * tests/e2e/Services/Databases/DatabasesBase.php.
     */
    private static array $usersStatsCache = [];
    private static array $presenceStatsCache = [];
    private static array $storageStatsCache = [];
    private static array $collectionsStatsCache = [];
    private static array $tablesStatsCache = [];
    private static array $documentsDbStatsCache = [];
    private static array $vectorsDbStatsCache = [];
    private static array $functionsStatsCache = [];
    private static array $sitesStatsCache = [];

    protected string $projectId;

    public function setUp(): void
    {
        parent::setUp();
    }

    protected static string $formatTz = 'Y-m-d\TH:i:s.vP';

    protected function getCacheKey(): string
    {
        return $this->getProject()['$id'] ?? 'default';
    }

    /**
     * Eventually-consistent assertion that `/project/usage` reports at least as many
     * `network.requests` as we've tracked via $globalRequestsTotal. GTE is intentional:
     * internal helpers (assertEventually probes via getHeaders, SitesBase polling, etc.)
     * make additional counted calls that aren't worth threading through the counter.
     */
    protected function assertProjectRequestsAtLeastGlobal(): void
    {
        $this->assertEventually(function () {
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

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body']['requests']);

            $latest = $response['body']['requests'][array_key_last($response['body']['requests'])]['value'];
            $this->assertGreaterThanOrEqual(
                self::$globalRequestsTotal,
                $latest,
                'project network.requests should be >= cumulative tracked requests'
            );
            $this->validateDates($response['body']['requests']);
        });
    }

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

    /**
     * Setup: create users via the platform API and return what this scope produced.
     * Lazy-cached per project so any test can call it as its sole prerequisite.
     */
    protected function setupUsersStats(): array
    {
        $key = $this->getCacheKey();
        if (!empty(self::$usersStatsCache[$key])) {
            return self::$usersStatsCache[$key];
        }

        $usersTotal = 0;

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
            self::$globalRequestsTotal += 1;

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

                $usersTotal -= 1;
                self::$globalRequestsTotal += 1;
            }
        }

        $data = [
            'usersTotal' => $usersTotal,
        ];

        self::$usersStatsCache[$key] = $data;
        return $data;
    }

    public function testUsersStats(): void
    {
        $this->setupUsersStats();

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
            $this->assertCount(90, $response['body']['users']);
            $this->assertCount(90, $response['body']['sessions']);
            $this->assertEquals((self::CREATE / 2), $response['body']['users'][array_key_last($response['body']['users'])]['value']);
        });
    }

    /**
     * Setup: register an API-driven presence so the verify test can assert on the resulting count.
     * The realtime presence stays inside the test method because its websocket must remain open
     * while the assertion runs.
     */
    protected function setupPresenceStats(): array
    {
        $key = $this->getCacheKey();
        if (!empty(self::$presenceStatsCache[$key])) {
            return self::$presenceStatsCache[$key];
        }

        $presenceKey = $this->getNewKey([
            'presences.read',
            'presences.write',
        ]);
        $projectId = $this->getProject()['$id'];

        // getUser(true) makes 2 counted calls against the test project: POST /account + POST /account/sessions/email.
        $apiUser = $this->getUser(true);
        self::$globalRequestsTotal += 2;

        $apiPresence = $this->client->call(
            Client::METHOD_PUT,
            '/presences/' . ID::unique(),
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-key' => $presenceKey,
            ],
            [
                'userId' => $apiUser['$id'],
                'status' => 'online',
                'metadata' => [
                    'source' => 'api',
                    'testRunId' => ID::unique(),
                ],
                'permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
            ]
        );
        $this->assertEquals(200, $apiPresence['headers']['status-code']);
        self::$globalRequestsTotal += 1;

        $data = [
            'presenceKey' => $presenceKey,
            'apiUserId' => $apiUser['$id'],
        ];

        self::$presenceStatsCache[$key] = $data;
        return $data;
    }

    #[Retry(count: 1)]
    public function testPresenceStats(): void
    {
        $this->setupPresenceStats();

        $projectId = $this->getProject()['$id'];

        // Open a realtime presence; the assertion below requires it to be alive concurrently
        // with the API presence created by setupPresenceStats() so usersOnlineTotal == 2.
        // getUser(true) makes 2 counted calls against the test project.
        $realtimeUser = $this->getUser(true);
        self::$globalRequestsTotal += 2;

        $realtime = new WebSocketClient(
            'ws://appwrite.test/v1/realtime?' . \http_build_query([
                'project' => $projectId,
            ]),
            [
                'headers' => [
                    'origin' => 'http://localhost',
                    'cookie' => 'a_session_' . $projectId . '=' . $realtimeUser['session'],
                ],
                'timeout' => 2,
            ]
        );

        try {
            $connected = \json_decode($realtime->receive(), true);
            $this->assertSame('connected', $connected['type'] ?? null);

            $presenceId = ID::unique();
            $realtime->send(\json_encode([
                'type' => 'presence',
                'data' => [
                    'presenceId' => $presenceId,
                    'status' => 'online',
                    'metadata' => [
                        'source' => 'realtime',
                        'testRunId' => ID::unique(),
                    ],
                    'permissions' => [
                        Permission::read(Role::any()),
                        Permission::update(Role::any()),
                        Permission::delete(Role::any()),
                    ],
                ],
            ]));

            $response = \json_decode($realtime->receive(), true);
            $this->assertSame('response', $response['type'] ?? null);
            $this->assertSame('presence', $response['data']['to'] ?? null);
            $this->assertSame($presenceId, $response['data']['presence']['$id'] ?? null);

            $this->assertEventually(function () {
                $response = $this->client->call(
                    Client::METHOD_GET,
                    '/presences/usage?range=90d',
                    $this->getConsoleHeaders()
                );

                $this->assertEquals(200, $response['headers']['status-code']);
                $this->assertEquals('90d', $response['body']['range']);
                $this->assertCount(90, $response['body']['presences']);
                $this->assertEquals(2, $response['body']['usersOnlineTotal']);
                $this->assertEquals(2, $response['body']['presences'][array_key_last($response['body']['presences'])]['value']);
                $this->validateDates($response['body']['presences']);
            });
        } finally {
            $realtime->close();
        }
    }

    /**
     * Setup: create buckets and files used by storage usage assertions.
     */
    protected function setupStorageStats(): array
    {
        $key = $this->getCacheKey();
        if (!empty(self::$storageStatsCache[$key])) {
            return self::$storageStatsCache[$key];
        }

        $bucketsTotal = 0;
        $storageTotal = 0;
        $filesTotal = 0;
        $bucketId = '';

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
            self::$globalRequestsTotal += 1;

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

                $bucketsTotal -= 1;
                self::$globalRequestsTotal += 1;
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
            self::$globalRequestsTotal += 1;

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

                $filesTotal -= 1;
                $storageTotal -= $fileSize;
                self::$globalRequestsTotal += 1;
            }
        }

        $data = [
            'bucketId' => $bucketId,
            'bucketsTotal' => $bucketsTotal,
            'storageTotal' => $storageTotal,
            'filesTotal' => $filesTotal,
        ];

        self::$storageStatsCache[$key] = $data;
        return $data;
    }

    public function testStorageStats(): void
    {
        $data = $this->setupStorageStats();
        $bucketId     = $data['bucketId'];
        $bucketsTotal = $data['bucketsTotal'];
        $storageTotal = $data['storageTotal'];
        $filesTotal   = $data['filesTotal'];

        $this->assertProjectRequestsAtLeastGlobal();

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
    }

    /**
     * Setup: create one database + one collection + N documents for the collections-API path.
     * Returns per-scope counts only — no cumulative `requestsTotal`.
     */
    protected function setupCollectionsStats(): array
    {
        $key = $this->getCacheKey();
        if (!empty(self::$collectionsStatsCache[$key])) {
            return self::$collectionsStatsCache[$key];
        }

        $databasesTotal = 0;
        $collectionsTotal = 0;
        $documentsTotal = 0;
        $databaseId = '';
        $collectionId = '';

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

            $databasesTotal += 1;
            self::$globalRequestsTotal += 1;

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
                self::$globalRequestsTotal += 1;
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

            $collectionsTotal += 1;
            self::$globalRequestsTotal += 1;

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
                self::$globalRequestsTotal += 1;
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
        self::$globalRequestsTotal += 1;

        $this->assertEventually(function () use ($databaseId, $collectionId) {
            $attr = $this->client->call(
                Client::METHOD_GET,
                '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/name',
                $this->getConsoleHeaders()
            );
            $this->assertEquals(200, $attr['headers']['status-code']);
            $this->assertEquals('available', $attr['body']['status']);
        }, 30_000, 500);

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

            $documentsTotal += 1;
            self::$globalRequestsTotal += 1;

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
                self::$globalRequestsTotal += 1;
            }
        }

        $data = [
            'databaseId' => $databaseId,
            'collectionId' => $collectionId,
            'databasesTotal' => $databasesTotal,
            'collectionsTotal' => $collectionsTotal,
            'documentsTotal' => $documentsTotal,
        ];

        self::$collectionsStatsCache[$key] = $data;
        return $data;
    }

    public function testDatabaseStatsCollectionsAPI(): void
    {
        $data = $this->setupCollectionsStats();
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];
        $databasesTotal = $data['databasesTotal'];
        $collectionsTotal = $data['collectionsTotal'];
        $documentsTotal = $data['documentsTotal'];

        $this->assertProjectRequestsAtLeastGlobal();

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
    }

    /**
     * Setup: create one database + one table + N rows for the tables-DB path.
     * Reuses setupCollectionsStats() to compute the "absolute" (db-level) totals.
     */
    protected function setupTablesStats(): array
    {
        $key = $this->getCacheKey();
        if (!empty(self::$tablesStatsCache[$key])) {
            return self::$tablesStatsCache[$key];
        }

        $collectionsScope = $this->setupCollectionsStats();

        $rowsTotal = 0;
        $tablesTotal = 0;
        $databasesTotal = $collectionsScope['databasesTotal'];
        $databaseId = '';
        $tableId = '';

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

            $databasesTotal += 1;
            self::$globalRequestsTotal += 1;

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
                self::$globalRequestsTotal += 1;
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

            $tablesTotal += 1;
            self::$globalRequestsTotal += 1;

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
                self::$globalRequestsTotal += 1;
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
        self::$globalRequestsTotal += 1;

        $this->assertEventually(function () use ($databaseId, $tableId) {
            $attr = $this->client->call(
                Client::METHOD_GET,
                '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/columns/name',
                $this->getConsoleHeaders()
            );
            $this->assertEquals(200, $attr['headers']['status-code']);
            $this->assertEquals('available', $attr['body']['status']);
        }, 30_000, 500);

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

            $rowsTotal += 1;
            self::$globalRequestsTotal += 1;

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
                self::$globalRequestsTotal += 1;
            }
        }

        $data = [
            'databaseId' => $databaseId,
            'tableId' => $tableId,
            'databasesTotal' => $databasesTotal,
            'tablesTotal' => $tablesTotal,
            'rowsTotal' => $rowsTotal,

            // For clarity: project/db-level totals include both APIs.
            'absoluteRowsTotal' => $rowsTotal + $collectionsScope['documentsTotal'],
            'absoluteTablesTotal' => $tablesTotal + $collectionsScope['collectionsTotal'],
        ];

        self::$tablesStatsCache[$key] = $data;
        return $data;
    }

    #[Retry(count: 1)]
    public function testDatabaseStatsTablesAPI(): void
    {
        $data = $this->setupTablesStats();
        $tableId = $data['tableId'];
        $databaseId = $data['databaseId'];

        $absoluteRowsTotal = $data['absoluteRowsTotal'];
        $absoluteTablesTotal = $data['absoluteTablesTotal'];

        $rowsTotal = $data['rowsTotal'];
        $tablesTotal = $data['tablesTotal'];
        $databasesTotal = $data['databasesTotal'];

        $this->assertProjectRequestsAtLeastGlobal();

        $this->assertEventually(function () use ($databasesTotal, $absoluteRowsTotal, $absoluteTablesTotal, $tablesTotal, $rowsTotal, $databaseId, $tableId) {
            $response = $this->client->call(
                Client::METHOD_GET,
                '/databases/usage?range=30d',
                $this->getConsoleHeaders()
            );

            $this->assertEquals($databasesTotal, $response['body']['databases'][array_key_last($response['body']['databases'])]['value']);
            $this->validateDates($response['body']['databases']);

            // database listing includes all i.e. collections + tables total.
            $this->assertEquals($absoluteTablesTotal, $response['body']['tables'][array_key_last($response['body']['tables'])]['value']);
            $this->validateDates($response['body']['tables']);

            // database listing includes all i.e. documents + rows total.
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
        }, 30_000, 1000);
    }

    /**
     * Setup: create a documents-DB instance + collection + N documents.
     */
    protected function setupDocumentsDbStats(): array
    {
        $key = $this->getCacheKey();
        if (!empty(self::$documentsDbStatsCache[$key])) {
            return self::$documentsDbStatsCache[$key];
        }

        $documentsTotal = 0;
        $collectionsTotal = 0;
        $documentsDbTotal = 0;
        $documentsDbId = '';
        $collectionId = '';

        for ($i = 0; $i < self::CREATE; $i++) {
            $name = uniqid() . ' documentsdb';

            $response = $this->client->call(
                Client::METHOD_POST,
                '/documentsdb',
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

            $documentsDbTotal += 1;
            self::$globalRequestsTotal += 1;

            $documentsDbId = $response['body']['$id'];

            if ($i < (self::CREATE / 2)) {
                $response = $this->client->call(
                    Client::METHOD_DELETE,
                    '/documentsdb/' . $documentsDbId,
                    array_merge([
                        'x-appwrite-project' => $this->getProject()['$id']
                    ], $this->getHeaders()),
                );

                $this->assertEmpty($response['body']);

                $documentsDbTotal -= 1;
                self::$globalRequestsTotal += 1;
            }
        }

        for ($i = 0; $i < self::CREATE; $i++) {
            $name = uniqid() . ' collection';

            $response = $this->client->call(
                Client::METHOD_POST,
                '/documentsdb/' . $documentsDbId . '/collections',
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

            $collectionsTotal += 1;
            self::$globalRequestsTotal += 1;

            $collectionId = $response['body']['$id'];

            if ($i < (self::CREATE / 2)) {
                $response = $this->client->call(
                    Client::METHOD_DELETE,
                    '/documentsdb/' . $documentsDbId . '/collections/' . $collectionId,
                    array_merge([
                        'x-appwrite-project' => $this->getProject()['$id']
                    ], $this->getHeaders()),
                );

                $this->assertEmpty($response['body']);

                $collectionsTotal -= 1;
                self::$globalRequestsTotal += 1;
            }
        }

        for ($i = 0; $i < self::CREATE; $i++) {
            $response = $this->client->call(
                Client::METHOD_POST,
                '/documentsdb/' . $documentsDbId . '/collections/' . $collectionId . '/documents',
                array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id']
                ], $this->getHeaders()),
                [
                    'documentId' => 'unique()',
                    'data' => [
                        'name' => uniqid() . ' document',
                        'value' => $i
                    ]
                ]
            );

            $this->assertNotEmpty($response['body']['$id']);

            $documentsTotal += 1;
            self::$globalRequestsTotal += 1;

            $documentId = $response['body']['$id'];

            if ($i < (self::CREATE / 2)) {
                $response = $this->client->call(
                    Client::METHOD_DELETE,
                    '/documentsdb/' . $documentsDbId . '/collections/' . $collectionId . '/documents/' . $documentId,
                    array_merge([
                        'x-appwrite-project' => $this->getProject()['$id']
                    ], $this->getHeaders()),
                );

                $this->assertEmpty($response['body']);

                $documentsTotal -= 1;
                self::$globalRequestsTotal += 1;
            }
        }

        $data = [
            'documentsDbId' => $documentsDbId,
            'documentsDbCollectionId' => $collectionId,
            'documentsDbTotal' => $documentsDbTotal,
            'documentsDbCollectionsTotal' => $collectionsTotal,
            'documentsDbDocumentsTotal' => $documentsTotal,
        ];

        self::$documentsDbStatsCache[$key] = $data;
        return $data;
    }

    #[Retry(count: 1)]
    public function testDocumentsDBStats(): void
    {
        $data = $this->setupDocumentsDbStats();
        $documentsDbId = $data['documentsDbId'];
        $collectionId = $data['documentsDbCollectionId'];
        $documentsDbTotal = $data['documentsDbTotal'];
        $collectionsTotal = $data['documentsDbCollectionsTotal'];
        $documentsTotal = $data['documentsDbDocumentsTotal'];

        $this->assertProjectRequestsAtLeastGlobal();

        // Project-wide scalars: documentsdbTotal counts ONLY DocumentsDB instances (not
        // relational databases), and documentsdbDocumentsTotal is the sum of all documents
        // across DocumentsDB collections in this project. Both are produced exclusively by
        // setupDocumentsDbStats() in this test class, so an exact assertion is safe.
        $this->assertEventually(function () use ($documentsDbTotal, $documentsTotal) {
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

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals($documentsDbTotal, $response['body']['documentsdbTotal']);
            $this->assertEquals($documentsTotal, $response['body']['documentsdbDocumentsTotal']);
        });

        $this->assertEventually(function () use ($documentsDbId, $collectionsTotal, $documentsTotal) {
            $response = $this->client->call(
                Client::METHOD_GET,
                '/documentsdb/' . $documentsDbId . '/usage?range=30d',
                $this->getConsoleHeaders()
            );

            $this->assertEquals($collectionsTotal, $response['body']['collections'][array_key_last($response['body']['collections'])]['value']);
            $this->validateDates($response['body']['collections']);

            $this->assertEquals($documentsTotal, $response['body']['documents'][array_key_last($response['body']['documents'])]['value']);
            $this->validateDates($response['body']['documents']);
        });

        $this->assertEventually(function () use ($documentsDbId, $collectionId, $documentsTotal) {
            $response = $this->client->call(
                Client::METHOD_GET,
                '/documentsdb/' . $documentsDbId . '/collections/' . $collectionId . '/usage?range=30d',
                $this->getConsoleHeaders()
            );

            $this->assertEquals($documentsTotal, $response['body']['documents'][array_key_last($response['body']['documents'])]['value']);
            $this->validateDates($response['body']['documents']);
        });
    }

    /**
     * Setup: create a VectorsDB instance + collection + N vector documents.
     */
    protected function setupVectorsDbStats(): array
    {
        $key = $this->getCacheKey();
        if (!empty(self::$vectorsDbStatsCache[$key])) {
            return self::$vectorsDbStatsCache[$key];
        }

        $documentsTotal = 0;
        $collectionsTotal = 0;
        $vectordbTotal = 0;
        $vectordbId = '';
        $collectionId = '';

        for ($i = 0; $i < self::CREATE; $i++) {
            $name = uniqid() . ' vectorsdb';

            $response = $this->client->call(
                Client::METHOD_POST,
                '/vectorsdb',
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

            $vectordbTotal += 1;
            self::$globalRequestsTotal += 1;

            $vectordbId = $response['body']['$id'];

            if ($i < (self::CREATE / 2)) {
                $response = $this->client->call(
                    Client::METHOD_DELETE,
                    '/vectorsdb/' . $vectordbId,
                    array_merge([
                        'x-appwrite-project' => $this->getProject()['$id']
                    ], $this->getHeaders()),
                );

                $this->assertEmpty($response['body']);

                $vectordbTotal -= 1;
                self::$globalRequestsTotal += 1;
            }
        }

        for ($i = 0; $i < self::CREATE; $i++) {
            $name = uniqid() . ' collection';

            $response = $this->client->call(
                Client::METHOD_POST,
                '/vectorsdb/' . $vectordbId . '/collections',
                array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id']
                ], $this->getHeaders()),
                [
                    'collectionId' => 'unique()',
                    'name' => $name,
                    'dimension' => 1536,
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

            $collectionsTotal += 1;
            self::$globalRequestsTotal += 1;

            $collectionId = $response['body']['$id'];

            if ($i < (self::CREATE / 2)) {
                $response = $this->client->call(
                    Client::METHOD_DELETE,
                    '/vectorsdb/' . $vectordbId . '/collections/' . $collectionId,
                    array_merge([
                        'x-appwrite-project' => $this->getProject()['$id']
                    ], $this->getHeaders()),
                );

                $this->assertEmpty($response['body']);

                $collectionsTotal -= 1;
                self::$globalRequestsTotal += 1;
            }
        }

        for ($i = 0; $i < self::CREATE; $i++) {
            $response = $this->client->call(
                Client::METHOD_POST,
                '/vectorsdb/' . $vectordbId . '/collections/' . $collectionId . '/documents',
                array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id']
                ], $this->getHeaders()),
                [
                    'documentId' => 'unique()',
                    'data' => [
                        'embeddings' => array_fill(0, 1536, 0.1),
                        'metadata' => [
                            'name' => uniqid() . ' document',
                            'value' => $i
                        ]
                    ]
                ]
            );

            $this->assertNotEmpty($response['body']['$id']);

            $documentsTotal += 1;
            self::$globalRequestsTotal += 1;

            $documentId = $response['body']['$id'];

            if ($i < (self::CREATE / 2)) {
                $response = $this->client->call(
                    Client::METHOD_DELETE,
                    '/vectorsdb/' . $vectordbId . '/collections/' . $collectionId . '/documents/' . $documentId,
                    array_merge([
                        'x-appwrite-project' => $this->getProject()['$id']
                    ], $this->getHeaders()),
                );

                $this->assertEmpty($response['body']);

                $documentsTotal -= 1;
                self::$globalRequestsTotal += 1;
            }
        }

        $data = [
            'vectordbId' => $vectordbId,
            'vectordbCollectionId' => $collectionId,
            'vectordbTotal' => $vectordbTotal,
            'vectordbCollectionsTotal' => $collectionsTotal,
            'vectordbDocumentsTotal' => $documentsTotal,
        ];

        self::$vectorsDbStatsCache[$key] = $data;
        return $data;
    }

    #[Retry(count: 1)]
    public function testVectorsDBStats(): void
    {
        $data = $this->setupVectorsDbStats();
        $vectordbId = $data['vectordbId'];
        $collectionId = $data['vectordbCollectionId'];
        $vectordbTotal = $data['vectordbTotal'];
        $collectionsTotal = $data['vectordbCollectionsTotal'];
        $documentsTotal = $data['vectordbDocumentsTotal'];

        $this->assertProjectRequestsAtLeastGlobal();

        // Project-wide scalars: vectorsdbDatabasesTotal counts ONLY VectorsDB instances
        // (not relational databases), vectorsdbDocumentsTotal is the sum of all vector
        // documents across this project. Both are produced exclusively by
        // setupVectorsDbStats() in this test class, so an exact assertion is safe.
        $this->assertEventually(function () use ($vectordbTotal, $documentsTotal) {
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

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals($vectordbTotal, $response['body']['vectorsdbDatabasesTotal']);
            $this->assertEquals($documentsTotal, $response['body']['vectorsdbDocumentsTotal']);
        });

        $this->assertEventually(function () use ($vectordbId, $collectionsTotal, $documentsTotal) {
            $response = $this->client->call(
                Client::METHOD_GET,
                '/vectorsdb/' . $vectordbId . '/usage?range=30d',
                $this->getConsoleHeaders()
            );

            $this->assertEquals($collectionsTotal, $response['body']['collections'][array_key_last($response['body']['collections'])]['value']);
            $this->validateDates($response['body']['collections']);

            $this->assertEquals($documentsTotal, $response['body']['documents'][array_key_last($response['body']['documents'])]['value']);
            $this->validateDates($response['body']['documents']);
        });

        $this->assertEventually(function () use ($vectordbId, $collectionId, $documentsTotal) {
            $response = $this->client->call(
                Client::METHOD_GET,
                '/vectorsdb/' . $vectordbId . '/collections/' . $collectionId . '/usage?range=30d',
                $this->getConsoleHeaders()
            );

            $this->assertEquals($documentsTotal, $response['body']['documents'][array_key_last($response['body']['documents'])]['value']);
            $this->validateDates($response['body']['documents']);
        });
    }

    /**
     * Setup: create a function, deploy it, and run 3 executions (2 sync + 1 async).
     */
    protected function setupFunctionsStats(): array
    {
        $key = $this->getCacheKey();
        if (!empty(self::$functionsStatsCache[$key])) {
            return self::$functionsStatsCache[$key];
        }

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
                'buildSpecification' => Specification::S_8VCPU_8GB,
                'runtimeSpecification' => Specification::S_4VCPU_4GB,
            ]
        );

        $functionId = $response['body']['$id'] ?? '';

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        self::$globalRequestsTotal += 1;

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
        self::$globalRequestsTotal += 1;

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
        self::$globalRequestsTotal += 1;

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
        self::$globalRequestsTotal += 1;

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
        self::$globalRequestsTotal += 1;

        $executionId = $response['body']['$id'];

        // Capture the final execution document inside the polling closure so we tally
        // the same record the server already wrote to METRIC_EXECUTIONS. A separate GET
        // after the loop (especially via getHeaders / API-key) can briefly see a different
        // status than what assertEventually just validated via console headers, which is
        // how we ended up with "3 matches expected 2" — the post-poll GET fell into
        // neither the 'completed' nor 'failed' branch.
        $asyncResponse = null;
        $this->assertEventually(function () use ($functionId, $executionId, &$asyncResponse) {
            $asyncResponse = $this->client->call(
                Client::METHOD_GET,
                '/functions/' . $functionId . '/executions/' . $executionId,
                $this->getConsoleHeaders(),
            );
            $this->assertContains($asyncResponse['body']['status'], ['completed', 'failed']);
        }, 30_000, 500);

        if ($asyncResponse['body']['status'] === 'failed') {
            $failures += 1;
        } elseif ($asyncResponse['body']['status'] === 'completed') {
            $executions += 1;
        }

        $executionTime += (int) ($asyncResponse['body']['duration'] * 1000);

        $data = [
            'functionId' => $functionId,
            'executionTime' => $executionTime,
            'executions' => $executions,
            'failures' => $failures,
        ];

        self::$functionsStatsCache[$key] = $data;
        return $data;
    }

    public function testFunctionsStats(): void
    {
        $data = $this->setupFunctionsStats();
        $functionId = $data['functionId'];
        $executionTime = $data['executionTime'];
        // METRIC_EXECUTIONS counts every ExecutionCompleted event regardless of status,
        // so the assertion has to compare against successes + failures, not successes alone.
        $executions = $data['executions'] + $data['failures'];

        $this->assertEventually(function () use ($functionId, $executions, $executionTime) {
            $response = $this->client->call(
                Client::METHOD_GET,
                '/functions/' . $functionId . '/usage?range=30d',
                $this->getConsoleHeaders()
            );

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertCount(24, $response['body']);
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
            $this->assertCount(25, $response['body']);
            $this->assertEquals('30d', $response['body']['range']);
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
    }

    /**
     * Setup: provision a site and push two deployments (one active, one inactive).
     * Returns site id + deployment counts.
     */
    protected function setupSitesStats(): array
    {
        $key = $this->getCacheKey();
        if (!empty(self::$sitesStatsCache[$key])) {
            return self::$sitesStatsCache[$key];
        }

        $siteId = $this->setupSite([
            'buildRuntime' => 'node-22',
            'fallbackFile' => '',
            'framework' => 'other',
            'name' => 'Test Site',
            'outputDirectory' => './',
            'providerBranch' => 'main',
            'providerRootDirectory' => './',
            'siteId' => ID::unique(),
        ]);

        // Enqueue both deployments first, then wait for both to be ready concurrently.
        // The build worker processes them in parallel, so the wall-clock wait is bounded by
        // the slower of the two builds instead of (build1 + build2).
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

        $deployment = $this->createDeploymentSite($siteId, [
            'code' => $this->packageSite('static'),
            'activate' => 'false',
        ]);

        $this->assertEquals(202, $deployment['headers']['status-code']);
        $this->assertNotEmpty($deployment['body']['$id']);

        $deploymentIdInactive = $deployment['body']['$id'] ?? '';

        $this->assertEventually(function () use ($siteId, $deploymentIdActive, $deploymentIdInactive) {
            $active = $this->getDeploymentSite($siteId, $deploymentIdActive);
            $inactive = $this->getDeploymentSite($siteId, $deploymentIdInactive);

            $this->assertEquals('ready', $active['body']['status']);
            $this->assertEquals('ready', $inactive['body']['status']);
        }, 50000, 500);

        $site = $this->getSite($siteId);

        $this->assertEquals(200, $site['headers']['status-code']);
        $this->assertEquals($deploymentIdActive, $site['body']['deploymentId']);
        $this->assertNotEquals($deploymentIdInactive, $site['body']['deploymentId']);

        $data = [
            'siteId' => $siteId,
            'deployments' => 2,
            'deploymentsSuccess' => 2,
            'deploymentsFailed' => 0,
        ];

        self::$sitesStatsCache[$key] = $data;
        return $data;
    }

    #[Retry(count: 1)]
    public function testSitesStats(): void
    {
        $data = $this->setupSitesStats();
        $siteId = $data['siteId'];
        $executionTime = 0;
        $executions = 0;
        $deploymentsSuccess = $data['deploymentsSuccess'];
        $deploymentsFailed = $data['deploymentsFailed'];

        $this->assertEventually(function () use ($siteId, $deploymentsSuccess, $deploymentsFailed, $executions, $executionTime) {
            $response = $this->client->call(
                Client::METHOD_GET,
                '/sites/' . $siteId . '/usage?range=30d',
                $this->getConsoleHeaders()
            );

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertCount(30, $response['body']);
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
            $this->assertCount(31, $response['body']);
            $this->assertEquals('30d', $response['body']['range']);
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

    public function testCustomDomainsFunctionStats(): void
    {
        $data = $this->setupFunctionsStats();
        $functionId = $data['functionId'];

        $response = $this->client->call(
            Client::METHOD_PUT,
            '/functions/' . $functionId,
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id']
            ], $this->getHeaders()),
            [
                'name' => 'Test',
                'execute' => ['any']
            ]
        );

        $this->assertEquals(200, $response['headers']['status-code']);

        $functionsDomain = \explode(',', System::getEnv('_APP_DOMAIN_FUNCTIONS', ''))[0];
        $rule = $this->client->call(
            Client::METHOD_POST,
            '/proxy/rules/function',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()),
            [
                'domain' => 'test-' . ID::unique() . '.' . $functionsDomain,
                'functionId' => $functionId,
            ],
        );

        $this->assertEquals(201, $rule['headers']['status-code']);
        $this->assertNotEmpty($rule['body']['$id']);
        $this->assertNotEmpty($rule['body']['domain']);

        $domain = $rule['body']['domain'];

        // Snapshot both baselines in a single assertEventually so we only pay the polling
        // wait once. Each block is a separate console GET, so they don't interfere.
        $functionsMetrics = [];
        $projectMetrics = [];

        $this->assertEventually(function () use (&$functionsMetrics, &$projectMetrics, $functionId) {
            $functionsResponse = $this->client->call(
                Client::METHOD_GET,
                '/functions/' . $functionId . '/usage?range=30d',
                $this->getConsoleHeaders()
            );

            $this->assertEquals(200, $functionsResponse['headers']['status-code']);
            $this->assertCount(24, $functionsResponse['body']);
            $this->assertEquals('30d', $functionsResponse['body']['range']);

            $projectResponse = $this->client->call(
                Client::METHOD_GET,
                '/project/usage',
                $this->getConsoleHeaders(),
                [
                    'period' => '1h',
                    'startDate' => self::getToday(),
                    'endDate' => self::getTomorrow(),
                ]
            );
            $this->assertEquals(200, $projectResponse['headers']['status-code']);

            $functionsMetrics = $functionsResponse['body'];
            $projectMetrics = $projectResponse['body'];
        });

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
            $this->assertCount(24, $response['body']);
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

    #[Retry(count: 1)]
    public function testEmbeddingsTextUsageDoesNotBreakProjectUsage(): void
    {
        $callCount = 0;
        $this->assertEventually(function () use (&$callCount) {
            $response = $this->client->call(
                Client::METHOD_POST,
                '/vectorsdb/embeddings/text',
                array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'x-appwrite-key' => $this->getProject()['apiKey'],
                ], $this->getHeaders()),
                [
                    'model' => 'nomic-embed-text',
                    'texts' => ['usage test warm-up ' . $callCount],
                ]
            );
            $callCount++;

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertIsArray($response['body']['embeddings']);
            $first = $response['body']['embeddings'][0] ?? [];
            $this->assertSame('', (string)($first['error'] ?? ''), 'embed adapter still reporting error - model warming up');
            $this->assertNotEmpty($first['embedding'] ?? []);
        }, 600_000, 5_000);

        // Now run a couple more for stable per-call assertions.
        for ($i = 0; $i < 2; $i++) {
            $response = $this->client->call(
                Client::METHOD_POST,
                '/vectorsdb/embeddings/text',
                array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'x-appwrite-key' => $this->getProject()['apiKey'],
                ], $this->getHeaders()),
                [
                    'model' => 'nomic-embed-text',
                    'texts' => ['usage test text ' . $i],
                ]
            );

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertIsArray($response['body']['embeddings']);
            $this->assertGreaterThan(0, $response['body']['total']);
        }

        // Ensure project usage endpoint still responds correctly after embeddings calls
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
            $this->assertArrayHasKey('requests', $response['body']);
            $this->assertArrayHasKey('network', $response['body']);
            $this->assertArrayHasKey('executionsTotal', $response['body']);

            // New embeddings metrics should be present after calls above
            $this->assertArrayHasKey('embeddingsText', $response['body']);
            $this->assertArrayHasKey('embeddingsTextErrors', $response['body']);
            $this->assertArrayHasKey('embeddingsTextTokens', $response['body']);
            $this->assertArrayHasKey('embeddingsTextDuration', $response['body']);
            $this->assertArrayHasKey('embeddingsTextTotal', $response['body']);
            $this->assertArrayHasKey('embeddingsTextErrorsTotal', $response['body']);
            $this->assertArrayHasKey('embeddingsTextTokensTotal', $response['body']);
            $this->assertArrayHasKey('embeddingsTextDurationTotal', $response['body']);

            // Time-series arrays should be non-empty
            $this->assertNotEmpty($response['body']['embeddingsText']);
            $this->assertNotEmpty($response['body']['embeddingsTextTokens']);
            $this->assertNotEmpty($response['body']['embeddingsTextDuration']);
            $this->validateDates($response['body']['embeddingsText']);
            $this->validateDates($response['body']['embeddingsTextTokens']);
            $this->validateDates($response['body']['embeddingsTextDuration']);

            // Total scalars should be greater than 0 (or >= 0 for errors)
            $this->assertGreaterThan(0, $response['body']['embeddingsTextTotal']);
            $this->assertGreaterThanOrEqual(0, $response['body']['embeddingsTextErrorsTotal']);
            $this->assertGreaterThan(0, $response['body']['embeddingsTextTokensTotal']);
            $this->assertGreaterThan(0, $response['body']['embeddingsTextDurationTotal']);
        }, 60_000, 1_000);
    }

    public function tearDown(): void
    {
        $this->projectId = '';
    }
}
