<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations\Supabase;

use Appwrite\Auth\OAuth2\Supabase as OAuth2Supabase;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\Action;
use Appwrite\Utopia\Request;
use Utopia\Client\Client;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\System\System;

/**
 * The console user's authorization to read their Supabase account, kept as an identity
 * on the platform so it is shared by every project the user migrates into.
 */
abstract class Base extends Action
{
    /**
     * Identity provider of the connection, apart from Supabase sign in so the two never share a record
     */
    protected const string PROVIDER = 'supabase-migrations';

    protected function oauth2(Client $clientForOAuth2, Request $request, array $state = []): OAuth2Supabase
    {
        $appId = System::getEnv('_APP_MIGRATIONS_SUPABASE_CLIENT_ID', '');
        $appSecret = System::getEnv('_APP_MIGRATIONS_SUPABASE_CLIENT_SECRET', '');

        if ($appId === '' || $appSecret === '') {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED, 'Supabase authorization is not configured on this server.');
        }

        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
        $port = $request->getPort();
        $callback = $protocol . '://' . $request->getHostname();
        // An empty port means the host named none, so the scheme's default applies
        if ($port !== '' && $port !== ($protocol === 'https' ? '443' : '80')) {
            $callback .= ':' . $port;
        }

        return new OAuth2Supabase($clientForOAuth2, $appId, $appSecret, $callback . '/v1/migrations/supabase/oauth2/callback', $state);
    }

    protected function getIdentity(Database $dbForPlatform, Document $user): Document
    {
        $identity = $dbForPlatform->findOne('identities', [
            Query::equal('provider', [self::PROVIDER]),
            Query::equal('userInternalId', [$user->getSequence()]),
        ]);

        if ($identity->isEmpty()) {
            throw new Exception(Exception::USER_IDENTITY_NOT_FOUND, 'Connect your Supabase account first.');
        }

        return $identity;
    }

    /**
     * The identity's access token, refreshed first when it has expired
     */
    protected function getAccessToken(OAuth2Supabase $oauth2, Database $dbForPlatform, Document $identity): string
    {
        $expiry = $identity->getAttribute('providerAccessTokenExpiry');

        if (!empty($expiry) && new \DateTime($expiry) > new \DateTime()) {
            return $identity->getAttribute('providerAccessToken', '');
        }

        try {
            $oauth2->refreshTokens($identity->getAttribute('providerRefreshToken', ''));
        } catch (\Throwable) {
            throw new Exception(Exception::USER_IDENTITY_NOT_FOUND, 'Your Supabase authorization has expired. Connect your Supabase account again.');
        }

        $accessToken = $oauth2->getAccessToken('');

        if ($accessToken === '') {
            throw new Exception(Exception::USER_IDENTITY_NOT_FOUND, 'Your Supabase authorization has expired. Connect your Supabase account again.');
        }

        $dbForPlatform->updateDocument('identities', $identity->getId(), new Document([
            'providerAccessToken' => $accessToken,
            'providerRefreshToken' => $oauth2->getRefreshToken(''),
            'providerAccessTokenExpiry' => DateTime::addSeconds(new \DateTime(), $oauth2->getAccessTokenExpiry('')),
        ]));

        return $accessToken;
    }
}
