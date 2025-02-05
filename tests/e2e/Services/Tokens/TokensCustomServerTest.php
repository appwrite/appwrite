<?php

namespace Tests\E2E\Services\Tokens;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\DateTime;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

class TokensCustomServerTest extends Scope
{
    use TokensBase;
    use ProjectCustom;
    use SideServer;

    public function testCreateToken(): array
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

        $res = $this->client->call(Client::METHOD_POST, '/tokens/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()));

        $this->assertEquals(201, $res['headers']['status-code']);
        $this->assertEquals('files', $res['body']['resourceType']);

        $data = [];
        $data['fileId'] = $fileId;
        $data['bucketId'] = $bucketId;
        $data['tokenId'] = $res['body']['$id'];
        return $data;
    }

    /**
     * @depends testCreateToken
     */
    public function testUpdateToken(array $data): array
    {
        $tokenId = $data['tokenId'];

        $expiry = DateTime::now();
        $res = $this->client->call(Client::METHOD_PATCH, '/tokens/' . $tokenId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'expire' => $expiry,
        ]);

        $this->assertEquals($expiry, $res['body']['expire']);
        return $data;
    }

    /**
     * @depends testUpdateToken
     */
    public function testDeleteToken(array $data): array
    {
        $tokenId = $data['tokenId'];

        $res = $this->client->call(Client::METHOD_DELETE, '/tokens/' . $tokenId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $res['headers']['status-code']);
        return $data;
    }
}
