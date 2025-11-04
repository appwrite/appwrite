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
    'region' => 'fra',
    'legalName' => '',
    'legalCountry' => '',
    'legalState' => '',
    'legalCity' => '',
    'legalAddress' => '',
    'legalTaxId' => '',
    'auths' => [
        'mockNumbers' => [],
        'invites' => System::getEnv('_APP_CONSOLE_INVITES', 'enabled') === 'enabled',
        'limit' => (System::getEnv('_APP_CONSOLE_WHITELIST_ROOT', 'enabled') === 'enabled') ? 1 : 0, // limit signup to 1 user
        'duration' => TOKEN_EXPIRATION_LOGIN_LONG, // 1 Year in seconds
        'sessionAlerts' => System::getEnv('_APP_CONSOLE_SESSION_ALERTS', 'disabled') === 'enabled',
        'invalidateSessions' => true
    ],
    'authWhitelistEmails' => (!empty(System::getEnv('_APP_CONSOLE_WHITELIST_EMAILS', null))) ? \explode(',', System::getEnv('_APP_CONSOLE_WHITELIST_EMAILS', null)) : [],
    'authWhitelistIPs' => (!empty(System::getEnv('_APP_CONSOLE_WHITELIST_IPS', null))) ? \explode(',', System::getEnv('_APP_CONSOLE_WHITELIST_IPS', null)) : [],
    'oAuthProviders' => [
        'githubEnabled' => true,
        'githubSecret' => System::getEnv('_APP_CONSOLE_GITHUB_SECRET', ''),
        'githubAppid' => System::getEnv('_APP_CONSOLE_GITHUB_APP_ID', '')
    ],
    'smtpBaseTemplate' => APP_BRANDED_EMAIL_BASE_TEMPLATE,
];

return $console;
