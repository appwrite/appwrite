<?php

/**
 * VCS provider registry, read by Appwrite\Vcs\Resolver. To add a provider,
 * add an entry here (plus an OAuth2 adapter if auth is 'oauth2').
 */

use Appwrite\Auth\OAuth2\Gitea as GiteaOAuth2;
use Appwrite\Auth\OAuth2\Github as GithubOAuth2;
use Utopia\VCS\Adapter\Git\Gitea;
use Utopia\VCS\Adapter\Git\GitHub;

return [
    'github' => [
        'name' => 'GitHub',
        'enabled' => true,
        'adapter' => GitHub::class,
        'oauth2' => GithubOAuth2::class,
        'auth' => 'app',
        'envPrefix' => '_APP_VCS_GITHUB',
        'required' => ['APP_NAME', 'PRIVATE_KEY', 'APP_ID', 'CLIENT_ID', 'CLIENT_SECRET'],
        'endpoint' => false, // true = read {envPrefix}_ENDPOINT (self-hosted providers)
        'browserEndpoint' => 'https://github.com',
        'urls' => [
            'repository' => '{base}/{owner}/{repository}',
            'branch' => '{base}/{owner}/{repository}/tree/{branch}',
            'commit' => '{base}/{owner}/{repository}/commit/{commit}',
            'file' => '{base}/{owner}/{repository}/blob/{reference}',
        ],
        'headers' => [
            'event' => 'x-github-event',
            'signature' => 'x-hub-signature-256',
        ],
        'scopes' => [],
        'repositoryWebhook' => false,
    ],
    'gitea' => [
        'name' => 'Gitea',
        'enabled' => true,
        'adapter' => Gitea::class,
        'oauth2' => GiteaOAuth2::class,
        'auth' => 'oauth2',
        'envPrefix' => '_APP_VCS_GITEA',
        'required' => ['ENDPOINT', 'CLIENT_ID', 'CLIENT_SECRET'],
        'endpoint' => true, // read _APP_VCS_GITEA_ENDPOINT (self-hosted, no fixed default)
        'browserEndpoint' => null,
        'urls' => [
            'repository' => '{base}/{owner}/{repository}',
            'branch' => '{base}/{owner}/{repository}/src/branch/{branch}',
            'commit' => '{base}/{owner}/{repository}/commit/{commit}',
            'file' => '{base}/{owner}/{repository}/src/branch/{reference}',
        ],
        'headers' => [
            'event' => 'x-gitea-event',
            'signature' => 'x-gitea-signature',
        ],
        'scopes' => ['read:user', 'read:repository', 'write:repository', 'read:organization'],
        'repositoryWebhook' => true,
    ],
];
