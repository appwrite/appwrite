<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Appwrite\Databases\Decimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function nonDecimals(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => [' '];
        yield 'sign without digits' => ['+'];
        yield 'point without digits' => ['.'];
        yield 'exponent without a mantissa' => ['e5'];
        yield 'exponent without digits' => ['1e'];
        yield 'exponent without a mantissa digit' => ['.e5'];
        yield 'exponent with two signs' => ['1e-+5'];
        yield 'two exponent markers' => ['1ee5'];
        yield 'fractional exponent' => ['1e5.0'];
        yield 'hexadecimal' => ['0x1A'];
        yield 'digit separators' => ['1_000'];
        yield 'two decimal points' => ['1.2.3'];
        yield 'two signs' => ['--5'];
        yield 'mixed signs' => ['+-1'];
        yield 'infinity' => ['INF'];
        yield 'padded with a null byte' => ["10.5\0"];
        yield 'integer padded with a null byte' => ["1\0"];
    }

    #[DataProvider('nonDecimals')]
    public function testTextThatIsNotADecimalIsNotParsed(string $value): void
    {
        $this->assertNull(Decimal::parse($value));
    }

    /**
     * @return iterable<string, array{string, int|string, int|string}>
     */
    public static function decimals(): iterable
    {
        yield 'fractional' => ['10.5', 10, 11];
        yield 'negative fraction of one' => ['-0.5', -1, 0];
        yield 'whole' => ['10.000', 10, 10];
        yield 'padded with every numeric whitespace' => [" \t\n\r\v\f10.5 \t\n\r\v\f", 10, 11];
        yield 'zero with an exponent' => ['-0.000e50', 0, 0];
        yield 'explicitly positive exponent' => ['1e+5', 100000, 100000];
        yield 'upper case exponent marker' => ['1.5E+2', 150, 150];
        yield 'point before the exponent' => ['1.e5', 100000, 100000];
        yield 'fraction without a whole part and an exponent' => ['-.5e1', -5, -5];
        yield 'negative exponent' => ['1.5e-1', 0, 1];
        yield 'fractional at the unsigned limit' => ['18446744073709551615.9', '18446744073709551615', '18446744073709551616'];
        yield 'beyond the unsigned limit' => ['1e400', '184467440737095516150', '184467440737095516150'];
        yield 'below the negative unsigned limit' => ['-1e400', '-184467440737095516150', '-184467440737095516150'];
    }

    #[DataProvider('decimals')]
    public function testADecimalRoundsDownByFloorAndUpByCeiling(string $value, int|string $floor, int|string $ceiling): void
    {
        $decimal = Decimal::parse($value);

        $this->assertInstanceOf(Decimal::class, $decimal);
        $this->assertSame($floor, $decimal->floor());
        $this->assertSame($ceiling, $decimal->ceil());
    }
}
