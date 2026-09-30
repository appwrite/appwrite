<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Gitlab;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GitlabTest extends TestCase
{
    public function testLoginURLRequestsOpenIdEmailScope(): void
    {
        $gitlab = new Gitlab('client-id', 'client-secret', 'https://example.com/callback');

        \parse_str((string) \parse_url($gitlab->getLoginURL(), PHP_URL_QUERY), $query);

        $this->assertSame('openid email', $query['scope']);
    }

    public function testUserInfo(): void
    {
        $gitlab = $this->createGitlab([
            'sub' => '7',
            'email' => 'owner@example.com',
            'email_verified' => true,
            'name' => 'Owner',
            'preferred_username' => 'owner',
            'picture' => 'https://gitlab.example/avatar.png',
        ]);

        $this->assertSame('7', $gitlab->getUserID('token'));
        $this->assertSame('owner@example.com', $gitlab->getUserEmail('token'));
        $this->assertTrue($gitlab->isEmailVerified('token'));
        $this->assertSame('Owner', $gitlab->getUserName('token'));
        $this->assertSame('owner', $gitlab->getUserSlug('token'));
        $this->assertSame('https://gitlab.example/avatar.png', $gitlab->getUserPhoto('token'));
    }

    public function testUnverifiedEmail(): void
    {
        $gitlab = $this->createGitlab([
            'sub' => '7',
            'email' => 'owner@example.com',
            'email_verified' => false,
        ]);

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
