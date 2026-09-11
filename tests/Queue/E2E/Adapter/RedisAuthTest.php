<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Connection\Redis;

/**
 * Runs against the `redis-auth` compose service: `requirepass secretpw` for the
 * default user and an ACL user `worker` with password `workerpw`.
 */
final class RedisAuthTest extends TestCase
{
    private const string HOST = '127.0.0.1';
    private const int PORT = 16380;
    private const string PASSWORD = 'secretpw';
    private const string USER = 'worker';
    private const string USER_PASSWORD = 'workerpw';

    public function testPasswordAuthenticatesDefaultUser(): void
    {
        $this->assertRoundTrip(new Redis(self::HOST, self::PORT, null, self::PASSWORD));
    }

    public function testUserAndPasswordAuthenticateAclUser(): void
    {
        $this->assertRoundTrip(new Redis(self::HOST, self::PORT, self::USER, self::USER_PASSWORD));
    }

    public function testMissingCredentialsAreRejected(): void
    {
        $this->assertRejected(new Redis(self::HOST, self::PORT));
    }

    public function testWrongPasswordIsRejected(): void
    {
        $this->assertRejected(new Redis(self::HOST, self::PORT, self::USER, 'not-the-password'));
    }

    /**
     * A credentialed connection can write a job and read it back.
     */
    private function assertRoundTrip(Redis $connection): void
    {
        $key = 'auth-test-' . uniqid();

        $this->assertTrue($connection->rightPush($key, 'payload'));
        $this->assertSame('payload', $connection->rightPop($key, 1));
    }

    /**
     * The observable contract of rejected credentials: the push throws, and the
     * server holds nothing for that key when a trusted connection looks.
     */
    private function assertRejected(Redis $rejected): void
    {
        $key = 'auth-test-' . uniqid();
        $thrown = null;

        try {
            $rejected->rightPush($key, 'payload');
        } catch (\RedisException $e) {
            $thrown = $e;
        }

        $trusted = new Redis(self::HOST, self::PORT, null, self::PASSWORD);

        $this->assertInstanceOf(\RedisException::class, $thrown, 'Push with rejected credentials must throw');
        $this->assertSame(0, $trusted->listSize($key), 'Rejected push must not reach the server');
    }
}
