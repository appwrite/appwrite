<?php

namespace Tests\Unit\Validator;

use PHPUnit\Framework\TestCase;
use Utopia\Database\Validator\Vector;

class VectorTest extends TestCase
{
    public function testVector(): void
    {
        // Test valid vectors
        $validator = new Vector(3);

        $this->assertTrue($validator->isValid([1.0, 2.0, 3.0]));
        $this->assertTrue($validator->isValid([0, 0, 0]));
        $this->assertTrue($validator->isValid([-1.5, 0.0, 2.5]));

        // Test invalid vectors
        $this->assertFalse($validator->isValid([1.0, 2.0])); // Wrong dimensions
        $this->assertFalse($validator->isValid([1.0, 2.0, 3.0, 4.0])); // Wrong dimensions
        $this->assertFalse($validator->isValid('not an array')); // Not an array
        $this->assertFalse($validator->isValid(['1', '2', '3'])); // String numbers should fail
        $this->assertFalse($validator->isValid([1.0, 'not numeric', 3.0])); // Non-numeric value
        $this->assertFalse($validator->isValid([1.0, null, 3.0])); // Null value
        $this->assertFalse($validator->isValid([])); // Empty array
        $this->assertFalse($validator->isValid(['x' => 1.0, 'y' => 2.0, 'z' => 3.0])); // Associative array
        $this->assertFalse($validator->isValid([1.0, true, 3.0])); // Boolean value
    }

    public function testVectorWithDifferentDimensions(): void
    {
        $validator1 = new Vector(1);
        $this->assertTrue($validator1->isValid([5.0]));
        $this->assertFalse($validator1->isValid([1.0, 2.0]));

        $validator5 = new Vector(5);
        $this->assertTrue($validator5->isValid([1.0, 2.0, 3.0, 4.0, 5.0]));
        $this->assertFalse($validator5->isValid([1.0, 2.0, 3.0]));

        $validator128 = new Vector(128);
        $vector128 = array_fill(0, 128, 1.0);
        $this->assertTrue($validator128->isValid($vector128));

        $vector127 = array_fill(0, 127, 1.0);
        $this->assertFalse($validator128->isValid($vector127));
    }

    public function testVectorDescription(): void
    {
        $validator = new Vector(3);
        $this->assertEquals('Value must be an array of 3 numeric values', $validator->getDescription());

        $validator256 = new Vector(256);
        $this->assertEquals('Value must be an array of 256 numeric values', $validator256->getDescription());
    }

    public function testVectorType(): void
    {
        $validator = new Vector(3);
        $this->assertEquals('array', $validator->getType());
        $this->assertFalse($validator->isArray());
    }
}
