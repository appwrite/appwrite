<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Avatars;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

final class AvatarsConsoleClientTest extends Scope
{
    use AvatarsBase;
    use ProjectConsole;
    use SideClient;

    public function testUpdatePhotoWithConsoleSession(): void
    {
        /**
         * Test for SUCCESS — a console user sets their own photo with their
         * console session, which is how the Console account page uploads it
         */
        $headers = $this->createPhotoUser();
        $red = $this->createImage('#FF0000', 'png');

        $response = $this->uploadPhoto($headers, $red, 'photo.png');

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertSamePhoto($red, $this->getPhoto($headers));

        /**
         * Test for SUCCESS — `current()` resolves the session user
         */
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', $headers, [
            'userId' => 'current()',
            'width' => 0,
            'height' => 0,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertSamePhoto($red, $response['body']);

        /**
         * Test for SUCCESS — another console user resolves the photo by user
         * ID, which is how the Console renders organization members
         */
        $account = $this->client->call(Client::METHOD_GET, '/account', $headers);

        $this->assertEquals(200, $account['headers']['status-code']);

        $other = $this->createPhotoUser();
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', $other, [
            'userId' => $account['body']['$id'],
            'width' => 0,
            'height' => 0,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertSamePhoto($red, $response['body']);

        /**
         * Test for SUCCESS — the other user's own avatar is untouched
         */
        $this->assertPhotoInitials($this->getPhoto($other));
    }

    public function testDeletePhotoWithConsoleSession(): void
    {
        $headers = $this->createPhotoUser();
        $blue = $this->createImage('#0000FF', 'png');

        $response = $this->uploadPhoto($headers, $blue, 'photo.png');

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertSamePhoto($blue, $this->getPhoto($headers));

        /**
         * Test for SUCCESS — deleting replaces the photo with the placeholder
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/avatars/photo', $headers);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertPhotoFallback($this->getPhoto($headers));

        /**
         * Test for SUCCESS — deleting again keeps the placeholder
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/avatars/photo', $headers);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertPhotoFallback($this->getPhoto($headers));
    }

    public function testUpdatePhotoUnauthorized(): void
    {
        /**
         * Test for FAILURE — without a console session there is no user to
         * attach the photo to
         */
        $headers = [
            'origin' => 'http://localhost',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        $response = $this->uploadPhoto($headers, $this->createImage('#00FF00', 'png'), 'photo.png');

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_DELETE, '/avatars/photo', $headers);

        $this->assertEquals(401, $response['headers']['status-code']);
    }
}
