<?php

declare(strict_types=1);

namespace Tests\E2E\General;

use CURLFile;
use PHPUnit\Framework\Attributes\Group;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideNone;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Role;
use Utopia\System\System;

final class AbuseTest extends Scope
{
    use ProjectCustom;
    use SideNone;

    private const int MEMBERSHIP_STATUS_PERIOD = 3600;

    private const int COUNTING_SECONDS = 20;

    protected function setUp(): void
    {
        parent::setUp();

        if (System::getEnv('_APP_OPTIONS_ABUSE') === 'disabled') {
            $this->markTestSkipped('Abuse is not enabled.');
        }
    }

    #[Group('abuseEnabled')]
    public function testAbuseIncreasedLimitProject(): void
    {
        $increasedLimitProjects = \array_values(\array_filter(\array_map('trim', \explode(',', System::getEnv('_APP_OPTIONS_ABUSE_INCREASED_LIMIT_PROJECTS', '')))));
        if (empty($increasedLimitProjects)) {
            $this->markTestSkipped('No projects with increased rate limits configured.');
        }

        $projectId = $increasedLimitProjects[0];

        $team = $this->createTeamFixture([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'teamId' => Id::unique(),
            'name' => 'Increased Limit Team',
        ]);

        $this->assertSame(200, $team['headers']['status-code']);

        $project = $this->client->call(Client::METHOD_POST, '/projects', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'projectId' => $projectId,
            'region' => System::getEnv('_APP_REGION', 'default'),
            'name' => 'Increased Limit Project',
            'teamId' => $team['body']['$id'],
        ]);

        // 409 means the project is left over from a previous run against the same stack
        $this->assertContains($project['headers']['status-code'], [201, 409]);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-forwarded-for' => '198.51.100.' . random_int(1, 254),
        ], [
            'userId' => Id::unique(),
            'email' => 'increased.limit.' . bin2hex(random_bytes(8)) . '@example.com',
            'password' => 'password',
        ]);

        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertEquals(1000, $response['headers']['x-ratelimit-limit']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-forwarded-for' => '198.51.100.' . random_int(1, 254),
        ], [
            'userId' => Id::unique(),
            'email' => 'default.limit.' . bin2hex(random_bytes(8)) . '@example.com',
            'password' => 'password',
        ]);

        $this->assertSame(201, $response['headers']['status-code']);
        $this->assertEquals(10, $response['headers']['x-ratelimit-limit']);
    }

    #[Group('abuseEnabled')]
    public function testAbuseCreateDocumentCollectionsAPI(): void
    {
        $data = $this->createCollectionOrTable();
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];
        $max = 120;

        $this->startCountingInOneWindow(APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT);
        for ($i = 0; $i <= $max + 1; $i++) {
            $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], [
                'documentId' => Id::unique(),
                'data' => [
                    'title' => 'The Hulk ' . $i,
                ],
            ]);

            if ($i < $max) {
                $this->assertSame(201, $response['headers']['status-code']);
            } else {
                $this->assertSame(429, $response['headers']['status-code']);
            }
        }
    }

    #[Group('abuseEnabled')]
    public function testAbuseUpdateDocumentCollectionsAPI(): void
    {
        $data = $this->createCollectionOrTable();
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];
        $max = 120;

        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'documentId' => Id::unique(),
            'data' => [
                'title' => 'The Hulk',
            ],
        ]);

        $documentId = $document['body']['$id'];

        $this->startCountingInOneWindow(APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT);
        for ($i = 0; $i <= $max + 1; $i++) {
            $response = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], [
                'data' => [
                    'title' => 'The Hulk ' . $i,
                ],
            ]);

            if ($i < $max) {
                $this->assertSame(200, $response['headers']['status-code']);
            } else {
                $this->assertSame(429, $response['headers']['status-code']);
            }
        }
    }

    #[Group('abuseEnabled')]
    public function testAbuseDeleteDocumentCollectionsAPI(): void
    {
        $data = $this->createCollectionOrTable();
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];
        $max = 60;

        $this->startCountingInOneWindow(APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT);
        for ($i = 0; $i <= $max + 1; $i++) {
            $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ], [
                'documentId' => Id::unique(),
                'data' => [
                    'title' => 'The Hulk',
                ],
            ]);

            $documentId = $document['body']['$id'];

            $response = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ]);

            if ($i < $max) {
                $this->assertSame(204, $response['headers']['status-code']);
            } else {
                $this->assertSame(429, $response['headers']['status-code']);
            }
        }
    }

    #[Group('abuseEnabled')]
    public function testAbuseCreateDocumentTablesAPI(): void
    {
        $data = $this->createCollectionOrTable(false);
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];
        $max = 120;

        $this->startCountingInOneWindow(APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT);
        for ($i = 0; $i <= $max + 1; $i++) {
            $response = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $collectionId . '/rows', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], [
                'rowId' => Id::unique(),
                'data' => [
                    'title' => 'The Hulk ' . $i,
                ],
            ]);

            if ($i < $max) {
                $this->assertSame(201, $response['headers']['status-code']);
            } else {
                $this->assertSame(429, $response['headers']['status-code']);
            }
        }
    }

    #[Group('abuseEnabled')]
    public function testAbuseUpdateDocumentTablesAPI(): void
    {
        $data = $this->createCollectionOrTable(false);
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];
        $max = 120;

        $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $collectionId . '/rows', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'rowId' => Id::unique(),
            'data' => [
                'title' => 'The Hulk',
            ],
        ]);

        $rowId = $row['body']['$id'];

        $this->startCountingInOneWindow(APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT);
        for ($i = 0; $i <= $max + 1; $i++) {
            $response = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $collectionId . '/rows/' . $rowId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], [
                'data' => [
                    'title' => 'The Hulk ' . $i,
                ],
            ]);

            if ($i < $max) {
                $this->assertSame(200, $response['headers']['status-code']);
            } else {
                $this->assertSame(429, $response['headers']['status-code']);
            }
        }
    }

    #[Group('abuseEnabled')]
    public function testAbuseDeleteDocumentTablesAPI(): void
    {
        $data = $this->createCollectionOrTable(false);
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];
        $max = 60;

        $this->startCountingInOneWindow(APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT);
        for ($i = 0; $i <= $max + 1; $i++) {
            $document = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $collectionId . '/rows', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ], [
                'rowId' => Id::unique(),
                'data' => [
                    'title' => 'The Hulk',
                ],
            ]);

            $documentId = $document['body']['$id'];

            $response = $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $collectionId . '/rows/' . $documentId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ]);

            if ($i < $max) {
                $this->assertSame(204, $response['headers']['status-code']);
            } else {
                $this->assertSame(429, $response['headers']['status-code']);
            }
        }
    }

    #[Group('abuseEnabled')]
    public function testAbuseQueryDocumentsCollectionsAPI(): void
    {
        $data = $this->createCollectionOrTable();

        $this->assertQueryLimited('/databases/' . $data['databaseId'] . '/collections/' . $data['collectionId'] . '/documents/query');
    }

    #[Group('abuseEnabled')]
    public function testAbuseQueryRowsTablesAPI(): void
    {
        $data = $this->createCollectionOrTable(false);

        $this->assertQueryLimited('/tablesdb/' . $data['databaseId'] . '/tables/' . $data['collectionId'] . '/rows/query');
    }

    #[Group('abuseEnabled')]
    public function testAbuseCreateFile(): void
    {
        $data = $this->createBucket();
        $bucketId = $data['bucketId'];
        $max = 60;

        $this->startCountingInOneWindow(APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT);
        for ($i = 0; $i <= $max + 1; $i++) {
            $response = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
                'content-type' => 'multipart/form-data',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], [
                'fileId' => Id::unique(),
                'file' => new CURLFile(realpath(__DIR__ . '/../../resources/logo.png'), 'image/png', 'permissions.png'),
            ]);

            if ($i < $max) {
                $this->assertSame(201, $response['headers']['status-code']);
            } else {
                $this->assertSame(429, $response['headers']['status-code']);
            }
        }
    }

    #[Group('abuseEnabled')]
    public function testAbuseUpdateFile(): void
    {
        $data = $this->createBucket();
        $bucketId = $data['bucketId'];
        $max = 60;

        $response = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'fileId' => Id::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $fileId = $response['body']['$id'];

        $this->startCountingInOneWindow(APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT);
        for ($i = 0; $i <= $max + 1; $i++) {
            $response = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], [
                'name' => 'permissions' . $i . '.png',
            ]);

            if ($i < $max) {
                $this->assertSame(200, $response['headers']['status-code']);
            } else {
                $this->assertSame(429, $response['headers']['status-code']);
            }
        }
    }

    #[Group('abuseEnabled')]
    public function testAbuseDeleteFile(): void
    {
        $data = $this->createBucket();
        $bucketId = $data['bucketId'];
        $max = 60;

        $this->startCountingInOneWindow(APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT);
        for ($i = 0; $i <= $max + 1; $i++) {
            $response = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
                'content-type' => 'multipart/form-data',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ], [
                'fileId' => Id::unique(),
                'file' => new CURLFile(realpath(__DIR__ . '/../../resources/logo.png'), 'image/png', 'permissions.png'),
            ]);

            $fileId = $response['body']['$id'];

            $response = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ]);

            if ($i < $max) {
                $this->assertSame(204, $response['headers']['status-code']);
            } else {
                $this->assertSame(429, $response['headers']['status-code']);
            }
        }
    }

    private function assertQueryLimited(string $path): void
    {
        $max = 120;
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-forwarded-for' => '198.51.100.' . random_int(1, 254),
        ];

        $this->startCountingInOneWindow(APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT);
        for ($i = 0; $i < $max; $i++) {
            $response = $this->client->call(Client::METHOD_POST, $path, $headers, ['queries' => []]);
            $this->assertSame(200, $response['headers']['status-code'], 'Request ' . ($i + 1) . ' is within the limit');
        }

        $response = $this->client->call(Client::METHOD_POST, $path, $headers, ['queries' => []]);
        $this->assertSame(429, $response['headers']['status-code'], 'A guest querying past the limit is throttled');
    }

    private function createCollectionOrTable(bool $isCollection = true): array
    {
        $api = $isCollection ? '/databases' : '/tablesdb';

        $database = $this->client->call(Client::METHOD_POST, $api, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => Id::unique(),
            'name' => 'AbuseDatabase',
        ]);

        $this->assertSame(201, $database['headers']['status-code']);
        $this->assertSame('AbuseDatabase', $database['body']['name']);

        $databaseId = $database['body']['$id'];

        $endpoint = $isCollection ? 'collections' : 'tables';
        $idParam = $isCollection ? 'collectionId' : 'tableId';
        $attributePath = $isCollection ? 'attributes' : 'columns';

        $movies = $this->client->call(Client::METHOD_POST, $api . '/' . $databaseId . "/$endpoint", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            $idParam => Id::unique(),
            'name' => 'Movies',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $collectionId = $movies['body']['$id'];

        $this->client->call(Client::METHOD_POST, $api . '/' . $databaseId . "/$endpoint/" . $collectionId . "/$attributePath/string", [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);

        $attrEndpoint = $isCollection
            ? '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/title'
            : '/tablesdb/' . $databaseId . '/tables/' . $collectionId . '/columns/title';

        $this->assertEventually(function () use ($attrEndpoint) {
            $attr = $this->client->call(Client::METHOD_GET, $attrEndpoint, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertSame(200, $attr['headers']['status-code']);
            $this->assertSame('available', $attr['body']['status']);
        }, 30_000, 500);

        return [
            'databaseId' => $databaseId,
            'collectionId' => $collectionId,
        ];
    }

    private function createBucket(): array
    {
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => Id::unique(),
            'name' => 'Test Bucket',
            'fileSecurity' => true,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        return [
            'bucketId' => $bucket['body']['$id'],
        ];
    }

    #[Group('abuseEnabled')]
    public function testAbuseUpdateMembershipStatus(): void
    {
        $team = $this->client->call(Client::METHOD_POST, '/teams', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'teamId' => Id::unique(),
            'name' => 'Abuse Team',
        ]);
        $this->assertSame(201, $team['headers']['status-code']);

        $membership = $this->client->call(Client::METHOD_POST, '/teams/' . $team['body']['$id'] . '/memberships', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'email' => 'abuse.' . \bin2hex(\random_bytes(8)) . '@example.com',
            'roles' => ['developer'],
            'url' => 'http://localhost/join',
        ]);
        $this->assertSame(201, $membership['headers']['status-code']);

        $path = '/teams/' . $team['body']['$id'] . '/memberships/' . $membership['body']['$id'] . '/status';
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-forwarded-for' => '198.51.100.' . \random_int(1, 254),
        ];

        $this->startCountingInOneWindow(self::MEMBERSHIP_STATUS_PERIOD);
        for ($i = 0; $i < 10; $i++) {
            $response = $this->client->call(Client::METHOD_PATCH, $path, $headers, [
                'userId' => Id::unique(),
                'secret' => 'wrong-secret',
            ]);
            $this->assertNotSame(429, $response['headers']['status-code'], 'Request ' . ($i + 1) . ' is within the limit');
        }

        $response = $this->client->call(Client::METHOD_PATCH, $path, $headers, [
            'userId' => $membership['body']['userId'],
            'secret' => 'wrong-secret',
        ]);
        $this->assertSame(429, $response['headers']['status-code'], 'Changing the user ID must not reset the limit on secret guesses');
    }

    #[Group('abuseEnabled')]
    public function testAbuseCreateTransactionOperationsCollectionsAPI(): void
    {
        $this->assertGuestLimited(Client::METHOD_POST, '/databases/transactions/' . Id::unique() . '/operations', ['operations' => []], 120);
    }

    #[Group('abuseEnabled')]
    public function testAbuseCreateTransactionOperationsTablesAPI(): void
    {
        $this->assertGuestLimited(Client::METHOD_POST, '/tablesdb/transactions/' . Id::unique() . '/operations', ['operations' => []], 120);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function assertGuestLimited(string $method, string $path, array $params, int $limit): void
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-forwarded-for' => '198.51.100.' . \random_int(1, 254),
        ];

        $this->startCountingInOneWindow(APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT);
        for ($i = 0; $i < $limit; $i++) {
            $response = $this->client->call($method, $path, $headers, $params);
            $this->assertNotSame(429, $response['headers']['status-code'], 'Request ' . ($i + 1) . ' is within the limit');
        }

        $response = $this->client->call($method, $path, $headers, $params);
        $this->assertSame(429, $response['headers']['status-code'], 'A guest past the limit is throttled');
    }

    /**
     * A limit counts hits in windows aligned to its period, so a count split across two windows never reaches it.
     */
    private function startCountingInOneWindow(int $period): void
    {
        $remaining = $period - \fmod(\microtime(true), $period);
        if ($remaining < self::COUNTING_SECONDS) {
            \usleep((int) \ceil(($remaining + 0.5) * 1_000_000));
        }
    }
}
