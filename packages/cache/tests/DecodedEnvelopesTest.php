<?php

declare(strict_types=1);

namespace Utopia\Cache\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Codec\Json;
use Utopia\Cache\DecodedEnvelopes;
use Utopia\Cache\Envelope;

final class DecodedEnvelopesTest extends TestCase
{
    private Envelope $envelope;

    protected function setUp(): void
    {
        $this->envelope = new Envelope(new Json());
    }

    /**
     * @return array<string, mixed> a payload large enough to be memoized
     */
    private function payload(string $marker): array
    {
        return ['marker' => $marker, 'attributes' => array_fill(0, 40, ['key' => str_repeat('a', 32), 'type' => 'string'])];
    }

    private function marker(mixed $data): mixed
    {
        $this->assertIsArray($data);

        return $data['marker'];
    }

    public function testRepeatedLoadOfTheSameStringReturnsTheSameData(): void
    {
        $memo = new DecodedEnvelopes($this->envelope);
        $stored = $this->envelope->encode($this->payload('v1'), 100);

        $first = $memo->decode('k', $stored, 60, 110);
        $second = $memo->decode('k', $stored, 60, 120);

        $this->assertSame($this->payload('v1'), $first);
        $this->assertSame($first, $second);
        $this->assertSame(1, $memo->count());
    }

    public function testChangedStringUnderTheSameKeyIsDecodedAgain(): void
    {
        $memo = new DecodedEnvelopes($this->envelope);
        $memo->decode('k', $this->envelope->encode($this->payload('v1'), 100), 60, 110);

        $data = $memo->decode('k', $this->envelope->encode($this->payload('v2'), 101), 60, 110);

        $this->assertSame('v2', $this->marker($data));
        $this->assertSame(1, $memo->count());
    }

    public function testMemoizedEntryStillExpiresWithTheEnvelopeTime(): void
    {
        $memo = new DecodedEnvelopes($this->envelope);
        $stored = $this->envelope->encode($this->payload('v1'), 100);

        $this->assertSame('v1', $this->marker($memo->decode('k', $stored, 60, 159)));
        $this->assertFalse($memo->decode('k', $stored, 60, 160));
        $this->assertFalse($memo->decode('k', $stored, 60, 200));
    }

    public function testMalformedEnvelopeIsAMissAndIsNotMemoized(): void
    {
        $memo = new DecodedEnvelopes($this->envelope);

        $this->assertFalse($memo->decode('k', str_repeat('x', 2048), 60, 0));
        $this->assertFalse($memo->decode('k', '{"time":100}' . str_repeat(' ', 2048), 60, 0));
        $this->assertSame(0, $memo->count());
    }

    public function testSmallPayloadsPassThroughWithoutBeingMemoized(): void
    {
        $memo = new DecodedEnvelopes($this->envelope, minimumBytes: 1024);
        $stored = $this->envelope->encode('empty', 100);

        $this->assertSame('empty', $memo->decode('k', $stored, 60, 110));
        $this->assertSame(0, $memo->count());
    }

    public function testCapacityBoundsTheMemoAndEvictsTheOldestKey(): void
    {
        $memo = new DecodedEnvelopes($this->envelope, capacity: 2);
        $a = $this->envelope->encode($this->payload('a'), 100);
        $b = $this->envelope->encode($this->payload('b'), 100);
        $c = $this->envelope->encode($this->payload('c'), 100);

        $memo->decode('a', $a, 60, 110);
        $memo->decode('b', $b, 60, 110);
        $memo->decode('c', $c, 60, 110);

        $this->assertSame(2, $memo->count());
        // 'a' was evicted, so it decodes again and pushes 'b' out in turn.
        $this->assertSame('a', $this->marker($memo->decode('a', $a, 60, 110)));
        $this->assertSame(2, $memo->count());
        $this->assertSame('c', $this->marker($memo->decode('c', $c, 60, 110)));
        $this->assertSame('b', $this->marker($memo->decode('b', $b, 60, 110)));
    }

    public function testRejectsZeroCapacity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DecodedEnvelopes($this->envelope, capacity: 0);
    }
}
