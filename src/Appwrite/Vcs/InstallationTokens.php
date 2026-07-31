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
    private const LOCK_WAIT_SECONDS = 65; // > LOCK_TTL so waiters can steal

    private const LOCK_TTL_SECONDS = OAuth2::TIMEOUT * 4;

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

        if (! $this->isExpired($accessTokenExpiry) && ! empty($accessToken)) {
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

        $lock = 'installation-'.$installation->getId();
        // Random per-attempt id so a waiter can only ever delete the lock it created,
        // never one a different worker (original holder or another stealer) is using.
        $holderId = \bin2hex(\random_bytes(8));
        $authorization = $dbForPlatform->getAuthorization();
        $acquired = false;
        $deadline = \time() + $this->getLockWaitSeconds();
        $retries = 0;

        while (\time() < $deadline) {
            $retries++;

            try {
                $authorization->skip(fn () => $dbForPlatform->createDocument('vcsCommentLocks', new Document([
                    '$id' => $lock,
                    'holderId' => $holderId,
                ])));
                $acquired = true;
                break;
            } catch (\Throwable $err) {
                if ($fresh = $this->tryReturnFresh($dbForPlatform, $installation, $waitStartedAt)) {
                    return $fresh;
                }

                if ($this->stealLock($dbForPlatform, $authorization, $lock)) {
                    continue;
                }

                $this->sleepWithBackoff($retries);
            }
        }

        if (! $acquired) {
            if ($fresh = $this->tryReturnFresh($dbForPlatform, $installation, $waitStartedAt)) {
                return $fresh;
            }

            throw new Exception(Exception::GENERAL_RESOURCE_LOCKED);
        }

        try {
            if ($fresh = $this->tryReturnFresh($dbForPlatform, $installation, $waitStartedAt)) {
                return $fresh;
            }

            return $this->exchange($installation, $dbForPlatform, $oauth2);
        } finally {
            $this->releaseLock($dbForPlatform, $authorization, $lock, $holderId, $installation->getId());
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

        $this->clear($installation, $dbForPlatform, $tokens['error'] ?? '');

        $accessToken = $oauth2->getAccessToken('');
        $newRefreshToken = $oauth2->getRefreshToken('') ?: $installation->getAttribute('personalRefreshToken');
        $expirySeconds = (int) $oauth2->getAccessTokenExpiry('');

        if (empty($accessToken) || empty($newRefreshToken)) {
            throw new Exception(Exception::GENERAL_PROVIDER_FAILURE, 'Failed to refresh OAuth2 access token. Please reconnect the installation.');
        }

        // Retry once: a network blip on the identity check shouldn't discard an
        // otherwise good, already-rotated token. A genuinely empty/invalid response
        // on both attempts means the token itself is bad, not the network.
        $userId = $oauth2->getUserID($accessToken);
        if (empty($userId)) {
            $this->sleepWithBackoff(1);
            $userId = $oauth2->getUserID($accessToken);
        }

        if (empty($userId)) {
            throw new Exception(Exception::GENERAL_PROVIDER_FAILURE, 'Failed to refresh OAuth2 access token. Please reconnect the installation.');
        }

        return $dbForPlatform->updateDocument('installations', $installation->getId(), new Document([
            'personalAccessToken' => $accessToken,
            'personalRefreshToken' => $newRefreshToken,
            'personalAccessTokenExpiry' => DateTime::addSeconds(new \DateTime, $expirySeconds),
        ]));
    }

    /** Clear tokens only on explicit refusal (invalid_grant, bad_refresh_token). */
    protected function clear(Document $installation, Database $dbForPlatform, mixed $error): void
    {
        if (! \is_string($error) || ! \in_array($error, ['invalid_grant', 'bad_refresh_token'], true)) {
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
            return true;
        }
    }

    protected function isUsable(Document $installation): bool
    {
        return ! $installation->isEmpty()
            && ! empty($installation->getAttribute('personalAccessToken'))
            && ! $this->isExpired($installation->getAttribute('personalAccessTokenExpiry'));
    }

    protected function tryReturnFresh(Database $dbForPlatform, Document $installation, \DateTime $waitStartedAt): ?Document
    {
        try {
            $current = $dbForPlatform->getDocument('installations', $installation->getId());
        } catch (\Throwable) {
            return null;
        }

        if (! $this->isUsable($current)) {
            return null;
        }

        $updatedAt = $current->getAttribute('$updatedAt');
        if (empty($updatedAt)) {
            return null;
        }

        try {
            return new \DateTime($updatedAt) >= (clone $waitStartedAt)->modify('-2 seconds') ? $current : null;
        } catch (\Throwable) {
            return null;
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

    protected function stealLock(Database $dbForPlatform, mixed $authorization, string $lock): bool
    {
        try {
            $document = $authorization->skip(
                fn () => $dbForPlatform->getDocument('vcsCommentLocks', $lock)
            );

            if ($document->isEmpty()) {
                return true;
            }

            $lockedAt = $document->getAttribute('$createdAt');
            $expired = empty($lockedAt) || (\time() - \strtotime($lockedAt)) >= self::LOCK_TTL_SECONDS;

            if (! $expired) {
                return false;
            }

            $currentHolderId = $document->getAttribute('holderId');
            $reread = $authorization->skip(fn () => $dbForPlatform->getDocument('vcsCommentLocks', $lock));

            if ($reread->isEmpty() || $reread->getAttribute('holderId') !== $currentHolderId) {
                return false;
            }

            $this->deleteLockDocument($dbForPlatform, $authorization, $lock);
            $this->logWarning('Stole expired vcs installation lock: '.$lock);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Deletes only when the caller is the current owner, so a released or already-stolen
     * lock is never removed out from under whoever holds it now.
     */
    protected function releaseLock(Database $dbForPlatform, mixed $authorization, string $lock, string $holderId, string $installationId): void
    {
        try {
            $current = $authorization->skip(fn () => $dbForPlatform->getDocument('vcsCommentLocks', $lock));

            if ($current->isEmpty() || $current->getAttribute('holderId') !== $holderId) {
                // Someone else's lock now occupies this id (ours was stolen while we
                // worked, or already released) — deleting it would drop a lock we don't own.
                return;
            }

            $this->deleteLockDocument($dbForPlatform, $authorization, $lock);
        } catch (\Throwable $err) {
            $this->logWarning('Failed to release vcs installation lock for '.$installationId.': '.$err->getMessage());
        }
    }

    protected function logWarning(string $message): void
    {
        Console::warning($message);
    }

    protected function deleteLockDocument(Database $dbForPlatform, mixed $authorization, string $lock): void
    {
        $authorization->skip(fn () => $dbForPlatform->deleteDocument('vcsCommentLocks', $lock));
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
