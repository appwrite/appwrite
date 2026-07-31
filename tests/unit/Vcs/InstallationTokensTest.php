<?php

declare(strict_types=1);

namespace Tests\Unit\Vcs;

use Appwrite\Auth\OAuth2;
use Appwrite\Auth\OAuth2\Exception as OAuth2Exception;
use Appwrite\Extend\Exception;
use Appwrite\Vcs\InstallationTokens;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

final class InstallationTokensTest extends TestCase
{
    protected function db(): Database
    {
        $db = $this->createStub(Database::class);
        $db->method('getAuthorization')->willReturn(new Authorization);
        $db->method('getDocument')->willReturn(new Document);

        return $db;
    }

    public function test_unexpired_token_is_not_refreshed(): void
    {
        $installation = new Document([
            '$id' => 'installation1',
            'personalAccessToken' => 'valid-token',
            'personalRefreshToken' => 'valid-refresh',
            'personalAccessTokenExpiry' => DateTime::addSeconds(new \DateTime, 3600),
        ]);

        $oauth2 = $this->fakeOAuth2();

        $result = (new InstallationTokens)->refresh($installation, $this->db(), $oauth2);

        $this->assertSame('valid-token', $result->getAttribute('personalAccessToken'));
        $this->assertSame(0, $oauth2->refreshCalls);
    }

    public function test_missing_expiry_is_not_refreshed(): void
    {
        $installation = new Document([
            '$id' => 'installation1',
            'personalAccessToken' => 'valid-token',
            'personalRefreshToken' => 'valid-refresh',
            'personalAccessTokenExpiry' => null,
        ]);

        $oauth2 = $this->fakeOAuth2();

        $result = (new InstallationTokens)->refresh($installation, $this->db(), $oauth2);

        $this->assertSame('valid-token', $result->getAttribute('personalAccessToken'));
        $this->assertSame(0, $oauth2->refreshCalls);
    }

    public function test_invalid_expiry_forces_refresh(): void
    {
        $installation = new Document([
            '$id' => 'installation1',
            'personalAccessToken' => 'valid-token',
            'personalRefreshToken' => 'valid-refresh',
            'personalAccessTokenExpiry' => 'not-a-date',
        ]);

        $db = $this->createMock(Database::class);
        $db->method('getAuthorization')->willReturn(new Authorization);
        $db->method('getDocument')->willReturn(new Document);
        $db->expects($this->once())->method('updateDocument')->willReturnArgument(2);

        $oauth2 = $this->fakeOAuth2();

        $result = (new InstallationTokens)->refresh($installation, $db, $oauth2);

        $this->assertSame('fresh-token', $result->getAttribute('personalAccessToken'));
        $this->assertSame(1, $oauth2->refreshCalls);
    }

    public function test_cleared_installation_throws_without_calling_the_provider(): void
    {
        // The state clear() leaves behind. GitHub works from here on its app credentials, and the
        // providers that need the token fail at the adapter with a definite reason.
        $installation = new Document([
            '$id' => 'installation1',
            'personalAccessToken' => '',
            'personalRefreshToken' => '',
            'personalAccessTokenExpiry' => null,
        ]);

        $db = $this->createMock(Database::class);
        $db->method('getAuthorization')->willReturn(new Authorization);
        $db->expects($this->never())->method('updateDocument');
        $db->expects($this->never())->method('createDocument');

        $oauth2 = $this->fakeOAuth2();

        try {
            (new InstallationTokens)->refresh($installation, $db, $oauth2);
            $this->fail('Expected Exception');
        } catch (Exception $e) {
            $this->assertSame(Exception::GENERAL_PROVIDER_FAILURE, $e->getType());
        }
    }

    public function test_expired_token_is_refreshed_and_persisted(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('getAuthorization')->willReturn(new Authorization);
        $db->method('getDocument')->willReturn(new Document);

        $db->expects($this->once())
            ->method('updateDocument')
            ->with('installations', 'installation1', $this->callback(function (Document $update) {
                $this->assertSame('fresh-token', $update->getAttribute('personalAccessToken'));
                $this->assertSame('fresh-refresh', $update->getAttribute('personalRefreshToken'));

                return true;
            }))
            ->willReturnArgument(2);

        $oauth2 = $this->fakeOAuth2();

        $result = (new InstallationTokens)->refresh($this->expired(), $db, $oauth2);

        $this->assertSame('fresh-token', $result->getAttribute('personalAccessToken'));
        $this->assertSame(1, $oauth2->refreshCalls);
    }

    public function test_falls_back_to_identity_when_installation_has_no_tokens(): void
    {
        $installation = new Document(['$id' => 'installation1']);
        $identity = new Document([
            'providerAccessToken' => 'identity-token',
            'providerRefreshToken' => 'identity-refresh',
            'providerAccessTokenExpiry' => DateTime::addSeconds(new \DateTime, 3600),
        ]);

        $oauth2 = $this->fakeOAuth2();

        $result = (new InstallationTokens)->refresh($installation, $this->db(), $oauth2, $identity);

        $this->assertSame('identity-token', $result->getAttribute('personalAccessToken'));
        $this->assertSame('identity-refresh', $result->getAttribute('personalRefreshToken'));
        $this->assertSame($identity->getAttribute('providerAccessTokenExpiry'), $result->getAttribute('personalAccessTokenExpiry'));
    }

    public function test_missing_refresh_token_throws_clear_error(): void
    {
        $installation = new Document([
            '$id' => 'installation1',
            'personalAccessToken' => 'stale-token',
            'personalRefreshToken' => null,
            'personalAccessTokenExpiry' => DateTime::addSeconds(new \DateTime, -3600),
        ]);

        $oauth2 = $this->fakeOAuth2();

        try {
            (new InstallationTokens)->refresh($installation, $this->db(), $oauth2);
            $this->fail('Expected an Exception');
        } catch (Exception $e) {
            $this->assertSame(Exception::GENERAL_PROVIDER_FAILURE, $e->getType());
        }

        $this->assertSame(0, $oauth2->refreshCalls);
    }

    public function test_failed_refresh_throws(): void
    {
        $oauth2 = $this->fakeOAuth2(refresh: 'throw');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to refresh OAuth2 access token');
        (new InstallationTokens)->refresh($this->expired(), $this->db(), $oauth2);
    }

    public function test_rotated_tokens_persisted_even_if_identity_check_flakes(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('getAuthorization')->willReturn(new Authorization);
        $db->method('getDocument')->willReturn(new Document);

        // Rotated tokens MUST be persisted immediately so the DB never holds an invalidated refresh token.
        $db->expects($this->once())
            ->method('updateDocument')
            ->with('installations', 'installation1', $this->callback(function (Document $update) {
                $this->assertSame('fresh-token', $update->getAttribute('personalAccessToken'));
                $this->assertSame('fresh-refresh', $update->getAttribute('personalRefreshToken'));

                return true;
            }))
            ->willReturnArgument(2);

        $oauth2 = $this->fakeOAuth2(emptyUserId: true);

        $result = (new InstallationTokens)->refresh($this->expired(), $db, $oauth2);

        $this->assertSame('fresh-token', $result->getAttribute('personalAccessToken'));
        $this->assertSame('fresh-refresh', $result->getAttribute('personalRefreshToken'));
    }

    public function test_transient_user_id_failure_retries_and_succeeds(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('getAuthorization')->willReturn(new Authorization);
        $db->method('getDocument')->willReturn(new Document);

        $db->expects($this->once())
            ->method('updateDocument')
            ->with('installations', 'installation1', $this->callback(function (Document $update) {
                $this->assertSame('fresh-token', $update->getAttribute('personalAccessToken'));

                return true;
            }))
            ->willReturnArgument(2);

        $userIdCalls = 0;
        $oauth2 = new class(false, 'ok', $userIdCalls) extends OAuth2
        {
            public int $refreshCalls = 0;

            protected array $tokens = [];

            public function __construct(protected bool $emptyUserId, protected string $refresh, public int &$userIdCalls)
            {
                parent::__construct('id', 'secret', '');
            }

            public function getName(): string
            {
                return 'fake';
            }

            public function getLoginURL(): string
            {
                return '';
            }

            protected function getTokens(string $code): array
            {
                return $this->tokens;
            }

            public function refreshTokens(string $refreshToken): array
            {
                $this->refreshCalls++;
                $this->tokens = ['access_token' => 'fresh-token', 'refresh_token' => 'fresh-refresh', 'expires_in' => 3600];

                return $this->tokens;
            }

            public function getUserID(string $accessToken): string
            {
                $this->userIdCalls++;

                return $this->userIdCalls === 1 ? '' : 'user1';
            }

            public function getUserEmail(string $accessToken): string
            {
                return '';
            }

            public function isEmailVerified(string $accessToken): bool
            {
                return true;
            }

            public function getUserName(string $accessToken): string
            {
                return '';
            }
        };

        $result = (new InstallationTokens)->refresh($this->expired(), $db, $oauth2);

        $this->assertSame('fresh-token', $result->getAttribute('personalAccessToken'));
        $this->assertSame(1, $oauth2->refreshCalls);
        $this->assertSame(2, $userIdCalls);
    }

    public function test_refused_refresh_token_is_cleared(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('getAuthorization')->willReturn(new Authorization);
        $db->method('getDocument')->willReturn(new Document);

        $db->expects($this->once())
            ->method('updateDocument')
            ->with('installations', 'installation1', $this->callback(function (Document $update) {
                $this->assertSame('', $update->getAttribute('personalAccessToken'));
                $this->assertSame('', $update->getAttribute('personalRefreshToken'));
                $this->assertNull($update->getAttribute('personalAccessTokenExpiry'));

                return true;
            }))
            ->willReturnArgument(2);

        $oauth2 = $this->fakeOAuth2(refresh: 'refused');

        $this->expectException(Exception::class);
        (new InstallationTokens)->refresh($this->expired(), $db, $oauth2);
    }

    public function test_invalid_grant_is_cleared(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('getAuthorization')->willReturn(new Authorization);
        $db->method('getDocument')->willReturn(new Document);

        $db->expects($this->once())
            ->method('updateDocument')
            ->with('installations', 'installation1', $this->callback(function (Document $update) {
                $this->assertSame('', $update->getAttribute('personalRefreshToken'));

                return true;
            }))
            ->willReturnArgument(2);

        $oauth2 = $this->fakeOAuth2(refresh: 'invalidGrant');

        try {
            (new InstallationTokens)->refresh($this->expired(), $db, $oauth2);
            $this->fail('Expected Exception');
        } catch (Exception $e) {
            $this->assertSame(Exception::GENERAL_PROVIDER_FAILURE, $e->getType());
        }
    }

    public function test_empty_token_response_is_not_persisted(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('getAuthorization')->willReturn(new Authorization);
        $db->method('getDocument')->willReturn(new Document);

        $db->expects($this->never())->method('updateDocument');

        $oauth2 = $this->fakeOAuth2(refresh: 'empty');

        try {
            (new InstallationTokens)->refresh($this->expired(), $db, $oauth2);
            $this->fail('Expected Exception');
        } catch (Exception $e) {
            $this->assertSame(Exception::GENERAL_PROVIDER_FAILURE, $e->getType());
        }
    }

    public function test_failed_refresh_call_is_not_persisted(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('getAuthorization')->willReturn(new Authorization);
        $db->method('getDocument')->willReturn(new Document);

        $db->expects($this->never())->method('updateDocument');

        $oauth2 = $this->fakeOAuth2(refresh: 'throw');

        try {
            (new InstallationTokens)->refresh($this->expired(), $db, $oauth2);
            $this->fail('Expected Exception');
        } catch (Exception $e) {
            $this->assertSame(Exception::GENERAL_PROVIDER_FAILURE, $e->getType());
        }
    }

    public function test_token_refreshed_by_lock_holder_is_reused(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('getAuthorization')->willReturn(new Authorization);

        $db->expects($this->exactly(2))
            ->method('getDocument')
            ->willReturnMap([
                ['installations', 'installation1', new Document([
                    '$id' => 'installation1',
                    'personalAccessToken' => 'already-refreshed-token',
                    'personalRefreshToken' => 'already-refreshed-refresh',
                    'personalAccessTokenExpiry' => DateTime::addSeconds(new \DateTime, 3600),
                    '$updatedAt' => DateTime::now(),
                ])],
                ['vcsCommentLocks', 'installation-installation1', new Document],
            ]);
        $db->expects($this->never())->method('updateDocument');

        $oauth2 = $this->fakeOAuth2();

        $result = (new InstallationTokens)->refresh($this->expired(), $db, $oauth2);

        $this->assertSame('already-refreshed-token', $result->getAttribute('personalAccessToken'));
        $this->assertSame(0, $oauth2->refreshCalls);
    }

    public function test_refresh_is_serialized_by_lock(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('getAuthorization')->willReturn(new Authorization);
        $db->method('updateDocument')->willReturnArgument(2);

        $createdLock = null;
        $db->expects($this->once())
            ->method('createDocument')
            ->with('vcsCommentLocks', $this->callback(function (Document $lock) use (&$createdLock) {
                $this->assertSame('installation-installation1', $lock->getId());
                $this->assertNotEmpty($lock->getAttribute('holderId'));
                $createdLock = $lock;

                return true;
            }))
            ->willReturnArgument(1);

        $db->method('getDocument')->willReturnCallback(function ($collection, $id) use (&$createdLock) {
            if ($collection === 'vcsCommentLocks') {
                return $createdLock ?? new Document;
            }

            return new Document;
        });

        $db->expects($this->once())
            ->method('deleteDocument')
            ->with('vcsCommentLocks', 'installation-installation1')
            ->willReturn(true);

        $oauth2 = $this->fakeOAuth2();

        (new InstallationTokens)->refresh($this->expired(), $db, $oauth2);

        $this->assertSame(1, $oauth2->refreshCalls);
    }

    public function test_lock_is_released_when_refresh_fails(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('getAuthorization')->willReturn(new Authorization);

        $createdLock = null;
        $db->method('createDocument')->willReturnCallback(function ($collection, $doc) use (&$createdLock) {
            $createdLock = $doc;

            return $doc;
        });

        $db->method('getDocument')->willReturnCallback(function ($collection, $id) use (&$createdLock) {
            if ($collection === 'vcsCommentLocks') {
                return $createdLock ?? new Document;
            }

            return new Document;
        });

        $db->expects($this->once())
            ->method('deleteDocument')
            ->with('vcsCommentLocks', 'installation-installation1')
            ->willReturn(true);

        $oauth2 = $this->fakeOAuth2(refresh: 'throw');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to refresh OAuth2 access token');
        (new InstallationTokens)->refresh($this->expired(), $db, $oauth2);
    }

    public function test_stolen_lock_is_not_deleted_by_original_holder(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('getAuthorization')->willReturn(new Authorization);
        $db->method('updateDocument')->willReturnArgument(2);
        $db->method('createDocument')->willReturnArgument(1);

        // Simulate lock being stolen by another worker while we worked (different holderId)
        $stolenLock = new Document([
            '$id' => 'installation-installation1',
            'holderId' => 'different-worker-holder-id',
        ]);

        $db->method('getDocument')->willReturnMap([
            ['vcsCommentLocks', 'installation-installation1', $stolenLock],
            ['installations', 'installation1', new Document],
        ]);

        // Guard against releasing a lock that no longer belongs to this worker
        $db->expects($this->never())->method('deleteDocument');

        $oauth2 = $this->fakeOAuth2();

        (new InstallationTokens)->refresh($this->expired(), $db, $oauth2);

        $this->assertSame(1, $oauth2->refreshCalls);
    }

    public function test_stale_lock_steal_is_aborted_if_already_replaced(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('getAuthorization')->willReturn(new Authorization);

        $staleLockedAt = DateTime::addSeconds(new \DateTime, -80);
        $initialStaleLock = new Document([
            '$id' => 'installation-installation1',
            'holderId' => 'original-holder-id',
            '$createdAt' => $staleLockedAt,
        ]);

        $replacedLock = new Document([
            '$id' => 'installation-installation1',
            'holderId' => 'newer-stealer-holder-id',
            '$createdAt' => DateTime::now(),
        ]);

        $calls = 0;
        $db->method('getDocument')->willReturnCallback(function ($collection, $id) use (&$calls, $initialStaleLock, $replacedLock) {
            if ($collection === 'vcsCommentLocks') {
                $calls++;

                return $calls === 1 ? $initialStaleLock : $replacedLock;
            }

            return new Document;
        });

        // Ensure delete is aborted when re-read shows a different holderId
        $db->expects($this->never())->method('deleteDocument');

        $tokensService = new class extends InstallationTokens
        {
            public function triggerStealLock(Database $db, mixed $auth, string $lock): bool
            {
                return $this->stealLock($db, $auth, $lock);
            }
        };

        $result = $tokensService->triggerStealLock($db, $db->getAuthorization(), 'installation-installation1');

        $this->assertFalse($result);
    }

    public function test_stale_lock_is_stolen_and_refreshed(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('getAuthorization')->willReturn(new Authorization);

        $createdLock = null;
        $calls = 0;
        $db->method('createDocument')->willReturnCallback(function ($collection, $doc) use (&$calls, &$createdLock) {
            $calls++;
            if ($calls === 1) {
                throw new \RuntimeException('Lock collision');
            }
            $createdLock = $doc;

            return $doc;
        });

        $staleLockedAt = DateTime::addSeconds(new \DateTime, -80);
        $staleLockDoc = new Document([
            '$id' => 'installation-installation1',
            '$createdAt' => $staleLockedAt,
        ]);

        $db->method('getDocument')->willReturnCallback(function ($collection, $id) use (&$createdLock, $staleLockDoc) {
            if ($collection === 'vcsCommentLocks') {
                return $createdLock ?? $staleLockDoc;
            }

            return new Document;
        });
        $db->method('updateDocument')->willReturnCallback(function ($collection, $id, $doc) use (&$createdLock, $staleLockDoc) {
            $target = $createdLock ?? $staleLockDoc;
            foreach ($doc->getArrayCopy() as $k => $v) {
                $target->setAttribute($k, $v);
            }

            return $target;
        });

        $db->expects($this->exactly(2))
            ->method('deleteDocument')
            ->with('vcsCommentLocks', 'installation-installation1')
            ->willReturn(true);

        $oauth2 = $this->fakeOAuth2();

        $result = $this->silentInstallationTokens()->refresh($this->expired(), $db, $oauth2);

        $this->assertSame('fresh-token', $result->getAttribute('personalAccessToken'));
        $this->assertSame(1, $oauth2->refreshCalls);
    }

    public function test_malformed_lock_timestamp_triggers_strotime_self_healing(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('getAuthorization')->willReturn(new Authorization);

        $createdLock = null;
        $calls = 0;
        $db->method('createDocument')->willReturnCallback(function ($collection, $doc) use (&$calls, &$createdLock) {
            $calls++;
            if ($calls === 1) {
                throw new \RuntimeException('Lock collision');
            }
            $createdLock = $doc;

            return $doc;
        });

        $malformedLockDoc = new Document([
            '$id' => 'installation-installation1',
            '$createdAt' => 'invalid-timestamp-string',
        ]);

        $db->method('getDocument')->willReturnCallback(function ($collection, $id) use (&$createdLock, $malformedLockDoc) {
            if ($collection === 'vcsCommentLocks') {
                return $createdLock ?? $malformedLockDoc;
            }

            return new Document;
        });
        $db->method('updateDocument')->willReturnCallback(function ($collection, $id, $doc) use (&$createdLock, $malformedLockDoc) {
            $target = $createdLock ?? $malformedLockDoc;
            foreach ($doc->getArrayCopy() as $k => $v) {
                $target->setAttribute($k, $v);
            }

            return $target;
        });

        $db->expects($this->exactly(2))
            ->method('deleteDocument')
            ->with('vcsCommentLocks', 'installation-installation1')
            ->willReturn(true);

        $oauth2 = $this->fakeOAuth2();

        $result = $this->silentInstallationTokens()->refresh($this->expired(), $db, $oauth2);

        $this->assertSame('fresh-token', $result->getAttribute('personalAccessToken'));
        $this->assertSame(1, $oauth2->refreshCalls);
    }

    public function test_missing_lock_timestamp_triggers_steal(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('getAuthorization')->willReturn(new Authorization);

        $createdLock = null;
        $calls = 0;
        $db->method('createDocument')->willReturnCallback(function ($collection, $doc) use (&$calls, &$createdLock) {
            $calls++;
            if ($calls === 1) {
                throw new \RuntimeException('Lock collision');
            }
            $createdLock = $doc;

            return $doc;
        });

        $missingLockDoc = new Document([
            '$id' => 'installation-installation1',
            '$createdAt' => null,
        ]);

        $db->method('getDocument')->willReturnCallback(function ($collection, $id) use (&$createdLock, $missingLockDoc) {
            if ($collection === 'vcsCommentLocks') {
                return $createdLock ?? $missingLockDoc;
            }

            return new Document;
        });
        $db->method('updateDocument')->willReturnCallback(function ($collection, $id, $doc) use (&$createdLock, $missingLockDoc) {
            $target = $createdLock ?? $missingLockDoc;
            foreach ($doc->getArrayCopy() as $k => $v) {
                $target->setAttribute($k, $v);
            }

            return $target;
        });

        $db->expects($this->exactly(2))
            ->method('deleteDocument')
            ->with('vcsCommentLocks', 'installation-installation1')
            ->willReturn(true);

        $oauth2 = $this->fakeOAuth2();

        $result = $this->silentInstallationTokens()->refresh($this->expired(), $db, $oauth2);

        $this->assertSame('fresh-token', $result->getAttribute('personalAccessToken'));
        $this->assertSame(1, $oauth2->refreshCalls);
    }

    public function test_lock_wait_timeout_throws_resource_locked(): void
    {
        $db = $this->createStub(Database::class);
        $db->method('getAuthorization')->willReturn(new Authorization);

        $db->method('createDocument')->willThrowException(new \RuntimeException('Lock collision'));

        $activeLockDoc = new Document([
            '$id' => 'installation-installation1',
            'holderId' => 'some-other-worker',
            '$createdAt' => DateTime::addSeconds(new \DateTime, -5),
        ]);

        $db->method('getDocument')->willReturnCallback(function ($collection, $id) use ($activeLockDoc) {
            if ($collection === 'vcsCommentLocks') {
                return $activeLockDoc;
            }

            return new Document;
        });

        $oauth2 = $this->fakeOAuth2();

        $tokensService = new class extends InstallationTokens
        {
            protected function getLockWaitSeconds(): int
            {
                return 0;
            }

            protected function sleepWithBackoff(int $retries): void {}

            protected function logWarning(string $message): void {}
        };

        try {
            $tokensService->refresh($this->expired(), $db, $oauth2);
            $this->fail('Expected Exception::GENERAL_RESOURCE_LOCKED');
        } catch (Exception $e) {
            $this->assertSame(Exception::GENERAL_RESOURCE_LOCKED, $e->getType());
        }
    }

    public function test_sleep_with_backoff_calculates_exponential_delay_with_jitter(): void
    {
        $tokensService = new class extends InstallationTokens
        {
            public array $recordedSeconds = [];

            public function sleepWithBackoff(int $retries): void
            {
                $base = \min(0.2 * (2 ** \max(0, $retries - 1)), 1.5);
                $jitter = \mt_rand(0, 100) / 1000;
                $seconds = $base + $jitter;
                $this->recordedSeconds[] = $seconds;
            }
        };

        $tokensService->sleepWithBackoff(1);
        $tokensService->sleepWithBackoff(2);
        $tokensService->sleepWithBackoff(5);

        $recordedSeconds = $tokensService->recordedSeconds;

        $this->assertGreaterThanOrEqual(0.2, $recordedSeconds[0]);
        $this->assertLessThanOrEqual(0.3, $recordedSeconds[0]);

        $this->assertGreaterThanOrEqual(0.4, $recordedSeconds[1]);
        $this->assertLessThanOrEqual(0.5, $recordedSeconds[1]);

        $this->assertGreaterThanOrEqual(1.5, $recordedSeconds[2]);
        $this->assertLessThanOrEqual(1.6, $recordedSeconds[2]);
    }

    protected function expired(): Document
    {
        return new Document([
            '$id' => 'installation1',
            'personalAccessToken' => 'stale-token',
            'personalRefreshToken' => 'stale-refresh',
            'personalAccessTokenExpiry' => DateTime::addSeconds(new \DateTime, -3600),
        ]);
    }

    /**
     * @param  string  $refresh  One of: ok, refused, invalidGrant, empty, throw.
     */
    protected function fakeOAuth2(bool $emptyUserId = false, string $refresh = 'ok')
    {
        return new class($emptyUserId, $refresh) extends OAuth2
        {
            public int $refreshCalls = 0;

            protected array $tokens = [];

            public function __construct(protected bool $emptyUserId, protected string $refresh)
            {
                parent::__construct('id', 'secret', '');
            }

            public function getName(): string
            {
                return 'fake';
            }

            public function getLoginURL(): string
            {
                return '';
            }

            protected function getTokens(string $code): array
            {
                return $this->tokens;
            }

            public function refreshTokens(string $refreshToken): array
            {
                $this->refreshCalls++;

                // GitHub states a refused token in a 200 body; GitLab and Gitea answer 400.
                // A request that never completed states nothing at all.
                $this->tokens = match ($this->refresh) {
                    'refused' => ['error' => 'bad_refresh_token'],
                    'invalidGrant' => throw new OAuth2Exception('{"error":"invalid_grant"}', 400),
                    'empty' => [],
                    'throw' => throw new \RuntimeException('connection timed out'),
                    default => [
                        'access_token' => 'fresh-token',
                        'refresh_token' => 'fresh-refresh',
                        'expires_in' => 3600,
                    ],
                };

                return $this->tokens;
            }

            public function getUserID(string $accessToken): string
            {
                return $this->emptyUserId ? '' : 'user1';
            }

            public function getUserEmail(string $accessToken): string
            {
                return '';
            }

            public function isEmailVerified(string $accessToken): bool
            {
                return true;
            }

            public function getUserName(string $accessToken): string
            {
                return '';
            }
        };
    }

    protected function silentInstallationTokens(): InstallationTokens
    {
        return new class extends InstallationTokens
        {
            protected function logWarning(string $message): void {}
        };
    }
}
