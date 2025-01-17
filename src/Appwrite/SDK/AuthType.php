<?php

namespace Appwrite\SDK;

enum AuthType: string
{
    case JWT = APP_AUTH_TYPE_JWT;
    case KEY = APP_AUTH_TYPE_KEY;
    case SESSION = APP_AUTH_TYPE_SESSION;
    case ADMIN = APP_AUTH_TYPE_ADMIN;
}
