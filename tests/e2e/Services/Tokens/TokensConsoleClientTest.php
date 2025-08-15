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
use Utopia\Database\Validator\Datetime as DatetimeValidator;

class TokensConsoleClientTest extends Scope
{
    use TokensBase;
    use ProjectCustom;
    use SideServer;

    public function testCreateToken(): array
    {

        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
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

        // Finite expiry
        $expiry = DateTime::addSeconds(new \DateTime(), 3600);
        $token = $this->client->call(Client::METHOD_PATCH, '/tokens/' . $tokenId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'expire' => $expiry,
        ]);

        $dateValidator = new DatetimeValidator();
        $this->assertTrue($dateValidator->isValid($token['body']['expire']));

        // Infinite expiry
        $token = $this->client->call(Client::METHOD_PATCH, '/tokens/' . $tokenId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'expire' => null,
        ]);

        $this->assertEmpty($token['body']['expire']);

        return $data;
    }

    /**
     * @depends testCreateToken
     */
    public function testExpiredTokenJWT(array $data): array
    {
        $fileId = $data['fileId'];
        $bucketId = $data['bucketId'];

        // Create a token with an expiry date in the past (expired)
        $pastExpiry = DateTime::addSeconds(new \DateTime(), -3600); // 1 hour ago
        $expiredToken = $this->client->call(Client::METHOD_POST, '/tokens/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'expire' => $pastExpiry,
        ]);

        $this->assertEquals(201, $expiredToken['headers']['status-code']);
        $this->assertEquals('files', $expiredToken['body']['resourceType']);
        
        // Verify that the JWT is generated without causing a 500 error
        $this->assertNotEmpty($expiredToken['body']['secret']);
        
        // Parse the JWT to verify expiration is set correctly for expired tokens
        $jwtParts = explode('.', $expiredToken['body']['secret']);
        $this->assertCount(3, $jwtParts, 'JWT should have 3 parts');
        
        $payload = json_decode(base64_decode($jwtParts[1]), true);
        $this->assertArrayHasKey('exp', $payload, 'JWT payload should contain exp field');
        
        // For expired tokens, exp should be set to a short time in the future (around 1 minute)
        $now = time();
        $this->assertGreaterThan($now, $payload['exp'], 'JWT exp should be in the future even for expired tokens');
        $this->assertLessThanOrEqual($now + 120, $payload['exp'], 'JWT exp should not be more than 2 minutes in the future for expired tokens');

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
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id']
            ], $this->getHeaders())
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

        $res = $this->client->call(Client::METHOD_DELETE, '/tokens/' . $tokenId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()));

        $this->assertEquals(204, $res['headers']['status-code']);
        return $data;
    }
}
