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
}
