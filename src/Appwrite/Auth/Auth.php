<?php

namespace Appwrite\Auth;

use Utopia\Auth\Proofs\Token;

class Auth
{
    /**
     * @var string
     *
     * @deprecated We plan to deprecate this class in the future. Use Utopia Auth when possible.
     */
    public static $cookieNamePreview = 'a_jwt_console';

    /**
     * Token type to session provider mapping.
     *
     * @deprecated We plan to deprecate this class in the future. Use Utopia Auth when possible.
     * @param int $type
     *
     * @return string
     */
    public static function getSessionProviderByTokenType(int $type): string
    {
        switch ($type) {
            case TOKEN_TYPE_VERIFICATION:
            case TOKEN_TYPE_RECOVERY:
            case TOKEN_TYPE_INVITE:
                return SESSION_PROVIDER_EMAIL;
            case TOKEN_TYPE_MAGIC_URL:
                return SESSION_PROVIDER_MAGIC_URL;
            case TOKEN_TYPE_PHONE:
                return SESSION_PROVIDER_PHONE;
            case TOKEN_TYPE_OAUTH2:
                return SESSION_PROVIDER_OAUTH2;
            default:
                return SESSION_PROVIDER_TOKEN;
        }
    }
}
