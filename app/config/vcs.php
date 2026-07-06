<?php

/**
 * VCS provider registry.
 *
 * Each entry describes a git provider the VCS service can connect to. Adding a
 * provider means adding an entry here (plus an OAuth2 adapter when auth is
 * 'oauth2') — endpoints, workers, and the console read this registry through
 * Appwrite\Vcs\Resolver instead of hardcoding provider details.
 *
 * Keys:
 * - name:              Human-readable provider name.
 * - enabled:           Registry-level switch; disabled entries are never exposed.
 * - adapter:           Utopia VCS adapter class.
 * - oauth2:            Appwrite OAuth2 adapter class (token exchange/refresh).
 * - auth:              'app' for app-installation flows (GitHub App),
 *                      'oauth2' for plain OAuth2 code flows (Gitea and similar).
 * - envPrefix:         Prefix for the provider's environment variables.
 * - required:          Env keys (without prefix) that must be set for the
 *                      provider to count as configured.
 * - endpoint:          True when the API endpoint comes from {envPrefix}_ENDPOINT
 *                      (self-hosted providers); false when the adapter default is used.
 * - browserEndpoint:   Base URL for user-facing links; {envPrefix}_BROWSER_ENDPOINT
 *                      overrides, then this value, then the endpoint.
 * - urls:              User-facing URL templates. Placeholders: {base}, {owner},
 *                      {repository}, {branch}, {commit}, {reference}.
 * - headers:           Webhook request headers carrying the event name and payload signature.
 * - scopes:            OAuth2 scopes requested during authorization ('oauth2' auth only).
 * - repositoryWebhook: True when Appwrite must create per-repository webhooks
 *                      (providers without app-level event subscriptions).
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
        'endpoint' => false,
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
