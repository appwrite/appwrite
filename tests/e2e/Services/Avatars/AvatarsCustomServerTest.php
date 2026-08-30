<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Avatars;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

final class AvatarsCustomServerTest extends Scope
{
    use AvatarsBase;
    use ProjectCustom;
    use SideServer;

    public function testGetPhotoByUserId(): void
    {
        /**
         * Test for SUCCESS — the photo resolves from the target user, not the
         * caller: Gravatar and Libravatar miss on the random email, so the
         * target's name renders as initials.
         */
        $userId = ID::unique();

        $user = $this->client->call(Client::METHOD_POST, '/users', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => $userId,
            'email' => \uniqid('photo-') . '@appwrite.io',
            'password' => 'password',
            'name' => 'W W',
        ]);

        $this->assertEquals(201, $user['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', \array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => $userId,
            'width' => 100,
            'height' => 100,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertPhotoInitials($response['body']);

        $storedNamePhoto = $response['body'];

        // An explicit name takes priority over the target user's stored name:
        // 'B B' draws different initials than the user's own 'W W'.
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', \array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => $userId,
            'name' => 'B B',
            'width' => 100,
            'height' => 100,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertPhotoInitials($response['body']);
        $this->assertNotSame($storedNamePhoto, $response['body']);

        // The 'current()' sentinel resolves the authenticated user — an
        // API key carries none, so the explicit name decides the result.
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', \array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => 'current()',
            'name' => 'W W',
            'width' => 100,
            'height' => 100,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertPhotoInitials($response['body']);

        /**
         * Test for SUCCESS — regression: a user with an email but no name
         * gets the static fallback. Initials must never derive from the
         * email address.
         */
        $namelessId = ID::unique();

        $user = $this->client->call(Client::METHOD_POST, '/users', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => $namelessId,
            'email' => \uniqid('photo-') . '@appwrite.io',
            'password' => 'password',
        ]);

        $this->assertEquals(201, $user['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', \array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => $namelessId,
            'width' => 100,
            'height' => 100,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertPhotoFallback($response['body']);

        /**
         * Test for FAILURE
         */

        // Unknown user.
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', \array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => ID::unique(),
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals(Exception::USER_NOT_FOUND, $response['body']['type']);

        // '0' is falsy in PHP but a legal user ID: it must be looked up like
        // any other, not skipped into an anonymous fallback.
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', \array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => '0',
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals(Exception::USER_NOT_FOUND, $response['body']['type']);

        // A bare 'current' is an ordinary ID, not the sentinel — nothing
        // stores it, so it misses like any other unknown user.
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', \array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => 'current',
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals(Exception::USER_NOT_FOUND, $response['body']['type']);

        // Malformed user ID.
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', \array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => 'invalid#id!',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }
}
