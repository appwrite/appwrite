<?php

declare(strict_types=1);

namespace Utopia\Mqtt\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Mqtt\Packet;

final class PacketFramesTest extends TestCase
{
    public function testSplitsConcatenatedPackets(): void
    {
        $one = Packet::pingreq();

        [$packets, $rest] = Packet::frames($one . $one);

        $this->assertCount(2, $packets);
        $this->assertSame($one, $packets[0]);
        $this->assertSame($one, $packets[1]);
        $this->assertSame('', $rest);
    }

    public function testKeepsTrailingPartialAsRemainder(): void
    {
        $one = Packet::pingreq();

        [$packets, $rest] = Packet::frames($one . "\xC0");

        $this->assertCount(1, $packets);
        $this->assertSame("\xC0", $rest);
    }

    public function testIncompletePacketIsAllRemainder(): void
    {
        $partial = "\x30\x05\x00\x03"; // header says 5 remaining bytes, only 2 present

        [$packets, $rest] = Packet::frames($partial);

        $this->assertSame([], $packets);
        $this->assertSame($partial, $rest);
    }

    public function testMultiByteRemainingLength(): void
    {
        $packet = "\x30\xC8\x01" . str_repeat('x', 200); // remaining length 200

        [$packets, $rest] = Packet::frames($packet);

        $this->assertCount(1, $packets);
        $this->assertSame($packet, $packets[0]);
        $this->assertSame('', $rest);
    }
}
