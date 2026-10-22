<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Validator\Hash;

use Appwrite\Auth\Validator\Hash\PHPass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PHPassTest extends TestCase
{
    /**
     * @return \Iterator<string, array{mixed, bool}>
     */
    public static function hashes(): \Iterator
    {
        yield 'portable' => ['$P$Br387rwferoKN7uwHZqNMu98q3U8RO.', true];
        yield 'phpbb' => ['$H$9r387rwferoKN7uwHZqNMu98q3U8RO.', true];
        yield 'minimum iterations' => ['$P$5r387rwferoKN7uwHZqNMu98q3U8RO.', true];
        yield 'ceiling iterations' => ['$P$Er387rwferoKN7uwHZqNMu98q3U8RO.', true];
        yield 'above ceiling' => ['$P$Fr387rwferoKN7uwHZqNMu98q3U8RO.', false];
        yield 'maximum iterations' => ['$P$Sr387rwferoKN7uwHZqNMu98q3U8RO.', false];
        yield 'below minimum' => ['$P$4r387rwferoKN7uwHZqNMu98q3U8RO.', false];
        yield 'unknown iteration char' => ['$P$-r387rwferoKN7uwHZqNMu98q3U8RO.', false];
        yield 'truncated' => ['$P$Br387rwferoKN7uwHZqNMu98q3U8RO', false];
        yield 'unknown prefix' => ['$S$Br387rwferoKN7uwHZqNMu98q3U8RO.', false];
        yield 'bcrypt' => ['$2a$08$xX/myGbFU.ZSKHSi6EHdBOySTdYm8QxBLXmOPHrYMwV0mHRBBSBOq', true];
        yield 'bcrypt above ceiling' => ['$2a$31$xX/myGbFU.ZSKHSi6EHdBOySTdYm8QxBLXmOPHrYMwV0mHRBBSBOq', false];
        yield 'extended des' => ['_zzzzsaltiAm7ZTrEBv.k', false];
        yield 'sha512 crypt' => ['$6$rounds=999999999$saltsalt$xX/myGbFU.ZSKHSi6EHdBOySTdYm8QxB', false];
        yield 'empty' => ['', false];
        yield 'not a string' => [[], false];
    }

    #[DataProvider('hashes')]
    public function testIsValid(mixed $hash, bool $expected): void
    {
        $this->assertSame($expected, (new PHPass())->isValid($hash));
    }
}
