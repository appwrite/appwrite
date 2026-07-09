<?php

namespace Appwrite\Vcs;

use Appwrite\Auth\OAuth2;
use Appwrite\Extend\Exception;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;

/**
 * Refreshes and persists an installation's personal OAuth2 tokens.
 *
 * Only relevant for personal (OAuth2-authenticated) installations. Owns the
 * one database write this concern needs, so adapter construction (Factory)
 * stays free of persistence.
 */
class InstallationTokens
{
    /**
     * Returns the installation with a valid, unexpired access token attached.
     * Falls back to $identity's tokens when the installation has none yet
     * (first repository action after connecting a personal installation).
     * Refreshes and persists to `installations` only when the token is
     * actually expired.
     */
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

        $installation = $installation->setAttribute('personalAccessToken', $accessToken);

        if (!empty($accessTokenExpiry) && new \DateTime($accessTokenExpiry) >= new \DateTime('now')) {
            return $installation;
        }

        $oauth2->refreshTokens($refreshToken);

        $accessToken = $oauth2->getAccessToken('');
        $refreshToken = $oauth2->getRefreshToken('');
        $verificationId = $oauth2->getUserID($accessToken);

        if (empty($verificationId)) {
            throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'Another request is currently refreshing OAuth token. Please try again.');
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
}
