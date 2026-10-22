<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Validator\Hash;

use Appwrite\Auth\Validator\Hash\Argon2;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Argon2Test extends TestCase
{
    /**
     * @return \Iterator<string, array{mixed, bool}>
     */
    public static function hashes(): \Iterator
    {
        $tail = '$TmtWSjZ3aE5keWNEdFlhcg$KjIALjgKcbZxSfJh1RkK6/9wqD+WbGVRjzTJ3BIQaBg';
        yield 'argon2id' => ['$argon2id$v=19$m=65536,t=4,p=3' . $tail, true];
        yield 'argon2i' => ['$argon2i$v=19$m=20,t=3,p=2' . $tail, true];
        yield 'ceiling costs' => ['$argon2id$v=19$m=262144,t=10,p=16' . $tail, true];
        yield 'memory above ceiling' => ['$argon2id$v=19$m=262145,t=4,p=1' . $tail, false];
        yield 'time above ceiling' => ['$argon2id$v=19$m=65536,t=11,p=1' . $tail, false];
        yield 'threads above ceiling' => ['$argon2id$v=19$m=65536,t=4,p=17' . $tail, false];
        yield 'huge memory' => ['$argon2id$v=19$m=4294967295,t=4,p=1' . $tail, false];
        yield 'zero time' => ['$argon2id$v=19$m=65536,t=0,p=1' . $tail, false];
        yield 'reordered costs' => ['$argon2id$v=19$t=4,m=65536,p=1' . $tail, false];
        yield 'extra cost' => ['$argon2id$v=19$m=65536,t=4,p=1,x=1' . $tail, false];
        yield 'negative cost' => ['$argon2id$v=19$m=-1,t=4,p=1' . $tail, false];
        yield 'missing version' => ['$argon2id$m=65536,t=4,p=1' . $tail, false];
        yield 'unsupported variant' => ['$argon2d$v=19$m=65536,t=4,p=1' . $tail, false];
        yield 'missing digest' => ['$argon2id$v=19$m=65536,t=4,p=1$TmtWSjZ3aE5keWNEdFlhcg$', false];
        yield 'bcrypt' => ['$2y$10$xX/myGbFU.ZSKHSi6EHdBOySTdYm8QxBLXmOPHrYMwV0mHRBBSBOq', false];
        yield 'empty' => ['', false];
        yield 'not a string' => [null, false];
    }

    #[DataProvider('hashes')]
    public function testIsValid(mixed $hash, bool $expected): void
    {
        $this->assertSame($expected, (new Argon2())->isValid($hash));
    }
}
