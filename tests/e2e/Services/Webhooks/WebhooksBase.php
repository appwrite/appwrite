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


    public function testCreateCollection(): array
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

        return array_merge(['actorsId' => $actorsId, 'databaseId' => $databaseId]);
    }

    /**
     * @depends testCreateCollection
     */
    public function testCreateAttributes(array $data): array
    {
        $actorsId = $data['actorsId'];
        $databaseId = $data['databaseId'];

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
        sleep(10);

        $webhook = $this->getLastRequest();
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

        return $data;
    }

    /**
     * @depends testCreateAttributes
     */
    public function testCreateDocument(array $data): array
    {
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

        $data['documentId'] = $document['body']['$id'];

        return $data;
    }

    /**
     * @depends testCreateDocument
     */
    public function testUpdateDocument(array $data): array
    {
        $actorsId = $data['actorsId'];
        $databaseId = $data['databaseId'];

        /**
         * Test for SUCCESS
         */
        $document = $this->client->call(Client::METHOD_PATCH, '/databases/' . $databaseId . '/collections/' . $actorsId . '/documents/' . $data['documentId'], array_merge([
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

        return $data;
    }

    /**
     * @depends testCreateCollection
     */
    #[Retry(count: 1)]
    public function testDeleteDocument(array $data): array
    {
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

        return $data;
    }


    public function testCreateStorageBucket(): array
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

        return array_merge(['bucketId' => $bucketId]);
    }

    /**
     * @depends testCreateStorageBucket
     */
    public function testUpdateStorageBucket(array $data): array
    {
        $bucketId = $data['bucketId'];

        /**
         * Test for SUCCESS
         */
        $bucket = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $data['bucketId'], array_merge([
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

        return array_merge(['bucketId' => $bucket['body']['$id']]);
    }

    /**
     * @depends testCreateStorageBucket
     */
    public function testCreateBucketFile(array $data): array
    {
        $bucketId = $data['bucketId'];

        //enable bucket
        $bucket = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $data['bucketId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'Test Bucket Updated',
            'fileSecurity' => true,
            'enabled' => true,
        ]);

        $this->assertEquals($bucket['headers']['status-code'], 200);
        /**
         * Test for SUCCESS
         */
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $data['bucketId'] . '/files', array_merge([
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

        $data['fileId'] = $fileId;

        return $data;
    }

    /**
     * @depends testCreateBucketFile
     */
    public function testUpdateBucketFile(array $data): array
    {
        $bucketId = $data['bucketId'];
        $fileId = $data['fileId'];

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

        return $data;
    }

    /**
     * @depends testUpdateBucketFile
     */
    public function testDeleteBucketFile(array $data): array
    {
        $bucketId = $data['bucketId'];
        $fileId = $data['fileId'];

        /**
         * Test for SUCCESS
         */
        $file = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $data['bucketId'] . '/files/' . $data['fileId'], array_merge([
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

        return $data;
    }

    /**
     * @depends testDeleteBucketFile
     */
    public function testDeleteStorageBucket(array $data)
    {
        $bucketId = $data['bucketId'];
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

    public function testCreateTeam(): array
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

        /**
         * Test for FAILURE
         */
        return ['teamId' => $teamId];
    }

    /**
     * @depends testCreateTeam
     */
    public function testUpdateTeam($data): array
    {
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

        /**
         * Test for FAILURE
         */
        return ['teamId' => $team['body']['$id']];
    }

    /**
     * @depends testCreateTeam
     */
    public function testUpdateTeamPrefs(array $data): array
    {
        $id = $data['teamId'] ?? '';

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

        return $data;
    }

    public function testDeleteTeam(): array
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

        /**
         * Test for FAILURE
         */
        return [];
    }

    /**
     * @depends testCreateTeam
     */
    public function testCreateTeamMembership($data): array
    {
        $teamId = $data['teamId'] ?? '';
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

        $secret = substr($lastEmail['text'], strpos($lastEmail['text'], '&secret=', 0) + 8, 256);
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

        /**
         * Test for FAILURE
         */
        return [
            'teamId' => $teamId,
            'secret' => $secret,
            'membershipId' => $membershipId,
            'userId' => $webhook['data']['userId'],
        ];
    }

    /**
     * @depends testCreateTeamMembership
     */
    public function testDeleteTeamMembership($data): void
    {
        $teamId = $data['teamId'] ?? '';
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

    /**
     * @depends testCreateCollection
     */
    public function testWebhookAutoDisable(array $data): void
    {
        $projectId = $this->getProject()['$id'];
        $webhookId = $this->getProject()['webhookId'];
        $databaseId = $data['databaseId'];

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

        sleep(10);

        $webhook = $this->client->call(Client::METHOD_GET, '/projects/' . $projectId . '/webhooks/' . $webhookId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ]));

        // assert that the webhook is now disabled after 10 consecutive failures
        $this->assertEquals($webhook['body']['enabled'], false);
        $this->assertEquals($webhook['body']['attempts'], 10);
    }
}
