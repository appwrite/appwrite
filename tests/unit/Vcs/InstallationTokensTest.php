<?php

declare(strict_types=1);

namespace Tests\Unit\Vcs;

use Appwrite\Auth\OAuth2;
use Appwrite\Extend\Exception;
use Appwrite\Vcs\InstallationTokens;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;

final class InstallationTokensTest extends TestCase
{
    protected function db(): Database
    {
        return $this->createStub(Database::class);
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

        $installation = new Document([
            '$id' => 'installation1',
            'personalAccessToken' => 'stale-token',
            'personalRefreshToken' => 'stale-refresh',
            'personalAccessTokenExpiry' => DateTime::addSeconds(new \DateTime(), -3600),
        ]);

        $oauth2 = $this->fakeOAuth2();

        $result = (new InstallationTokens())->refresh($installation, $db, $oauth2);

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
        $installation = new Document([
            '$id' => 'installation1',
            'personalAccessToken' => 'stale-token',
            'personalRefreshToken' => 'stale-refresh',
            'personalAccessTokenExpiry' => DateTime::addSeconds(new \DateTime(), -3600),
        ]);

        $oauth2 = $this->fakeOAuth2(emptyUserId: true);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to refresh OAuth2 access token');
        (new InstallationTokens())->refresh($installation, $this->db(), $oauth2);
    }

    public function testFailedRefreshReturnsCurrentInstallationWhenAnotherRequestRefreshed(): void
    {
        $db = $this->createMock(Database::class);
        $current = new Document([
            '$id' => 'installation1',
            'personalAccessToken' => 'already-refreshed-token',
            'personalRefreshToken' => 'already-refreshed-refresh',
            'personalAccessTokenExpiry' => DateTime::addSeconds(new \DateTime(), 3600),
        ]);

        $db->expects($this->once())
            ->method('getDocument')
            ->with('installations', 'installation1')
            ->willReturn($current);
        $db->expects($this->never())->method('updateDocument');

        $installation = new Document([
            '$id' => 'installation1',
            'personalAccessToken' => 'stale-token',
            'personalRefreshToken' => 'stale-refresh',
            'personalAccessTokenExpiry' => DateTime::addSeconds(new \DateTime(), -3600),
        ]);

        $oauth2 = $this->fakeOAuth2(emptyUserId: true);

        $result = (new InstallationTokens())->refresh($installation, $db, $oauth2);

        $this->assertSame('already-refreshed-token', $result->getAttribute('personalAccessToken'));
    }

    protected function fakeOAuth2(bool $emptyUserId = false)
    {
        return new class ($emptyUserId) extends OAuth2 {
            public int $refreshCalls = 0;
            protected array $tokens = [];

            public function __construct(protected bool $emptyUserId)
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
                $this->tokens = [
                    'access_token' => 'fresh-token',
                    'refresh_token' => 'fresh-refresh',
                    'expires_in' => 3600,
                ];

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
