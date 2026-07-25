<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Validator;

use Appwrite\Auth\Validator\Password;
use PHPUnit\Framework\TestCase;

final class PasswordTest extends TestCase
{
    protected ?Password $object = null;

    public function setUp(): void
    {
        $this->object = new Password();
    }

    public function testValues(): void
    {
        $this->assertFalse($this->object->isValid(false));
        $this->assertFalse($this->object->isValid(null));
        $this->assertFalse($this->object->isValid(''));
        $this->assertFalse($this->object->isValid('1'));
        $this->assertFalse($this->object->isValid('12'));
        $this->assertFalse($this->object->isValid('123'));
        $this->assertFalse($this->object->isValid('1234'));
        $this->assertFalse($this->object->isValid('12345'));
        $this->assertFalse($this->object->isValid('123456'));
        $this->assertFalse($this->object->isValid('1234567'));
        $this->assertTrue($this->object->isValid('WUnOZcn0piQMN8Mh31xw4KQPF0gcNGVA'));
    }
}
