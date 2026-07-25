<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Validator;

use Appwrite\Auth\Validator\PasswordDictionary;
use PHPUnit\Framework\TestCase;

final class PasswordDictionaryTest extends TestCase
{
    protected ?PasswordDictionary $object = null;

    public function setUp(): void
    {
        $this->object = new PasswordDictionary(
            ['password' => true, '123456' => true],
            true
        );
    }

    public function testValues(): void
    {
        $this->assertFalse($this->object->isValid('1')); // to check parent is being called
        $this->assertFalse($this->object->isValid('123456'));
        $this->assertFalse($this->object->isValid('password'));
        $this->assertTrue($this->object->isValid('myPasswordIsRight'));

        $pass = ''; // 256 chars
        for ($i = 0; $i < 256; $i++) {
            $pass .= 'p';
        }

        $this->assertTrue($this->object->isValid($pass));

        $pass .= 'p'; // 257 chars

        $this->assertFalse($this->object->isValid($pass));
    }
}
