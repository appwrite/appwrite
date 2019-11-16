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

        var_dump($logo);

        $this->assertEquals(200, $logo['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $logo['headers']['content-type']);

        $this->assertEquals('ef7512678fc617a708298a535dac143b', md5($logo['body']));

        $logo = $this->client->call(Client::METHOD_GET, '/avatars/credit-cards/visa', [
            'x-appwrite-project' => $data['projectUid'],
        ], [
            'width' => 200,
            'height' => 200,
        ]);

        $this->assertEquals(200, $logo['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $logo['headers']['content-type']);
        $this->assertEquals('022ca094acd9eb6c59a5e0ee5b6d6f14', md5($logo['body']));

        $logo = $this->client->call(Client::METHOD_GET, '/avatars/credit-cards/visa', [
            'x-appwrite-project' => $data['projectUid'],
        ], [
            'width' => 300,
            'height' => 300,
            'quality' => 30,
        ]);

        $this->assertEquals(200, $logo['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $logo['headers']['content-type']);
        $this->assertEquals('2b4052955b6515d667990b22cbd4dc82', md5($logo['body']));

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
        $this->assertEquals('0c9ed000128635206d72e55b287a5be0', md5($logo['body']));

        $logo = $this->client->call(Client::METHOD_GET, '/avatars/browsers/ch', [
            'x-appwrite-project' => $data['projectUid'],
        ], [
            'width' => 200,
            'height' => 200,
        ]);

        $this->assertEquals(200, $logo['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $logo['headers']['content-type']);
        $this->assertEquals('2a9da62aa876303dab6d8e00d2f8789c', md5($logo['body']));

        $logo = $this->client->call(Client::METHOD_GET, '/avatars/browsers/ch', [
            'x-appwrite-project' => $data['projectUid'],
        ], [
            'width' => 300,
            'height' => 300,
            'quality' => 30,
        ]);

        $this->assertEquals(200, $logo['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $logo['headers']['content-type']);
        $this->assertEquals('111cbe4bef171fb47ac1f597274279ed', md5($logo['body']));

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
        $this->assertEquals('39da0f4b444b1a41e9fe850589fb082f', md5($logo['body']));

        $logo = $this->client->call(Client::METHOD_GET, '/avatars/flags/us', [
            'x-appwrite-project' => $data['projectUid'],
        ], [
            'width' => 200,
            'height' => 200,
        ]);

        $this->assertEquals(200, $logo['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $logo['headers']['content-type']);
        $this->assertEquals('37fa54e9f4b0c868365e7f0721aae1d6', md5($logo['body']));

        $logo = $this->client->call(Client::METHOD_GET, '/avatars/flags/us', [
            'x-appwrite-project' => $data['projectUid'],
        ], [
            'width' => 300,
            'height' => 300,
            'quality' => 30,
        ]);

        $this->assertEquals(200, $logo['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $logo['headers']['content-type']);
        $this->assertEquals('e3d32d455826451e1d88f58417716502', md5($logo['body']));

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
        $this->assertEquals('8718564af44bf2672b184ef1e0baee81', md5($logo['body']));

        $logo = $this->client->call(Client::METHOD_GET, '/avatars/image', [
            'x-appwrite-project' => $data['projectUid'],
        ], [
            'url' => 'https://appwrite.io/images/apple.png',
            'width' => 200,
            'height' => 200,
        ]);

        $this->assertEquals(200, $logo['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $logo['headers']['content-type']);
        $this->assertEquals('53ed3af9150a94edbfed25cd65283bcc', md5($logo['body']));

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
        $this->assertEquals('2dac180f0b3f8dc9de7e65c978eac9db', md5($logo['body']));

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
        $this->assertEquals('5f22187ae9b19c9d92a28b3ce1f74777', md5($logo['body']));

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
        $this->assertEquals('c5c7224f33e68e11022529f846086217', md5($logo['body']));

        $logo = $this->client->call(Client::METHOD_GET, '/avatars/qr', [
            'x-appwrite-project' => $data['projectUid'],
        ], [
            'text' => 'url:https://appwrite.io/',
            'size' => 200,
        ]);

        $this->assertEquals(200, $logo['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $logo['headers']['content-type']);
        $this->assertEquals('385dbbd81bab7a4b9e4d621a0c34fa71', md5($logo['body']));

        $logo = $this->client->call(Client::METHOD_GET, '/avatars/qr', [
            'x-appwrite-project' => $data['projectUid'],
        ], [
            'text' => 'url:https://appwrite.io/',
            'size' => 200,
            'margin' => 10,
        ]);

        $this->assertEquals(200, $logo['headers']['status-code']);
        $this->assertEquals('image/png; charset=UTF-8', $logo['headers']['content-type']);
        $this->assertEquals('bc2a09d6a34d9a3e9ac81c9a0a43a7f3', md5($logo['body']));

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
        $this->assertEquals('bc2a09d6a34d9a3e9ac81c9a0a43a7f3', md5($logo['body']));

        return $data;
    }
}
