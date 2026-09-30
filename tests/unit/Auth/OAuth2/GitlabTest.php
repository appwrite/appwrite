<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Exception;
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

    public function testOpenIdUserInfoSuppliesTheProfile(): void
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

    public function testOpenIdUnverifiedPrimaryEmailIsNotVerified(): void
    {
        $gitlab = $this->createGitlab([
            'sub' => '7',
            'email' => 'owner@example.com',
            'email_verified' => false,
        ]);

        $this->assertSame('owner@example.com', $gitlab->getUserEmail('token'));
        $this->assertFalse($gitlab->isEmailVerified('token'));
    }

    public function testProfileFallbackIsNotVerified(): void
    {
        $gitlab = $this->createGitlab([], [
            'id' => 7,
            'email' => 'owner@example.com',
            'confirmed_at' => '2024-01-01T00:00:00.000Z',
            'username' => 'owner',
        ], failUserInfo: true);

        $this->assertSame('7', $gitlab->getUserID('token'));
        $this->assertSame('owner', $gitlab->getUserSlug('token'));
        $this->assertSame('owner@example.com', $gitlab->getUserEmail('token'));
        $this->assertFalse($gitlab->isEmailVerified('token'));
    }

    /**
     * @param array<string, mixed> $userInfo
     * @param array<string, mixed> $profile
     */
    private function createGitlab(array $userInfo, array $profile = [], bool $failUserInfo = false): Gitlab&MockObject
    {
        $gitlab = $this->getMockBuilder(Gitlab::class)
            ->setConstructorArgs(['client-id', 'client-secret', 'https://example.com/callback'])
            ->onlyMethods(['request'])
            ->getMock();

        $calls = $failUserInfo ? 2 : 1;
        $gitlab
            ->expects($this->exactly($calls))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $headers = []) use ($userInfo, $profile, $failUserInfo): string {
                $this->assertSame('GET', $method);

                if (\str_contains($url, '/oauth/userinfo')) {
                    $this->assertContains('Authorization: Bearer token', $headers);
                    if ($failUserInfo) {
                        throw new Exception('{"error":"insufficient_scope"}', 403);
                    }

                    return \json_encode($userInfo, JSON_THROW_ON_ERROR);
                }

                if (\str_contains($url, '/api/v4/user?')) {
                    $this->assertStringContainsString('access_token=token', $url);

                    return \json_encode($profile, JSON_THROW_ON_ERROR);
                }

                $this->fail('Unexpected GitLab request: ' . $url);
            });

        return $gitlab;
    }
}
