<?php

declare(strict_types=1);

namespace Tests\Unit\Redis;

use Appwrite\Redis\Auth;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    public function testCredentialsReturnNullWhenNoAuthIsConfigured(): void
    {
        $this->assertNull(Auth::credentials(null, null));
        $this->assertNull(Auth::credentials('', ''));
    }

    public function testCredentialsUsePasswordOnlyForDefaultUser(): void
    {
        $this->assertSame('secret', Auth::credentials('', 'secret'));
        $this->assertSame('0', Auth::credentials('', '0'));
    }

    public function testCredentialsUseUserAndPasswordForAclUsers(): void
    {
        $this->assertSame(['appwrite', 'secret'], Auth::credentials('appwrite', 'secret'));
        $this->assertSame(['0', '0'], Auth::credentials('0', '0'));
    }

    public function testCredentialsUseNamedUserWithEmptyPassword(): void
    {
        $this->assertSame(['appwrite', ''], Auth::credentials('appwrite', ''));
        $this->assertSame(['appwrite', ''], Auth::credentials('appwrite', null));
    }

    public function testAuthenticateSkipsRedisAuthWithoutCredentials(): void
    {
        $redis = new RedisSpy();

        Auth::authenticate($redis, '', '');

        $this->assertSame([], $redis->authCalls);
    }

    public function testAuthenticatePassesPreparedCredentialsToRedis(): void
    {
        $redis = new RedisSpy();

        Auth::authenticate($redis, 'appwrite', 'secret');

        $this->assertSame([['appwrite', 'secret']], $redis->authCalls);
    }
}

final class RedisSpy extends \Redis
{
    /**
     * @var array<int, mixed>
     */
    public array $authCalls = [];

    public function auth(mixed $credentials): \Redis|bool
    {
        $this->authCalls[] = $credentials;

        return true;
    }
}
