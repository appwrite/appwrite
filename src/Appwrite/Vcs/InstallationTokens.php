<?php

namespace Appwrite\Vcs;

use Appwrite\Auth\OAuth2;
use Appwrite\Auth\OAuth2\Exception as OAuth2Exception;
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
        // Only personal installations carry OAuth tokens to refresh. An app
        // installation (a GitHub App, an Origin app) authenticates per request
        // with an app-signed JWT and may have no OAuth2 client to resolve at all.
        if (!$installation->getAttribute('personal', false)) {
            return $installation;
        }

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
        // The comment lock collection is reused for simplicity: it holds nothing but ids, and the
        // prefix keeps these apart from the provider comment ids stored alongside them. Ideally
        // this moves to a generic lock collection.
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
            $tokens = $oauth2->refreshTokens($installation->getAttribute('personalRefreshToken'));
        } catch (OAuth2Exception $err) {
            $this->clear($installation, $dbForPlatform, $err->getError());

            throw new Exception(Exception::GENERAL_PROVIDER_FAILURE, 'Failed to refresh OAuth2 access token. Please reconnect the installation.');
        } catch (\Throwable) {
            throw new Exception(Exception::GENERAL_PROVIDER_FAILURE, 'Failed to refresh OAuth2 access token. Please reconnect the installation.');
        }

        // GitHub answers a refused token with a 200 and an error body rather than a 4xx.
        $this->clear($installation, $dbForPlatform, $tokens['error'] ?? '');

        $accessToken = $oauth2->getAccessToken('');

        // No token and no stated reason, so the provider may simply not have answered. Persisting
        // an empty token here would replace a pair that is possibly still good.
        if (empty($accessToken)) {
            throw new Exception(Exception::GENERAL_PROVIDER_FAILURE, 'Failed to refresh OAuth2 access token. Please reconnect the installation.');
        }

        // The provider has already rotated the family, so persist before anything else can fail.
        $installation = $dbForPlatform->updateDocument('installations', $installation->getId(), new Document([
            'personalAccessToken' => $accessToken,
            'personalRefreshToken' => $oauth2->getRefreshToken(''),
            'personalAccessTokenExpiry' => DateTime::addSeconds(new \DateTime(), (int)$oauth2->getAccessTokenExpiry('')),
        ]));

        if (empty($oauth2->getUserID($accessToken))) {
            throw new Exception(Exception::GENERAL_PROVIDER_FAILURE, 'Failed to refresh OAuth2 access token. Please reconnect the installation.');
        }

        return $installation;
    }

    /**
     * Drops the stored pair when the provider states it refused the token, so later calls stop
     * replaying it. Only an explicit refusal counts: a timeout or a 5xx may pass, and clearing on
     * those would force a reconnect that was never needed.
     */
    protected function clear(Document $installation, Database $dbForPlatform, mixed $error): void
    {
        if (!\is_string($error) || !\in_array($error, ['invalid_grant', 'bad_refresh_token'], true)) {
            return;
        }

        $dbForPlatform->updateDocument('installations', $installation->getId(), new Document([
            'personalAccessToken' => '',
            'personalRefreshToken' => '',
            'personalAccessTokenExpiry' => null,
        ]));
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
