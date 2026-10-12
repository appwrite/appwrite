<?php

declare(strict_types=1);

namespace Tests\Unit\Network\Validators;

use Appwrite\Network\Validator\Fingerprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FingerprintTest extends TestCase
{
    private const string FINGERPRINT = '14:6D:E9:83:C5:73:06:50:D8:EE:B9:95:2F:34:FC:64:16:A0:83:42:E6:1D:BE:A8:8A:04:96:B2:3F:CF:44:E5';

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function values(): array
    {
        return [
            'uppercase' => [self::FINGERPRINT, true],
            'lowercase' => [\strtolower(self::FINGERPRINT), true],
            'without colons' => [\str_replace(':', '', self::FINGERPRINT), false],
            'dashes' => [\str_replace(':', '-', self::FINGERPRINT), false],
            'SHA-1 length' => [\implode(':', \array_fill(0, 20, 'AB')), false],
            'one byte more' => [self::FINGERPRINT . ':00', false],
            'single digit byte' => [\substr(self::FINGERPRINT, 1), false],
            'not hex' => [\str_replace('6D', 'G4', self::FINGERPRINT), false],
            'trailing colon' => [self::FINGERPRINT . ':', false],
            'empty' => ['', false],
            'not a string' => [123, false],
        ];
    }

    #[DataProvider('values')]
    public function testIsValid(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, (new Fingerprint())->isValid($value));
    }
}
