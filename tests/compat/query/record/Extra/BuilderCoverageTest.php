<?php

declare(strict_types=1);

namespace Utopia\Query\Tests\Extra;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Builder\Case\Expression as CaseExpression;
use Utopia\Query\Builder\Case\Operator;
use Utopia\Query\Builder\ClickHouse;
use Utopia\Query\Builder\LockMode;
use Utopia\Query\Builder\MariaDB;
use Utopia\Query\Builder\MongoDB;
use Utopia\Query\Builder\MySQL;
use Utopia\Query\Builder\PostgreSQL;
use Utopia\Query\Builder\SQLite;
use Utopia\Query\Builder\Statement;
use Utopia\Query\Builder\VectorMetric;
use Utopia\Query\Builder\WindowFrame;
use Utopia\Query\Method;
use Utopia\Query\Query;

/**
 * Calls the builder API the ported PHP tests leave out, so every public
 * symbol has a compat case (see tests/compat/query/record/record.php).
 */
final class BuilderCoverageTest extends TestCase
{
    public function testExecutorOnSelect(): void
    {
        $builder = (new MySQL())
            ->from('users')
            ->setExecutor(fn (Statement $statement) => [['id' => 1, 'sql' => $statement->query]]);
        $rows = $builder->execute();
        $this->assertSame(1, $rows[0]['id']);

        $count = (new PostgreSQL())
            ->from('users')
            ->setExecutor(fn (Statement $statement) => \count($statement->bindings))
            ->filter([Query::equal('id', [1, 2])])
            ->execute();
        $this->assertSame(2, $count);
    }

    public function testExecuteWithoutExecutor(): void
    {
        $this->expectException(\BadMethodCallException::class);
        (new SQLite())->from('users')->execute();
    }

    public function testFetch(): void
    {
        $this->assertNotSame('', (new PostgreSQL())->from('users')->sortAsc('id')->fetch(5)->build()->query);
        $this->assertNotSame('', (new PostgreSQL())->from('users')->sortAsc('id')->fetch(5, true)->build()->query);
        $this->assertNotSame('', (new MySQL())->from('users')->fetch(3)->build()->query);
        $this->assertNotSame('', (new SQLite())->from('users')->fetch(0)->build()->query);
    }

    public function testStatementExecutor(): void
    {
        $statement = (new MySQL())->from('users')->filter([Query::equal('id', [7])])->build();
        $executed = $statement->withExecutor(fn (Statement $s) => [['bindings' => $s->bindings]]);
        $this->assertSame([['bindings' => [7]]], $executed->execute());
    }

    public function testStatementExecuteWithoutExecutor(): void
    {
        $this->expectException(\BadMethodCallException::class);
        (new MySQL())->from('users')->build()->execute();
    }

    public function testInsertDefaultValues(): void
    {
        $this->assertNotSame('', (new MySQL())->into('logs')->insertDefaultValues()->query);
        $this->assertNotSame('', (new MariaDB())->into('logs')->insertDefaultValues()->query);
        $this->assertNotSame('', (new PostgreSQL())->into('logs')->insertDefaultValues()->query);
        $this->assertNotSame('', (new SQLite())->into('logs')->insertDefaultValues()->query);
    }

    public function testInsertDefaultValuesClickHouse(): void
    {
        $this->expectException(\Throwable::class);
        (new ClickHouse())->into('logs')->insertDefaultValues();
    }

    public function testInsertDefaultValuesMongoDB(): void
    {
        $this->expectException(\Throwable::class);
        (new MongoDB())->into('logs')->insertDefaultValues();
    }

    public function testInsertDefaultValuesWithoutTable(): void
    {
        $this->expectException(\Throwable::class);
        (new SQLite())->insertDefaultValues();
    }

    public function testCaseExpression(): void
    {
        $case = (new CaseExpression())
            ->whenNull('deleted_at', 'live')
            ->whenNotNull('archived_at', 'archived')
            ->whenIn('status', ['a', 'b'], 'grouped')
            ->else('other')
            ->alias('state');
        $this->assertCount(3, $case->getWhens());
        $this->assertTrue($case->hasElse());
        $this->assertSame('other', $case->getElse());
        $this->assertSame('state', $case->getAlias());
        $this->assertNotSame('', (new MySQL())->from('t')->selectCase($case)->build()->query);

        $empty = new CaseExpression();
        $this->assertFalse($empty->hasElse());
        $this->assertNull($empty->getElse());
        $this->assertSame('', $empty->getAlias());
        $this->assertSame([], $empty->getWhens());
    }

    public function testCaseWhenInEmpty(): void
    {
        $this->expectException(\Throwable::class);
        (new CaseExpression())->whenIn('status', [], 'none');
    }

    public function testCaseOperators(): void
    {
        foreach (Operator::cases() as $operator) {
            $this->assertNotSame('', $operator->sqlOperator());
        }
    }

    public function testEnumHelpers(): void
    {
        foreach (LockMode::cases() as $mode) {
            $this->assertNotSame('', $mode->toSql());
        }
        foreach (VectorMetric::cases() as $metric) {
            $this->assertNotSame('', $metric->toOperator());
        }
        $this->assertTrue(Method::And->isNested());
        $this->assertTrue(Method::Or->isNested());
        $this->assertFalse(Method::Equal->isNested());
        $this->assertTrue(Method::Having->isNested());
    }

    public function testWindowFrame(): void
    {
        $this->assertSame('ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW', (new WindowFrame('ROWS', 'UNBOUNDED PRECEDING', 'CURRENT ROW'))->toSql());
        $this->assertSame('RANGE CURRENT ROW', (new WindowFrame('RANGE', 'CURRENT ROW'))->toSql());
    }

    public function testQueryFactories(): void
    {
        $contains = Query::contains('tags', ['a', 'b']);
        $this->assertSame(Method::Contains, $contains->getMethod());
        $this->assertNotSame('', (new MySQL())->from('t')->filter([$contains])->build()->query);
        $distinct = Query::countDistinct('user_id', 'users');
        $this->assertSame('users', $distinct->getAlias());
        $this->assertNotSame('', (new MySQL())->from('t')->queries([Query::countDistinct('user_id')])->build()->query);
    }

    public function testClickHouseInheritedMethods(): void
    {
        $result = (new ClickHouse())->from('a')->naturalJoin('b')->naturalJoin('c', 'cc')->build();
        $this->assertNotSame('', $result->query);
    }

    public function testClickHouseInsertColumnExpression(): void
    {
        $result = (new ClickHouse())
            ->into('events')
            ->set(['id' => 1, 'at' => 'now'])
            ->insertColumnExpression('at', 'parseDateTimeBestEffort(?)', [])
            ->insert();
        $this->assertNotSame('', $result->query);
    }

    public function testClickHouseConflictSetRaw(): void
    {
        $result = (new ClickHouse())->into('events')->set(['id' => 1])->conflictSetRaw('count', 'count + ?', [1])->insert();
        $this->assertNotSame('', $result->query);
    }

    public function testAggregates(): void
    {
        foreach ([new MySQL(), new MariaDB(), new PostgreSQL(), new SQLite(), new ClickHouse()] as $builder) {
            $result = $builder
                ->from('t')
                ->bitOr('flags', 'any_flags')
                ->bitXor('flags')
                ->build();
            $this->assertNotSame('', $result->query);
        }
    }

    public function testJsonAggregates(): void
    {
        foreach ([new MySQL(), new PostgreSQL(), new SQLite()] as $builder) {
            $result = $builder
                ->from('t')
                ->jsonArrayAgg('name', 'names')
                ->jsonObjectAgg('id', 'name', 'map')
                ->jsonArrayAgg('email')
                ->build();
            $this->assertNotSame('', $result->query);
        }
    }

    public function testGroupConcat(): void
    {
        foreach ([new PostgreSQL(), new SQLite()] as $builder) {
            $result = $builder
                ->from('t')
                ->groupConcat('name', ';', 'names', ['name'])
                ->groupConcat('email')
                ->groupBy(['team'])
                ->build();
            $this->assertNotSame('', $result->query);
        }
    }

    public function testPostgreSQLCompileFilter(): void
    {
        $builder = (new PostgreSQL())->from('t');
        $this->assertNotSame('', $builder->compileFilter(Query::equal('id', [1, 2])));
        $this->assertNotSame('', $builder->compileFilter(Query::search('body', 'hello')));
        $this->assertNotSame('', $builder->compileFilter(Query::regex('name', '^a')));
        $this->assertNotSame('', $builder->compileFilter(Query::greaterThan('age', 18)));
    }

    public function testPostgreSQLWithCube(): void
    {
        $result = (new PostgreSQL())->from('sales')->count('*', 'n')->groupBy(['region', 'year'])->withCube()->build();
        $this->assertStringContainsString('CUBE', $result->query);
    }

    public function testSQLiteSetJsonPath(): void
    {
        $result = (new SQLite())
            ->from('docs')
            ->setJsonPath('data', '$.name', 'NewValue')
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertNotSame('', $result->query);
    }

    public function testSQLiteSetJsonPathInvalid(): void
    {
        $this->expectException(\Throwable::class);
        (new SQLite())->from('docs')->setJsonPath('data', 'name', 'NewValue');
    }

    public function testSQLiteUpsertSelect(): void
    {
        $source = (new SQLite())->from('staging')->select(['id', 'name']);
        $result = (new SQLite())
            ->into('users')
            ->fromSelect(['id', 'name'], $source)
            ->onConflict(['id'], ['name'])
            ->upsertSelect();
        $this->assertNotSame('', $result->query);
    }

    public function testSQLiteUpsertSelectWithoutSource(): void
    {
        $this->expectException(\Throwable::class);
        (new SQLite())->into('users')->onConflict(['id'], ['name'])->upsertSelect();
    }
}
