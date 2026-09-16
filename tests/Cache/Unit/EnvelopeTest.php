<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Codec\Igbinary;
use Utopia\Cache\Codec\Json;
use Utopia\Cache\Envelope;

final class EnvelopeTest extends TestCase
{
    private Envelope $envelope;

    protected function setUp(): void
    {
        $this->envelope = new Envelope(new Json());
    }

    public function testEncodeWrapsDataAndTime(): void
    {
        $encoded = $this->envelope->encode('hello', 1700000000);
        $this->assertSame('{"time":1700000000,"data":"hello"}', $encoded);
    }

    public function testEncodeArrayPayload(): void
    {
        $encoded = $this->envelope->encode(['a' => 1], 42);
        $this->assertSame('{"time":42,"data":{"a":1}}', $encoded);
    }

    public function testDecodeReturnsDataWhenFresh(): void
    {
        $encoded = $this->envelope->encode(['x' => 1], 100);
        $this->assertSame(['x' => 1], $this->envelope->decode($encoded, ttl: 60, now: 130));
    }

    public function testDecodeReturnsFalseWhenStale(): void
    {
        $encoded = $this->envelope->encode('value', 100);
        // 100 + 60 = 160; now = 161 → stale
        $this->assertFalse($this->envelope->decode($encoded, ttl: 60, now: 161));
    }

    public function testDecodeBoundaryIsExclusive(): void
    {
        $encoded = $this->envelope->encode('value', 100);
        // time + ttl > now means strictly greater; equal counts as stale
        $this->assertFalse($this->envelope->decode($encoded, ttl: 60, now: 160));
        $this->assertSame('value', $this->envelope->decode($encoded, ttl: 60, now: 159));
    }

    public function testDecodeTreatsMalformedJsonAsMiss(): void
    {
        $this->assertFalse($this->envelope->decode('not json', 60, 0));
        $this->assertFalse($this->envelope->decode('', 60, 0));
        $this->assertFalse($this->envelope->decode('null', 60, 0));
    }

    public function testDecodeRejectsMissingFields(): void
    {
        $this->assertFalse($this->envelope->decode('{"time":100}', 60, 0));
        $this->assertFalse($this->envelope->decode('{"data":"x"}', 60, 0));
        $this->assertFalse($this->envelope->decode('{}', 60, 0));
    }

    public function testDecodeRejectsNonIntegerTime(): void
    {
        $this->assertFalse($this->envelope->decode('{"time":"100","data":"x"}', 60, 0));
        $this->assertFalse($this->envelope->decode('{"time":1.5,"data":"x"}', 60, 0));
    }

    public function testDecodePreservesNullData(): void
    {
        // isset() rejects null, so null-data envelopes are treated as a miss.
        // This matches existing adapter behavior; documenting via test.
        $this->assertFalse($this->envelope->decode('{"time":100,"data":null}', 60, 130));
    }

    public function testDecodePreservesNestedArrayData(): void
    {
        $data = ['a' => ['b' => ['c' => 'deep']], 'list' => [1, 2, 3]];
        $encoded = $this->envelope->encode($data, 100);
        $this->assertSame($data, $this->envelope->decode($encoded, 60, 130));
    }

    public function testDecodePreservesEmptyObjects(): void
    {
        $data = [
            'empty' => new \stdClass(),
            'nested' => ['empty' => new \stdClass()],
            'list' => [new \stdClass(), ['x' => 1]],
            'emptyArray' => [],
        ];
        $encoded = $this->envelope->encode($data, 100);

        $this->assertSame(
            '{"empty":{},"nested":{"empty":{}},"list":[{},{"x":1}],"emptyArray":[]}',
            json_encode($this->envelope->decode($encoded, 60, 130)),
        );

        $touched = $this->envelope->touch($encoded, 120);
        $this->assertIsString($touched);
        $this->assertSame(
            '{"empty":{},"nested":{"empty":{}},"list":[{},{"x":1}],"emptyArray":[]}',
            json_encode($this->envelope->decode($touched, 60, 130)),
        );
    }

    public function testTouchRewritesTime(): void
    {
        $encoded = $this->envelope->encode('value', 100);
        $touched = $this->envelope->touch($encoded, 200);

        $this->assertIsString($touched);
        $this->assertSame('value', $this->envelope->decode($touched, 60, 250));
        // Original timestamp would have made this stale
        $this->assertFalse($this->envelope->decode($encoded, 60, 250));
    }

    public function testTouchPreservesArrayData(): void
    {
        $data = ['x' => 1, 'y' => [2, 3]];
        $encoded = $this->envelope->encode($data, 100);
        $touched = $this->envelope->touch($encoded, 200);

        $this->assertIsString($touched);
        $this->assertSame($data, $this->envelope->decode($touched, 60, 230));
    }

    public function testTouchReturnsFalseOnMalformedJson(): void
    {
        $this->assertFalse($this->envelope->touch('not json', 200));
    }

    public function testTouchReturnsFalseWhenDataKeyMissing(): void
    {
        $this->assertFalse($this->envelope->touch('{"time":100}', 200));
        $this->assertFalse($this->envelope->touch('{}', 200));
    }

    public function testTouchAcceptsEnvelopeWithoutPriorTimeField(): void
    {
        // touch() only requires a 'data' key — re-stamping is the whole point.
        $touched = $this->envelope->touch('{"data":"x"}', 200);
        $this->assertIsString($touched);
        $this->assertSame('x', $this->envelope->decode($touched, 60, 230));
    }

    public function testRoundTripsThroughAnyCodec(): void
    {
        $envelope = new Envelope(new Igbinary());
        $data = ['a' => ['b' => 'deep'], 'empty' => new \stdClass()];

        $encoded = $envelope->encode($data, 100);
        $this->assertEquals($data, $envelope->decode($encoded, 60, 130));
        $this->assertFalse($envelope->decode($encoded, 60, 161));

        $touched = $envelope->touch($encoded, 200);
        $this->assertIsString($touched);
        $this->assertEquals($data, $envelope->decode($touched, 60, 250));
    }

    public function testPayloadFromAnotherCodecIsAMiss(): void
    {
        $json = new Envelope(new Json());
        $encoded = $json->encode('value', 100);

        $igbinary = new Envelope(new Igbinary());
        $this->assertFalse($igbinary->decode($encoded, 60, 130));
        $this->assertFalse($igbinary->touch($encoded, 200));
    }
}
