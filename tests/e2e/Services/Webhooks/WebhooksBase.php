<?php

namespace Tests\E2E\Services\Webhooks;

use Appwrite\Tests\Async;
use Appwrite\Tests\Retry;
use CURLFile;
use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

trait WebhooksBase
{
    use Async;

    protected function awaitDeploymentIsBuilt($functionId, $deploymentId): void
    {
        $this->assertEventually(function () use ($functionId, $deploymentId) {
            $deployment = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/deployments/' . $deploymentId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);

            $this->assertEquals(200, $deployment['headers']['status-code']);
            $this->assertEquals('ready', $deployment['body']['status'], \json_encode($deployment['body']));
        });
    }

    public static function getWebhookSignature(array $webhook, string $signatureKey): string
    {
        $payload = json_encode($webhook['data']);
        $url     = $webhook['url'];
        return base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));
    }

    /**
     * Creates a database and collection with proper attributes for document operations.
     *
     * @return array Array containing 'databaseId' and 'actorsId'
     */
    protected function setupCollectionWithAttributes(): array
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Actors DB',
        ]);

        $databaseId = $database['body']['$id'];

        // Create collection
        $actors = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Actors',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'documentSecurity' => true,
        ]);

        $actorsId = $actors['body']['$id'];

        // Create attributes
        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $actorsId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'firstName',
            'size' => 256,
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $actorsId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'lastName',
            'size' => 256,
            'required' => true,
        ]);

        // Wait for attributes to be available
        $this->assertEventually(function () use ($databaseId, $actorsId) {
            $collection = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $actorsId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertCount(2, $collection['body']['attributes']);
            $this->assertEquals('available', $collection['body']['attributes'][0]['status']);
            $this->assertEquals('available', $collection['body']['attributes'][1]['status']);
        }, 15000, 500);

        return ['databaseId' => $databaseId, 'actorsId' => $actorsId];
    }

    /**
     * Creates a database and table with proper columns for row operations.
     *
     * @return array Array containing 'databaseId' and 'actorsId'
     */
    protected function setupTableWithColumns(): array
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Actors DB',
        ]);

        $databaseId = $database['body']['$id'];

        // Create table
        $actors = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Actors',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'rowSecurity' => true,
        ]);

        $actorsId = $actors['body']['$id'];

        // Create columns
        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $actorsId . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'firstName',
            'size' => 256,
            'required' => true,
        ]);

        $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $actorsId . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'lastName',
            'size' => 256,
            'required' => true,
        ]);

        // Wait for columns to be available
        $this->assertEventually(function () use ($databaseId, $actorsId) {
            $table = $this->client->call(Client::METHOD_GET, '/tablesdb/' . $databaseId . '/tables/' . $actorsId, [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]);
            $this->assertCount(2, $table['body']['columns']);
            $this->assertEquals('available', $table['body']['columns'][0]['status']);
            $this->assertEquals('available', $table['body']['columns'][1]['status']);
        }, 15000, 500);

        return ['databaseId' => $databaseId, 'actorsId' => $actorsId];
    }

    /**
     * Creates an enabled storage bucket.
     *
     * @return array Array containing 'bucketId'
     */
    protected function setupStorageBucket(): array
    {
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'fileSecurity' => true,
            'enabled' => true,
        ]);

        return ['bucketId' => $bucket['body']['$id']];
    }

    /**
     * Creates a team and returns its ID.
     *
     * @param string $name Team name
     * @return array Array containing 'teamId'
     */
    protected function setupTeam(string $name = 'Arsenal'): array
    {
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => $name
        ]);

        return ['teamId' => $team['body']['$id']];
    }

    /**
     * Creates a team membership and returns membership details including secret.
     *
     * @param string $teamId The team ID
     * @return array Array containing 'teamId', 'membershipId', 'userId', 'secret'
     */
    protected function setupTeamMembership(string $teamId): array
    {
        $email = uniqid() . 'friend@localhost.test';

        // Create user first to ensure team event is triggered after user event
        $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => 'password',
            'name' => 'Friend User',
        ]);

        // Create membership
        $team = $this->client->call(Client::METHOD_POST, '/teams/' . $teamId . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => $email,
            'roles' => ['admin', 'editor'],
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        $membershipId = $team['body']['$id'];
        $userId = $team['body']['userId'];

        // Get the secret from email
        $lastEmail = $this->getLastEmail();
        $tokens = $this->extractQueryParamsFromEmailLink($lastEmail['html'] ?? '');
        $secret = $tokens['secret'] ?? '';

        return [
            'teamId' => $teamId,
            'membershipId' => $membershipId,
            'userId' => $userId,
            'secret' => $secret,
        ];
    }

    /**
     * Creates a document in a collection.
     *
     * @param string $databaseId Database ID
     * @param string $collectionId Collection ID
     * @return array Array containing document details including 'documentId'
     */
    protected function setupDocument(string $databaseId, string $collectionId): array
    {
        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'firstName' => 'Chris',
                'lastName' => 'Evans',
            ],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        return ['documentId' => $document['body']['$id']];
    }

    /**
     * Creates a row in a table.
     *
     * @param string $databaseId Database ID
     * @param string $tableId Table ID
     * @return array Array containing row details including 'rowId'
     */
    protected function setupRow(string $databaseId, string $tableId): array
    {
        $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $tableId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'firstName' => 'Chris',
                'lastName' => 'Evans',
            ],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        return ['rowId' => $row['body']['$id']];
    }

    /**
     * Creates a file in a bucket.
     *
     * @param string $bucketId Bucket ID
     * @return array Array containing file details including 'fileId'
     */
    protected function setupBucketFile(string $bucketId): array
    {
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'folderId' => ID::custom('xyz'),
        ]);

        return ['fileId' => $file['body']['$id']];
    }

    // Collection APIs
    public function testCreateCollection(): void
    {
        /**
         * Create database
         */
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Actors DB',
        ]);

        $databaseId = $database['body']['$id'];

        /**
          * Test for SUCCESS
          */
        $actors = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Actors',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'documentSecurity' => true,
        ]);

        $actorsId = $actors['body']['$id'];

        $this->assertEquals($actors['headers']['status-code'], 201);
        $this->assertNotEmpty($actors['body']['$id']);

        $webhook = $this->getLastRequest();
        $signatureExpected = self::getWebhookSignature($webhook, $this->getProject()['signatureKey']);

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('databases.' . $databaseId . '.collections.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('databases.' . $databaseId . '.collections.*.create', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.collections.{$actorsId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.collections.{$actorsId}.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), true);
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['name'], 'Actors');
        $this->assertIsArray($webhook['data']['$permissions']);
        $this->assertCount(4, $webhook['data']['$permissions']);
    }

    public function testCreateAttributes(): void
    {
        /**
         * Create database
         */
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Actors DB',
        ]);

        $databaseId = $database['body']['$id'];

        /**
         * Create collection
         */
        $actors = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => ID::unique(),
            'name' => 'Actors',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'documentSecurity' => true,
        ]);

        $actorsId = $actors['body']['$id'];

        $firstName = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $actorsId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'firstName',
            'size' => 256,
            'required' => true,
        ]);

        $lastName = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $actorsId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'lastName',
            'size' => 256,
            'required' => true,
        ]);

        $extra = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $actorsId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'extra',
            'size' => 64,
            'required' => false,
        ]);

        $attributeId = $extra['body']['key'];

        $this->assertEquals($firstName['headers']['status-code'], 202);
        $this->assertEquals($firstName['body']['key'], 'firstName');
        $this->assertEquals($lastName['headers']['status-code'], 202);
        $this->assertEquals($lastName['body']['key'], 'lastName');
        $this->assertEquals($extra['headers']['status-code'], 202);
        $this->assertEquals($extra['body']['key'], 'extra');

        // wait for database worker to kick in
        $this->assertEventually(function () use ($databaseId, $actorsId) {
            $webhook = $this->getLastRequest();
            $this->assertNotEmpty($webhook);
            $signatureExpected = self::getWebhookSignature($webhook, $this->getProject()['signatureKey']);
            $this->assertEquals($webhook['method'], 'POST');
            $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
            $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
            $this->assertStringContainsString('databases.' . $databaseId . '.collections.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
            $this->assertStringContainsString('databases.' . $databaseId . '.collections.*.attributes.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
            $this->assertStringContainsString('databases.' . $databaseId . '.collections.*.attributes.*.create', $webhook['headers']['X-Appwrite-Webhook-Events']);
            $this->assertStringContainsString("databases.{$databaseId}.collections.{$actorsId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
            $this->assertStringContainsString("databases.{$databaseId}.collections.{$actorsId}.attributes.*", $webhook['headers']['X-Appwrite-Webhook-Events']);
            $this->assertStringContainsString("databases.{$databaseId}.collections.{$actorsId}.attributes.*.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
            $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
            $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
            $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
            $this->assertNotEmpty($webhook['data']['key']);
            $this->assertEquals($webhook['data']['key'], 'extra');
        }, 15000, 500);

        $removed = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $data['actorsId'] . '/attributes/' . $extra['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $removed['headers']['status-code']);

        $webhook = $this->getLastRequest();
        $signatureExpected = self::getWebhookSignature($webhook, $this->getProject()['signatureKey']);

        // $this->assertEquals($webhook['method'], 'DELETE');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('databases.' . $databaseId . '.collections.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('databases.' . $databaseId . '.collections.*.attributes.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('databases.' . $databaseId . '.collections.*.attributes.*.update', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.collections.{$actorsId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.collections.{$actorsId}.attributes.*", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.collections.{$actorsId}.attributes.*.update", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertNotEmpty($webhook['data']['key']);
        $this->assertEquals($webhook['data']['key'], 'extra');
    }

    public function testCreateDocument(): void
    {
        // Set up collection with attributes
        $data = $this->setupCollectionWithAttributes();
        $actorsId = $data['actorsId'];
        $databaseId = $data['databaseId'];

        /**
         * Test for SUCCESS
         */
        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $actorsId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'firstName' => 'Chris',
                'lastName' => 'Evans',
            ],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $documentId = $document['body']['$id'];

        $this->assertEquals($document['headers']['status-code'], 201);
        $this->assertNotEmpty($document['body']['$id']);

        $webhook = $this->getLastRequest();
        $signatureExpected = self::getWebhookSignature($webhook, $this->getProject()['signatureKey']);

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('databases.' . $databaseId . '.collections.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('databases.' . $databaseId . '.collections.*.documents.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('databases.' . $databaseId . '.collections.*.documents.*.create', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.collections.*.documents.{$documentId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.collections.*.documents.{$documentId}.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.collections.{$actorsId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.collections.{$actorsId}.documents.*", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.collections.{$actorsId}.documents.*.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.collections.{$actorsId}.documents.{$documentId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.collections.{$actorsId}.documents.{$documentId}.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['firstName'], 'Chris');
        $this->assertEquals($webhook['data']['lastName'], 'Evans');
        $this->assertIsArray($webhook['data']['$permissions']);
        $this->assertCount(3, $webhook['data']['$permissions']);
    }

    public function testUpdateDocument(): void
    {
        // Set up collection with attributes and create a document
        $data = $this->setupCollectionWithAttributes();
        $actorsId = $data['actorsId'];
        $databaseId = $data['databaseId'];
        $documentData = $this->setupDocument($databaseId, $actorsId);
        $documentId = $documentData['documentId'];

        /**
         * Test for SUCCESS
         */
        $document = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $actorsId . '/documents/' . $documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'firstName' => 'Chris1',
                'lastName' => 'Evans2',
            ],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $documentId = $document['body']['$id'];

        $this->assertEquals($document['headers']['status-code'], 200);
        $this->assertNotEmpty($document['body']['$id']);

        $webhook = $this->getLastRequest();
        $signatureExpected = self::getWebhookSignature($webhook, $this->getProject()['signatureKey']);

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('databases.' . $databaseId . '.collections.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('databases.' . $databaseId . '.collections.*.documents.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('databases.' . $databaseId . '.collections.*.documents.*.update', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.collections.*.documents.{$documentId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.collections.*.documents.{$documentId}.update", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.collections.{$actorsId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.collections.{$actorsId}.documents.*", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.collections.{$actorsId}.documents.*.update", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.collections.{$actorsId}.documents.{$documentId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.collections.{$actorsId}.documents.{$documentId}.update", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['firstName'], 'Chris1');
        $this->assertEquals($webhook['data']['lastName'], 'Evans2');
        $this->assertIsArray($webhook['data']['$permissions']);
        $this->assertCount(3, $webhook['data']['$permissions']);
    }

    #[Retry(count: 1)]
    public function testDeleteDocument(): void
    {
        // Set up collection with attributes
        $data = $this->setupCollectionWithAttributes();
        $actorsId = $data['actorsId'];
        $databaseId = $data['databaseId'];

        /**
         * Test for SUCCESS
         */
        $document = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $actorsId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'firstName' => 'Bradly',
                'lastName' => 'Cooper',

            ],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $documentId = $document['body']['$id'];

        $this->assertEquals($document['headers']['status-code'], 201);
        $this->assertNotEmpty($document['body']['$id']);

        $document = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $actorsId . '/documents/' . $document['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($document['headers']['status-code'], 204);

        $webhook = $this->getLastRequest();
        $signatureExpected = self::getWebhookSignature($webhook, $this->getProject()['signatureKey']);

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('databases.' . $databaseId . '.collections.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('databases.' . $databaseId . '.collections.*.documents.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('databases.' . $databaseId . '.collections.*.documents.*.delete', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.collections.*.documents.{$documentId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.collections.*.documents.{$documentId}.delete", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.collections.{$actorsId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.collections.{$actorsId}.documents.*", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.collections.{$actorsId}.documents.*.delete", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.collections.{$actorsId}.documents.{$documentId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.collections.{$actorsId}.documents.{$documentId}.delete", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['firstName'], 'Bradly');
        $this->assertEquals($webhook['data']['lastName'], 'Cooper');
        $this->assertIsArray($webhook['data']['$permissions']);
        $this->assertCount(3, $webhook['data']['$permissions']);
    }

    // Table APIs
    public function testCreateTable(): void
    {
        /**
         * Create database
         */
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Actors DB',
        ]);

        $databaseId = $database['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $actors = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Actors',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'rowSecurity' => true,
        ]);

        $actorsId = $actors['body']['$id'];

        $this->assertEquals($actors['headers']['status-code'], 201);
        $this->assertNotEmpty($actors['body']['$id']);

        $webhook = $this->getLastRequest();
        $signatureExpected = self::getWebhookSignature($webhook, $this->getProject()['signatureKey']);

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('databases.' . $databaseId . '.tables.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('databases.' . $databaseId . '.tables.*.create', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.tables.{$actorsId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.tables.{$actorsId}.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), true);
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['name'], 'Actors');
        $this->assertIsArray($webhook['data']['$permissions']);
        $this->assertCount(4, $webhook['data']['$permissions']);
    }

    public function testCreateColumns(): void
    {
        /**
         * Create database
         */
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'Actors DB',
        ]);

        $databaseId = $database['body']['$id'];

        /**
         * Create table
         */
        $actors = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'tableId' => ID::unique(),
            'name' => 'Actors',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'rowSecurity' => true,
        ]);

        $actorsId = $actors['body']['$id'];

        $firstName = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $actorsId . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'firstName',
            'size' => 256,
            'required' => true,
        ]);

        $lastName = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $actorsId . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'lastName',
            'size' => 256,
            'required' => true,
        ]);

        $extra = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $actorsId . '/columns/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'extra',
            'size' => 64,
            'required' => false,
        ]);

        $this->assertEquals($firstName['headers']['status-code'], 202);
        $this->assertEquals($firstName['body']['key'], 'firstName');
        $this->assertEquals($lastName['headers']['status-code'], 202);
        $this->assertEquals($lastName['body']['key'], 'lastName');
        $this->assertEquals($extra['headers']['status-code'], 202);
        $this->assertEquals($extra['body']['key'], 'extra');

        // wait for database worker to kick in
        $this->assertEventually(function () use ($databaseId, $actorsId) {
            $webhook = $this->getLastRequest();
            $this->assertNotEmpty($webhook);
            $signatureExpected = self::getWebhookSignature($webhook, $this->getProject()['signatureKey']);
            $this->assertEquals($webhook['method'], 'POST');
            $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
            $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
            $this->assertStringContainsString('databases.' . $databaseId . '.tables.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
            $this->assertStringContainsString('databases.' . $databaseId . '.tables.*.columns.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
            $this->assertStringContainsString('databases.' . $databaseId . '.tables.*.columns.*.create', $webhook['headers']['X-Appwrite-Webhook-Events']);
            $this->assertStringContainsString("databases.{$databaseId}.tables.{$actorsId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
            $this->assertStringContainsString("databases.{$databaseId}.tables.{$actorsId}.columns.*", $webhook['headers']['X-Appwrite-Webhook-Events']);
            $this->assertStringContainsString("databases.{$databaseId}.tables.{$actorsId}.columns.*.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
            $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
            $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
            $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
            $this->assertNotEmpty($webhook['data']['key']);
            $this->assertEquals($webhook['data']['key'], 'extra');
        }, 15000, 500);

        $removed = $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $data['actorsId'] . '/columns/' . $extra['body']['key'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(204, $removed['headers']['status-code']);

        $webhook = $this->getLastRequest();
        $signatureExpected = self::getWebhookSignature($webhook, $this->getProject()['signatureKey']);

        // $this->assertEquals($webhook['method'], 'DELETE');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('databases.' . $databaseId . '.tables.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('databases.' . $databaseId . '.tables.*.columns.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('databases.' . $databaseId . '.tables.*.columns.*.update', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.tables.{$actorsId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.tables.{$actorsId}.columns.*", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.tables.{$actorsId}.columns.*.update", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertNotEmpty($webhook['data']['key']);
        $this->assertEquals($webhook['data']['key'], 'extra');
    }

    public function testCreateRow(): void
    {
        // Set up table with columns
        $data = $this->setupTableWithColumns();
        $actorsId = $data['actorsId'];
        $databaseId = $data['databaseId'];

        /**
         * Test for SUCCESS
         */
        $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $actorsId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'firstName' => 'Chris',
                'lastName' => 'Evans',
            ],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $documentId = $row['body']['$id'];

        $this->assertEquals($row['headers']['status-code'], 201);
        $this->assertNotEmpty($row['body']['$id']);

        $webhook = $this->getLastRequest();
        $signatureExpected = self::getWebhookSignature($webhook, $this->getProject()['signatureKey']);

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('databases.' . $databaseId . '.tables.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('databases.' . $databaseId . '.tables.*.rows.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('databases.' . $databaseId . '.tables.*.rows.*.create', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.tables.*.rows.{$documentId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.tables.*.rows.{$documentId}.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.tables.{$actorsId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.tables.{$actorsId}.rows.*", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.tables.{$actorsId}.rows.*.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.tables.{$actorsId}.rows.{$documentId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.tables.{$actorsId}.rows.{$documentId}.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['firstName'], 'Chris');
        $this->assertEquals($webhook['data']['lastName'], 'Evans');
        $this->assertIsArray($webhook['data']['$permissions']);
        $this->assertCount(3, $webhook['data']['$permissions']);
    }

    public function testUpdateRow(): void
    {
        // Set up table with columns and create a row
        $data = $this->setupTableWithColumns();
        $actorsId = $data['actorsId'];
        $databaseId = $data['databaseId'];
        $rowData = $this->setupRow($databaseId, $actorsId);
        $rowId = $rowData['rowId'];

        /**
         * Test for SUCCESS
         */
        $document = $this->client->call(Client::METHOD_PATCH, '/tablesdb/' . $databaseId . '/tables/' . $actorsId . '/rows/' . $rowId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'firstName' => 'Chris1',
                'lastName' => 'Evans2',
            ],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $rowId = $document['body']['$id'];

        $this->assertEquals($document['headers']['status-code'], 200);
        $this->assertNotEmpty($document['body']['$id']);

        $webhook = $this->getLastRequest();
        $signatureExpected = self::getWebhookSignature($webhook, $this->getProject()['signatureKey']);

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('databases.' . $databaseId . '.tables.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('databases.' . $databaseId . '.tables.*.rows.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('databases.' . $databaseId . '.tables.*.rows.*.update', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.tables.*.rows.{$rowId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.tables.*.rows.{$rowId}.update", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.tables.{$actorsId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.tables.{$actorsId}.rows.*", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.tables.{$actorsId}.rows.*.update", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.tables.{$actorsId}.rows.{$rowId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.tables.{$actorsId}.rows.{$rowId}.update", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['firstName'], 'Chris1');
        $this->assertEquals($webhook['data']['lastName'], 'Evans2');
        $this->assertIsArray($webhook['data']['$permissions']);
        $this->assertCount(3, $webhook['data']['$permissions']);
    }

    #[Retry(count: 1)]
    public function testDeleteRow(): void
    {
        // Set up table with columns
        $data = $this->setupTableWithColumns();
        $actorsId = $data['actorsId'];
        $databaseId = $data['databaseId'];

        /**
         * Test for SUCCESS
         */
        $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $actorsId . '/rows', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'rowId' => ID::unique(),
            'data' => [
                'firstName' => 'Bradly',
                'lastName' => 'Cooper',

            ],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $rowId = $row['body']['$id'];

        $this->assertEquals($row['headers']['status-code'], 201);
        $this->assertNotEmpty($row['body']['$id']);

        $row = $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId . '/tables/' . $actorsId . '/rows/' . $row['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($row['headers']['status-code'], 204);

        $webhook = $this->getLastRequest();
        $signatureExpected = self::getWebhookSignature($webhook, $this->getProject()['signatureKey']);

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('databases.' . $databaseId . '.tables.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('databases.' . $databaseId . '.tables.*.rows.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('databases.' . $databaseId . '.tables.*.rows.*.delete', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.tables.*.rows.{$rowId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.tables.*.rows.{$rowId}.delete", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.tables.{$actorsId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.tables.{$actorsId}.rows.*", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.tables.{$actorsId}.rows.*.delete", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.tables.{$actorsId}.rows.{$rowId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("databases.{$databaseId}.tables.{$actorsId}.rows.{$rowId}.delete", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['firstName'], 'Bradly');
        $this->assertEquals($webhook['data']['lastName'], 'Cooper');
        $this->assertIsArray($webhook['data']['$permissions']);
        $this->assertCount(3, $webhook['data']['$permissions']);
    }

    public function testCreateStorageBucket(): void
    {
        /**
         * Test for SUCCESS
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $bucketId = $bucket['body']['$id'];

        $this->assertEquals($bucket['headers']['status-code'], 201);
        $this->assertNotEmpty($bucket['body']['$id']);

        $webhook = $this->getLastRequest();
        $signatureExpected = self::getWebhookSignature($webhook, $this->getProject()['signatureKey']);

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('buckets.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('buckets.*.create', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("buckets.{$bucketId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("buckets.{$bucketId}.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), true);
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals('Test Bucket', $webhook['data']['name']);
        $this->assertEquals(true, $webhook['data']['enabled']);
        $this->assertIsArray($webhook['data']['$permissions']);
    }

    public function testUpdateStorageBucket(): void
    {
        // Set up a storage bucket
        $data = $this->setupStorageBucket();
        $bucketId = $data['bucketId'];

        /**
         * Test for SUCCESS
         */
        $bucket = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'Test Bucket Updated',
            'fileSecurity' => true,
            'enabled' => false,
        ]);

        $this->assertEquals($bucket['headers']['status-code'], 200);
        $this->assertNotEmpty($bucket['body']['$id']);

        $webhook = $this->getLastRequest();
        $signatureExpected = self::getWebhookSignature($webhook, $this->getProject()['signatureKey']);

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('buckets.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('buckets.*.update', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("buckets.{$bucketId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("buckets.{$bucketId}.update", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), true);
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals('Test Bucket Updated', $webhook['data']['name']);
        $this->assertEquals(false, $webhook['data']['enabled']);
        $this->assertIsArray($webhook['data']['$permissions']);
    }

    public function testCreateBucketFile(): void
    {
        // Set up an enabled storage bucket
        $data = $this->setupStorageBucket();
        $bucketId = $data['bucketId'];

        /**
         * Test for SUCCESS
         */
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'folderId' => ID::custom('xyz'),
        ]);

        $fileId = $file['body']['$id'];

        $this->assertEquals($file['headers']['status-code'], 201);
        $this->assertNotEmpty($file['body']['$id']);

        $webhook = $this->getLastRequest();
        $signatureExpected = self::getWebhookSignature($webhook, $this->getProject()['signatureKey']);

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('buckets.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('buckets.*.files.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('buckets.*.files.*.create', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("buckets.*.files.{$fileId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("buckets.*.files.{$fileId}.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("buckets.{$bucketId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("buckets.{$bucketId}.files.*", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("buckets.{$bucketId}.files.*.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("buckets.{$bucketId}.files.{$fileId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("buckets.{$bucketId}.files.{$fileId}.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertIsArray($webhook['data']['$permissions']);
        $this->assertEquals($webhook['data']['name'], 'logo.png');
        $this->assertEquals(true, (new DatetimeValidator())->isValid($webhook['data']['$createdAt']));
        $this->assertNotEmpty($webhook['data']['signature']);
        $this->assertEquals($webhook['data']['mimeType'], 'image/png');
        $this->assertEquals($webhook['data']['sizeOriginal'], 47218);
    }

    public function testUpdateBucketFile(): void
    {
        // Set up an enabled storage bucket and create a file
        $data = $this->setupStorageBucket();
        $bucketId = $data['bucketId'];
        $fileData = $this->setupBucketFile($bucketId);
        $fileId = $fileData['fileId'];

        /**
         * Test for SUCCESS
         */
        $file = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $this->assertEquals($file['headers']['status-code'], 200);
        $this->assertNotEmpty($file['body']['$id']);

        $webhook = $this->getLastRequest();
        $signatureExpected = self::getWebhookSignature($webhook, $this->getProject()['signatureKey']);

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('buckets.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('buckets.*.files.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('buckets.*.files.*.update', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("buckets.*.files.{$fileId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("buckets.*.files.{$fileId}.update", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("buckets.{$bucketId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("buckets.{$bucketId}.files.*", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("buckets.{$bucketId}.files.*.update", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("buckets.{$bucketId}.files.{$fileId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("buckets.{$bucketId}.files.{$fileId}.update", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertIsArray($webhook['data']['$permissions']);
        $this->assertEquals($webhook['data']['name'], 'logo.png');
        $this->assertEquals(true, (new DatetimeValidator())->isValid($webhook['data']['$createdAt']));
        $this->assertNotEmpty($webhook['data']['signature']);
        $this->assertEquals($webhook['data']['mimeType'], 'image/png');
        $this->assertEquals($webhook['data']['sizeOriginal'], 47218);
    }

    public function testDeleteBucketFile(): void
    {
        // Set up an enabled storage bucket and create a file
        $data = $this->setupStorageBucket();
        $bucketId = $data['bucketId'];
        $fileData = $this->setupBucketFile($bucketId);
        $fileId = $fileData['fileId'];

        /**
         * Test for SUCCESS
         */
        $file = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $file['headers']['status-code']);
        $this->assertEmpty($file['body']);

        $webhook = $this->getLastRequest();
        $signatureExpected = self::getWebhookSignature($webhook, $this->getProject()['signatureKey']);

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('buckets.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('buckets.*.files.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('buckets.*.files.*.delete', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("buckets.*.files.{$fileId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("buckets.*.files.{$fileId}.delete", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("buckets.{$bucketId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("buckets.{$bucketId}.files.*", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("buckets.{$bucketId}.files.*.delete", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("buckets.{$bucketId}.files.{$fileId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("buckets.{$bucketId}.files.{$fileId}.delete", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertIsArray($webhook['data']['$permissions']);
        $this->assertEquals($webhook['data']['name'], 'logo.png');
        $this->assertEquals(true, (new DatetimeValidator())->isValid($webhook['data']['$createdAt']));
        $this->assertNotEmpty($webhook['data']['signature']);
        $this->assertEquals($webhook['data']['mimeType'], 'image/png');
        $this->assertEquals($webhook['data']['sizeOriginal'], 47218);
    }

    public function testDeleteStorageBucket(): void
    {
        // Set up an enabled storage bucket
        $data = $this->setupStorageBucket();
        $bucketId = $data['bucketId'];

        // Update bucket name before deleting to make test self-sufficient
        // (In parallel execution, testUpdateStorageBucket may not have run)
        $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'Test Bucket Updated',
            'fileSecurity' => true,
        ]);

        /**
         * Test for SUCCESS
         */
        $bucket = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals($bucket['headers']['status-code'], 204);
        $this->assertEmpty($bucket['body']);

        $webhook = $this->getLastRequest();
        $signatureExpected = self::getWebhookSignature($webhook, $this->getProject()['signatureKey']);

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('buckets.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('buckets.*.delete', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("buckets.{$bucketId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("buckets.{$bucketId}.delete", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), true);
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals('Test Bucket Updated', $webhook['data']['name']);
        $this->assertEquals(true, $webhook['data']['enabled']);
        $this->assertIsArray($webhook['data']['$permissions']);
    }

    public function testCreateTeam(): void
    {
        /**
         * Test for SUCCESS
         */
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Arsenal'
        ]);

        $teamId = $team['body']['$id'];

        $this->assertEquals(201, $team['headers']['status-code']);
        $this->assertNotEmpty($team['body']['$id']);

        $webhook = $this->getLastRequest();
        $signatureExpected = self::getWebhookSignature($webhook, $this->getProject()['signatureKey']);

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('teams.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('teams.*.create', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamId}.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals('Arsenal', $webhook['data']['name']);
        $this->assertGreaterThan(-1, $webhook['data']['total']);
        $this->assertIsInt($webhook['data']['total']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($webhook['data']['$createdAt']));
    }

    public function testUpdateTeam(): void
    {
        // Set up a team
        $data = $this->setupTeam();
        $teamId = $data['teamId'];
        /**
         * Test for SUCCESS
         */
        $team = $this->client->call(Client::METHOD_PUT, '/teams/' . $teamId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Demo New'
        ]);

        $this->assertEquals(200, $team['headers']['status-code']);
        $this->assertNotEmpty($team['body']['$id']);

        $webhook = $this->getLastRequest();
        $signatureExpected = self::getWebhookSignature($webhook, $this->getProject()['signatureKey']);

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('teams.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('teams.*.update', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamId}.update", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals('Demo New', $webhook['data']['name']);
        $this->assertGreaterThan(-1, $webhook['data']['total']);
        $this->assertIsInt($webhook['data']['total']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($webhook['data']['$createdAt']));
    }

    public function testUpdateTeamPrefs(): void
    {
        // Set up a team
        $data = $this->setupTeam();
        $id = $data['teamId'];

        $team = $this->client->call(Client::METHOD_PUT, '/teams/' . $id . '/prefs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'prefs' => [
                'prefKey1' => 'prefValue1',
                'prefKey2' => 'prefValue2',
            ]
        ]);

        $this->assertEquals($team['headers']['status-code'], 200);
        $this->assertIsArray($team['body']);

        $webhook = $this->getLastRequest();
        $signatureKey = $this->getProject()['signatureKey'];
        $payload = json_encode($webhook['data']);
        $url     = $webhook['url'];
        $signatureExpected = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('teams.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('teams.*.update', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('teams.*.update.prefs', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$id}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$id}.update", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$id}.update.prefs", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertEquals($webhook['data'], [
            'prefKey1' => 'prefValue1',
            'prefKey2' => 'prefValue2',
        ]);
    }

    public function testDeleteTeam(): void
    {
        /**
         * Test for SUCCESS
         */
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Chelsea'
        ]);

        $teamId = $team['body']['$id'];

        $this->assertEquals(201, $team['headers']['status-code']);
        $this->assertNotEmpty($team['body']['$id']);

        $team = $this->client->call(Client::METHOD_DELETE, '/teams/' . $team['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $webhook = $this->getLastRequest();
        $signatureExpected = self::getWebhookSignature($webhook, $this->getProject()['signatureKey']);

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('teams.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('teams.*.delete', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamId}.delete", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals('Chelsea', $webhook['data']['name']);
        $this->assertGreaterThan(-1, $webhook['data']['total']);
        $this->assertIsInt($webhook['data']['total']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($webhook['data']['$createdAt']));
    }

    public function testCreateTeamMembership(): void
    {
        // Set up a team
        $data = $this->setupTeam();
        $teamId = $data['teamId'];
        $email = uniqid() . 'friend@localhost.test';

        // Create user to ensure team event is triggered after user event
        $user = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => 'password',
            'name' => 'Friend User',
        ]);

        /**
         * Test for SUCCESS
         */
        $team = $this->client->call(Client::METHOD_POST, '/teams/' . $teamId . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => $email,
            'roles' => ['admin', 'editor'],
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);
        $this->assertNotEmpty($team['body']['$id']);

        $lastEmail = $this->getLastEmail();

        // `$isAppUser` — no email expected;
        $tokens = $this->extractQueryParamsFromEmailLink($lastEmail['html'] ?? '');

        $secret = $tokens['secret'] ?? '';
        $membershipId = $team['body']['$id'];

        $webhook = $this->getLastRequest();
        $signatureExpected = self::getWebhookSignature($webhook, $this->getProject()['signatureKey']);

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('teams.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('teams.*.memberships.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('teams.*.memberships.*.create', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.*.memberships.{$membershipId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.*.memberships.{$membershipId}.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamId}.memberships.*", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamId}.memberships.*.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamId}.memberships.{$membershipId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamId}.memberships.{$membershipId}.create", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertNotEmpty($webhook['data']['userId']);
        $this->assertNotEmpty($webhook['data']['teamId']);
        $this->assertCount(2, $webhook['data']['roles']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($webhook['data']['invited']));
        $this->assertEquals(('server' === $this->getSide()), $webhook['data']['confirm']);
    }

    public function testDeleteTeamMembership(): void
    {
        // Set up a team
        $data = $this->setupTeam();
        $teamId = $data['teamId'];
        $email = uniqid() . 'friend@localhost.test';

        /**
         * Test for SUCCESS
         */
        $team = $this->client->call(Client::METHOD_POST, '/teams/' . $teamId . '/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'email' => $email,
            'name' => 'Friend User',
            'roles' => ['admin', 'editor'],
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        $membershipId = $team['body']['$id'] ?? '';

        $this->assertEquals(201, $team['headers']['status-code']);
        $this->assertNotEmpty($team['body']['$id']);

        $team = $this->client->call(Client::METHOD_DELETE, '/teams/' . $teamId . '/memberships/' . $team['body']['$id'], array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $team['headers']['status-code']);

        $webhook = $this->getLastRequest();
        $signatureExpected = self::getWebhookSignature($webhook, $this->getProject()['signatureKey']);

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertStringContainsString('teams.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('teams.*.memberships.*', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString('teams.*.memberships.*.delete', $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.*.memberships.{$membershipId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.*.memberships.{$membershipId}.delete", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamId}.memberships.*", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamId}.memberships.*.delete", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamId}.memberships.{$membershipId}", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertStringContainsString("teams.{$teamId}.memberships.{$membershipId}.delete", $webhook['headers']['X-Appwrite-Webhook-Events']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], $signatureExpected);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertNotEmpty($webhook['data']['userId']);
        $this->assertNotEmpty($webhook['data']['teamId']);
        $this->assertCount(2, $webhook['data']['roles']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($webhook['data']['invited']));
        $this->assertEquals(('server' === $this->getSide()), $webhook['data']['confirm']);
    }

    public function testCreateWebhookWithPrivateDomain(): void
    {
        /**
         * Test for FAILURE
         */
        $projectId = $this->getProject()['$id'];
        $webhook = $this->client->call(Client::METHOD_POST, '/projects/' . $projectId . '/webhooks', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'name' => 'Webhook Test',
            'enabled' => true,
            'events' => [
                'databases.*',
                'functions.*',
                'buckets.*',
                'teams.*',
                'users.*'
            ],
            'url' => 'http://localhost/webhook', // private domains not allowed
            'security' => false,
        ]);

        $this->assertEquals(400, $webhook['headers']['status-code']);
    }

    public function testUpdateWebhookWithPrivateDomain(): void
    {
        /**
         * Test for FAILURE
         */
        $projectId = $this->getProject()['$id'];
        $webhookId = $this->getProject()['webhookId'];
        $webhook = $this->client->call(Client::METHOD_PUT, '/projects/' . $projectId . '/webhooks/' . $webhookId, [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'name' => 'Webhook Test',
            'enabled' => true,
            'events' => [
                'databases.*',
                'functions.*',
                'buckets.*',
                'teams.*',
                'users.*'
            ],
            'url' => 'http://localhost/webhook', // private domains not allowed
            'security' => false,
        ]);

        $this->assertEquals(400, $webhook['headers']['status-code']);
    }

    public function testWebhookAutoDisable(): void
    {
        $projectId = $this->getProject()['$id'];
        $webhookId = $this->getProject()['webhookId'];

        // Create a database for this test
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => ID::unique(),
            'name' => 'AutoDisable DB',
        ]);

        $databaseId = $database['body']['$id'];

        $webhook = $this->client->call(Client::METHOD_PUT, '/projects/' . $projectId . '/webhooks/' . $webhookId, [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'name' => 'Webhook Test',
            'enabled' => true,
            'events' => [
                'databases.*',
                'functions.*',
                'buckets.*',
                'teams.*',
                'users.*'
            ],
            'url' => 'http://appwrite-non-existing-domain.com', // set non-existent URL
            'security' => false,
        ]);

        $this->assertEquals(200, $webhook['headers']['status-code']);
        $this->assertNotEmpty($webhook['body']);

        // trigger webhook for failure event 10 times
        for ($i = 0; $i < 10; $i++) {
            $newCollection = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'collectionId' => ID::unique(),
                'name' => 'newCollection' . $i,
                'permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'documentSecurity' => true,
            ]);

            $this->assertEquals($newCollection['headers']['status-code'], 201);
            $this->assertNotEmpty($newCollection['body']['$id']);
        }

        $this->assertEventually(function () use ($projectId, $webhookId) {
            $webhook = $this->client->call(Client::METHOD_GET, '/projects/' . $projectId . '/webhooks/' . $webhookId, array_merge([
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'cookie' => 'a_session_console=' . $this->getRoot()['session'],
                'x-appwrite-project' => 'console',
            ]));

            // assert that the webhook is now disabled after 10 consecutive failures
            $this->assertEquals($webhook['body']['enabled'], false);
            $this->assertEquals($webhook['body']['attempts'], 10);
        }, 15000, 500);
    }
}
