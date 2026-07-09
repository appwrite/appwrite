<?php

/**
 * VCS provider registry, read by Appwrite\Vcs\Factory.
 */

use Utopia\VCS\Adapter\Git\Gitea;
use Utopia\VCS\Adapter\Git\GitHub;

return [
    'github' => [
        'enabled' => true,
        'adapter' => GitHub::class,
        'envVariables' => [
            'APP_NAME' => '_APP_VCS_GITHUB_APP_NAME',
            'PRIVATE_KEY' => '_APP_VCS_GITHUB_PRIVATE_KEY',
            'APP_ID' => '_APP_VCS_GITHUB_APP_ID',
            'CLIENT_ID' => '_APP_VCS_GITHUB_CLIENT_ID',
            'CLIENT_SECRET' => '_APP_VCS_GITHUB_CLIENT_SECRET',
            'WEBHOOK_SECRET' => '_APP_VCS_GITHUB_WEBHOOK_SECRET',
        ],
        'requiredEnvVariables' => ['APP_NAME', 'PRIVATE_KEY', 'APP_ID', 'CLIENT_ID', 'CLIENT_SECRET'],
    ],
    'gitea' => [
        'enabled' => true,
        'adapter' => Gitea::class,
        'envVariables' => [
            'ENDPOINT' => '_APP_VCS_GITEA_ENDPOINT',
            'CLIENT_ID' => '_APP_VCS_GITEA_CLIENT_ID',
            'CLIENT_SECRET' => '_APP_VCS_GITEA_CLIENT_SECRET',
            'WEBHOOK_SECRET' => '_APP_VCS_GITEA_WEBHOOK_SECRET',
        ],
        // WEBHOOK_SECRET is required (unlike GitHub's) because a Gitea webhook
        // has no other verification layer -- GitHub App deliveries also carry
        // a signed JWT Appwrite always validates independently of the secret.
        'requiredEnvVariables' => ['ENDPOINT', 'CLIENT_ID', 'CLIENT_SECRET', 'WEBHOOK_SECRET'],
    ],
];
