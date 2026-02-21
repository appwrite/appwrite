<?php

namespace Tests\E2E\Services\Tokens;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

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

        // Failure case: Expire date is in the past
        $token = $this->client->call(Client::METHOD_POST, '/tokens/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'expire' => '2022-11-02',
        ]);
        $this->assertEquals(400, $token['headers']['status-code']);
        $this->assertStringContainsString('Value must be valid date in the future', $token['body']['message']);

        // Success case: No expire date
        $token = $this->client->call(Client::METHOD_POST, '/tokens/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()));

        $this->assertEquals(201, $token['headers']['status-code']);
        $this->assertEquals('files', $token['body']['resourceType']);

        return [
            'fileId' => $fileId,
            'bucketId' => $bucketId,
            'tokenId' => $token['body']['$id'],
        ];
    }

    /**
     * @depends testCreateToken
     */
    public function testUpdateToken(array $data): array
    {
        $tokenId = $data['tokenId'];

        // Failure case: Expire date is in the past
        $token = $this->client->call(Client::METHOD_PATCH, '/tokens/' . $tokenId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'expire' => '2022-11-02',
        ]);
        $this->assertEquals(400, $token['headers']['status-code']);
        $this->assertStringContainsString('Value must be valid date in the future', $token['body']['message']);

        // Success case: Finite expiry
        $expiry = date('Y-m-d', strtotime("tomorrow"));
        $token = $this->client->call(Client::METHOD_PATCH, '/tokens/' . $tokenId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'expire' => $expiry,
        ]);

        $dateValidator = new DatetimeValidator();
        $this->assertEquals(200, $token['headers']['status-code']);
        $this->assertTrue($dateValidator->isValid($token['body']['expire']));

        // Success case: Infinite expiry
        $token = $this->client->call(Client::METHOD_PATCH, '/tokens/' . $tokenId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'expire' => null,
        ]);

        $this->assertEmpty($token['body']['expire']);

        return $data;
    }

    /**
     * @depends testCreateToken
     */
    public function testListTokens(array $data): array
    {
        $res = $this->client->call(
            Client::METHOD_GET,
            '/tokens/buckets/' . $data['bucketId'] . '/files/' . $data['fileId'],
            [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ]
        );

        $this->assertIsArray($res['body']);
        $this->assertEquals(200, $res['headers']['status-code']);
        return $data;
    }

    /**
     * @depends testUpdateToken
     */
    public function testDeleteToken(array $data): array
    {
        $tokenId = $data['tokenId'];

        $res = $this->client->call(Client::METHOD_DELETE, '/tokens/' . $tokenId, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(204, $res['headers']['status-code']);
        return $data;
    }
}
