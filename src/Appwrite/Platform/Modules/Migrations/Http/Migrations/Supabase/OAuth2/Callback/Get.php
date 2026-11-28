<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations\Supabase\OAuth2\Callback;

use Appwrite\Auth\EncryptionKey;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\Supabase\Base;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Client\Client;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Validator\Text;

class Get extends Base
{
    public static function getName(): string
    {
        return 'getSupabaseOAuth2Callback';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Base::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/migrations/supabase/oauth2/callback')
            ->desc('Supabase account connection callback')
            ->groups(['api', 'migrations'])
            ->label('scope', 'migrations.write')
            ->label('docs', false)
            ->label('error', APP_VIEWS_DIR . '/general/error.phtml')
            ->param('code', '', new Text(2048, 0), 'Authorization code to exchange for tokens.', true)
            ->param('state', '', new Text(4096), 'State started by the connect request.')
            ->param('error', '', new Text(2048, 0), 'Error Supabase returns when access is not granted.', true)
            ->inject('request')
            ->inject('response')
            ->inject('user')
            ->inject('dbForPlatform')
            ->inject('clientForOAuth2')
            ->inject('platform')
            ->callback($this->action(...));
    }

    public function action(
        string $code,
        string $state,
        string $error,
        Request $request,
        Response $response,
        User $user,
        Database $dbForPlatform,
        Client $clientForOAuth2,
        array $platform,
    ): void {
        $oauth2 = $this->oauth2($clientForOAuth2, $request);
        $state = $oauth2->parseState($state) ?? [];

        $nonce = $state['nonce'] ?? '';
        $binding = $state['binding'] ?? '';

        // A state this user did not start is not trusted, so neither are the URLs inside it
        if (!\is_string($nonce) || !\is_string($binding) || $nonce === '' || !\hash_equals(\hash_hmac('sha256', $nonce . ':' . $user->getId(), EncryptionKey::current()), $binding)) {
            $this->redirect($response, $platform['consoleUrl'] ?? '');

            return;
        }

        // Both URLs passed the console hostname check before they went into the state
        $success = $state['success'] ?? '';
        $failure = $state['failure'] ?? '';

        if ($error !== '' || $code === '') {
            $this->redirect($response, $failure, 'access_denied');

            return;
        }

        try {
            $accessToken = $oauth2->getAccessToken($code);
            $refreshToken = $oauth2->getRefreshToken($code);
            $expiry = $oauth2->getAccessTokenExpiry($code);
            $accountId = $oauth2->getUserID($accessToken);
        } catch (\Throwable) {
            $this->redirect($response, $failure, 'access_denied');

            return;
        }

        if ($accessToken === '' || $refreshToken === '' || $accountId === '') {
            $this->redirect($response, $failure, 'access_denied');

            return;
        }

        $identity = $dbForPlatform->findOne('identities', [
            Query::equal('provider', [self::PROVIDER]),
            Query::equal('providerUid', [$accountId]),
        ]);

        if (!$identity->isEmpty() && $identity->getAttribute('userInternalId') !== $user->getSequence()) {
            $this->redirect($response, $failure, 'already_connected');

            return;
        }

        $tokens = [
            'providerAccessToken' => $accessToken,
            'providerRefreshToken' => $refreshToken,
            'providerAccessTokenExpiry' => DateTime::addSeconds(new \DateTime(), $expiry),
        ];

        if ($identity->isEmpty()) {
            // Without a provider email, so the identity never counts as a sign in email
            $dbForPlatform->createDocument('identities', new Document([
                '$id' => Id::unique(),
                '$permissions' => [
                    Permission::read(Role::user($user->getId())),
                    Permission::update(Role::user($user->getId())),
                    Permission::delete(Role::user($user->getId())),
                ],
                'userInternalId' => $user->getSequence(),
                'userId' => $user->getId(),
                'provider' => self::PROVIDER,
                'providerUid' => $accountId,
                'providerEmail' => '',
                ...$tokens,
            ]));
        } else {
            $dbForPlatform->updateDocument('identities', $identity->getId(), new Document($tokens));
        }

        $this->redirect($response, $success);
    }

    /**
     * Back to the console, with the reason on a failure so the wizard can say what went wrong
     */
    private function redirect(Response $response, string $url, string $error = ''): void
    {
        if ($error !== '') {
            $url .= (\str_contains($url, '?') ? '&' : '?') . \http_build_query(['error' => $error]);
        }

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($url);
    }
}
