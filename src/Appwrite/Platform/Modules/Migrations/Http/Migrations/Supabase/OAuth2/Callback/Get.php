<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations\Supabase\OAuth2\Callback;

use Appwrite\Auth\EncryptionKey;
use Appwrite\Extend\Exception;
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
    ): void {
        $oauth2 = $this->oauth2($clientForOAuth2, $request);
        $state = $oauth2->parseState($state) ?? [];

        $nonce = $state['nonce'] ?? '';
        $binding = $state['binding'] ?? '';

        if (!\is_string($nonce) || !\is_string($binding) || $nonce === '' || !\hash_equals(\hash_hmac('sha256', $nonce . ':' . $user->getId(), EncryptionKey::current()), $binding)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'This Supabase connection was started by another session. Start it again from the console.');
        }

        // Both URLs passed the console hostname check before they went into the state
        $success = $state['success'] ?? '';
        $failure = $state['failure'] ?? '';

        if ($error !== '' || $code === '') {
            $response
                ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                ->addHeader('Pragma', 'no-cache')
                ->redirect($failure);

            return;
        }

        try {
            $accessToken = $oauth2->getAccessToken($code);
            $refreshToken = $oauth2->getRefreshToken($code);
            $expiry = $oauth2->getAccessTokenExpiry($code);
            $accountId = $oauth2->getUserID($accessToken);
        } catch (\Throwable) {
            throw new Exception(Exception::MIGRATION_PROVIDER_ERROR, 'Supabase did not grant access. Try connecting your Supabase account again.');
        }

        if ($accessToken === '' || $refreshToken === '' || $accountId === '') {
            throw new Exception(Exception::MIGRATION_PROVIDER_ERROR, 'Supabase did not grant access. Try connecting your Supabase account again.');
        }

        $identity = $dbForPlatform->findOne('identities', [
            Query::equal('provider', ['supabase']),
            Query::equal('providerUid', [$accountId]),
        ]);

        if (!$identity->isEmpty() && $identity->getAttribute('userInternalId') !== $user->getSequence()) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'This Supabase account is connected to another console user.');
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
                'provider' => 'supabase',
                'providerUid' => $accountId,
                'providerEmail' => '',
                ...$tokens,
            ]));
        } else {
            $dbForPlatform->updateDocument('identities', $identity->getId(), new Document($tokens));
        }

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($success);
    }
}
