<?php

declare(strict_types=1);

namespace Tests\Unit\SMTP;

use Appwrite\SMTP\Breaker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;

final class BreakerTest extends TestCase
{
    public function testOpensOnTheRefusedLoginThatReachesTheThreshold(): void
    {
        $cache = new Cache(new Memory());
        $now = \time();

        for ($i = 1; $i < Breaker::THRESHOLD; $i++) {
            $this->assertFalse((new Breaker($cache, 'project-a', 'smtp.mailersend.net', $now))->record(535));
        }
        $this->assertFalse((new Breaker($cache, 'project-a', 'smtp.mailersend.net', $now))->isOpen());

        $this->assertTrue((new Breaker($cache, 'project-a', 'smtp.mailersend.net', $now))->record(535));
        $this->assertTrue((new Breaker($cache, 'project-a', 'smtp.mailersend.net', $now))->isOpen());
    }

    /**
     * @return \Iterator<string, array{int}>
     */
    public static function otherRefusals(): \Iterator
    {
        yield 'recipient does not exist' => [550];
        yield 'sender not owned' => [553];
        yield 'greeting refused' => [554];
        yield 'login refused for now' => [454];
        yield 'no reply code' => [0];
    }

    #[DataProvider('otherRefusals')]
    public function testOnlyARefusedLoginCounts(int $code): void
    {
        $cache = new Cache(new Memory());

        for ($i = 0; $i < Breaker::THRESHOLD * 2; $i++) {
            $this->assertFalse((new Breaker($cache, 'project-a', 'smtp.example.test'))->record($code));
        }

        $this->assertFalse((new Breaker($cache, 'project-a', 'smtp.example.test'))->isOpen());
    }

    public function testRefusalsOlderThanTheWindowDoNotCount(): void
    {
        $cache = new Cache(new Memory());
        $then = \time() - Breaker::WINDOW;

        for ($i = 1; $i < Breaker::THRESHOLD; $i++) {
            (new Breaker($cache, 'project-a', 'smtp.mailersend.net', $then))->record(535);
        }

        $this->assertFalse((new Breaker($cache, 'project-a', 'smtp.mailersend.net', \time()))->record(535));
        $this->assertFalse((new Breaker($cache, 'project-a', 'smtp.mailersend.net', \time()))->isOpen());
    }

    public function testStaysOpenForTheCooldownOnly(): void
    {
        $cache = new Cache(new Memory());
        $now = \time();

        for ($i = 0; $i < Breaker::THRESHOLD; $i++) {
            (new Breaker($cache, 'project-a', 'smtp.mailersend.net', $now))->record(535);
        }

        $this->assertTrue((new Breaker($cache, 'project-a', 'smtp.mailersend.net', $now + Breaker::COOLDOWN - 1))->isOpen());
        $this->assertFalse((new Breaker($cache, 'project-a', 'smtp.mailersend.net', $now + Breaker::COOLDOWN))->isOpen());
    }

    public function testIsKeptPerProjectAndHost(): void
    {
        $cache = new Cache(new Memory());

        for ($i = 0; $i < Breaker::THRESHOLD; $i++) {
            (new Breaker($cache, 'project-a', 'smtp.mailersend.net'))->record(535);
        }

        $this->assertTrue((new Breaker($cache, 'project-a', ' SMTP.MailerSend.net '))->isOpen());
        $this->assertFalse((new Breaker($cache, 'project-b', 'smtp.mailersend.net'))->isOpen());
        $this->assertFalse((new Breaker($cache, 'project-a', 'smtp.resend.com'))->isOpen());
    }

    public function testAnUnreachableCacheNeverHoldsMailBack(): void
    {
        $breaker = new Breaker(new Cache(new UnreachableCache()), 'project-a', 'smtp.mailersend.net');

        for ($i = 0; $i < Breaker::THRESHOLD; $i++) {
            $this->assertFalse($breaker->record(535));
        }

        $this->assertFalse($breaker->isOpen());
    }
}

final class UnreachableCache extends Memory
{
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        throw new \RuntimeException('read error on connection');
    }

    public function save(string $key, array|string $data, string $hash = '', int $ttl = 0): bool|string|array
    {
        throw new \RuntimeException('read error on connection');
    }
}
