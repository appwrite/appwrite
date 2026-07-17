<?php

namespace Appwrite\Vcs;

use Appwrite\Auth\OAuth2;
use Appwrite\Auth\OAuth2\Gitea as OAuth2Gitea;
use Appwrite\Auth\OAuth2\Github as OAuth2Github;
use Appwrite\Auth\OAuth2\Gitlab as OAuth2Gitlab;
use Appwrite\Extend\Exception;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\System\System;

class InstallationTokens
{
    /**
     * Refreshes an installation's token, resolving the right OAuth2 client
     * for its provider. Centralizes the per-provider construction that used
     * to be duplicated at every call site -- callers just pass the
     * installation. A no-op for App-based installations (e.g. GitHub App),
     * since those have no personalAccessTokenExpiry to begin with.
     */
    public function refreshForInstallation(Document $installation, Database $dbForPlatform): Document
    {
        $provider = $installation->getAttribute('provider', 'github');

        $oauth2 = match ($provider) {
            'github' => new OAuth2Github(System::getEnv('_APP_VCS_GITHUB_CLIENT_ID', ''), System::getEnv('_APP_VCS_GITHUB_CLIENT_SECRET', ''), ''),
            'gitea' => (function () {
                $oauth2 = new OAuth2Gitea(System::getEnv('_APP_VCS_GITEA_CLIENT_ID', ''), System::getEnv('_APP_VCS_GITEA_CLIENT_SECRET', ''), '');
                $oauth2->setEndpoint(System::getEnv('_APP_VCS_GITEA_ENDPOINT', ''));
                return $oauth2;
            })(),
            'gitlab' => new OAuth2Gitlab(System::getEnv('_APP_VCS_GITLAB_CLIENT_ID', ''), \json_encode([
                'clientSecret' => System::getEnv('_APP_VCS_GITLAB_CLIENT_SECRET', ''),
                'endpoint' => System::getEnv('_APP_VCS_GITLAB_ENDPOINT', 'https://gitlab.com'),
            ]), ''),
            default => throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Unsupported VCS provider: ' . $provider),
        };

        return $this->refresh($installation, $dbForPlatform, $oauth2);
    }

    public function refresh(Document $installation, Database $dbForPlatform, OAuth2 $oauth2, ?Document $identity = null): Document
    {
        $accessToken = $installation->getAttribute('personalAccessToken');
        $refreshToken = $installation->getAttribute('personalRefreshToken');
        $accessTokenExpiry = $installation->getAttribute('personalAccessTokenExpiry');

        if ($identity !== null) {
            $accessToken = $accessToken ?? $identity->getAttribute('providerAccessToken');
            $refreshToken = $refreshToken ?? $identity->getAttribute('providerRefreshToken');
            $accessTokenExpiry = $accessTokenExpiry ?? $identity->getAttribute('providerAccessTokenExpiry');
        }

        $installation = $installation
            ->setAttribute('personalAccessToken', $accessToken)
            ->setAttribute('personalRefreshToken', $refreshToken)
            ->setAttribute('personalAccessTokenExpiry', $accessTokenExpiry);

        if (!$this->isExpired($accessTokenExpiry)) {
            return $installation;
        }

        if (empty($refreshToken)) {
            throw new Exception(Exception::GENERAL_PROVIDER_FAILURE, 'This installation has no refresh token on file. Please reconnect it.');
        }

        try {
            $oauth2->refreshTokens($refreshToken);
        } catch (\Throwable) {
            $current = $this->getCurrentInstallation($dbForPlatform, $installation);
            if (!$current->isEmpty() && !empty($current->getAttribute('personalAccessToken')) && !$this->isExpired($current->getAttribute('personalAccessTokenExpiry'))) {
                return $current;
            }

            throw new Exception(Exception::GENERAL_PROVIDER_FAILURE, 'Failed to refresh OAuth2 access token. Please reconnect the installation.');
        }

        $accessToken = $oauth2->getAccessToken('');
        $refreshToken = $oauth2->getRefreshToken('');
        $verificationId = $oauth2->getUserID($accessToken);

        if (empty($verificationId)) {
            $current = $this->getCurrentInstallation($dbForPlatform, $installation);
            if (!$current->isEmpty() && !empty($current->getAttribute('personalAccessToken')) && !$this->isExpired($current->getAttribute('personalAccessTokenExpiry'))) {
                return $current;
            }

            throw new Exception(Exception::GENERAL_PROVIDER_FAILURE, 'Failed to refresh OAuth2 access token. Please reconnect the installation.');
        }

        $installation = $installation
            ->setAttribute('personalAccessToken', $accessToken)
            ->setAttribute('personalRefreshToken', $refreshToken)
            ->setAttribute('personalAccessTokenExpiry', DateTime::addSeconds(new \DateTime(), (int)$oauth2->getAccessTokenExpiry('')));

        $dbForPlatform->updateDocument('installations', $installation->getId(), new Document([
            'personalAccessToken' => $installation->getAttribute('personalAccessToken'),
            'personalRefreshToken' => $installation->getAttribute('personalRefreshToken'),
            'personalAccessTokenExpiry' => $installation->getAttribute('personalAccessTokenExpiry'),
        ]));

        return $installation;
    }

    protected function isExpired(?string $expiry): bool
    {
        if (empty($expiry)) {
            return false;
        }

        try {
            return new \DateTime($expiry) < new \DateTime('now');
        } catch (\Throwable) {
            return false;
        }
    }

    protected function getCurrentInstallation(Database $dbForPlatform, Document $installation): Document
    {
        try {
            return $dbForPlatform->getDocument('installations', $installation->getId());
        } catch (\Throwable) {
            return new Document();
        }
    }
}
