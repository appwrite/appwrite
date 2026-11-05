<?php

namespace Utopia\Query\Tests\Builder;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Query\Builder;
use Utopia\Query\Builder\ClickHouse;
use Utopia\Query\Builder\MariaDB;
use Utopia\Query\Builder\MySQL;
use Utopia\Query\Builder\PostgreSQL;
use Utopia\Query\Builder\SQLite;
use Utopia\Query\Query;
use Utopia\Query\Tests\AssertsBindingCount;

final class LikeEscapeTest extends TestCase
{
    use AssertsBindingCount;

    /**
     * @return iterable<string, array{Builder, Query, string, list<mixed>}>
     */
    public static function predicates(): iterable
    {
        foreach (['mysql' => new MySQL(), 'mariadb' => new MariaDB()] as $name => $builder) {
            yield $name . ' contains percent' => [clone $builder, Query::containsString('c', ['50%']), 'SELECT * FROM `t` WHERE `c` LIKE ? ESCAPE \'!\'', ['%50!%%']];
            yield $name . ' starts with underscore' => [clone $builder, Query::startsWith('c', 'a_'), 'SELECT * FROM `t` WHERE `c` LIKE ? ESCAPE \'!\'', ['a!_%']];
            yield $name . ' ends with escape and backslash' => [clone $builder, Query::endsWith('c', 'x!\\'), 'SELECT * FROM `t` WHERE `c` LIKE ? ESCAPE \'!\'', ['%x!!\\']];
            yield $name . ' not starts with' => [clone $builder, Query::notStartsWith('c', '1%'), 'SELECT * FROM `t` WHERE `c` NOT LIKE ? ESCAPE \'!\'', ['1!%%']];
            yield $name . ' not contains escape' => [clone $builder, Query::notContains('c', ['!']), 'SELECT * FROM `t` WHERE `c` NOT LIKE ? ESCAPE \'!\'', ['%!!%']];
            yield $name . ' contains many' => [clone $builder, Query::containsString('c', ['a', 'b_']), 'SELECT * FROM `t` WHERE (`c` LIKE ? ESCAPE \'!\' OR `c` LIKE ? ESCAPE \'!\')', ['%a%', '%b!_%']];
            yield $name . ' contains all' => [clone $builder, Query::containsAll('c', ['a', '%']), 'SELECT * FROM `t` WHERE (`c` LIKE ? ESCAPE \'!\' AND `c` LIKE ? ESCAPE \'!\')', ['%a%', '%!%%']];
            yield $name . ' not contains many' => [clone $builder, Query::notContains('c', ['a', '_']), 'SELECT * FROM `t` WHERE (`c` NOT LIKE ? ESCAPE \'!\' AND `c` NOT LIKE ? ESCAPE \'!\')', ['%a%', '%!_%']];
        }

        yield 'sqlite contains percent' => [new SQLite(), Query::containsString('c', ['50%']), 'SELECT * FROM `t` WHERE `c` LIKE ? ESCAPE \'\\\'', ['%50\\%%']];
        yield 'sqlite starts with underscore' => [new SQLite(), Query::startsWith('c', 'a_'), 'SELECT * FROM `t` WHERE `c` LIKE ? ESCAPE \'\\\'', ['a\\_%']];
        yield 'sqlite ends with backslash' => [new SQLite(), Query::endsWith('c', 'x!\\'), 'SELECT * FROM `t` WHERE `c` LIKE ? ESCAPE \'\\\'', ['%x!\\\\']];
        yield 'sqlite not contains' => [new SQLite(), Query::notContains('c', ['_']), 'SELECT * FROM `t` WHERE `c` NOT LIKE ? ESCAPE \'\\\'', ['%\\_%']];

        yield 'postgresql contains percent' => [new PostgreSQL(), Query::containsString('c', ['50%']), 'SELECT * FROM "t" WHERE "c" ILIKE ?', ['%50\\%%']];
        yield 'postgresql starts with underscore' => [new PostgreSQL(), Query::startsWith('c', 'a_'), 'SELECT * FROM "t" WHERE "c" ILIKE ?', ['a\\_%']];
        yield 'postgresql ends with backslash' => [new PostgreSQL(), Query::endsWith('c', 'x!\\'), 'SELECT * FROM "t" WHERE "c" ILIKE ?', ['%x!\\\\']];
        yield 'postgresql not contains' => [new PostgreSQL(), Query::notContains('c', ['_']), 'SELECT * FROM "t" WHERE "c" NOT ILIKE ?', ['%\\_%']];

        yield 'clickhouse contains percent' => [new ClickHouse(), Query::containsString('c', ['50%']), 'SELECT * FROM `t` WHERE position(`c`, ?) > 0', ['50%']];
        yield 'clickhouse starts with underscore' => [new ClickHouse(), Query::startsWith('c', 'a_'), 'SELECT * FROM `t` WHERE startsWith(`c`, ?)', ['a_']];
        yield 'clickhouse ends with backslash' => [new ClickHouse(), Query::endsWith('c', 'x!\\'), 'SELECT * FROM `t` WHERE endsWith(`c`, ?)', ['x!\\']];
        yield 'clickhouse not contains' => [new ClickHouse(), Query::notContains('c', ['!']), 'SELECT * FROM `t` WHERE position(`c`, ?) = 0', ['!']];
    }

    /**
     * @param  list<mixed>  $bindings
     */
    #[DataProvider('predicates')]
    public function testPredicateDeclaresDialectEscape(Builder $builder, Query $query, string $sql, array $bindings): void
    {
        $result = $builder->from('t')->filter([$query])->build();
        $this->assertBindingCount($result);

        $this->assertSame($sql, $result->query);
        $this->assertSame($bindings, $result->bindings);
    }

    /**
     * @return iterable<string, array{MySQL, mixed, mixed}>
     */
    public static function jsonRemovals(): iterable
    {
        foreach (['mysql' => new MySQL(), 'mariadb' => new MariaDB()] as $name => $builder) {
            yield $name . ' percent' => [clone $builder, '50%', '50!%'];
            yield $name . ' underscore' => [clone $builder, 'a_b', 'a!_b'];
            yield $name . ' escape' => [clone $builder, 'wow!', 'wow!!'];
            yield $name . ' backslash' => [clone $builder, 'C:\\x', 'C:\\x'];
            yield $name . ' plain' => [clone $builder, 'tag', 'tag'];
            yield $name . ' integer' => [clone $builder, 5, '5'];
        }
    }

    #[DataProvider('jsonRemovals')]
    public function testJsonRemoveEscapesSearchPattern(MySQL $builder, mixed $value, mixed $binding): void
    {
        $result = $builder
            ->from('docs')
            ->setJsonRemove('tags', $value)
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame(
            'UPDATE `docs` SET `tags` = COALESCE(JSON_REMOVE(`tags`, JSON_UNQUOTE(JSON_SEARCH(`tags`, \'one\', ?, \'!\'))), `tags`) WHERE `id` IN (?)',
            $result->query,
        );
        $this->assertSame([$binding, 1], $result->bindings);
    }

    /**
     * @return iterable<string, array{bool, string}>
     */
    public static function clickHouseLikeFallbacks(): iterable
    {
        yield 'like' => [false, '`c` LIKE ?'];
        yield 'not like' => [true, '`c` NOT LIKE ?'];
    }

    #[DataProvider('clickHouseLikeFallbacks')]
    public function testClickHouseLikeFallbackOmitsEscapeClause(bool $not, string $sql): void
    {
        $builder = new class () extends ClickHouse {
            /**
             * @return array{string, list<mixed>}
             */
            public function like(bool $not): array
            {
                return [$this->compileLike('`c`', ['a_%'], '%', '%', $not), $this->getBindingValues()];
            }
        };

        $this->assertSame([$sql, ['%a\\_\\%%']], $builder->like($not));
    }
}
