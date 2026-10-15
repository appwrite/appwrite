<?php

declare(strict_types=1);

namespace Utopia\Client\Tests\Destinations;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Client\Destinations\IPRange;

final class IPRangeTest extends TestCase
{
    public function testContainsAddressesInsideTheRange(): void
    {
        $range = new IPRange('10.0.0.0/8');

        $this->assertTrue($range->contains('10.0.0.0'));
        $this->assertTrue($range->contains('10.255.255.255'));
        $this->assertFalse($range->contains('11.0.0.0'));
        $this->assertFalse($range->contains('::ffff:10.0.0.1'));
        $this->assertFalse($range->contains('not an address'));
    }

    public function testMatchesUnalignedPrefixes(): void
    {
        $range = new IPRange('172.16.0.0/12');

        $this->assertTrue($range->contains('172.31.255.255'));
        $this->assertFalse($range->contains('172.32.0.0'));
    }

    public function testASingleAddressMatchesEverySpellingOfIt(): void
    {
        $range = new IPRange('0:0:0:0:0:0:0:1');

        $this->assertTrue($range->contains('::1'));
        $this->assertFalse($range->contains('::2'));
    }

    #[DataProvider('malformedRanges')]
    public function testRefusesMalformedRanges(string $range): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IPRange($range);
    }

    public static function malformedRanges(): \Iterator
    {
        yield 'empty prefix, would read as /0' => ['10.0.0.0/'];
        yield 'non-numeric prefix' => ['10.0.0.0/foo'];
        yield 'prefix beyond ipv4' => ['10.0.0.0/33'];
        yield 'prefix beyond ipv6' => ['::/129'];
        yield 'negative prefix' => ['10.0.0.0/-1'];
        yield 'hostname' => ['example.com'];
        yield 'empty' => [''];
    }
}
