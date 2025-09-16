<?php

/**
 * Initializes console project document.
 */

use Appwrite\Auth\Auth;
use Appwrite\Network\Platform;
use Utopia\Database\Helpers\ID;
use Utopia\System\System;

$localeCodes = include __DIR__ . '/locale/codes.php';

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
        'duration' => Auth::TOKEN_EXPIRATION_LOGIN_LONG, // 1 Year in seconds
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
    'templates' => [
        'email.verification-en' => [
            'subject' => 'Account Verification',
            'preview' => 'Verify your email to activate your {{project}} account.',
            'heading' => 'Verify your email to start using Appwrite Cloud',
            'hello' => 'Hello {{user}},',
            'body' => 'Thanks for signing up for Appwrite Cloud. Before you can get started, please verify your email address.',
            'footer' => 'If you didn’t create an account, you can ignore this email.',
            'buttonText' => 'Verify email',
            'thanks' => 'Thanks,',
            "signature" => "{{project}} team",
        ],
        'email.mfaChallenge-en' => [
            'subject' => 'Verification Code for {{project}}',
            'preview' => 'Use code {{otp}} for two-step verification in {{project}}. Expires in 15 minutes.',
            'heading' => 'Complete two-step verification to use Appwrite Cloud',
            'hello' => 'Hello {{user}},',
            'body' => 'Enter the following code to confirm your two-step verification in {{b}}{{project}}{{/b}}. This code will expire in 15 minutes.',
            'thanks' => 'Thanks,',
            "signature" => "{{project}} team",
        ]
    ],
    'customEmails' => true,
];

foreach ($localeCodes as $localeCode) {
    $console['templates']['email.verification-'.$localeCode['code']] = $console['templates']['email.verification-en'];
    $console['templates']['email.mfaChallenge-'.$localeCode['code']] = $console['templates']['email.mfaChallenge-en'];
}

return $console;
