<?php

namespace Tests\E2E;

use Tests\E2E\Client;

class ProjectAvatarsTest extends BaseProjects
{
    public function testRegisterSuccess(): array
    {
        return $this->initProject([]);
    }

    /**
     * @depends testRegisterSuccess
     */
    public function testAvatarsCCReadSuccess(array $data): array
    {
        $logo = $this->client->call(Client::METHOD_GET, '/avatars/credit-cards/visa', [
            'x-appwrite-project' => $data['projectUid'],
        ]);

        $this->assertEquals(200, $logo['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $logo['headers']['content-type']);
        $this->assertNotEmpty($logo['body']);

        $logo = $this->client->call(Client::METHOD_GET, '/avatars/credit-cards/visa', [
            'x-appwrite-project' => $data['projectUid'],
        ], [
            'width' => 200,
            'height' => 200,
        ]);

        $this->assertEquals(200, $logo['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $logo['headers']['content-type']);
        $this->assertNotEmpty($logo['body']);

        $logo = $this->client->call(Client::METHOD_GET, '/avatars/credit-cards/visa', [
            'x-appwrite-project' => $data['projectUid'],
        ], [
            'width' => 300,
            'height' => 300,
            'quality' => 30,
        ]);

        $this->assertEquals(200, $logo['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $logo['headers']['content-type']);
        $this->assertNotEmpty($logo['body']);

        return $data;
    }

    /**
     * @depends testRegisterSuccess
     */
    public function testAvatarsBrowserReadSuccess(array $data): array
    {
        $logo = $this->client->call(Client::METHOD_GET, '/avatars/browsers/ch', [
            'x-appwrite-project' => $data['projectUid'],
        ]);

        $this->assertEquals(200, $logo['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $logo['headers']['content-type']);
        $this->assertNotEmpty($logo['body']);

        $logo = $this->client->call(Client::METHOD_GET, '/avatars/browsers/ch', [
            'x-appwrite-project' => $data['projectUid'],
        ], [
            'width' => 200,
            'height' => 200,
        ]);

        $this->assertEquals(200, $logo['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $logo['headers']['content-type']);
        $this->assertNotEmpty($logo['body']);

        $logo = $this->client->call(Client::METHOD_GET, '/avatars/browsers/ch', [
            'x-appwrite-project' => $data['projectUid'],
        ], [
            'width' => 300,
            'height' => 300,
            'quality' => 30,
        ]);

        $this->assertEquals(200, $logo['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $logo['headers']['content-type']);
        $this->assertNotEmpty($logo['body']);

        return $data;
    }

    /**
     * @depends testRegisterSuccess
     */
    public function testAvatarsFlagReadSuccess(array $data): array
    {
        $logo = $this->client->call(Client::METHOD_GET, '/avatars/flags/us', [
            'x-appwrite-project' => $data['projectUid'],
        ]);

        $this->assertEquals(200, $logo['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $logo['headers']['content-type']);
        $this->assertNotEmpty($logo['body']);

        $logo = $this->client->call(Client::METHOD_GET, '/avatars/flags/us', [
            'x-appwrite-project' => $data['projectUid'],
        ], [
            'width' => 200,
            'height' => 200,
        ]);

        $this->assertEquals(200, $logo['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $logo['headers']['content-type']);
        $this->assertNotEmpty($logo['body']);

        $logo = $this->client->call(Client::METHOD_GET, '/avatars/flags/us', [
            'x-appwrite-project' => $data['projectUid'],
        ], [
            'width' => 300,
            'height' => 300,
            'quality' => 30,
        ]);

        $this->assertEquals(200, $logo['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $logo['headers']['content-type']);
        $this->assertNotEmpty($logo['body']);

        return $data;
    }

    /**
     * @depends testRegisterSuccess
     */
    public function testAvatarsRemoteImageReadSuccess(array $data): array
    {
        $logo = $this->client->call(Client::METHOD_GET, '/avatars/image', [
            'x-appwrite-project' => $data['projectUid'],
        ], [
            'url' => 'https://appwrite.io/images/apple.png',
        ]);

        $this->assertEquals(200, $logo['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $logo['headers']['content-type']);
        $this->assertNotEmpty($logo['body']);

        $logo = $this->client->call(Client::METHOD_GET, '/avatars/image', [
            'x-appwrite-project' => $data['projectUid'],
        ], [
            'url' => 'https://appwrite.io/images/apple.png',
            'width' => 200,
            'height' => 200,
        ]);

        $this->assertEquals(200, $logo['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $logo['headers']['content-type']);
        $this->assertNotEmpty($logo['body']);

        $logo = $this->client->call(Client::METHOD_GET, '/avatars/image', [
            'x-appwrite-project' => $data['projectUid'],
        ], [
            'url' => 'https://appwrite.io/images/apple.png',
            'width' => 300,
            'height' => 300,
            'quality' => 30,
        ]);

        $this->assertEquals(200, $logo['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $logo['headers']['content-type']);
        $this->assertNotEmpty($logo['body']);

        return $data;
    }

    /**
     * @depends testRegisterSuccess
     */
    public function testAvatarsFaviconReadSuccess(array $data): array
    {
        $logo = $this->client->call(Client::METHOD_GET, '/avatars/favicon', [
            'x-appwrite-project' => $data['projectUid'],
        ], [
            'url' => 'https://appwrite.io/',
        ]);

        $this->assertEquals(200, $logo['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $logo['headers']['content-type']);
        $this->assertNotEmpty($logo['body']);

        return $data;
    }

    /**
     * @depends testRegisterSuccess
     */
    public function testAvatarsQRReadSuccess(array $data): array
    {
        $logo = $this->client->call(Client::METHOD_GET, '/avatars/qr', [
            'x-appwrite-project' => $data['projectUid'],
        ], [
            'text' => 'url:https://appwrite.io/',
        ]);

        $this->assertEquals(200, $logo['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $logo['headers']['content-type']);
        $this->assertNotEmpty($logo['body']);

        $logo = $this->client->call(Client::METHOD_GET, '/avatars/qr', [
            'x-appwrite-project' => $data['projectUid'],
        ], [
            'text' => 'url:https://appwrite.io/',
            'size' => 200,
        ]);

        $this->assertEquals(200, $logo['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $logo['headers']['content-type']);
        $this->assertNotEmpty($logo['body']);

        $logo = $this->client->call(Client::METHOD_GET, '/avatars/qr', [
            'x-appwrite-project' => $data['projectUid'],
        ], [
            'text' => 'url:https://appwrite.io/',
            'size' => 200,
            'margin' => 10,
        ]);

        $this->assertEquals(200, $logo['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $logo['headers']['content-type']);
        $this->assertNotEmpty($logo['body']);

        $logo = $this->client->call(Client::METHOD_GET, '/avatars/qr', [
            'x-appwrite-project' => $data['projectUid'],
        ], [
            'text' => 'url:https://appwrite.io/',
            'size' => 200,
            'margin' => 10,
            'download' => 1,
        ]);

        $this->assertEquals(200, $logo['headers']['status-code']);
        $this->assertEquals('attachment; filename="qr.png"', $logo['headers']['content-disposition']);
        $this->assertEquals('image/png; charset=UTF-8', $logo['headers']['content-type']);
        $this->assertNotEmpty($logo['body']);

        return $data;
    }
}
