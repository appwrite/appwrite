<?php

namespace Tests\E2E\Services\Tokens;

use CURLFile;
use Tests\E2E\Client;
use Utopia\Database\DateTime;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

trait TokensBase
{
    /**
     * @group fileTokens
     */
    public function testCreateFileToken(): array
    {

        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket',
            'fileSecurity' => true,
            'maximumFileSize' => 2000000, //2MB
            'allowedFileExtensions' => ['jpg', 'png', 'jfif'],
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $this->assertEquals(201, $bucket['headers']['status-code']);
        $this->assertNotEmpty($bucket['body']['$id']);

        $bucketId = $bucket['body']['$id'];

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
        ]);
        $this->assertEquals(201, $file['headers']['status-code']);
        $this->assertNotEmpty($file['body']['$id']);

        $fileId = $file['body']['$id'];

        $res = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files/' . $fileId . '/tokens', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(201, $res['headers']['status-code']);
        $this->assertEquals('files', $res['body']['resourceType']);

        $data = [];
        $data['fileId'] = $fileId;
        $data['bucketId'] = $bucketId;
        $data['tokenId'] = $res['body']['$id'];
        return $data;
    }

    /**
     * @group fileTokens
     * @depends testCreateFileToken
     */
    public function testUpdateFileToken(array $data): array
    {
        $bucketId = $data['bucketId'];
        $fileId = $data['fileId'];
        $tokenId = $data['tokenId'];

        $expiry = DateTime::now();
        $res = $this->client->call(Client::METHOD_PUT, '/storage/buckets/'. $bucketId . '/files/'. $fileId . '/tokens/' . $tokenId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'expire' => $expiry,
        ]);

        $this->assertEquals($expiry, $res['body']['expire']);
        return $data;
    }

}
