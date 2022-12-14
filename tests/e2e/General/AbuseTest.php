<?php

namespace Tests\E2E\General;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideNone;
use Utopia\App;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Permission;
use Utopia\Database\Role;

class AbuseTest extends Scope
{
    use ProjectCustom;
    use SideNone;

    protected function setUp(): void
    {
        parent::setUp();

        if (App::getEnv('_APP_OPTIONS_ABUSE') === 'disabled') {
            $this->markTestSkipped('Abuse is not enabled.');
        }
    }

    public function testAbuseCreateDocument()
    {
        $data = $this->createCollection();
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];
        $max = 120;

        for ($i = 0; $i <= $max + 1; $i++) {
            $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], [
                'documentId' => ID::unique(),
                'data' => [
                    'title' => 'The Hulk ' . $i,
                ],
            ]);

            if ($i < $max) {
                $this->assertEquals(201, $response['headers']['status-code']);
            } else {
                $this->assertEquals(429, $response['headers']['status-code']);
            }
        }
    }

    public function testAbuseUpdateDocument()
    {
        $data = $this->createCollection();
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];
        $max = 120;

        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'The Hulk',
            ],
        ]);

        $documentId = $document['body']['$id'];

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
                $this->assertEquals(200, $response['headers']['status-code']);
            } else {
                $this->assertEquals(429, $response['headers']['status-code']);
            }
        }
    }

    public function testAbuseDeleteDocument()
    {
        $data = $this->createCollection();
        $databaseId = $data['databaseId'];
        $collectionId = $data['collectionId'];
        $max = 60;

        for ($i = 0; $i <= $max + 1; $i++) {
            $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ], [
                'documentId' => ID::unique(),
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
                $this->assertEquals(204, $response['headers']['status-code']);
            } else {
                $this->assertEquals(429, $response['headers']['status-code']);
            }
        }
    }

    public function testAbuseCreateFile()
    {
        $data = $this->createBucket();
        $bucketId = $data['bucketId'];
        $max = 60;

        for ($i = 0; $i <= $max + 1; $i++) {
            $response = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
                'content-type' => 'multipart/form-data',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], [
                'fileId' => ID::unique(),
                'file' => new CURLFile(realpath(__DIR__ . '/../../resources/logo.png'), 'image/png', 'permissions.png'),
            ]);

            if ($i < $max) {
                $this->assertEquals(201, $response['headers']['status-code']);
            } else {
                $this->assertEquals(429, $response['headers']['status-code']);
            }
        }
    }

    public function testAbuseUpdateFile()
    {
        $data = $this->createBucket();
        $bucketId = $data['bucketId'];
        $max = 60;

        $response = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../resources/logo.png'), 'image/png', 'permissions.png'),
        ]);

        $fileId = $response['body']['$id'];

        for ($i = 0; $i <= $max + 1; $i++) {
            $response = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], [
                'name' => 'permissions' . $i . '.png',
            ]);

            if ($i < $max) {
                $this->assertEquals(200, $response['headers']['status-code']);
            } else {
                $this->assertEquals(429, $response['headers']['status-code']);
            }
        }
    }

    public function testAbuseDeleteFile()
    {
        $data = $this->createBucket();
        $bucketId = $data['bucketId'];
        $max = 60;

        for ($i = 0; $i <= $max + 1; $i++) {
            $response = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
                'content-type' => 'multipart/form-data',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ], [
                'fileId' => ID::unique(),
                'file' => new CURLFile(realpath(__DIR__ . '/../../resources/logo.png'), 'image/png', 'permissions.png'),
            ]);

            $fileId = $response['body']['$id'];

            $response = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ]);

            if ($i < $max) {
                $this->assertEquals(204, $response['headers']['status-code']);
            } else {
                $this->assertEquals(429, $response['headers']['status-code']);
            }
        }
    }

    private function createCollection(): array
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'AbuseDatabase',
        ]);

        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('AbuseDatabase', $database['body']['name']);

        $databaseId = $database['body']['$id'];

        $movies = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'collectionId' => ID::unique(),
            'name' => 'Movies',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $collectionId = $movies['body']['$id'];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);

        sleep(1);

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
            'bucketId' => ID::unique(),
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
}
