<?php

namespace Appwrite\Vcs;

use Appwrite\Auth\OAuth2;
use Appwrite\Extend\Exception;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;

// Owns the one database write OAuth2 token refresh needs, so Factory stays
// free of persistence.
class InstallationTokens
{
    // Falls back to $identity's tokens when the installation has none yet --
    // the first repository action after connecting a personal installation.
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

        // A missing expiry means the provider issued a non-expiring token
        // (or none was recorded yet) -- treat it as not expired rather than
        // forcing a refresh on every call.
        $isExpired = !empty($accessTokenExpiry) && new \DateTime($accessTokenExpiry) < new \DateTime('now');

        if (!$isExpired) {
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
