<?php

namespace Utopia\Query\Tests\Extra;

use PHPUnit\Framework\TestCase;
use Utopia\Query\AST\Expression\Binary;
use Utopia\Query\AST\Literal;
use Utopia\Query\AST\Reference\Column;
use Utopia\Query\AST\Reference\Table;
use Utopia\Query\AST\Star;
use Utopia\Query\AST\Statement\Select;
use Utopia\Query\AST\Visitor\ColumnValidator;
use Utopia\Query\AST\Visitor\FilterInjector;
use Utopia\Query\AST\Visitor\TableRenamer;
use Utopia\Query\Exception;

/**
 * Calls each library visitor's methods directly (the ported tests only run
 * them through the Walker).
 */
class VisitorCoverageTest extends TestCase
{
    public function testColumnValidator(): void
    {
        $validator = new ColumnValidator(['id', 'name'], allowStar: true);
        $column = new Column('id', 'users');
        $this->assertEquals($column, $validator->visitExpression($column));
        $this->assertEquals(new Star(), $validator->visitExpression(new Star()));
        $this->assertEquals(new Literal(1), $validator->visitExpression(new Literal(1)));
        $table = new Table('users', 'u');
        $this->assertEquals($table, $validator->visitTableReference($table));
        $select = new Select(columns: [new Column('id')], from: $table);
        $this->assertEquals($select, $validator->visitSelect($select));
    }

    public function testColumnValidatorRejectsColumn(): void
    {
        $this->expectException(Exception::class);
        (new ColumnValidator(['id']))->visitExpression(new Column('secret'));
    }

    public function testColumnValidatorRejectsStar(): void
    {
        $this->expectException(Exception::class);
        (new ColumnValidator(['id']))->visitExpression(new Star('users'));
    }

    public function testFilterInjector(): void
    {
        $condition = new Binary(new Column('tenant'), '=', new Literal(7));
        $injector = new FilterInjector($condition);
        $column = new Column('id');
        $this->assertEquals($column, $injector->visitExpression($column));
        $table = new Table('users');
        $this->assertEquals($table, $injector->visitTableReference($table));
        $plain = $injector->visitSelect(new Select(columns: [new Star()], from: $table));
        $this->assertEquals($condition, $plain->where);
        $filtered = $injector->visitSelect(new Select(
            columns: [new Star()],
            from: $table,
            where: new Binary(new Column('active'), '=', new Literal(true)),
        ));
        $this->assertSame('AND', $filtered->where->operator);
    }

    public function testTableRenamer(): void
    {
        $renamer = new TableRenamer(['users' => 'accounts', 'u' => 'a']);
        $this->assertEquals(new Column('id', 'accounts', 'app'), $renamer->visitExpression(new Column('id', 'users', 'app')));
        $this->assertEquals(new Column('id'), $renamer->visitExpression(new Column('id')));
        $this->assertEquals(new Column('id', 'other'), $renamer->visitExpression(new Column('id', 'other')));
        $this->assertEquals(new Star('a'), $renamer->visitExpression(new Star('u')));
        $this->assertEquals(new Star(), $renamer->visitExpression(new Star()));
        $this->assertEquals(new Literal('x'), $renamer->visitExpression(new Literal('x')));
        $this->assertEquals(new Table('accounts', 'a', 'app'), $renamer->visitTableReference(new Table('users', 'u', 'app')));
        $this->assertEquals(new Table('orders', 'a'), $renamer->visitTableReference(new Table('orders', 'u')));
        $this->assertEquals(new Table('orders'), $renamer->visitTableReference(new Table('orders')));
        $select = new Select(columns: [new Star()], from: new Table('users'));
        $this->assertEquals($select, $renamer->visitSelect($select));
    }
}
