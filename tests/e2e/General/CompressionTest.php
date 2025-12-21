<?php

namespace Tests\E2E\General;

use Appwrite\ID;
use Appwrite\Permission;
use Appwrite\Role;
use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class CompressionTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testSmallResponse()
    {
        // with header
        $response = $this->client->call(Client::METHOD_GET, '/ping', [
            'accept-encoding' => 'gzip',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('Pong!', $response['body']);
        $this->assertLessThan(1024, strlen($response['body']));
        $this->assertArrayNotHasKey('content-encoding', $response['headers']);

        // without header
        $response = $this->client->call(Client::METHOD_GET, '/ping', [
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('Pong!', $response['body']);
        $this->assertLessThan(1024, strlen($response['body']));
        $this->assertArrayNotHasKey('content-encoding', $response['headers']);
    }

    public function testLargeResponse()
    {
        // create an anonymous user
        $response = $this->client->call(Client::METHOD_POST, '/users', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
            'content-type' => 'application/json',
        ], $this->getHeaders()), [
            'userId' => ID::unique(),
            'email' => 'test@localhost.test',
            'password' => 'password',
            'name' => 'User Name',
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $userId = $response['body']['$id'];

        // set prefs with 2000 bytes of data
        $prefs = ["longValue" => str_repeat('a', 2000)];

        $response = $this->client->call(Client::METHOD_PATCH, '/users/' . $userId . '/prefs', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
            'content-type' => 'application/json',
        ], $this->getHeaders()), [
            'prefs' => $prefs,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        // get prefs with compression
        $response = $this->client->call(Client::METHOD_GET, '/users/' . $userId . '/prefs', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
            'accept-encoding' => 'gzip',
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('content-encoding', $response['headers'], 'Content encoding should be gzip, headers received: ' . json_encode($response['headers'], JSON_PRETTY_PRINT));
        $this->assertArrayHasKey('content-length', $response['headers'], 'Compressed response should provide content length, headers received: ' . json_encode($response['headers'], JSON_PRETTY_PRINT));
        $this->assertLessThan(2000, \intval($response['headers']['content-length']));
        $this->assertArrayNotHasKey('transfer-encoding', $response['headers'], 'Compressed response should not be chunked, headers received: ' . json_encode($response['headers'], JSON_PRETTY_PRINT));

        // get prefs without compression
        $response = $this->client->call(Client::METHOD_GET, '/users/' . $userId . '/prefs', array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertArrayNotHasKey('content-encoding', $response['headers']);
        $this->assertEquals('chunked', $response['headers']['transfer-encoding'] ?? null, 'Uncompressed response should use chunked transfer, headers received: ' . json_encode($response['headers'], JSON_PRETTY_PRINT));
        $this->assertArrayNotHasKey('content-length', $response['headers'], 'Uncompressed response should not send content length when chunked.');

        $this->assertArrayHasKey('longValue', $response['body'], 'Prefs payload should expose longValue at the top level, body received: ' . json_encode($response['body'], JSON_PRETTY_PRINT));

        $prefsPayload = $response['body']['longValue'];
        $payloadLength = \strlen($prefsPayload);
        $this->assertGreaterThanOrEqual(2000, $payloadLength, 'Prefs payload should be at least 2000 bytes.');
    }

    public function testImageResponse()
    {
        // create bucket
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'bucketId' => ID::unique(),
            'name' => 'Test Bucket',
            'fileSecurity' => true,
        ]);
        $bucketId = $bucket['body']['$id'];
        $this->assertEquals(201, $bucket['headers']['status-code']);

        // upload image
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(realpath(__DIR__ . '/../../resources/logo.png'), 'image/png', 'logo.png'),
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $fileId = $file['body']['$id'];

        // get image with header
        $response = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'accept-encoding' => 'gzip',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertArrayNotHasKey('content-encoding', $response['headers']);
        // get image without
        $response = $this->client->call(Client::METHOD_GET, '/storage/buckets/' . $bucketId . '/files/' . $fileId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertArrayNotHasKey('content-encoding', $response['headers']);
    }
}
