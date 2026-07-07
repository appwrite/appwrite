<?php

/**
 * VCS provider registry, read by Appwrite\Vcs\Resolver. To add a provider,
 * add an entry here (plus an OAuth2 adapter if auth is 'oauth2').
 */

use Utopia\VCS\Adapter\Git\GitHub;

return [
    'github' => [
        'name' => 'GitHub',
        'enabled' => true,
        'adapter' => GitHub::class,
        'oauth2' => 'Appwrite\\Auth\\OAuth2\\Github',
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
];
