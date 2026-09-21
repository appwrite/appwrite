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
        $db->method('getAuthorization')->willReturn(new Authorization());
        $db->method('getDocument')->willReturn(new Document());

        return $db;
    }

    public function testUnexpiredTokenIsNotRefreshed(): void
    {
        $installation = new Document([
            '$id' => 'installation1',
            'personalAccessToken' => 'valid-token',
            'personalRefreshToken' => 'valid-refresh',
            'personalAccessTokenExpiry' => DateTime::addSeconds(new \DateTime(), 3600),
        ]);

        $oauth2 = $this->fakeOAuth2();

        $result = (new InstallationTokens())->refresh($installation, $this->db(), $oauth2);

        $this->assertSame('valid-token', $result->getAttribute('personalAccessToken'));
        $this->assertSame(0, $oauth2->refreshCalls);
    }

    public function testMissingExpiryIsNotRefreshed(): void
    {
        $installation = new Document([
            '$id' => 'installation1',
            'personalAccessToken' => 'valid-token',
            'personalRefreshToken' => 'valid-refresh',
            'personalAccessTokenExpiry' => null,
        ]);

        $oauth2 = $this->fakeOAuth2();

        $result = (new InstallationTokens())->refresh($installation, $this->db(), $oauth2);

        $this->assertSame('valid-token', $result->getAttribute('personalAccessToken'));
        $this->assertSame(0, $oauth2->refreshCalls);
    }

    public function testInvalidExpiryIsNotRefreshed(): void
    {
        $installation = new Document([
            '$id' => 'installation1',
            'personalAccessToken' => 'valid-token',
            'personalRefreshToken' => 'valid-refresh',
            'personalAccessTokenExpiry' => 'not-a-date',
        ]);

        $oauth2 = $this->fakeOAuth2();

        $result = (new InstallationTokens())->refresh($installation, $this->db(), $oauth2);

        $this->assertSame('valid-token', $result->getAttribute('personalAccessToken'));
        $this->assertSame(0, $oauth2->refreshCalls);
    }

    public function testClearedInstallationIsReturnedWithoutCallingTheProvider(): void
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
        $db->method('getAuthorization')->willReturn(new Authorization());
        $db->expects($this->never())->method('updateDocument');
        $db->expects($this->never())->method('createDocument');

        $oauth2 = $this->fakeOAuth2();

        $result = (new InstallationTokens())->refresh($installation, $db, $oauth2);

        $this->assertSame('', $result->getAttribute('personalAccessToken'));
        $this->assertSame(0, $oauth2->refreshCalls);
    }

    public function testExpiredTokenIsRefreshedAndPersisted(): void
    {
        $db = $this->createMock(Database::class);

        $db->expects($this->once())
            ->method('updateDocument')
            ->with('installations', 'installation1', $this->callback(function (Document $update) {
                $this->assertSame('fresh-token', $update->getAttribute('personalAccessToken'));
                $this->assertSame('fresh-refresh', $update->getAttribute('personalRefreshToken'));
                return true;
            }))
            ->willReturnArgument(2);

        $oauth2 = $this->fakeOAuth2();

        $result = (new InstallationTokens())->refresh($this->expired(), $db, $oauth2);

        $this->assertSame('fresh-token', $result->getAttribute('personalAccessToken'));
        $this->assertSame(1, $oauth2->refreshCalls);
    }

    public function testFallsBackToIdentityWhenInstallationHasNoTokens(): void
    {
        $installation = new Document(['$id' => 'installation1']);
        $identity = new Document([
            'providerAccessToken' => 'identity-token',
            'providerRefreshToken' => 'identity-refresh',
            'providerAccessTokenExpiry' => DateTime::addSeconds(new \DateTime(), 3600),
        ]);

        $oauth2 = $this->fakeOAuth2();

        $result = (new InstallationTokens())->refresh($installation, $this->db(), $oauth2, $identity);

        $this->assertSame('identity-token', $result->getAttribute('personalAccessToken'));
        $this->assertSame('identity-refresh', $result->getAttribute('personalRefreshToken'));
        $this->assertSame($identity->getAttribute('providerAccessTokenExpiry'), $result->getAttribute('personalAccessTokenExpiry'));
    }

    public function testMissingRefreshTokenThrowsClearError(): void
    {
        $installation = new Document([
            '$id' => 'installation1',
            'personalAccessToken' => 'stale-token',
            'personalRefreshToken' => null,
            'personalAccessTokenExpiry' => DateTime::addSeconds(new \DateTime(), -3600),
        ]);

        $oauth2 = $this->fakeOAuth2();

        try {
            (new InstallationTokens())->refresh($installation, $this->db(), $oauth2);
            $this->fail('Expected an Exception');
        } catch (Exception $e) {
            $this->assertSame(Exception::GENERAL_PROVIDER_FAILURE, $e->getType());
        }

        $this->assertSame(0, $oauth2->refreshCalls);
    }

    public function testFailedRefreshThrows(): void
    {
        $oauth2 = $this->fakeOAuth2(emptyUserId: true);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to refresh OAuth2 access token');
        (new InstallationTokens())->refresh($this->expired(), $this->db(), $oauth2);
    }

    public function testRotatedTokenIsPersistedWhenVerificationFails(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('getAuthorization')->willReturn(new Authorization());
        $db->method('getDocument')->willReturn(new Document());

        // The provider rotated the family, so the new pair has to land even though the call after it failed.
        $db->expects($this->once())
            ->method('updateDocument')
            ->with('installations', 'installation1', $this->callback(function (Document $update) {
                $this->assertSame('fresh-refresh', $update->getAttribute('personalRefreshToken'));
                return true;
            }))
            ->willReturnArgument(2);

        $oauth2 = $this->fakeOAuth2(emptyUserId: true);

        try {
            (new InstallationTokens())->refresh($this->expired(), $db, $oauth2);
            $this->fail('Expected an Exception');
        } catch (Exception $e) {
            $this->assertSame(Exception::GENERAL_PROVIDER_FAILURE, $e->getType());
        }
    }

    public function testRefusedRefreshTokenIsCleared(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('getAuthorization')->willReturn(new Authorization());
        $db->method('getDocument')->willReturn(new Document());

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
        (new InstallationTokens())->refresh($this->expired(), $db, $oauth2);
    }

    public function testInvalidGrantIsCleared(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('getAuthorization')->willReturn(new Authorization());
        $db->method('getDocument')->willReturn(new Document());

        $db->expects($this->once())
            ->method('updateDocument')
            ->with('installations', 'installation1', $this->callback(function (Document $update) {
                $this->assertSame('', $update->getAttribute('personalRefreshToken'));
                return true;
            }))
            ->willReturnArgument(2);

        $oauth2 = $this->fakeOAuth2(refresh: 'invalidGrant');

        $this->expectException(Exception::class);
        (new InstallationTokens())->refresh($this->expired(), $db, $oauth2);
    }

    public function testEmptyTokenResponseIsNotPersisted(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('getAuthorization')->willReturn(new Authorization());
        $db->method('getDocument')->willReturn(new Document());

        // A provider that answers without a token leaves nothing worth writing, and the stored
        // pair may still be good, so it has to survive.
        $db->expects($this->never())->method('updateDocument');

        $oauth2 = $this->fakeOAuth2(refresh: 'empty');

        $this->expectException(Exception::class);
        (new InstallationTokens())->refresh($this->expired(), $db, $oauth2);
    }

    public function testFailedRefreshCallIsNotPersisted(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('getAuthorization')->willReturn(new Authorization());
        $db->method('getDocument')->willReturn(new Document());

        $db->expects($this->never())->method('updateDocument');

        $oauth2 = $this->fakeOAuth2(refresh: 'throw');

        $this->expectException(Exception::class);
        (new InstallationTokens())->refresh($this->expired(), $db, $oauth2);
    }

    public function testTokenRefreshedByLockHolderIsReused(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('getAuthorization')->willReturn(new Authorization());

        $db->expects($this->once())
            ->method('getDocument')
            ->with('installations', 'installation1')
            ->willReturn(new Document([
                '$id' => 'installation1',
                'personalAccessToken' => 'already-refreshed-token',
                'personalRefreshToken' => 'already-refreshed-refresh',
                'personalAccessTokenExpiry' => DateTime::addSeconds(new \DateTime(), 3600),
            ]));
        $db->expects($this->never())->method('updateDocument');

        $oauth2 = $this->fakeOAuth2();

        $result = (new InstallationTokens())->refresh($this->expired(), $db, $oauth2);

        $this->assertSame('already-refreshed-token', $result->getAttribute('personalAccessToken'));
        $this->assertSame(0, $oauth2->refreshCalls);
    }

    public function testRefreshIsSerializedByLock(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('getAuthorization')->willReturn(new Authorization());
        $db->method('getDocument')->willReturn(new Document());
        $db->method('updateDocument')->willReturnArgument(2);

        $db->expects($this->once())
            ->method('createDocument')
            ->with('vcsCommentLocks', $this->callback(function (Document $lock) {
                $this->assertSame('installation-installation1', $lock->getId());
                return true;
            }))
            ->willReturnArgument(1);

        $db->expects($this->once())
            ->method('deleteDocument')
            ->with('vcsCommentLocks', 'installation-installation1')
            ->willReturn(true);

        $oauth2 = $this->fakeOAuth2();

        (new InstallationTokens())->refresh($this->expired(), $db, $oauth2);

        $this->assertSame(1, $oauth2->refreshCalls);
    }

    public function testLockIsReleasedWhenRefreshFails(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('getAuthorization')->willReturn(new Authorization());
        $db->method('getDocument')->willReturn(new Document());
        $db->method('createDocument')->willReturnArgument(1);

        $db->expects($this->once())
            ->method('deleteDocument')
            ->with('vcsCommentLocks', 'installation-installation1')
            ->willReturn(true);

        $oauth2 = $this->fakeOAuth2(refresh: 'throw');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to refresh OAuth2 access token');
        (new InstallationTokens())->refresh($this->expired(), $db, $oauth2);
    }

    protected function expired(): Document
    {
        return new Document([
            '$id' => 'installation1',
            'personalAccessToken' => 'stale-token',
            'personalRefreshToken' => 'stale-refresh',
            'personalAccessTokenExpiry' => DateTime::addSeconds(new \DateTime(), -3600),
        ]);
    }

    /**
     * @param string $refresh One of: ok, refused, invalidGrant, empty, throw.
     */
    protected function fakeOAuth2(bool $emptyUserId = false, string $refresh = 'ok')
    {
        return new class ($emptyUserId, $refresh) extends OAuth2 {
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
}
