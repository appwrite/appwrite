<?php

namespace Tests\E2E\Services\Tokens;

use CURLFile;
use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

trait TokensBase
{
    private static array $bucketAndFileData = [];

    protected function setupBucketAndFile(): array
    {
        if (!empty(static::$bucketAndFileData)) {
            return static::$bucketAndFileData;
        }

        $bucket = $this->client->call(
            Client::METHOD_POST,
            '/storage/buckets',
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ],
            [
                'name' => 'Test Bucket',
                'bucketId' => ID::unique(),
                'allowedFileExtensions' => ['jpg', 'png', 'jfif'],
            ]
        );

        $bucketId = $bucket['body']['$id'];

        $file = $this->client->call(
            Client::METHOD_POST,
            '/storage/buckets/' . $bucketId . '/files',
            [
                'content-type' => 'multipart/form-data',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ],
            [
                'fileId' => ID::unique(),
                'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            ]
        );

        $fileId = $file['body']['$id'];

        $token = $this->client->call(
            Client::METHOD_POST,
            '/tokens/buckets/' . $bucketId . '/files/' . $fileId,
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]
        );

        static::$bucketAndFileData = [
            'fileId' => $fileId,
            'bucketId' => $bucketId,
            'token' => $token['body'],
            'jwtToken' => $token['body']['secret'],
            'guestHeaders' => [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ],
        ];

        return static::$bucketAndFileData;
    }

    public function testCreateBucketAndFile(): void
    {
        $bucket = $this->client->call(
            Client::METHOD_POST,
            '/storage/buckets',
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ],
            [
                'name' => 'Test Bucket',
                'bucketId' => ID::unique(),
                'allowedFileExtensions' => ['jpg', 'png', 'jfif'],
            ]
        );

        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucket['body']['$id']);

        $bucketId = $bucket['body']['$id'];

        $file = $this->client->call(
            Client::METHOD_POST,
            '/storage/buckets/' . $bucketId . '/files',
            [
                'content-type' => 'multipart/form-data',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ],
            [
                'fileId' => ID::unique(),
                'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            ]
        );

        $this->assertEquals(201, $file['headers']['status-code']);
        $this->assertNotEmpty($file['body']['$id']);

        $fileId = $file['body']['$id'];

        $token = $this->client->call(
            Client::METHOD_POST,
            '/tokens/buckets/' . $bucketId . '/files/' . $fileId,
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]
        );

        $this->assertEquals(201, $token['headers']['status-code']);
        $this->assertEquals($bucketId . ':' . $fileId, $token['body']['resourceId']);
        $this->assertEquals(TOKENS_RESOURCE_TYPE_FILES, $token['body']['resourceType']);
    }

    public function testFailuresWithoutToken(): void
    {
        $data = $this->setupBucketAndFile();
        $fileId = $data['fileId'];
        $bucketId = $data['bucketId'];
        $guestHeaders = $data['guestHeaders'];

        // File preview. Should fail as an anonymous user with no form of any access to the file.
        $failedPreview = $this->client->call(
            Client::METHOD_GET,
            '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview',
            $guestHeaders
        );
        $this->assertEquals(401, $failedPreview['body']['code']);
        $this->assertEquals(401, $failedPreview['headers']['status-code']);
        $this->assertEquals('user_unauthorized', $failedPreview['body']['type']);
        $this->assertEquals('No permissions provided for action \'read\'', $failedPreview['body']['message']);

        // Extended file preview. Should fail as an anonymous user with no form of any access to the file.
        $failedCustomPreview = $this->client->call(
            Client::METHOD_GET,
            '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview',
            $guestHeaders,
            [
                'width' => 300,
                'height' => 100,
                'borderRadius' => '50',
                'opacity' => '0.5',
                'output' => 'png',
                'rotation' => '45'
            ]
        );
        $this->assertEquals(401, $failedCustomPreview['body']['code']);
        $this->assertEquals(401, $failedCustomPreview['headers']['status-code']);
        $this->assertEquals('user_unauthorized', $failedCustomPreview['body']['type']);
        $this->assertEquals('No permissions provided for action \'read\'', $failedCustomPreview['body']['message']);

        // File view. Should fail as an anonymous user with no form of any access to the file.
        $failedView = $this->client->call(
            Client::METHOD_GET,
            '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/view',
            $guestHeaders
        );
        $this->assertEquals(401, $failedView['body']['code']);
        $this->assertEquals(401, $failedView['headers']['status-code']);
        $this->assertEquals('user_unauthorized', $failedView['body']['type']);
        $this->assertEquals('No permissions provided for action \'read\'', $failedView['body']['message']);

        // File download. Should fail as an anonymous user with no form of any access to the file.
        $failedDownload = $this->client->call(
            Client::METHOD_GET,
            '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/download',
            $guestHeaders
        );
        $this->assertEquals(401, $failedDownload['body']['code']);
        $this->assertEquals(401, $failedDownload['headers']['status-code']);
        $this->assertEquals('user_unauthorized', $failedDownload['body']['type']);
        $this->assertEquals('No permissions provided for action \'read\'', $failedDownload['body']['message']);
    }

    public function testPreviewFileWithToken(): void
    {
        $data = $this->setupBucketAndFile();
        $token = $data['token'];
        $fileId = $data['fileId'];
        $bucketId = $data['bucketId'];
        $guestHeaders = $data['guestHeaders'];

        $tokenJWT = $token['secret'];

        // Generate a preview
        $filePreview = $this->client->call(
            Client::METHOD_GET,
            '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview',
            $guestHeaders,
            [
                'token' => $tokenJWT
            ]
        );
        $this->assertEquals(200, $filePreview['headers']['status-code']);
        $this->assertEquals('image/png', $filePreview['headers']['content-type']);
        $this->assertNotEmpty($filePreview['body']);

        $image = new \Imagick();
        $image->readImageBlob($filePreview['body']);
        $original = new \Imagick(__DIR__ . '/../../../resources/logo.png');

        $this->assertEquals($image->getImageWidth(), $original->getImageWidth());
        $this->assertEquals($image->getImageHeight(), $original->getImageHeight());
        $this->assertEquals('PNG', $image->getImageFormat());
    }

    public function testCustomPreviewFileWithToken(): void
    {
        $data = $this->setupBucketAndFile();
        $fileId = $data['fileId'];
        $bucketId = $data['bucketId'];
        $jwtToken = $data['jwtToken'];
        $guestHeaders = $data['guestHeaders'];

        // Generate an extended preview
        $customFilePreview = $this->client->call(
            Client::METHOD_GET,
            '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/preview/',
            $guestHeaders,
            [
                'width' => 300,
                'height' => 100,
                'borderRadius' => '50',
                'opacity' => '0.5',
                'output' => 'png',
                'rotation' => '45',
                'token' => $jwtToken
            ]
        );

        $this->assertEquals(200, $customFilePreview['headers']['status-code']);
        $this->assertEquals('image/png', $customFilePreview['headers']['content-type']);
        $this->assertNotEmpty($customFilePreview['body']);

        $image = new \Imagick();
        $image->readImageBlob($customFilePreview['body']);
        $original = new \Imagick(__DIR__ . '/../../../resources/logo-after.png');

        $this->assertEquals($image->getImageWidth(), $original->getImageWidth());
        $this->assertEquals($image->getImageHeight(), $original->getImageHeight());
        $this->assertEquals('PNG', $image->getImageFormat());
    }

    public function testViewFileWithToken(): void
    {
        $data = $this->setupBucketAndFile();
        $fileId = $data['fileId'];
        $bucketId = $data['bucketId'];
        $jwtToken = $data['jwtToken'];
        $guestHeaders = $data['guestHeaders'];

        $fileView = $this->client->call(
            Client::METHOD_GET,
            '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/view',
            $guestHeaders,
            [
                'token' => $jwtToken
            ]
        );

        $this->assertEquals(200, $fileView['headers']['status-code']);

        $image = new \Imagick();
        $image->readImageBlob($fileView['body']);
        $original = new \Imagick(__DIR__ . '/../../../resources/logo.png');

        $this->assertEquals($image->getImageWidth(), $original->getImageWidth());
        $this->assertEquals($image->getImageHeight(), $original->getImageHeight());
        $this->assertEquals('PNG', $image->getImageFormat());
    }

    public function testDownloadFileWithToken(): void
    {
        $data = $this->setupBucketAndFile();
        $fileId = $data['fileId'];
        $bucketId = $data['bucketId'];
        $jwtToken = $data['jwtToken'];
        $guestHeaders = $data['guestHeaders'];

        $fileDownload = $this->client->call(
            Client::METHOD_GET,
            '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/download',
            $guestHeaders,
            [
                'token' => $jwtToken
            ]
        );

        $this->assertEquals(200, $fileDownload['headers']['status-code']);

        $image = new \Imagick();
        $image->readImageBlob($fileDownload['body']);
        $original = new \Imagick(__DIR__ . '/../../../resources/logo.png');

        $this->assertEquals($image->getImageWidth(), $original->getImageWidth());
        $this->assertEquals($image->getImageHeight(), $original->getImageHeight());
        $this->assertEquals('PNG', $image->getImageFormat());
    }

    public function testFileAccessWithFileSecurity(): void
    {
        $bucket = $this->client->call(
            Client::METHOD_POST,
            '/storage/buckets',
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ],
            [
                'name' => 'Test Bucket',
                'bucketId' => ID::unique(),
                'fileSecurity' => true,
                'allowedFileExtensions' => ['jpg', 'png', 'jfif'],
            ]
        );

        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucket['body']['$id']);

        $bucketId = $bucket['body']['$id'];

        $file = $this->client->call(
            Client::METHOD_POST,
            '/storage/buckets/' . $bucketId . '/files',
            [
                'content-type' => 'multipart/form-data',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ],
            [
                'fileId' => ID::unique(),
                'permissions' => [ Permission::read(Role::label('devrel')) ],
                'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            ]
        );

        $fileId = $file['body']['$id'];

        $token = $this->client->call(
            Client::METHOD_POST,
            '/tokens/buckets/' . $bucketId . '/files/' . $fileId,
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]
        );

        $jwtToken = $token['body']['secret'];

        $endpoints = ['preview', 'view', 'download'];

        foreach ($endpoints as $endpoint) {
            $response = $this->client->call(
                Client::METHOD_GET,
                "/storage/buckets/$bucketId/files/$fileId/$endpoint",
                [
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ],
                [
                    'token' => $jwtToken
                ]
            );

            $this->assertNotEmpty($response['body']);
            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals('image/png', $response['headers']['content-type']);

            if ($endpoint === 'download') {
                $image = new \Imagick();
                $image->readImageBlob($response['body']);
                $original = new \Imagick(__DIR__ . '/../../../resources/logo.png');

                $this->assertEquals($original->getImageWidth(), $image->getImageWidth());
                $this->assertEquals($original->getImageHeight(), $image->getImageHeight());
                $this->assertEquals('PNG', $image->getImageFormat());
            }
        }

    }
}
