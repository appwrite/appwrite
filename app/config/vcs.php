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
        // Each provider owns its own construction quirks (Gitea calls
        // setEndpoint(), GitLab JSON-encodes its secret); Factory just
        // supplies the resolved env values, so no per-provider branching lives there.
        'oauth2' => fn (string $clientId, string $clientSecret, string $endpoint) => new OAuth2Github($clientId, $clientSecret, ''),
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
        'oauth2' => function (string $clientId, string $clientSecret, string $endpoint) {
            $oauth2 = new OAuth2Gitea($clientId, $clientSecret, '');
            $oauth2->setEndpoint($endpoint);
            return $oauth2;
        },
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
        // Auth\OAuth2\Gitlab is shared with the "Sign in with GitLab" account-login
        // provider, which JSON-encodes its secret as {"clientSecret","endpoint"}
        // to support a per-project self-hosted endpoint -- match that shape here too.
        'oauth2' => fn (string $clientId, string $clientSecret, string $endpoint) => new OAuth2Gitlab($clientId, \json_encode([
            'clientSecret' => $clientSecret,
            'endpoint' => $endpoint,
        ]), ''),
        // Only official gitlab.com is supported -- fixed, not configurable.
        'endpoint' => 'https://gitlab.com',
        'variables' => [
            'clientId' => ['required' => true, 'envVariable' => '_APP_VCS_GITLAB_CLIENT_ID'],
            'clientSecret' => ['required' => true, 'envVariable' => '_APP_VCS_GITLAB_CLIENT_SECRET'],
            'webhookSecret' => ['required' => true, 'envVariable' => '_APP_VCS_GITLAB_WEBHOOK_SECRET'],
        ],
    ],
];
