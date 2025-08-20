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

        // Verify JWT contains correct expiration
        $this->assertNotEmpty($token['body']['secret']);
        $jwtParts = explode('.', $token['body']['secret']);
        $this->assertCount(3, $jwtParts, 'JWT should have 3 parts');

        // Decode JWT payload (using base64url decoding)
        $payloadB64 = $jwtParts[1];
        // Convert base64url to base64
        $payloadB64 = str_replace(['-', '_'], ['+', '/'], $payloadB64);
        // Add padding if needed
        $payloadB64 .= str_repeat('=', (4 - strlen($payloadB64) % 4) % 4);
        $payload = json_decode(base64_decode($payloadB64), true);

        $this->assertIsArray($payload, 'JWT payload should decode to an array');
        $this->assertArrayHasKey('exp', $payload, 'JWT payload should contain exp field');

        $expectedExp = (new \DateTime($expiry))->getTimestamp();
        $this->assertEquals($expectedExp, $payload['exp'], 'JWT exp should match token expiry');

        // Infinite expiry
        $token = $this->client->call(Client::METHOD_PATCH, '/tokens/' . $tokenId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'expire' => null,
        ]);

        $this->assertEmpty($token['body']['expire']);

        // Verify JWT does not contain exp for infinite expiry
        $jwtParts = explode('.', $token['body']['secret']);
        $payloadB64 = $jwtParts[1];
        $payloadB64 = str_replace(['-', '_'], ['+', '/'], $payloadB64);
        $payloadB64 .= str_repeat('=', (4 - strlen($payloadB64) % 4) % 4);
        $payload = json_decode(base64_decode($payloadB64), true);

        $this->assertArrayNotHasKey('exp', $payload, 'JWT payload should not contain exp field for infinite expiry');

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
