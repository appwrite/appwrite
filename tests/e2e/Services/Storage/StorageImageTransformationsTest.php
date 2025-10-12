<?php

namespace Tests\E2E\Services\Storage;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

class StorageImageTransformationsTest extends Scope
{
    use ProjectCustom;
    use SideClient;

    public function testImageTransformationsDisabledBlocksPreviewForAllUsers(): array
    {
        // Create a bucket with imageTransformations disabled
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => ID::unique(),
            'name' => 'ImageTransformDisabled',
            'fileSecurity' => false,
            'imageTransformations' => false,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $bucket['headers']['status-code']);
        $bucketId = $bucket['body']['$id'];
        $this->assertNotEmpty($bucketId);

        // Upload an image to the bucket
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
        ]);

        $this->assertEquals(201, $file['headers']['status-code']);
        $fileId = $file['body']['$id'];
        $this->assertNotEmpty($fileId);

        // Attempt preview as normal project session user -> should be unauthorized
        $preview = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(401, $preview['headers']['status-code']);

        // Attempt preview using project API key (previously privileged) -> should also be unauthorized
        $previewKey = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(401, $previewKey['headers']['status-code']);

        return ['bucketId' => $bucketId, 'fileId' => $fileId];
    }

    /**
     * @depends testImageTransformationsDisabledBlocksPreviewForAllUsers
     */
    public function testToggleImageTransformationsEnablesAndDisablesPreview(array $data): void
    {
        $bucketId = $data['bucketId'];
        $fileId = $data['fileId'];

        // Enable image transformations via bucket update
        $update = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'name' => 'ImageTransformDisabled',
            'imageTransformations' => true,
        ]);

        $this->assertEquals(200, $update['headers']['status-code']);

        // Now preview should be allowed for session user
        $preview = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $preview['headers']['status-code']);

        // And allowed for project API key
        $previewKey = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $previewKey['headers']['status-code']);

        // Now disable image transformations again
        $update2 = $this->client->call(Client::METHOD_PUT, '/storage/buckets/' . $bucketId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'name' => 'ImageTransformDisabled',
            'imageTransformations' => false,
        ]);

        $this->assertEquals(200, $update2['headers']['status-code']);

        // Preview should now be unauthorized again for session
        $preview2 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(401, $preview2['headers']['status-code']);

        // And for API key
        $previewKey2 = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(401, $previewKey2['headers']['status-code']);
    }
}
