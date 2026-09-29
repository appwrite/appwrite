<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Avatars;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;

final class AvatarsCustomClientTest extends Scope
{
    use AvatarsBase;
    use ProjectCustom;
    use SideClient;

    /**
     * The mock OAuth2 adapter reports a profile picture served by the mock
     * endpoints: a solid #00FF00 PNG. Cropping and re-encoding leave every
     * pixel that colour, so the assertion holds for any requested size.
     */
    private const OAUTH2_PHOTO_COLOR = ['r' => 0, 'g' => 255, 'b' => 0];

    public function testGetPhotoOAuth2(): void
    {
        /**
         * Test for SUCCESS — OAuth2 identity photo (Priority 1)
         *
         * Sign in through the mock OAuth2 provider, which stores its photo on
         * the identity, then assert /avatars/photo serves that photo instead of
         * falling through to Gravatar, initials or the static fallback.
         */
        $session = $this->createOAuth2Session();

        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', [
            'origin' => 'http://localhost',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ], [
            'width' => 128,
            'height' => 128,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        // The response must never be cached — profile photos change at any time.
        $this->assertEquals('private, no-store', $response['headers']['cache-control']);
        $this->assertOAuth2Photo($response['body']);

        // The photo also wins at the default size, where no crop is applied.
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', [
            'origin' => 'http://localhost',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ], []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertOAuth2Photo($response['body']);
    }

    public function testGetPhotoIdToken(): void
    {
        /**
         * Test for SUCCESS — OAuth2 identity photo from a native ID token sign-in
         *
         * The native flow takes the avatar straight off the token's `picture`
         * claim rather than calling getUserPhoto(), so assert it lands on the
         * identity and wins the avatar chain exactly like the browser flow.
         */
        $this->enableMockProvider();

        $projectId = $this->getProject()['$id'];

        $token = $this->client->call(Client::METHOD_GET, '/mock/tests/general/oauth2/id-token', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'claims' => \json_encode([
                'iss' => 'https://localhost/v1/mock',
                'aud' => '1',
                'iat' => \time(),
                'exp' => \time() + 3600,
                'sub' => 'idtoken-photo-' . \uniqid('', true),
                'email' => 'idtoken.photo.' . \uniqid('', true) . '@localhost.test',
                'email_verified' => true,
                'picture' => 'http://localhost/v1/mock/tests/general/oauth2/photo',
            ]),
            'header' => '',
        ]);

        $this->assertEquals(200, $token['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/id-token', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'provider' => 'mock',
            'idToken' => $token['body']['token'],
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $session = $response['cookies']['a_session_' . $projectId] ?? '';
        $this->assertNotEmpty($session);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', [
            'origin' => 'http://localhost',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session,
        ], [
            'width' => 128,
            'height' => 128,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/png', $response['headers']['content-type']);
        $this->assertOAuth2Photo($response['body']);
    }

    public function testGetPhotoOverridesIdentityPhoto(): void
    {
        /**
         * Test for SUCCESS — explicit parameters replace the identity photo
         *
         * The signed-in account has an OAuth2 identity photo, which wins the
         * chain when nothing else is asked for. An emailHash or name without
         * a userId may describe anyone, so the session user leaves the chain
         * and the account's own photo sources never shadow them. An explicit
         * userId — 'current()' included — opts the user back in, and the
         * parameters then override only their matching attribute.
         */
        $session = $this->createOAuth2Session();
        $hash = \hash('sha256', \uniqid('photo-') . '@appwrite.io');

        // Premise: without overrides the identity photo wins.
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', [
            'origin' => 'http://localhost',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ], [
            'width' => 100,
            'height' => 100,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertOAuth2Photo($response['body']);

        // name only: initials render from the requested name even though the
        // account holds a real photo.
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', [
            'origin' => 'http://localhost',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ], [
            'name' => 'W W',
            'width' => 100,
            'height' => 100,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertPhotoInitials($response['body']);

        // name '0' is falsy in PHP but is a real override: it must render as
        // initials instead of returning the identity photo.
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', [
            'origin' => 'http://localhost',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ], [
            'name' => '0',
            'width' => 100,
            'height' => 100,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertPhotoInitials($response['body']);

        // emailHash only: Gravatar and Libravatar miss on the random hash and
        // the chain ends at the static fallback — never the identity photo,
        // and never initials of the account's own name.
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', [
            'origin' => 'http://localhost',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ], [
            'emailHash' => $hash,
            'width' => 100,
            'height' => 100,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertPhotoFallback($response['body']);

        // emailHash + name: a photo when the hash resolves to one, otherwise
        // initials of the requested name.
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', [
            'origin' => 'http://localhost',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ], [
            'emailHash' => $hash,
            'name' => 'W W',
            'width' => 100,
            'height' => 100,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertPhotoInitials($response['body']);

        // An explicit userId merges instead: the name only replaces the
        // initials source, which sits below the identity photo — the account's
        // photo still wins.
        $account = $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]);

        $this->assertEquals(200, $account['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', [
            'origin' => 'http://localhost',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ], [
            'userId' => $account['body']['$id'],
            'name' => 'W W',
            'width' => 100,
            'height' => 100,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertOAuth2Photo($response['body']);

        // The 'current()' sentinel counts as an explicit userId too.
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', [
            'origin' => 'http://localhost',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ], [
            'userId' => 'current()',
            'emailHash' => $hash,
            'name' => 'W W',
            'width' => 100,
            'height' => 100,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertOAuth2Photo($response['body']);
    }

    public function testGetPhotoByUserId(): void
    {
        /**
         * Test for SUCCESS — a client session resolves another user's photo
         * by ID: the target's name renders as initials, not the caller's own
         * photo.
         */
        $userId = ID::unique();

        $user = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
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

        /**
         * Test for FAILURE — unknown user.
         */
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', \array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'userId' => ID::unique(),
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals(Exception::USER_NOT_FOUND, $response['body']['type']);
    }

    /**
     * Enable the mock OAuth2 provider on the project and walk the full login
     * redirect chain, returning the session secret of the signed-in user.
     */
    private function createOAuth2Session(): string
    {
        $this->enableMockProvider();

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/mock', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'success' => 'http://localhost/v1/mock/tests/general/oauth2/success',
            'failure' => 'http://localhost/v1/mock/tests/general/oauth2/failure',
        ], followRedirects: false);

        $this->assertEquals(301, $response['headers']['status-code']);

        // Provider consent, callback and redirect are three separate hops, each
        // answering with the location of the next one.
        $oauthClient = new Client();
        $oauthClient->setEndpoint('');

        foreach (\range(1, 3) as $ignored) {
            $response = $oauthClient->call(Client::METHOD_GET, $response['headers']['location'], followRedirects: false);
            $this->assertEquals(301, $response['headers']['status-code']);
        }

        $session = $response['cookies']['a_session_' . $this->getProject()['$id']] ?? '';
        $this->assertNotEmpty($session);

        return $session;
    }

    /**
     * Enable the mock OAuth2 provider on the project under test.
     */
    private function enableMockProvider(): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $this->getProject()['$id'] . '/oauth2', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ], [
            'provider' => 'mock',
            'appId' => '1',
            'secret' => '123456',
            'enabled' => true,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PATCH, '/mock/tests/general/oauth2/native', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ], [
            'projectId' => $this->getProject()['$id'],
            'enabled' => true,
        ]);

        $this->assertEquals(204, $response['headers']['status-code']);
    }

    /**
     * Assert the image is the photo the mock OAuth2 provider handed out.
     */
    private function assertOAuth2Photo(string $blob): void
    {
        $this->assertNotEmpty($blob);

        $image = new \Imagick();
        $image->readImageBlob($blob);

        $samples = [
            [0, 0],
            [$image->getImageWidth() - 1, $image->getImageHeight() - 1],
            [\intdiv($image->getImageWidth(), 2), \intdiv($image->getImageHeight(), 2)],
        ];

        foreach ($samples as [$x, $y]) {
            $color = $image->getImagePixelColor($x, $y)->getColor();

            $this->assertSame(
                self::OAUTH2_PHOTO_COLOR,
                ['r' => $color['r'], 'g' => $color['g'], 'b' => $color['b']],
                "Pixel at {$x},{$y} is not the OAuth2 provider photo — the avatar chain fell through to another provider."
            );
        }
    }

    public function testUpdatePhoto(): void
    {
        $headers = $this->createPhotoUser();

        /**
         * Test for SUCCESS — the uploaded photo wins the provider chain
         */
        $red = $this->createImage('#FF0000', 'png');
        $response = $this->uploadPhoto($headers, $red, 'photo.png');

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($response['body']['chunksTotal'], $response['body']['chunksUploaded']);
        $this->assertEquals('image/png', $response['body']['mimeType']);
        $this->assertSamePhoto($red, $this->getPhoto($headers));

        /**
         * Test for SUCCESS — a replacement is served right away
         */
        $blue = $this->createImage('#0000FF', 'webp');
        $response = $this->uploadPhoto($headers, $blue, 'photo.webp');

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('image/webp', $response['body']['mimeType']);
        $this->assertSamePhoto($blue, $this->getPhoto($headers));
    }

    public function testUpdatePhotoInvalid(): void
    {
        $headers = $this->createPhotoUser();
        $png = $this->createImage('#FF0000', 'png');

        /**
         * Test for FAILURE — no file
         */
        $response = $this->client->call(Client::METHOD_PUT, '/avatars/photo', \array_merge($headers, [
            'content-type' => 'multipart/form-data',
        ]), []);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals(Exception::STORAGE_FILE_EMPTY, $response['body']['type']);

        /**
         * Test for FAILURE — unsupported extension
         */
        $response = $this->uploadPhoto($headers, 'not an image', 'notes.txt');

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals(Exception::STORAGE_FILE_TYPE_UNSUPPORTED, $response['body']['type']);

        /**
         * Test for FAILURE — an SVG renamed to .png is rejected by its content
         */
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><rect width="64" height="64" fill="#FF0000"/></svg>';
        $response = $this->uploadPhoto($headers, $svg, 'photo.png');

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals(Exception::STORAGE_FILE_TYPE_UNSUPPORTED, $response['body']['type']);

        /**
         * Test for FAILURE — declared size over the limit
         */
        $chunkSize = 5 * 1024 * 1024;
        $response = $this->uploadPhoto($headers, \str_repeat("\0", $chunkSize), 'photo.png', [
            'content-range' => 'bytes 0-' . ($chunkSize - 1) . '/99999999',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals(Exception::STORAGE_INVALID_FILE_SIZE, $response['body']['type']);

        /**
         * Test for FAILURE — body larger than the declared range can't sneak past the size limit
         */
        $response = $this->uploadPhoto($headers, $png, 'photo.png', [
            'content-range' => 'bytes 0-0/1',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals(Exception::STORAGE_INVALID_CONTENT_RANGE, $response['body']['type']);

        /**
         * Test for FAILURE — range not aligned to a chunk boundary
         */
        $response = $this->uploadPhoto($headers, $png, 'photo.png', [
            'content-range' => 'bytes 1-' . \strlen($png) . '/' . (\strlen($png) + 1),
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals(Exception::STORAGE_INVALID_CONTENT_RANGE, $response['body']['type']);

        /**
         * Test for FAILURE — malformed range
         */
        $response = $this->uploadPhoto($headers, $png, 'photo.png', [
            'content-range' => 'bytes invalid',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals(Exception::STORAGE_INVALID_CONTENT_RANGE, $response['body']['type']);

        /**
         * Test for FAILURE — a later chunk of an upload that was never started
         */
        $response = $this->uploadPhoto($headers, 'tail', 'photo.png', [
            'content-range' => 'bytes ' . $chunkSize . '-' . ($chunkSize + 3) . '/' . ($chunkSize + 4),
            'x-appwrite-id' => ID::unique(),
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals(Exception::STORAGE_FILE_NOT_FOUND, $response['body']['type']);

        /**
         * Test for SUCCESS — none of the failures became the photo
         */
        $this->assertPhotoInitials($this->getPhoto($headers));
    }

    public function testUpdatePhotoChunked(): void
    {
        $headers = $this->createPhotoUser();
        $chunkSize = 5 * 1024 * 1024;

        // Random pixels don't compress, so this PNG spans two chunks
        $image = new \Imagick();
        $image->newImage(1600, 1600, '#808080');
        $image->addNoiseImage(\Imagick::NOISE_RANDOM);
        $image->setImageDepth(8);
        $image->setImageFormat('png24');
        $large = $image->getImageBlob();
        $size = \strlen($large);

        $this->assertGreaterThan($chunkSize, $size);

        /**
         * Test for SUCCESS — chunks assemble into the served photo
         */
        $id = ID::unique();
        $response = [];

        for ($offset = 0; $offset < $size; $offset += $chunkSize) {
            $response = $this->uploadPhoto($headers, \substr($large, $offset, $chunkSize), 'large.png', [
                'content-range' => 'bytes ' . $offset . '-' . (\min($offset + $chunkSize, $size) - 1) . '/' . $size,
                'x-appwrite-id' => $id,
            ]);

            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals($id, $response['body']['$id']);
        }

        $this->assertEquals($response['body']['chunksTotal'], $response['body']['chunksUploaded']);
        $this->assertSamePhoto($large, $this->getPhoto($headers));

        /**
         * Test for SUCCESS — resending the final chunk changes nothing
         */
        $lastOffset = $chunkSize * \intdiv($size - 1, $chunkSize);
        $response = $this->uploadPhoto($headers, \substr($large, $lastOffset), 'large.png', [
            'content-range' => 'bytes ' . $lastOffset . '-' . ($size - 1) . '/' . $size,
            'x-appwrite-id' => $id,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($id, $response['body']['$id']);
        $this->assertSamePhoto($large, $this->getPhoto($headers));

        /**
         * Test for FAILURE — a newer photo cancels an upload still in progress
         */
        $pending = ID::unique();
        $response = $this->uploadPhoto($headers, \substr($large, 0, $chunkSize), 'large.png', [
            'content-range' => 'bytes 0-' . ($chunkSize - 1) . '/' . $size,
            'x-appwrite-id' => $pending,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        $green = $this->createImage('#00FF00', 'png');
        $response = $this->uploadPhoto($headers, $green, 'photo.png');

        $this->assertEquals(200, $response['headers']['status-code']);

        $response = $this->uploadPhoto($headers, \substr($large, $chunkSize), 'large.png', [
            'content-range' => 'bytes ' . $chunkSize . '-' . ($size - 1) . '/' . $size,
            'x-appwrite-id' => $pending,
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertSamePhoto($green, $this->getPhoto($headers));
    }

    public function testDeletePhoto(): void
    {
        $headers = $this->createPhotoUser();
        $red = $this->createImage('#FF0000', 'png');

        $response = $this->uploadPhoto($headers, $red, 'photo.png');

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertSamePhoto($red, $this->getPhoto($headers));

        /**
         * Test for SUCCESS — deleting falls back to the default chain
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/avatars/photo', $headers);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertPhotoInitials($this->getPhoto($headers));

        /**
         * Test for SUCCESS — deleting again is a no-op
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/avatars/photo', $headers);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertPhotoInitials($this->getPhoto($headers));

        /**
         * Test for SUCCESS — a photo can be set again after deletion
         */
        $response = $this->uploadPhoto($headers, $red, 'photo.png');

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertSamePhoto($red, $this->getPhoto($headers));
    }

    /**
     * A user of its own, so a photo never leaks into tests that expect the default chain.
     *
     * @return array<string, string>
     */
    private function createPhotoUser(): array
    {
        $projectId = $this->getProject()['$id'];
        $email = \uniqid('photo-', true) . '@localhost.test';

        $user = $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => 'password',
            'name' => 'User Name',
        ]);

        $this->assertEquals(201, $user['headers']['status-code']);

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'email' => $email,
            'password' => 'password',
        ]);

        $this->assertEquals(201, $session['headers']['status-code']);

        return [
            'origin' => 'http://localhost',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $session['cookies']['a_session_' . $projectId],
        ];
    }

    private function createImage(string $color, string $format): string
    {
        $image = new \Imagick();
        $image->newImage(64, 64, $color);
        $image->setImageFormat($format);
        $image->setImageCompressionQuality(100);

        if ($format === 'webp') {
            $image->setOption('webp:lossless', 'true');
        }

        return $image->getImageBlob();
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $extra
     * @return array<string, mixed>
     */
    private function uploadPhoto(array $headers, string $contents, string $filename, array $extra = []): array
    {
        return $this->client->call(Client::METHOD_PUT, '/avatars/photo', \array_merge($headers, [
            'content-type' => 'multipart/form-data',
        ], $extra), [
            'file' => new \CURLFile('data://application/octet-stream;base64,' . \base64_encode($contents), 'application/octet-stream', $filename),
        ]);
    }

    /**
     * @param array<string, string> $headers
     */
    private function getPhoto(array $headers): string
    {
        $response = $this->client->call(Client::METHOD_GET, '/avatars/photo', $headers, [
            'width' => 0,
            'height' => 0,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        return $response['body'];
    }

    private function assertSamePhoto(string $expected, string $actual): void
    {
        $expectedImage = new \Imagick();
        $expectedImage->readImageBlob($expected);
        $actualImage = new \Imagick();
        $actualImage->readImageBlob($actual);

        $width = $expectedImage->getImageWidth();
        $height = $expectedImage->getImageHeight();

        $this->assertSame([$width, $height], [$actualImage->getImageWidth(), $actualImage->getImageHeight()]);

        foreach ([[0, 0], [$width - 1, $height - 1], [\intdiv($width, 2), \intdiv($height, 2)], [\intdiv($width, 3), \intdiv($height, 5)]] as [$x, $y]) {
            $expectedColor = $expectedImage->getImagePixelColor($x, $y)->getColor();
            $actualColor = $actualImage->getImagePixelColor($x, $y)->getColor();

            $this->assertSame(
                [$expectedColor['r'], $expectedColor['g'], $expectedColor['b']],
                [$actualColor['r'], $actualColor['g'], $actualColor['b']],
                "Pixel at {$x},{$y} differs from the uploaded photo."
            );
        }
    }
}
