<?php

namespace Appwrite\Vcs;

use Appwrite\Auth\OAuth2;
use Appwrite\Auth\OAuth2\Exception as OAuth2Exception;
use Appwrite\Extend\Exception;
use Swoole\Coroutine;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;

class InstallationTokens
{
    /** WAIT (40s) must exceed TTL so a waiter can detect and steal an abandoned lock. */
    private const LOCK_WAIT_SECONDS = 40;

    /** TTL is structurally derived as 2x the OAuth2 HTTP timeout (15s). */
    private const LOCK_TTL_SECONDS = OAuth2::TIMEOUT * 2;

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
        // The comment lock collection is reused for simplicity: it holds nothing but ids, and the
        // prefix keeps these apart from the provider comment ids stored alongside them. Ideally
        // this moves to a generic lock collection.
        $waitStartedAt = new \DateTime('now');

        $lock = 'installation-' . $installation->getId();
        $authorization = $dbForPlatform->getAuthorization();
        $acquired = false;
        $deadline = \time() + $this->getLockWaitSeconds();
        $retries = 0;

        while (\time() < $deadline) {
            $retries++;

            try {
                $authorization->skip(fn () => $dbForPlatform->createDocument('vcsCommentLocks', new Document(['$id' => $lock, 'lockedAt' => DateTime::now()])));
                $acquired = true;
                break;
            } catch (\Throwable $err) {
                if ($fresh = $this->tryReturnFresh($dbForPlatform, $installation, $waitStartedAt)) {
                    return $fresh;
                }

                if ($this->tryStealExpiredLock($dbForPlatform, $authorization, $lock)) {
                    continue;
                }

                $this->sleepWithBackoff($retries);
            }
        }

        if (!$acquired) {
            // The holder outlasted our wait. Reuse its token if it landed, never replay ours.
            if ($fresh = $this->tryReturnFresh($dbForPlatform, $installation, $waitStartedAt)) {
                return $fresh;
            }

            throw new Exception(Exception::GENERAL_RESOURCE_LOCKED);
        }

        try {
            // The lock holder may have refreshed already.
            if ($fresh = $this->tryReturnFresh($dbForPlatform, $installation, $waitStartedAt)) {
                return $fresh;
            }

            return $this->exchange($installation, $dbForPlatform, $oauth2);
        } finally {
            try {
                $authorization->skip(fn () => $dbForPlatform->deleteDocument('vcsCommentLocks', $lock));
            } catch (\Throwable $err) {
                Console::warning('Failed to release vcs installation lock for ' . $installation->getId() . ': ' . $err->getMessage());
            }
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
        $newRefreshToken = $oauth2->getRefreshToken('') ?: $installation->getAttribute('personalRefreshToken');
        $expirySeconds = (int) $oauth2->getAccessTokenExpiry('');

        if (empty($accessToken) || empty($newRefreshToken)) {
            throw new Exception(Exception::GENERAL_PROVIDER_FAILURE, 'Failed to refresh OAuth2 access token. Please reconnect the installation.');
        }

        if (empty($oauth2->getUserID($accessToken))) {
            throw new Exception(Exception::GENERAL_PROVIDER_FAILURE, 'Failed to refresh OAuth2 access token. Please reconnect the installation.');
        }

        // Persist new token pair once access token and user identity are verified.
        return $dbForPlatform->updateDocument('installations', $installation->getId(), new Document([
            'personalAccessToken' => $accessToken,
            'personalRefreshToken' => $newRefreshToken,
            'personalAccessTokenExpiry' => DateTime::addSeconds(new \DateTime(), $expirySeconds),
        ]));
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

    protected function isUsableAndFresh(Document $installation, \DateTime $waitStartedAt): bool
    {
        if (!$this->isUsable($installation)) {
            return false;
        }

        $updatedAt = $installation->getAttribute('$updatedAt');
        if (empty($updatedAt)) {
            return false;
        }

        try {
            $toleranceWindow = (clone $waitStartedAt)->modify('-2 seconds');

            return new \DateTime($updatedAt) >= $toleranceWindow;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function tryReturnFresh(Database $dbForPlatform, Document $installation, \DateTime $waitStartedAt): ?Document
    {
        $current = $this->getCurrentInstallation($dbForPlatform, $installation);

        return $this->isUsableAndFresh($current, $waitStartedAt) ? $current : null;
    }

    protected function getCurrentInstallation(Database $dbForPlatform, Document $installation): Document
    {
        try {
            return $dbForPlatform->getDocument('installations', $installation->getId());
        } catch (\Throwable) {
            return new Document();
        }
    }

    protected function tryStealExpiredLock(Database $dbForPlatform, mixed $authorization, string $lock): bool
    {
        try {
            $document = $authorization->skip(
                fn () => $dbForPlatform->getDocument('vcsCommentLocks', $lock)
            );

            if ($document->isEmpty()) {
                return true;
            }

            $lockedAt = $document->getAttribute('lockedAt');
            if (empty($lockedAt)) {
                $authorization->skip(fn () => $dbForPlatform->deleteDocument('vcsCommentLocks', $lock));
                Console::warning('Stole vcs installation lock with missing timestamp: ' . $lock);

                return true;
            }

            $age = \time() - \strtotime($lockedAt);
            if ($age < self::LOCK_TTL_SECONDS) {
                return false;
            }

            $authorization->skip(fn () => $dbForPlatform->deleteDocument('vcsCommentLocks', $lock));
            Console::warning('Stole expired vcs installation lock: ' . $lock);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function sleepWithBackoff(int $retries): void
    {
        $base = \min(0.2 * (2 ** \max(0, $retries - 1)), 1.5);
        $jitter = \mt_rand(0, 100) / 1000;
        $seconds = $base + $jitter;

        if (\class_exists(Coroutine::class) && Coroutine::getCid() > 0) {
            Coroutine::sleep($seconds);
        } else {
            \usleep((int) ($seconds * 1_000_000));
        }
    }

    protected function getLockWaitSeconds(): int
    {
        return self::LOCK_WAIT_SECONDS;
    }
}
