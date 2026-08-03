<?php

namespace Appwrite\SDK;

enum AuthType: string
{
    case JWT = APP_AUTH_TYPE_JWT;
    case KEY = APP_AUTH_TYPE_KEY;
    case SESSION = APP_AUTH_TYPE_SESSION;
    case ADMIN = APP_AUTH_TYPE_ADMIN;

    /**
     * Scopes a request to an organization via the X-Appwrite-Organization
     * header. Carries no platform of its own, so routes must still declare the
     * auth types that make them reachable (ADMIN, KEY, ...).
     */
    case ORGANIZATION = APP_AUTH_TYPE_ORGANIZATION;
}
