<?php

/**
 * VCS provider registry, read by Appwrite\Vcs\Manager. To add a provider,
 * add an entry here (plus an OAuth2 adapter if auth is 'oauth2').
 */

use Appwrite\Auth\OAuth2\Github as GithubOAuth2;
use Appwrite\Vcs\Provider;
use Utopia\VCS\Adapter\Git\GitHub;

return [
    'github' => [
        'name' => 'GitHub',
        'enabled' => true,
        'adapter' => GitHub::class,
        'oauth2' => GithubOAuth2::class,
        'auth' => Provider::AUTH_APP,
        'envVariables' => [
            'APP_NAME' => '_APP_VCS_GITHUB_APP_NAME',
            'PRIVATE_KEY' => '_APP_VCS_GITHUB_PRIVATE_KEY',
            'APP_ID' => '_APP_VCS_GITHUB_APP_ID',
            'CLIENT_ID' => '_APP_VCS_GITHUB_CLIENT_ID',
            'CLIENT_SECRET' => '_APP_VCS_GITHUB_CLIENT_SECRET',
            'WEBHOOK_SECRET' => '_APP_VCS_GITHUB_WEBHOOK_SECRET',
        ],
        'requiredEnvVariables' => ['APP_NAME', 'PRIVATE_KEY', 'APP_ID', 'CLIENT_ID', 'CLIENT_SECRET'],
        'endpoint' => 'https://api.github.com', // fixed; Utopia\VCS\Adapter\Git\GitHub has no setEndpoint()
        'browserEndpoint' => 'https://github.com',
        'repositoryUrl' => '{base}/{owner}/{repository}',
        'branchUrl' => '{base}/{owner}/{repository}/tree/{branch}',
        'commitUrl' => '{base}/{owner}/{repository}/commit/{commit}',
        'fileUrl' => '{base}/{owner}/{repository}/blob/{reference}',
        'headers' => [
            'event' => 'x-github-event',
            'signature' => 'x-hub-signature-256',
        ],
        'scopes' => [],
        'repositoryWebhook' => false,
    ],
];
