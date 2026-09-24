<?php

namespace Tests\Query\Builder;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Builder\Condition;

class ConditionTest extends TestCase
{
    public function testGetExpression(): void
    {
        $condition = new Condition('status = ?', ['active']);
        $this->assertSame('status = ?', $condition->expression);
    }

    public function testGetBindings(): void
    {
        $condition = new Condition('status = ?', ['active']);
        $this->assertSame(['active'], $condition->bindings);
    }

    public function testEmptyBindings(): void
    {
        $condition = new Condition('1 = 1');
        $this->assertSame('1 = 1', $condition->expression);
        $this->assertSame([], $condition->bindings);
    }

    public function testMultipleBindings(): void
    {
        $condition = new Condition('age BETWEEN ? AND ?', [18, 65]);
        $this->assertSame('age BETWEEN ? AND ?', $condition->expression);
        $this->assertSame([18, 65], $condition->bindings);
    }

    public function testPropertiesAreReadonly(): void
    {
        $condition = new Condition('x = ?', [1]);

        $ref = new \ReflectionClass($condition);
        $this->assertTrue($ref->isReadOnly());
        $this->assertTrue($ref->getProperty('expression')->isReadOnly());
        $this->assertTrue($ref->getProperty('bindings')->isReadOnly());
    }

    public function testExpressionPropertyNotWritable(): void
    {
        $condition = new Condition('x = ?', [1]);

        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line */
        $condition->expression = 'y = ?';
    }

    public function testBindingsPropertyNotWritable(): void
    {
        $condition = new Condition('x = ?', [1]);

        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line */
        $condition->bindings = [2];
    }

    public function testSingleBinding(): void
    {
        $condition = new Condition('id = ?', [42]);
        $this->assertSame('id = ?', $condition->expression);
        $this->assertSame([42], $condition->bindings);
    }

    public function testBindingsPreserveTypes(): void
    {
        $condition = new Condition('a = ? AND b = ? AND c = ?', [1, 'two', 3.0]);
        $this->assertIsInt($condition->bindings[0]);
        $this->assertIsString($condition->bindings[1]);
        $this->assertIsFloat($condition->bindings[2]);
    }
}
