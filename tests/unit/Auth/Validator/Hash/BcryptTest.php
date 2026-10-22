<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Validator\Hash;

use Appwrite\Auth\Validator\Hash\Bcrypt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BcryptTest extends TestCase
{
    /**
     * @return \Iterator<string, array{mixed, bool}>
     */
    public static function hashes(): \Iterator
    {
        $digest = 'xX/myGbFU.ZSKHSi6EHdBOySTdYm8QxBLXmOPHrYMwV0mHRBBSBOq';
        yield 'minimum cost' => ['$2y$04$' . $digest, true];
        yield 'ceiling cost' => ['$2a$15$' . $digest, true];
        yield 'b variant' => ['$2b$12$' . $digest, true];
        yield 'x variant' => ['$2x$10$' . $digest, true];
        yield 'above ceiling' => ['$2y$16$' . $digest, false];
        yield 'maximum bcrypt cost' => ['$2y$31$' . $digest, false];
        yield 'below minimum' => ['$2y$03$' . $digest, false];
        yield 'non numeric cost' => ['$2y$1a$' . $digest, false];
        yield 'unknown variant' => ['$2z$10$' . $digest, false];
        yield 'missing separator' => ['$2y$10x' . $digest, false];
        yield 'truncated' => ['$2y$10$' . \substr($digest, 1), false];
        yield 'argon2' => ['$argon2id$v=19$m=1024,t=2,p=1$TmtWSjZ3aE5keWNEdFlhcg$KjIALjgKcbZxSfJh1RkK6/9wqD+WbGVRjzTJ3BIQaBg', false];
        yield 'sha512 crypt' => ['$6$rounds=999999999$saltsalt$' . $digest, false];
        yield 'empty' => ['', false];
        yield 'not a string' => [15, false];
    }

    #[DataProvider('hashes')]
    public function testIsValid(mixed $hash, bool $expected): void
    {
        $this->assertSame($expected, (new Bcrypt())->isValid($hash));
    }
}
