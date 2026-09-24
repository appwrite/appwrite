<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Codec;
use Utopia\Cache\Codec\Igbinary;
use Utopia\Cache\Codec\Json;

final class CodecTest extends TestCase
{
    /**
     * @return iterable<string, array{Codec}>
     */
    public static function codecs(): iterable
    {
        yield 'json' => [new Json()];
        yield 'igbinary' => [new Igbinary()];
    }

    #[DataProvider('codecs')]
    public function testRoundTripsScalarsAndArrays(Codec $codec): void
    {
        foreach (['text', 42, 1.5, true, null, [], ['a' => 1, 'b' => [2, 3]], [1, 2, 3]] as $value) {
            $this->assertSame($value, $codec->decode($codec->encode($value)));
        }
    }

    #[DataProvider('codecs')]
    public function testPreservesEmptyObjects(Codec $codec): void
    {
        $value = [
            'empty' => new \stdClass(),
            'nested' => ['empty' => new \stdClass()],
            'list' => [new \stdClass(), ['x' => 1]],
            'emptyArray' => [],
        ];

        $this->assertSame(
            '{"empty":{},"nested":{"empty":{}},"list":[{},{"x":1}],"emptyArray":[]}',
            json_encode($codec->decode($codec->encode($value))),
        );
    }

    #[DataProvider('codecs')]
    public function testDecodeThrowsOnForeignBytes(Codec $codec): void
    {
        $this->expectException(\Throwable::class);
        $codec->decode("\x00\x01not a payload");
    }

    public function testJsonDecodesObjectsToArrays(): void
    {
        $this->assertSame(['a' => ['b' => 1]], new Json()->decode('{"a":{"b":1}}'));
    }

    public function testJsonWritesTheHistoricalWireFormat(): void
    {
        $this->assertSame('{"time":1,"data":"x"}', new Json()->encode(['time' => 1, 'data' => 'x']));
    }

    public function testIgbinaryDecodesItsOwnNull(): void
    {
        $codec = new Igbinary();
        $this->assertNull($codec->decode($codec->encode(null)));
    }

    public function testIgbinaryRejectsEmptyInput(): void
    {
        $this->expectException(\RuntimeException::class);
        new Igbinary()->decode('');
    }
}
