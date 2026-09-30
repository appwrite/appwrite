<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Exception;
use Appwrite\Auth\OAuth2\Gitlab;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GitlabTest extends TestCase
{
    /**
     * @param array<string, mixed> $userInfo
     */
    #[DataProvider('emailVerification')]
    public function testEmailVerified(array $userInfo, bool $verified): void
    {
        $gitlab = $this->createGitlab($userInfo);

        $this->assertSame($verified, $gitlab->isEmailVerified('token'));
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>, bool}>
     */
    public static function emailVerification(): \Iterator
    {
        yield 'verified' => [[
            'sub' => '7',
            'email' => 'owner@example.com',
            'email_verified' => true,
        ], true];

        yield 'unverified' => [[
            'sub' => '7',
            'email' => 'owner@example.com',
            'email_verified' => false,
        ], false];

        yield 'missing' => [[
            'sub' => '7',
            'email' => 'owner@example.com',
        ], false];
    }

    public function testOldTokenUsesProfileAndStaysUnverified(): void
    {
        $gitlab = $this->getMockBuilder(Gitlab::class)
            ->setConstructorArgs(['client-id', 'client-secret', 'https://example.com/callback'])
            ->onlyMethods(['request'])
            ->getMock();

        $gitlab
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $headers = []): string {
                $this->assertSame('GET', $method);

                if (\str_contains($url, '/oauth/userinfo')) {
                    throw new Exception('{"error":"insufficient_scope"}', 403);
                }

                $this->assertStringContainsString('/api/v4/user?', $url);

                return \json_encode([
                    'id' => 7,
                    'email' => 'owner@example.com',
                    'confirmed_at' => '2024-01-01T00:00:00.000Z',
                    'username' => 'owner',
                    'name' => 'Owner',
                    'avatar_url' => 'https://gitlab.example/avatar.png',
                ], JSON_THROW_ON_ERROR);
            });

        $this->assertSame('7', $gitlab->getUserID('token'));
        $this->assertSame('owner@example.com', $gitlab->getUserEmail('token'));
        $this->assertSame('owner', $gitlab->getUserSlug('token'));
        $this->assertSame('Owner', $gitlab->getUserName('token'));
        $this->assertSame('https://gitlab.example/avatar.png', $gitlab->getUserPhoto('token'));
        $this->assertFalse($gitlab->isEmailVerified('token'));
    }

    /**
     * @param array<string, mixed> $userInfo
     */
    private function createGitlab(array $userInfo): Gitlab&MockObject
    {
        $gitlab = $this->getMockBuilder(Gitlab::class)
            ->setConstructorArgs(['client-id', 'client-secret', 'https://example.com/callback'])
            ->onlyMethods(['request'])
            ->getMock();

        $gitlab
            ->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'https://gitlab.com/oauth/userinfo',
                ['Authorization: Bearer token'],
            )
            ->willReturn(\json_encode($userInfo, JSON_THROW_ON_ERROR));

        return $gitlab;
    }
}
