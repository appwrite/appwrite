<?php

namespace Tests\E2E\Services\Storage;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\DateTime;
use Utopia\Database\Helpers\ID;

class StorageCustomServerTest extends Scope
{
    use StorageBase;
    use ProjectCustom;
    use SideServer;

    public function testCreateBucket(): array
    {
        /**
         * Test for SUCCESS
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket',
            'fileSecurity' => true,
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucket['body']['$id']);
        $this->assertEquals(true, self::$dateValidator->isValid($bucket['body']['$createdAt']));
        $this->assertIsArray($bucket['body']['$permissions']);
        $this->assertIsArray($bucket['body']['allowedFileExtensions']);
        $this->assertEquals('Test Bucket', $bucket['body']['name']);
        $this->assertEquals(true, $bucket['body']['enabled']);
        $this->assertEquals(true, $bucket['body']['encryption']);
        $this->assertEquals(true, $bucket['body']['antivirus']);
        $bucketId = $bucket['body']['$id'];

        /**
         * Test create with Custom ID
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'bucketId' => ID::custom('bucket1'),
            'name' => 'Test Bucket',
            'fileSecurity' => true,
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertEquals('bucket1', $bucket['body']['$id']);

        /**
         * Test for FAILURE
         */
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'bucketId' => ID::unique(),
            'name' => '',
            'fileSecurity' => true,
        ]);
        $this->assertEquals(400, $bucket['headers']['status-code']);

        return ['bucketId' => $bucketId];
    }

    /**
     * @depends testCreateBucket
     */
    public function testListBucket($data): array
    {
        $id = $data['bucketId'] ?? '';
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(
            Client::METHOD_GET,
            '/storage/buckets',
            array_merge(
                [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                ],
                $this->getHeaders()
            )
        );
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals($id, $response['body']['buckets'][0]['$id']);
        $this->assertEquals('Test Bucket', $response['body']['buckets'][0]['name']);

        $response = $this->client->call(Client::METHOD_GET, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'limit(1)' ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['buckets']);

        $response = $this->client->call(Client::METHOD_GET, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'offset(1)' ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['buckets']);

        $response = $this->client->call(Client::METHOD_GET, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'equal("$id", "bucket1")' ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['buckets']);

        $response = $this->client->call(Client::METHOD_GET, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'equal("fileSecurity", true)' ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(2, $response['body']['buckets']);

        $response = $this->client->call(Client::METHOD_GET, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [ 'cursorAfter("' . $response['body']['buckets'][0]['$id'] . '")' ],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['buckets']);
        $this->assertCount(1, $response['body']['buckets']);

        $this->assertEquals('bucket1', $response['body']['buckets'][0]['$id']);
        return $data;
    }

    /**
     * @depends testCreateBucket
     */
    public function testGetBucket(array $data): array
    {
        $id = $data['bucketId'] ?? '';
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(
            Client::METHOD_GET,
            '/storage/buckets/' . $id,
            array_merge(
                [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                ],
                $this->getHeaders()
            )
        );
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']);
        $this->assertEquals($id, $response['body']['$id']);
        $this->assertEquals('Test Bucket', $response['body']['name']);

        /**
         * Test for FAILURE
         */

        $response = $this->client->call(
            Client::METHOD_GET,
            '/storage/buckets/empty',
            array_merge(
                [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                ],
                $this->getHeaders()
            )
        );
        $this->assertEquals(404, $response['headers']['status-code']);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/storage/buckets/id-is-really-long-id-is-really-long-id-is-really-long-id-is-really-long',
            array_merge(
                [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                ],
                $this->getHeaders()
            )
        );
        $this->assertEquals(400, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateBucket
     */
    public function testUpdateBucket(array $data): array
    {
        $id = $data['bucketId'] ?? '';
        /**
         * Test for SUCCESS
         */
        $bucket = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket Updated',
            'enabled' => false,
            'fileSecurity' => true,
        ]);
        $this->assertEquals(200, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucket['body']['$id']);
        $this->assertEquals(true, self::$dateValidator->isValid($bucket['body']['$createdAt']));
        $this->assertIsArray($bucket['body']['$permissions']);
        $this->assertIsArray($bucket['body']['allowedFileExtensions']);
        $this->assertEquals('Test Bucket Updated', $bucket['body']['name']);
        $this->assertEquals(false, $bucket['body']['enabled']);
        $this->assertEquals(true, $bucket['body']['encryption']);
        $this->assertEquals(true, $bucket['body']['antivirus']);
        $bucketId = $bucket['body']['$id'];
        /**
         * Test for FAILURE
         */
        $bucket = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => '',
            'enabled' => 'false',
        ]);
        $this->assertEquals(400, $bucket['headers']['status-code']);

        return ['bucketId' => $bucketId];
    }

    /**
     * @depends testCreateBucket
     */
    public function testDeleteBucket(array $data): array
    {
        $id = $data['bucketId'] ?? '';
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(
            Client::METHOD_DELETE,
            '/storage/buckets/' . $id,
            array_merge(
                [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                ],
                $this->getHeaders()
            )
        );
        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/storage/buckets/' . $id,
            array_merge(
                [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                ],
                $this->getHeaders()
            )
        );
        $this->assertEquals(404, $response['headers']['status-code']);

        return $data;
    }
}
