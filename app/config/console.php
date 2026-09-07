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
        'gitlabEnabled' => true,
        // Auth\OAuth2\Gitlab reads its endpoint out of a JSON-encoded secret, so
        // _APP_CONSOLE_GITLAB_ENDPOINT travels with the credential. Stays empty when
        // unconfigured so account.php keeps reporting the provider as disabled.
        'gitlabSecret' => empty(System::getEnv('_APP_CONSOLE_GITLAB_SECRET', '')) ? '' : \json_encode([
            'clientSecret' => System::getEnv('_APP_CONSOLE_GITLAB_SECRET', ''),
            'endpoint' => System::getEnv('_APP_CONSOLE_GITLAB_ENDPOINT', 'https://gitlab.com'),
        ]),
        'gitlabAppid' => System::getEnv('_APP_CONSOLE_GITLAB_APP_ID', ''),
        'bitbucketEnabled' => true,
        'bitbucketSecret' => System::getEnv('_APP_CONSOLE_BITBUCKET_SECRET', ''),
        'bitbucketAppid' => System::getEnv('_APP_CONSOLE_BITBUCKET_APP_ID', ''),
        'googleEnabled' => true,
        'googleSecret' => System::getEnv('_APP_CONSOLE_GOOGLE_SECRET', ''),
        'googleAppid' => System::getEnv('_APP_CONSOLE_GOOGLE_APP_ID', ''),
    ],
    'smtpBaseTemplate' => APP_BRANDED_EMAIL_BASE_TEMPLATE,
];

return $console;
