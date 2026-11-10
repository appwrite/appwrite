<?php

declare(strict_types=1);

namespace Utopia\Queue\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Broker\Redis;

final class RedisReapLimitsTest extends TestCase
{
    public function testRefusesAnAttemptCeilingBelowOne(): void
    {
        $connection = new PushRecordingConnection();

        $this->expectException(\InvalidArgumentException::class);
        new Redis($connection, $connection, reapMaxAttempts: 0);
    }

    /**
     * Every claim the sweep reaches is already reapAfter old, so this ceiling
     * would park every stranded claim and recover none.
     */
    public function testRefusesAnAgeCeilingAtOrBelowReapAfter(): void
    {
        $connection = new PushRecordingConnection();

        $this->expectException(\InvalidArgumentException::class);
        new Redis($connection, $connection, reapAfter: 3600, reapMaxAge: 3600);
    }

    public function testAcceptsAnAgeCeilingAboveReapAfter(): void
    {
        $connection = new PushRecordingConnection();

        $broker = new Redis($connection, $connection, reapAfter: 3600, reapMaxAge: 3601, reapMaxAttempts: 1);

        $this->assertInstanceOf(Redis::class, $broker);
    }
}
