<?php

/**
 * Initializes console project document.
 */

use Appwrite\Network\Platform;
use Utopia\Database\Helpers\ID;
use Utopia\System\System;

$console = [
    '$id' => ID::custom('console'),
    '$sequence' => ID::custom('console'),
    'name' => 'Appwrite',
    '$collection' => ID::custom('projects'),
    'description' => 'Appwrite core engine',
    'logo' => '',
    'teamId' => null,
    'webhooks' => [],
    'keys' => [],
    'platforms' => [
        [
            '$collection' => ID::custom('platforms'),
            'name' => 'Localhost',
            'type' => Platform::TYPE_WEB,
            'hostname' => 'localhost',
        ], // Current host is added on app init
    ],
    'region' => System::getEnv('_APP_REGION', 'default'),
    'legalName' => '',
    'legalCountry' => '',
    'legalState' => '',
    'legalCity' => '',
    'legalAddress' => '',
    'legalTaxId' => '',
    'auths' => [
        'membershipsUserName' => true,
        'membershipsUserEmail' => true,
        'membershipsMfa' => true,
        'membershipsUserId' => true,
        'membershipsUserPhone' => true,
        'membershipsUserAccessedAt' => true,
        'mockNumbers' => [],
        'invites' => System::getEnv('_APP_CONSOLE_INVITES', 'enabled') === 'enabled',
        'limit' => (System::getEnv('_APP_CONSOLE_WHITELIST_ROOT', 'enabled') === 'enabled') ? 1 : 0, // limit signup to 1 user
        'duration' => TOKEN_EXPIRATION_LOGIN_LONG, // 1 Year in seconds
        'sessionAlerts' => System::getEnv('_APP_CONSOLE_SESSION_ALERTS', 'disabled') === 'enabled',
        // For email configuration, false means feature is disabled; false means these emails are allowed during sign-ups
        'disposableEmails' => false,
        'canonicalEmails' => false,
        'freeEmails' => false,
        'corporateEmails' => false,
        'invalidateSessions' => true
    ],
    'authWhitelistEmails' => (!empty(System::getEnv('_APP_CONSOLE_WHITELIST_EMAILS', null))) ? \explode(',', System::getEnv('_APP_CONSOLE_WHITELIST_EMAILS', null)) : [],
    'authWhitelistIPs' => (!empty(System::getEnv('_APP_CONSOLE_WHITELIST_IPS', null))) ? \explode(',', System::getEnv('_APP_CONSOLE_WHITELIST_IPS', null)) : [],
    'oAuthProviders' => [
        'githubEnabled' => true,
        'githubSecret' => System::getEnv('_APP_CONSOLE_GITHUB_SECRET', ''),
        'githubAppid' => System::getEnv('_APP_CONSOLE_GITHUB_APP_ID', ''),
        // OIDC is only enabled when a client ID is provided together with a usable
        // endpoint configuration: either a discovery well-known URL, or all three
        // explicit endpoints (authorization, token, userinfo). Requiring at least
        // one of these prevents advertising a login option that cannot redirect to
        // the identity provider because every endpoint would resolve to an empty URL.
        'oidcEnabled' => !empty(System::getEnv('_APP_CONSOLE_OIDC_CLIENT_ID', ''))
            && (
                !empty(System::getEnv('_APP_CONSOLE_OIDC_WELLKNOWN_ENDPOINT', ''))
                || (
                    !empty(System::getEnv('_APP_CONSOLE_OIDC_AUTHORIZATION_ENDPOINT', ''))
                    && !empty(System::getEnv('_APP_CONSOLE_OIDC_TOKEN_ENDPOINT', ''))
                    && !empty(System::getEnv('_APP_CONSOLE_OIDC_USERINFO_ENDPOINT', ''))
                )
            ),
        'oidcAppid' => System::getEnv('_APP_CONSOLE_OIDC_CLIENT_ID', ''),
        'oidcSecret' => \json_encode([
            'clientSecret' => System::getEnv('_APP_CONSOLE_OIDC_CLIENT_SECRET', ''),
            'authorizationEndpoint' => System::getEnv('_APP_CONSOLE_OIDC_AUTHORIZATION_ENDPOINT', ''),
            'tokenEndpoint' => System::getEnv('_APP_CONSOLE_OIDC_TOKEN_ENDPOINT', ''),
            'userinfoEndpoint' => System::getEnv('_APP_CONSOLE_OIDC_USERINFO_ENDPOINT', ''),
            'wellKnownEndpoint' => System::getEnv('_APP_CONSOLE_OIDC_WELLKNOWN_ENDPOINT', ''),
        ]),
    ],
    'smtpBaseTemplate' => APP_BRANDED_EMAIL_BASE_TEMPLATE,
];

return $console;
