<?php

namespace Tests\Unit\Auth\Validator;

use Appwrite\Auth\Validator\PasswordDictionary;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

class PasswordDictionaryTest extends TestCase
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
        $this->assertEquals($this->object->isValid('1'), false); // to check parent is being called
        $this->assertEquals($this->object->isValid('123456'), false);
        $this->assertEquals($this->object->isValid('password'), false);
        $this->assertEquals($this->object->isValid('myPasswordIsRight'), true);
    }
}
