<?php

use PHPUnit\Framework\TestCase;

class StorageCacheHeaderValidationTest extends TestCase
{
    public function testMaxAgeValidation()
    {
        $validator = new \Appwrite\Utopia\Database\Validator\Integer(0, 31536000);
        $this->assertTrue($validator->isValid(0));
        $this->assertTrue($validator->isValid(31536000));
        $this->assertFalse($validator->isValid(-1));
        $this->assertFalse($validator->isValid(40000000));
    }

    public function testVaryAndPragmaLength()
    {
        $validator = new \Utopia\Validator\Text(0, 128);
        $this->assertTrue($validator->isValid('Accept-Encoding'));
        $this->assertFalse($validator->isValid(str_repeat('a', 129)));
    }
}
