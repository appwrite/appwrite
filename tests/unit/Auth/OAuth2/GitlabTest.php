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

        $this->assertSame('read_user openid email', $query['scope']);
    }

    public function testOpenIdVerifiedPrimaryEmailIsVerified(): void
    {
        $gitlab = $this->createGitlab(
            [
                'id' => 7,
                'email' => 'rest@example.com',
                'confirmed_at' => null,
            ],
            [
                'email' => 'owner@example.com',
                'email_verified' => true,
            ],
        );

        $this->assertSame('owner@example.com', $gitlab->getUserEmail('token'));
        $this->assertTrue($gitlab->isEmailVerified('token'));
    }

    public function testOpenIdUnverifiedPrimaryEmailIsNotVerified(): void
    {
        $gitlab = $this->createGitlab(
            [
                'id' => 7,
                'email' => 'owner@example.com',
                'confirmed_at' => '2024-01-01T00:00:00.000Z',
            ],
            [
                'email' => 'owner@example.com',
                'email_verified' => false,
            ],
        );

        $this->assertSame('owner@example.com', $gitlab->getUserEmail('token'));
        $this->assertFalse($gitlab->isEmailVerified('token'));
    }

    public function testProfileConfirmationWithoutOpenIdIsNotVerified(): void
    {
        $gitlab = $this->createGitlab(
            [
                'id' => 7,
                'email' => 'owner@example.com',
                'confirmed_at' => '2024-01-01T00:00:00.000Z',
            ],
            [],
            failUserInfo: true,
        );

        $this->assertSame('owner@example.com', $gitlab->getUserEmail('token'));
        $this->assertFalse($gitlab->isEmailVerified('token'));
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $userInfo
     */
    private function createGitlab(array $user, array $userInfo, bool $failUserInfo = false): Gitlab&MockObject
    {
        $gitlab = $this->getMockBuilder(Gitlab::class)
            ->setConstructorArgs(['client-id', 'client-secret', 'https://example.com/callback'])
            ->onlyMethods(['request'])
            ->getMock();

        $gitlab
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $headers = []) use ($user, $userInfo, $failUserInfo): string {
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

                    return \json_encode($user, JSON_THROW_ON_ERROR);
                }

                $this->fail('Unexpected GitLab request: ' . $url);
            });

        return $gitlab;
    }
}
