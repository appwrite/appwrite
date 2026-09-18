<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Queue\Codec;
use Utopia\Queue\Codec\Compat;
use Utopia\Queue\Codec\Igbinary;
use Utopia\Queue\Codec\Json;

final class CodecTest extends TestCase
{
    /**
     * @return iterable<string, array{Codec}>
     */
    public static function codecs(): iterable
    {
        yield 'json' => [new Json()];

        if (\function_exists('igbinary_serialize')) {
            yield 'igbinary' => [new Igbinary()];
        }
    }

    /**
     * The envelope shapes both brokers build, plus the payload matrix the E2E
     * suite publishes: whatever a codec writes must come back identical.
     *
     */
    #[DataProvider('codecs')]
    public function testRoundTripsAnEnvelope(Codec $codec): void
    {
        $envelope = [
            'pid' => '65e2a1b8c9d0e1.23456789',
            'queue' => 'v1-stats-usage',
            'timestamp' => 1758067200,
            'attempts' => 2,
            'payload' => [
                'string' => 'utopia',
                'int' => 42,
                'float' => 1.5,
                'true' => true,
                'false' => false,
                'null' => null,
                'list' => [1, 2, 3],
                'assoc' => ['nested' => ['deep' => 'value']],
                'empty' => [],
            ],
        ];

        $this->assertSame($envelope, $codec->decode($codec->encode($envelope)));
    }

    #[DataProvider('codecs')]
    public function testDecodeThrowsOnForeignBytes(Codec $codec): void
    {
        $this->expectException(\Throwable::class);

        $codec->decode("\x1f\x8b not a payload this codec wrote");
    }

    public function testJsonWritesTheHistoricalWireFormat(): void
    {
        $this->assertSame(
            '{"pid":"a","queue":"mail","timestamp":1,"payload":[]}',
            new Json()->encode(['pid' => 'a', 'queue' => 'mail', 'timestamp' => 1, 'payload' => []]),
        );
    }

    public function testCompatReadsEitherEncoding(): void
    {
        $envelope = ['pid' => 'a', 'queue' => 'mail', 'timestamp' => 1, 'payload' => ['to' => 'a@example.com']];
        $compat = new Compat();

        $this->assertSame($envelope, $compat->decode(new Json()->encode($envelope)));

        if (!\function_exists('igbinary_serialize')) {
            $this->markTestIncomplete('ext-igbinary is not loaded; the JSON half of the read path is covered above.');
        }

        $this->assertSame($envelope, $compat->decode(new Igbinary()->encode($envelope)));
    }

    /**
     * Step one of the cutover: read both, write what yesterday's release wrote.
     */
    public function testCompatWritesItsDelegatesFormat(): void
    {
        $envelope = ['pid' => 'a', 'queue' => 'mail', 'timestamp' => 1, 'payload' => []];

        $this->assertSame(new Json()->encode($envelope), new Compat()->encode($envelope));

        if (!\function_exists('igbinary_serialize')) {
            return;
        }

        $written = new Compat(new Igbinary())->encode($envelope);
        $this->assertSame(new Igbinary()->encode($envelope), $written);
        $this->assertSame($envelope, new Compat()->decode($written), 'a JSON writer must still read what igbinary wrote');
    }

    public function testCompatRefusesAnUnknownBinaryFormat(): void
    {
        $this->expectException(\Throwable::class);

        // A NUL opens an igbinary payload and can appear nowhere in JSON, so
        // bytes that start with one but carry another version are neither.
        new Compat()->decode("\x00\x00\x00\x09anything");
    }

    /**
     * The value goes out as a NATS Content-Type header, so it is part of the
     * wire contract and not an internal label: a consumer switches on it.
     */
    public function testContentTypesNameTheFormat(): void
    {
        $this->assertSame('application/json', new Json()->contentType());

        if (!\function_exists('igbinary_serialize')) {
            return;
        }

        $this->assertSame('application/vnd.php.igbinary', new Igbinary()->contentType());
    }

    /**
     * Compat reads both but writes one, and the header describes the message
     * it travels with -- so it is the writer's type, all through the cutover.
     */
    public function testCompatAdvertisesItsWritersContentType(): void
    {
        $this->assertSame('application/json', new Compat()->contentType());

        if (!\function_exists('igbinary_serialize')) {
            return;
        }

        $this->assertSame('application/vnd.php.igbinary', new Compat(new Igbinary())->contentType());
    }

    public function testIgbinaryRejectsEmptyInput(): void
    {
        if (!\function_exists('igbinary_serialize')) {
            $this->markTestSkipped('ext-igbinary is not loaded.');
        }

        $this->expectException(\Throwable::class);

        new Igbinary()->decode('');
    }
}
