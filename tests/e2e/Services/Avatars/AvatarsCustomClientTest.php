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
}
