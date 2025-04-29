<?php

namespace Tests\E2E\Services\Tokens;

use CURLFile;
use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;

trait TokensBase
{
    public function testCreateBucketAndFile(): array
    {
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'name' => 'Test Bucket',
            'bucketId' => ID::unique(),
            'allowedFileExtensions' => ['jpg', 'png', 'jfif'],
        ]);

        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucket['body']['$id']);

        $bucketId = $bucket['body']['$id'];

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
        ]);

        $this->assertEquals(201, $file['headers']['status-code']);
        $this->assertNotEmpty($file['body']['$id']);

        $fileId = $file['body']['$id'];

        $token = $this->client->call(Client::METHOD_POST, '/tokens/buckets/' . $bucketId . '/files/' . $fileId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(201, $token['headers']['status-code']);
        $this->assertEquals('files', $token['body']['resourceType']);
        $this->assertEquals($bucketId . ':' . $fileId, $token['body']['resourceId']);

        return [
            'fileId' => $fileId,
            'bucketId' => $bucketId,
            'tokenId' => $token['body']['$id'],
            'guestHeaders' => [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ],
        ];
    }

    /**
     * @depends testCreateBucketAndFile
     */
    public function testPreviewAccessFailureWithoutToken(array $data): array
    {
        $fileId = $data['fileId'];
        $bucketId = $data['bucketId'];
        $guestHeaders = $data['guestHeaders'];

        // Fail, anonymous user.
        $fileFailedPreview = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview', $guestHeaders);
        $this->assertEquals(401, $fileFailedPreview['body']['code']);
        $this->assertEquals(401, $fileFailedPreview['headers']['status-code']);
        $this->assertEquals('user_unauthorized', $fileFailedPreview['body']['type']);
        $this->assertEquals('The current user is not authorized to perform the requested action.', $fileFailedPreview['body']['message']);

        return $data;
    }

    /**
     * @depends testCreateBucketAndFile
     */
    public function testPreviewAccessFileWithToken(array $data): array
    {
        $fileId = $data['fileId'];
        $tokenId = $data['tokenId'];
        $bucketId = $data['bucketId'];
        $guestHeaders = $data['guestHeaders'];
        $adminHeaders = array_merge($guestHeaders, ['x-appwrite-key' => $this->getProject()['apiKey']]);

        // Generate JWT as an admin user.
        $tokenJWT = $this->client->call(Client::METHOD_GET, '/tokens/' . $tokenId . '/jwt/', $adminHeaders);
        $this->assertEquals(200, $tokenJWT['headers']['status-code']);
        $this->assertArrayHasKey('jwt', $tokenJWT['body']);

        $tokenJWT = $tokenJWT['body']['jwt'];

        // Generate a preview
        $filePreview = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview?token=' . $tokenJWT, $guestHeaders);
        $this->assertEquals(200, $filePreview['headers']['status-code']);
        $this->assertEquals('image/png', $filePreview['headers']['content-type']);
        $this->assertNotEmpty($filePreview['body']);

        $image = new \Imagick();
        $image->readImageBlob($filePreview['body']);
        $original = new \Imagick(__DIR__ . '/../../../resources/logo.png');

        $this->assertEquals($image->getImageWidth(), $original->getImageWidth());
        $this->assertEquals($image->getImageHeight(), $original->getImageHeight());
        $this->assertEquals('PNG', $image->getImageFormat());

        $data['jwtToken'] = $tokenJWT;
        return $data;
    }

    /**
     * @depends testPreviewAccessFileWithToken
     */
    public function testViewAccessFileWithToken(array $data): void
    {
        $fileId = $data['fileId'];
        $bucketId = $data['bucketId'];
        $jwtToken = $data['jwtToken'];
        $guestHeaders = $data['guestHeaders'];

        $fileView = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/view?token=' . $jwtToken, $guestHeaders);

        $this->assertEquals(200, $fileView['headers']['status-code']);

        $image = new \Imagick();
        $image->readImageBlob($fileView['body']);
        $original = new \Imagick(__DIR__ . '/../../../resources/logo.png');

        $this->assertEquals($image->getImageWidth(), $original->getImageWidth());
        $this->assertEquals($image->getImageHeight(), $original->getImageHeight());
        $this->assertEquals('PNG', $image->getImageFormat());
    }

    /**
     * @depends testPreviewAccessFileWithToken
     */
    public function testDownloadAccessFileWithToken(array $data): void
    {
        $fileId = $data['fileId'];
        $bucketId = $data['bucketId'];
        $jwtToken = $data['jwtToken'];
        $guestHeaders = $data['guestHeaders'];

        /**
         * Test should fail because -
         *
         * 1. There's no token logic on download endpoint
         * 2. The user does not have permissions as a guest user
         */
        $fileFailedDownload = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/download?token=' . $jwtToken, $guestHeaders);

        $this->assertEquals(401, $fileFailedDownload['body']['code']);
        $this->assertEquals(401, $fileFailedDownload['headers']['status-code']);
        $this->assertEquals('user_unauthorized', $fileFailedDownload['body']['type']);
        $this->assertEquals('The current user is not authorized to perform the requested action.', $fileFailedDownload['body']['message']);
    }
}
