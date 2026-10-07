<?php

namespace Tests\Unit\Validator;

use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Operator;
use Utopia\Database\Validator\Operator as OperatorValidator;

class OperatorTest extends TestCase
{
    protected Document $collection;

    public function setUp(): void
    {
        $this->collection = new Document([
            '$id' => 'test_collection',
            'attributes' => [
                new Document([
                    '$id' => 'count',
                    'key' => 'count',
                    'type' => Database::VAR_INTEGER,
                    'array' => false,
                ]),
                new Document([
                    '$id' => 'score',
                    'key' => 'score',
                    'type' => Database::VAR_FLOAT,
                    'array' => false,
                ]),
                new Document([
                    '$id' => 'title',
                    'key' => 'title',
                    'type' => Database::VAR_STRING,
                    'array' => false,
                    'size' => 100,
                ]),
                new Document([
                    '$id' => 'tags',
                    'key' => 'tags',
                    'type' => Database::VAR_STRING,
                    'array' => true,
                ]),
                new Document([
                    '$id' => 'active',
                    'key' => 'active',
                    'type' => Database::VAR_BOOLEAN,
                    'array' => false,
                ]),
                new Document([
                    '$id' => 'createdAt',
                    'key' => 'createdAt',
                    'type' => Database::VAR_DATETIME,
                    'array' => false,
                ]),
            ],
        ]);
    }

    public function tearDown(): void
    {
    }

    // Test parsing string operators (new functionality)
    public function testParseStringOperator(): void
    {
        $validator = new OperatorValidator($this->collection);

        // Create an operator and serialize it to JSON
        $operator = Operator::increment(5);
        $operator->setAttribute('count');
        $json = $operator->toString();

        // Validator should accept JSON string and parse it
        $this->assertTrue($validator->isValid($json), $validator->getDescription());
    }

    public function testParseInvalidStringOperator(): void
    {
        $validator = new OperatorValidator($this->collection);

        // Invalid JSON should fail
        $this->assertFalse($validator->isValid('invalid json'));
        $this->assertStringContainsString('Invalid operator:', $validator->getDescription());
    }

    public function testParseStringOperatorWithInvalidMethod(): void
    {
        $validator = new OperatorValidator($this->collection);

        // Valid JSON but invalid method
        $invalidOperator = json_encode([
            'method' => 'invalidMethod',
            'attribute' => 'count',
            'values' => [1]
        ]);

        $this->assertFalse($validator->isValid($invalidOperator));
        $this->assertStringContainsString('Invalid operator method:', $validator->getDescription());
    }

    // Test numeric operators
    public function testIncrementOperator(): void
    {
        $validator = new OperatorValidator($this->collection);

        $operator = Operator::increment(5);
        $operator->setAttribute('count');

        $this->assertTrue($validator->isValid($operator), $validator->getDescription());
    }

    public function testIncrementOnNonNumeric(): void
    {
        $validator = new OperatorValidator($this->collection);

        $operator = Operator::increment(5);
        $operator->setAttribute('title'); // String field

        $this->assertFalse($validator->isValid($operator));
        $this->assertStringContainsString('Cannot apply increment operator to non-numeric field', $validator->getDescription());
    }

    public function testDecrementOperator(): void
    {
        $validator = new OperatorValidator($this->collection);

        $operator = Operator::decrement(3);
        $operator->setAttribute('count');

        $this->assertTrue($validator->isValid($operator), $validator->getDescription());
    }

    public function testMultiplyOperator(): void
    {
        $validator = new OperatorValidator($this->collection);

        $operator = Operator::multiply(2.5);
        $operator->setAttribute('score');

        $this->assertTrue($validator->isValid($operator), $validator->getDescription());
    }

    public function testDivideByZero(): void
    {
        $validator = new OperatorValidator($this->collection);

        // The divide helper method throws exception before validator is called
        $this->expectException(\Utopia\Database\Exception\Operator::class);
        $this->expectExceptionMessage('Division by zero is not allowed');

        $operator = Operator::divide(0);
    }

    public function testModuloByZero(): void
    {
        $validator = new OperatorValidator($this->collection);

        // The modulo helper method throws exception before validator is called
        $this->expectException(\Utopia\Database\Exception\Operator::class);
        $this->expectExceptionMessage('Modulo by zero is not allowed');

        $operator = Operator::modulo(0);
    }

    // Test array operators
    public function testArrayAppend(): void
    {
        $validator = new OperatorValidator($this->collection);

        $operator = Operator::arrayAppend(['new-tag']);
        $operator->setAttribute('tags');

        $this->assertTrue($validator->isValid($operator), $validator->getDescription());
    }

    public function testArrayAppendOnNonArray(): void
    {
        $validator = new OperatorValidator($this->collection);

        $operator = Operator::arrayAppend(['value']);
        $operator->setAttribute('title'); // Non-array field

        $this->assertFalse($validator->isValid($operator));
        $this->assertStringContainsString('Cannot apply arrayAppend operator to non-array field', $validator->getDescription());
    }

    public function testArrayUnique(): void
    {
        $validator = new OperatorValidator($this->collection);

        $operator = Operator::arrayUnique();
        $operator->setAttribute('tags');

        $this->assertTrue($validator->isValid($operator), $validator->getDescription());
    }

    public function testArrayUniqueOnNonArray(): void
    {
        $validator = new OperatorValidator($this->collection);

        $operator = Operator::arrayUnique();
        $operator->setAttribute('count'); // Non-array field

        $this->assertFalse($validator->isValid($operator));
        $this->assertStringContainsString('Cannot apply arrayUnique operator to non-array field', $validator->getDescription());
    }

    public function testArrayIntersect(): void
    {
        $validator = new OperatorValidator($this->collection);

        $operator = Operator::arrayIntersect(['tag1', 'tag2']);
        $operator->setAttribute('tags');

        $this->assertTrue($validator->isValid($operator), $validator->getDescription());
    }

    public function testArrayIntersectWithEmptyArray(): void
    {
        $validator = new OperatorValidator($this->collection);

        $operator = Operator::arrayIntersect([]);
        $operator->setAttribute('tags');

        $this->assertFalse($validator->isValid($operator));
        $this->assertStringContainsString('requires a non-empty array value', $validator->getDescription());
    }

    public function testArrayDiff(): void
    {
        $validator = new OperatorValidator($this->collection);

        $operator = Operator::arrayDiff(['unwanted']);
        $operator->setAttribute('tags');

        $this->assertTrue($validator->isValid($operator), $validator->getDescription());
    }

    public function testArrayFilter(): void
    {
        $validator = new OperatorValidator($this->collection);

        $operator = Operator::arrayFilter('equal', 'active');
        $operator->setAttribute('tags');

        $this->assertTrue($validator->isValid($operator), $validator->getDescription());
    }

    public function testArrayFilterInvalidCondition(): void
    {
        $validator = new OperatorValidator($this->collection);

        $operator = Operator::arrayFilter('invalidCondition', 'value');
        $operator->setAttribute('tags');

        $this->assertFalse($validator->isValid($operator));
        $this->assertStringContainsString('Invalid array filter condition', $validator->getDescription());
    }

    // Test string operators
    public function testStringConcat(): void
    {
        $validator = new OperatorValidator($this->collection);

        $operator = Operator::stringConcat(' - Updated');
        $operator->setAttribute('title');

        $this->assertTrue($validator->isValid($operator), $validator->getDescription());
    }

    public function testStringConcatOnNonString(): void
    {
        $validator = new OperatorValidator($this->collection);

        $operator = Operator::stringConcat(' suffix');
        $operator->setAttribute('count'); // Non-string field

        $this->assertFalse($validator->isValid($operator));
        $this->assertStringContainsString('Cannot apply stringConcat operator to non-string field', $validator->getDescription());
    }

    public function testStringReplace(): void
    {
        $validator = new OperatorValidator($this->collection);

        $operator = Operator::stringReplace('old', 'new');
        $operator->setAttribute('title');

        $this->assertTrue($validator->isValid($operator), $validator->getDescription());
    }

    // Test boolean operators
    public function testToggle(): void
    {
        $validator = new OperatorValidator($this->collection);

        $operator = Operator::toggle();
        $operator->setAttribute('active');

        $this->assertTrue($validator->isValid($operator), $validator->getDescription());
    }

    public function testToggleOnNonBoolean(): void
    {
        $validator = new OperatorValidator($this->collection);

        $operator = Operator::toggle();
        $operator->setAttribute('count'); // Non-boolean field

        $this->assertFalse($validator->isValid($operator));
        $this->assertStringContainsString('Cannot apply toggle operator to non-boolean field', $validator->getDescription());
    }

    // Test date operators
    public function testDateAddDays(): void
    {
        $validator = new OperatorValidator($this->collection);

        $operator = Operator::dateAddDays(7);
        $operator->setAttribute('createdAt');

        $this->assertTrue($validator->isValid($operator), $validator->getDescription());
    }

    public function testDateAddDaysOnNonDateTime(): void
    {
        $validator = new OperatorValidator($this->collection);

        $operator = Operator::dateAddDays(7);
        $operator->setAttribute('count'); // Non-datetime field

        $this->assertFalse($validator->isValid($operator));
        $this->assertStringContainsString('Cannot apply dateAddDays operator to non-datetime field', $validator->getDescription());
    }

    public function testDateSubDays(): void
    {
        $validator = new OperatorValidator($this->collection);

        $operator = Operator::dateSubDays(3);
        $operator->setAttribute('createdAt');

        $this->assertTrue($validator->isValid($operator), $validator->getDescription());
    }

    public function testDateSubDaysOnNonDateTime(): void
    {
        $validator = new OperatorValidator($this->collection);

        $operator = Operator::dateSubDays(3);
        $operator->setAttribute('title'); // Non-datetime field

        $this->assertFalse($validator->isValid($operator));
        $this->assertStringContainsString('Cannot apply dateSubDays operator to non-datetime field', $validator->getDescription());
    }

    public function testDateSetNow(): void
    {
        $validator = new OperatorValidator($this->collection);

        $operator = Operator::dateSetNow();
        $operator->setAttribute('createdAt');

        $this->assertTrue($validator->isValid($operator), $validator->getDescription());
    }

    // Test attribute validation
    public function testNonExistentAttribute(): void
    {
        $validator = new OperatorValidator($this->collection);

        $operator = Operator::increment(1);
        $operator->setAttribute('nonExistentField');

        $this->assertFalse($validator->isValid($operator));
        $this->assertStringContainsString("Attribute 'nonExistentField' does not exist in collection", $validator->getDescription());
    }

    // Test multiple operators as strings (like Query validator does)
    public function testMultipleStringOperators(): void
    {
        $validator = new OperatorValidator($this->collection);

        // Test various operators as JSON strings
        $operators = [
            Operator::increment(1),
            Operator::arrayAppend(['tag']),
            Operator::stringConcat(' suffix'),
            Operator::toggle(),
            Operator::dateAddDays(7),
        ];

        $attributes = ['count', 'tags', 'title', 'active', 'createdAt'];

        foreach ($operators as $index => $operator) {
            $operator->setAttribute($attributes[$index]);
            $json = $operator->toString();
            $this->assertTrue($validator->isValid($json), "Failed for operator {$attributes[$index]}: " . $validator->getDescription());
        }
    }
}
