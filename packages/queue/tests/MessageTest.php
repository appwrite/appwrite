<?php

declare(strict_types=1);

namespace Utopia\Queue\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Message;

final class MessageTest extends TestCase
{
    private const array ENVELOPE = [
        'pid' => 'pid-1',
        'queue' => 'stats',
        'timestamp' => 1_700_000_000,
        'payload' => ['project' => 'alpha'],
        'attempts' => 2,
        'sequence' => 7,
    ];

    public function testKeyRoundTrips(): void
    {
        $message = new Message([...self::ENVELOPE, 'key' => 'project:alpha']);

        $this->assertSame('project:alpha', $message->getKey());
        $this->assertSame('pid-1', $message->getPid());
        $this->assertSame('stats', $message->getQueue());
        $this->assertSame(1_700_000_000, $message->getTimestamp());
        $this->assertSame(['project' => 'alpha'], $message->getPayload());
        $this->assertSame(2, $message->getAttempts());
        $this->assertSame(7, $message->getSequence());
    }

    public function testKeyDefaultsToNull(): void
    {
        $this->assertNull(new Message(self::ENVELOPE)->getKey());
        $this->assertNull(new Message()->getKey());
    }

    public function testArrayOmitsKeyWhenNull(): void
    {
        $this->assertSame(self::ENVELOPE, new Message(self::ENVELOPE)->asArray());
    }

    public function testArrayIncludesKeyWhenSet(): void
    {
        $keyed = [...self::ENVELOPE, 'key' => 'project:alpha'];

        $this->assertSame($keyed, new Message($keyed)->asArray());
    }
}
