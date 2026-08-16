<?php

namespace Appwrite\Auth;

use Utopia\Auth\OAuth2\Provider;
use Utopia\System\System;

/**
 * Builds relying-party OAuth2 clients with Appwrite's PKCE key and user agent.
 */
final class OAuth2Client
{
    /**
     * @param class-string<Provider> $class
     * @param array<string, mixed> $state
     * @param array<int, string> $scopes
     */
    public static function create(
        string $class,
        string $appId,
        string $appSecret,
        string $callback,
        array $state = [],
        array $scopes = [],
    ): Provider {
        return new $class(
            $appId,
            $appSecret,
            $callback,
            $state,
            $scopes,
            stateEncryptionKey: System::getEnv('_APP_OPENSSL_KEY_V1', ''),
            userAgent: 'Appwrite OAuth2',
        );
    }
}
