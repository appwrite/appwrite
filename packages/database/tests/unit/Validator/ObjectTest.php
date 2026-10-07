<?php

namespace Tests\Unit\Validator;

use PHPUnit\Framework\TestCase;
use Utopia\Database\Validator\ObjectValidator;

class ObjectTest extends TestCase
{
    public function testValidAssociativeObjects(): void
    {
        $validator = new ObjectValidator();

        $this->assertTrue($validator->isValid(['key' => 'value']));
        $this->assertTrue($validator->isValid([
            'a' => [
                'b' => [
                    'c' => 123
                ]
            ]
        ]));

        $this->assertTrue($validator->isValid([
            'author' => 'Arnab',
            'metadata' => [
                'rating' => 4.5,
                'info' => [
                    'category' => 'science'
                ]
            ]
        ]));

        $this->assertTrue($validator->isValid([
            'key1' => null,
            'key2' => ['nested' => null]
        ]));

        $this->assertTrue($validator->isValid([
            'meta' => (object)['x' => 1]
        ]));

        $this->assertTrue($validator->isValid([
            'a' => 1,
            2 => 'b'
        ]));

    }

    public function testInvalidStructures(): void
    {
        $validator = new ObjectValidator();

        $this->assertFalse($validator->isValid(['a', 'b', 'c']));

        $this->assertFalse($validator->isValid('not an array'));

        $this->assertFalse($validator->isValid([
            0 => 'value'
        ]));
    }

    public function testEmptyCases(): void
    {
        $validator = new ObjectValidator();

        $this->assertTrue($validator->isValid([]));
        $this->assertTrue($validator->isValid(new \stdClass()));

        $this->assertFalse($validator->isValid('sldfjsdlfj'));
    }
}
