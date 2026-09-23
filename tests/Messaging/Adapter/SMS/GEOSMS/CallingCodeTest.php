<?php

declare(strict_types=1);

namespace Utopia\Tests\Adapter\SMS\GEOSMS;

use PHPUnit\Framework\Attributes\DataProvider;
use Utopia\Messaging\Adapter\SMS\GEOSMS\CallingCode;
use Utopia\Tests\Adapter\Base;

final class CallingCodeTest extends Base
{
    public function testFromPhoneNumber(): void
    {
        $this->assertSame(CallingCode::NORTH_AMERICA, CallingCode::fromPhoneNumber('+11234567890'));
        $this->assertSame(CallingCode::INDIA, CallingCode::fromPhoneNumber('+911234567890'));
        $this->assertSame(CallingCode::ISRAEL, CallingCode::fromPhoneNumber('9721234567890'));
        $this->assertSame(CallingCode::UNITED_ARAB_EMIRATES, CallingCode::fromPhoneNumber('009711234567890'));
        $this->assertSame(CallingCode::UNITED_KINGDOM, CallingCode::fromPhoneNumber('011441234567890'));
        $this->assertEquals(null, CallingCode::fromPhoneNumber('2'));
    }

    /**
     * @return \Iterator<string, array{string, string}>
     */
    public static function neighbouringPrefixes(): \Iterator
    {
        yield 'Swiss mobile' => ['+41791234567', '41'];
        yield 'Liechtenstein' => ['+4237891234', '423'];
        yield 'Czech Republic' => ['+420601234567', '420'];
        yield 'Slovak Republic' => ['+421901234567', '421'];
        yield 'Egypt' => ['+201001234567', '20'];
        yield 'Morocco' => ['+212612345678', '212'];
        yield 'South Africa' => ['+27821234567', '27'];
        yield 'Kazakhstan' => ['+77011234567', '7'];
    }

    #[DataProvider('neighbouringPrefixes')]
    public function testResolvesCountryWhoseCodeStartsAnother(string $number, string $callingCode): void
    {
        $this->assertSame($callingCode, CallingCode::fromPhoneNumber($number));
    }
}
