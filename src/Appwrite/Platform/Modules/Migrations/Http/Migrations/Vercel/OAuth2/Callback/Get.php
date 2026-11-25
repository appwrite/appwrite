<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations\Vercel\OAuth2\Callback;

use Appwrite\Auth\EncryptionKey;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\Vercel\Base;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Client\Client;
use Utopia\Database\Database;
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
        return 'getVercelOAuth2Callback';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Base::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/migrations/vercel/oauth2/callback')
            ->desc('Vercel account connection callback')
            ->groups(['api', 'migrations'])
            ->label('scope', 'migrations.write')
            ->label('docs', false)
            ->label('error', APP_VIEWS_DIR . '/general/error.phtml')
            ->param('code', '', new Text(2048, 0), 'Authorization code to exchange for the installation.', true)
            ->param('state', '', new Text(4096), 'State started by the connect request.')
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
        Request $request,
        Response $response,
        User $user,
        Database $dbForPlatform,
        Client $clientForOAuth2,
        array $platform,
    ): void {
        $state = \json_decode($state, true);
        $state = \is_array($state) ? $state : [];

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

        if ($code === '') {
            $this->redirect($response, $failure, 'access_denied');

            return;
        }

        [$clientId, $clientSecret] = $this->getClient();

        try {
            // Only the installation is kept; its tokens are requested again when needed
            $installation = $this->request($clientForOAuth2, 'POST', '/v2/oauth/access_token', [], [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $code,
                'redirect_uri' => $this->getRedirectURL($request),
            ]);
        } catch (\Throwable) {
            $this->redirect($response, $failure, 'access_denied');

            return;
        }

        $installationId = $installation['installation_id'] ?? '';

        if ($installationId === '') {
            $this->redirect($response, $failure, 'access_denied');

            return;
        }

        $secrets = ['teamId' => $installation['team_id'] ?? ''];

        $identity = $dbForPlatform->findOne('identities', [
            Query::equal('provider', [self::PROVIDER]),
            Query::equal('userInternalId', [$user->getSequence()]),
        ]);

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
                'providerUid' => $installationId,
                'providerEmail' => '',
                'secrets' => $secrets,
            ]));
        } else {
            // A new installation replaces the earlier one, along with its cached token
            $dbForPlatform->updateDocument('identities', $identity->getId(), new Document([
                'providerUid' => $installationId,
                'providerAccessToken' => '',
                'providerAccessTokenExpiry' => null,
                'secrets' => $secrets,
            ]));
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
