<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Gitlab;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GitlabTest extends TestCase
{
    public function testConfirmedPrimaryEmailIsVerified(): void
    {
        $gitlab = $this->createGitlab([
            'id' => 1,
            'email' => 'owner@example.com',
            'confirmed_at' => '2024-01-01T00:00:00.000Z',
        ]);

        $this->assertSame('owner@example.com', $gitlab->getUserEmail('token'));
        $this->assertTrue($gitlab->isEmailVerified('token'));
    }

    public function testUnconfirmedPrimaryEmailIsNotVerified(): void
    {
        $gitlab = $this->createGitlab([
            'id' => 1,
            'email' => 'owner@example.com',
            'confirmed_at' => null,
        ]);

        $this->assertSame('owner@example.com', $gitlab->getUserEmail('token'));
        $this->assertFalse($gitlab->isEmailVerified('token'));
    }

    public function testConfirmedAccountWithoutPrimaryEmailIsNotVerified(): void
    {
        $gitlab = $this->createGitlab([
            'id' => 1,
            'email' => '',
            'confirmed_at' => '2024-01-01T00:00:00.000Z',
        ]);

        $this->assertSame('', $gitlab->getUserEmail('token'));
        $this->assertFalse($gitlab->isEmailVerified('token'));
    }

    /**
     * @param array<string, mixed> $user
     */
    private function createGitlab(array $user): Gitlab&MockObject
    {
        $gitlab = $this->getMockBuilder(Gitlab::class)
            ->setConstructorArgs(['client-id', 'client-secret', 'https://example.com/callback'])
            ->onlyMethods(['request'])
            ->getMock();

        $gitlab
            ->expects($this->once())
            ->method('request')
            ->willReturnCallback(function (string $method, string $url) use ($user): string {
                $this->assertSame('GET', $method);
                $this->assertStringContainsString('/api/v4/user?', $url);
                $this->assertStringNotContainsString('/api/v4/user/emails', $url);
                $this->assertStringContainsString('access_token=token', $url);

                return \json_encode($user, JSON_THROW_ON_ERROR);
            });

        return $gitlab;
    }
}
