<?php

namespace Appwrite\Vcs;

use Appwrite\Auth\OAuth2;
use Appwrite\Extend\Exception;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;

class InstallationTokens
{
    /**
     * Refreshes an installation's token, resolving the OAuth2 client for its provider.
     */
    public function refreshForInstallation(Document $installation, Database $dbForPlatform, Factory $vcsFactory): Document
    {
        $provider = $installation->getAttribute('provider', 'github');

        return $this->refresh($installation, $dbForPlatform, $vcsFactory->oauth2FromProvider($provider));
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

        // Providers rotate refresh tokens: two requests exchanging the same one revokes the family.
        // The comment lock collection is reused here, it holds nothing but ids, and the prefix
        // keeps these apart from the provider comment ids stored alongside them.
        $lock = 'installation-' . $installation->getId();
        $authorization = $dbForPlatform->getAuthorization();
        $acquired = false;
        $retries = 0;

        while ($retries < 9) {
            $retries++;

            try {
                $authorization->skip(fn () => $dbForPlatform->createDocument('vcsCommentLocks', new Document(['$id' => $lock])));
                $acquired = true;
                break;
            } catch (\Throwable $err) {
                if ($retries >= 9) {
                    Console::warning('Error creating vcs installation lock for ' . $installation->getId() . ': ' . $err->getMessage());
                }

                \sleep(1);
            }
        }

        if (!$acquired) {
            // The holder outlasted our wait. Reuse its token if it landed, never replay ours.
            $current = $this->getCurrentInstallation($dbForPlatform, $installation);
            if ($this->isUsable($current)) {
                return $current;
            }

            throw new Exception(Exception::GENERAL_RESOURCE_LOCKED);
        }

        try {
            // The lock holder may have refreshed already.
            $current = $this->getCurrentInstallation($dbForPlatform, $installation);

            if ($this->isUsable($current)) {
                return $current;
            }

            return $this->exchange($installation, $dbForPlatform, $oauth2);
        } finally {
            $authorization->skip(fn () => $dbForPlatform->deleteDocument('vcsCommentLocks', $lock));
        }
    }

    protected function exchange(Document $installation, Database $dbForPlatform, OAuth2 $oauth2): Document
    {
        try {
            $oauth2->refreshTokens($installation->getAttribute('personalRefreshToken'));
        } catch (\Throwable) {
            throw new Exception(Exception::GENERAL_PROVIDER_FAILURE, 'Failed to refresh OAuth2 access token. Please reconnect the installation.');
        }

        $accessToken = $oauth2->getAccessToken('');

        $installation = $installation
            ->setAttribute('personalAccessToken', $accessToken)
            ->setAttribute('personalRefreshToken', $oauth2->getRefreshToken(''))
            ->setAttribute('personalAccessTokenExpiry', DateTime::addSeconds(new \DateTime(), (int)$oauth2->getAccessTokenExpiry('')));

        // The provider has already rotated the family, so persist before anything else can fail.
        $dbForPlatform->updateDocument('installations', $installation->getId(), new Document([
            'personalAccessToken' => $installation->getAttribute('personalAccessToken'),
            'personalRefreshToken' => $installation->getAttribute('personalRefreshToken'),
            'personalAccessTokenExpiry' => $installation->getAttribute('personalAccessTokenExpiry'),
        ]));

        if (empty($oauth2->getUserID($accessToken))) {
            throw new Exception(Exception::GENERAL_PROVIDER_FAILURE, 'Failed to refresh OAuth2 access token. Please reconnect the installation.');
        }

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

    protected function isUsable(Document $installation): bool
    {
        return !$installation->isEmpty()
            && !empty($installation->getAttribute('personalAccessToken'))
            && !$this->isExpired($installation->getAttribute('personalAccessTokenExpiry'));
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
