<?php

namespace Tests\E2E\Services\Tokens;

use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\System\System;

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

        // Failure case: Expire date is in the past
        $token = $this->client->call(Client::METHOD_POST, '/tokens/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'expire' => '2022-11-02',
        ]);
        $this->assertEquals(400, $token['headers']['status-code']);
        $this->assertStringContainsString('Value must be valid date in the future', $token['body']['message']);

        // Success cases: With & without expiry
        $expireList = [null, date('Y-m-d', strtotime("tomorrow"))];
        foreach ($expireList as $expire) {
            $token = $this->client->call(Client::METHOD_POST, '/tokens/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id']
            ], $this->getHeaders()), [
                'expire' => $expire,
            ]);

            $this->assertEquals(201, $token['headers']['status-code']);
            $this->assertEquals('files', $token['body']['resourceType']);
            $this->assertNotEmpty($token['body']['$id']);
            $this->assertNotEmpty($token['body']['secret']);

            // Verify the generated token JWT contains correct resource information
            $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 86400 * 365 * 10, 10); // 10 years maxAge
            try {
                $payload = $jwt->decode($token['body']['secret']);
                $this->assertIsArray($payload, 'JWT payload should decode to an array');
                $this->assertArrayHasKey('tokenId', $payload, 'JWT payload should contain tokenId');
                $this->assertArrayHasKey('resourceId', $payload, 'JWT payload should contain resourceId');
                $this->assertArrayHasKey('resourceType', $payload, 'JWT payload should contain resourceType');
                $this->assertArrayHasKey('resourceInternalId', $payload, 'JWT payload should contain resourceInternalId');
                $this->assertArrayHasKey('iat', $payload, 'JWT payload should contain iat');

                if (!empty($expire)) {
                    $this->assertArrayHasKey('exp', $payload, 'JWT payload should contain exp');
                } else {
                    $this->assertArrayNotHasKey('exp', $payload, 'JWT payload should not contain exp field for tokens without expiry');
                }

                $this->assertEquals($token['body']['$id'], $payload['tokenId'], 'JWT tokenId should match token ID');
                $this->assertEquals($bucketId . ':' . $fileId, $payload['resourceId'], 'JWT resourceId should match bucketId:fileId format');
                $this->assertEquals('files', $payload['resourceType'], 'JWT resourceType should be files');

            } catch (JWTException $e) {
                $this->fail('Failed to decode JWT: ' . $e->getMessage());
            }
        }

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

        // Finite expiry
        $expiry = date('Y-m-d', strtotime("tomorrow"));
        $token = $this->client->call(Client::METHOD_PATCH, '/tokens/' . $tokenId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'expire' => $expiry,
        ]);

        $dateValidator = new DatetimeValidator();
        $this->assertTrue($dateValidator->isValid($token['body']['expire']));

        // Verify JWT contains correct expiration using native JWT decode
        $this->assertNotEmpty($token['body']['secret']);

        $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 86400 * 365 * 10, 10); // 10 years maxAge
        try {
            $payload = $jwt->decode($token['body']['secret']);
            $this->assertIsArray($payload, 'JWT payload should decode to an array');
            $this->assertArrayHasKey('exp', $payload, 'JWT payload should contain exp field');

            $expectedExp = (new \DateTime($expiry))->getTimestamp();
            $this->assertEquals($expectedExp, $payload['exp'], 'JWT exp should match token expiry');
        } catch (JWTException $e) {
            $this->fail('Failed to decode JWT: ' . $e->getMessage());
        }

        // Infinite expiry
        $token = $this->client->call(Client::METHOD_PATCH, '/tokens/' . $tokenId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'expire' => null,
        ]);

        $this->assertEmpty($token['body']['expire']);

        // Verify JWT does not contain exp for infinite expiry using native JWT decode
        try {
            $payload = $jwt->decode($token['body']['secret']);
            $this->assertIsArray($payload, 'JWT payload should decode to an array');
            $this->assertArrayNotHasKey('exp', $payload, 'JWT payload should not contain exp field for infinite expiry');
        } catch (JWTException $e) {
            $this->fail('Failed to decode JWT: ' . $e->getMessage());
        }

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
        $this->assertArrayHasKey('tokens', $res['body']);
        $this->assertIsArray($res['body']['tokens']);
        $this->assertGreaterThan(0, count($res['body']['tokens']), 'Should have at least one token');

        // Verify each token in the list
        $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 86400 * 365 * 10, 10); // 10 years maxAge
        foreach ($res['body']['tokens'] as $token) {
            $this->assertArrayHasKey('$id', $token, 'Token should have an ID');
            $this->assertArrayHasKey('secret', $token, 'Token should have a secret');
            $this->assertArrayHasKey('resourceType', $token, 'Token should have resourceType');
            $this->assertArrayHasKey('resourceId', $token, 'Token should have resourceId');

            $this->assertEquals('files', $token['resourceType'], 'Token resourceType should be files');
            $this->assertEquals($data['bucketId'] . ':' . $data['fileId'], $token['resourceId'], 'Token resourceId should match bucketId:fileId format');

            // Verify the JWT token is valid and contains correct information
            try {
                $payload = $jwt->decode($token['secret']);
                $this->assertIsArray($payload, 'JWT payload should decode to an array');
                $this->assertArrayHasKey('tokenId', $payload, 'JWT payload should contain tokenId');
                $this->assertArrayHasKey('resourceId', $payload, 'JWT payload should contain resourceId');
                $this->assertArrayHasKey('resourceType', $payload, 'JWT payload should contain resourceType');
                $this->assertArrayHasKey('resourceInternalId', $payload, 'JWT payload should contain resourceInternalId');
                $this->assertArrayHasKey('iat', $payload, 'JWT payload should contain iat');

                if (!empty($token['expire'])) {
                    $this->assertArrayHasKey('exp', $payload, 'JWT payload should contain exp');
                }

                $this->assertEquals($token['$id'], $payload['tokenId'], 'JWT tokenId should match token ID');
                $this->assertEquals($data['bucketId'] . ':' . $data['fileId'], $payload['resourceId'], 'JWT resourceId should match bucketId:fileId format');
                $this->assertEquals('files', $payload['resourceType'], 'JWT resourceType should be files');
            } catch (JWTException $e) {
                $this->fail('Failed to decode JWT for token ' . $token['$id'] . ': ' . $e->getMessage());
            }
        }

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
