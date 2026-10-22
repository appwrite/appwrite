<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Avatars;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Role;

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
        $userId = Id::unique();

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
        $namelessId = Id::unique();

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
            'userId' => Id::unique(),
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

    public function testUpdatePhotoUnauthorized(): void
    {
        /**
         * Test for FAILURE — API keys carry no user identity, so they can
         * never upload or delete a photo
         */
        $response = $this->client->call(Client::METHOD_PUT, '/avatars/photo', [
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'file' => new \CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_DELETE, '/avatars/photo', [
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
    }

    /**
     * The remote file is served by this Appwrite instance over loopback, which the
     * test environment allows through _APP_ALLOWED_INTERNAL_ADDRESSES.
     */
    public function testGetRemoteFileTooLarge(): void
    {
        $projectId = $this->getProject()['$id'];
        $headers = \array_merge(['x-appwrite-project' => $projectId], $this->getHeaders());

        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', \array_merge([
            'content-type' => 'application/json',
        ], $headers), [
            'bucketId' => Id::unique(),
            'name' => 'Avatars remote files',
            'fileSecurity' => false,
            'permissions' => [
                Permission::read(Role::any()),
            ],
        ]);

        $this->assertEquals(201, $bucket['headers']['status-code']);

        $bucketId = $bucket['body']['$id'];

        $small = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', \array_merge([
            'content-type' => 'multipart/form-data',
        ], $headers), [
            'fileId' => Id::unique(),
            'file' => new \CURLFile(\realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
        ]);

        $this->assertEquals(201, $small['headers']['status-code']);

        $source = __DIR__ . '/../../../resources/disk-a/large-file.mp4';
        $size = \filesize($source);
        $this->assertGreaterThan(APP_STORAGE_READ_BUFFER, $size);

        $chunkSize = 5 * 1024 * 1024;
        $handle = \fopen($source, 'rb');
        $offset = 0;
        $largeId = Id::unique();

        while ($offset < $size) {
            $chunk = \fread($handle, $chunkSize);
            $end = $offset + \strlen($chunk) - 1;

            $large = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', \array_merge([
                'content-type' => 'multipart/form-data',
                'content-range' => 'bytes ' . $offset . '-' . $end . '/' . $size,
                'x-appwrite-id' => $largeId,
            ], $headers), [
                'fileId' => $largeId,
                'file' => new \CURLFile('data://video/mp4;base64,' . \base64_encode($chunk), 'video/mp4', 'large-file.mp4'),
            ]);

            $this->assertEquals(201, $large['headers']['status-code']);

            $offset = $end + 1;
        }

        \fclose($handle);

        $view = fn (string $fileId): string => 'http://127.0.0.1/v1/storage/buckets/' . $bucketId . '/files/' . $fileId . '/view?project=' . $projectId;

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/avatars/image', $headers, [
            'url' => $view($small['body']['$id']),
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);

        /**
         * Test for FAILURE
         */
        foreach (['/avatars/image', '/avatars/favicon'] as $path) {
            $response = $this->client->call(Client::METHOD_GET, $path, $headers, [
                'url' => $view($largeId),
            ]);

            $this->assertEquals(404, $response['headers']['status-code'], $path);
            $this->assertEquals(Exception::AVATAR_REMOTE_URL_FAILED, $response['body']['type'], $path);
        }
    }
}
