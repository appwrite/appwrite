<?php

/**
 * VCS provider registry, read by Appwrite\Vcs\Factory.
 */

use Appwrite\Auth\OAuth2\Gitea as OAuth2Gitea;
use Appwrite\Auth\OAuth2\Github as OAuth2Github;
use Appwrite\Auth\OAuth2\Gitlab as OAuth2Gitlab;
use Utopia\VCS\Adapter\Git\Gitea;
use Utopia\VCS\Adapter\Git\GitHub;
use Utopia\VCS\Adapter\Git\GitLab;

return [
    'github' => [
        'adapter' => GitHub::class,
        'oauth2' => OAuth2Github::class,
        'variables' => [
            'appName' => ['required' => true, 'envVariable' => '_APP_VCS_GITHUB_APP_NAME'],
            'privateKey' => ['required' => true, 'envVariable' => '_APP_VCS_GITHUB_PRIVATE_KEY'],
            'appId' => ['required' => true, 'envVariable' => '_APP_VCS_GITHUB_APP_ID'],
            'clientId' => ['required' => true, 'envVariable' => '_APP_VCS_GITHUB_CLIENT_ID'],
            'clientSecret' => ['required' => true, 'envVariable' => '_APP_VCS_GITHUB_CLIENT_SECRET'],
            'webhookSecret' => ['required' => false, 'envVariable' => '_APP_VCS_GITHUB_WEBHOOK_SECRET'],
        ],
    ],
    'gitea' => [
        'adapter' => Gitea::class,
        'oauth2' => OAuth2Gitea::class,
        'variables' => [
            'endpoint' => ['required' => true, 'envVariable' => '_APP_VCS_GITEA_ENDPOINT'],
            'clientId' => ['required' => true, 'envVariable' => '_APP_VCS_GITEA_CLIENT_ID'],
            'clientSecret' => ['required' => true, 'envVariable' => '_APP_VCS_GITEA_CLIENT_SECRET'],
            // Unlike GitHub's legacy optional secret, Gitea webhooks must
            // always have a shared secret because Appwrite creates them directly.
            'webhookSecret' => ['required' => true, 'envVariable' => '_APP_VCS_GITEA_WEBHOOK_SECRET'],
        ],
    ],
    'gitlab' => [
        'adapter' => GitLab::class,
        'oauth2' => OAuth2Gitlab::class,
        // Only official gitlab.com is supported -- fixed, not configurable.
        'endpoint' => 'https://gitlab.com',
        'variables' => [
            'clientId' => ['required' => true, 'envVariable' => '_APP_VCS_GITLAB_CLIENT_ID'],
            'clientSecret' => ['required' => true, 'envVariable' => '_APP_VCS_GITLAB_CLIENT_SECRET'],
            'webhookSecret' => ['required' => true, 'envVariable' => '_APP_VCS_GITLAB_WEBHOOK_SECRET'],
        ],
    ],
];
