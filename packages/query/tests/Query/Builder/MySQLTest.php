<?php

namespace Tests\Query\Builder;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Tests\Query\Fixture\PermissionFilter as Permission;
use Utopia\Query\Builder\Case\Expression as CaseExpression;
use Utopia\Query\Builder\Case\Operator;
use Utopia\Query\Builder\Condition;
use Utopia\Query\Builder\Feature\Aggregates;
use Utopia\Query\Builder\Feature\CTEs;
use Utopia\Query\Builder\Feature\Cube;
use Utopia\Query\Builder\Feature\Deletes;
use Utopia\Query\Builder\Feature\Hints;
use Utopia\Query\Builder\Feature\Hooks;
use Utopia\Query\Builder\Feature\Inserts;
use Utopia\Query\Builder\Feature\Joins;
use Utopia\Query\Builder\Feature\Json;
use Utopia\Query\Builder\Feature\Locking;
use Utopia\Query\Builder\Feature\PostgreSQL\VectorSearch;
use Utopia\Query\Builder\Feature\Rollup;
use Utopia\Query\Builder\Feature\Selects;
use Utopia\Query\Builder\Feature\Spatial;
use Utopia\Query\Builder\Feature\Totals;
use Utopia\Query\Builder\Feature\Transactions;
use Utopia\Query\Builder\Feature\Unions;
use Utopia\Query\Builder\Feature\Updates;
use Utopia\Query\Builder\Feature\Upsert;
use Utopia\Query\Builder\Feature\Windows;
use Utopia\Query\Builder\JoinBuilder;
use Utopia\Query\Builder\JoinType;
use Utopia\Query\Builder\MySQL as Builder;
use Utopia\Query\Builder\Statement;
use Utopia\Query\Compiler;
use Utopia\Query\Exception\UnsupportedException;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Hook;
use Utopia\Query\Hook\Attribute;
use Utopia\Query\Hook\Attribute\Map as AttributeMap;
use Utopia\Query\Hook\Filter;
use Utopia\Query\Hook\Filter\Tenant;
use Utopia\Query\Method;
use Utopia\Query\Query;
use Utopia\Query\Schema\Index;

class MySQLTest extends TestCase
{
    use AssertsBindingCount;
    public function testImplementsCompiler(): void
    {
        $builder = new Builder();
        $this->assertInstanceOf(Compiler::class, $builder);
    }

    public function testImplementsRollup(): void
    {
        $this->assertInstanceOf(Rollup::class, new Builder());
    }

    public function testDoesNotImplementCubeOrTotals(): void
    {
        $interfaces = \class_implements(new Builder());

        $this->assertArrayNotHasKey(Cube::class, $interfaces);
        $this->assertArrayNotHasKey(Totals::class, $interfaces);
    }

    public function testImplementsTransactions(): void
    {
        $this->assertInstanceOf(Transactions::class, new Builder());
    }

    public function testImplementsLocking(): void
    {
        $this->assertInstanceOf(Locking::class, new Builder());
    }

    public function testImplementsUpsert(): void
    {
        $this->assertInstanceOf(Upsert::class, new Builder());
    }

    public function testImplementsSelects(): void
    {
        $this->assertInstanceOf(Selects::class, new Builder());
    }

    public function testImplementsAggregates(): void
    {
        $this->assertInstanceOf(Aggregates::class, new Builder());
    }

    public function testImplementsJoins(): void
    {
        $this->assertInstanceOf(Joins::class, new Builder());
    }

    public function testImplementsUnions(): void
    {
        $this->assertInstanceOf(Unions::class, new Builder());
    }

    public function testImplementsCTEs(): void
    {
        $this->assertInstanceOf(CTEs::class, new Builder());
    }

    public function testImplementsInserts(): void
    {
        $this->assertInstanceOf(Inserts::class, new Builder());
    }

    public function testImplementsUpdates(): void
    {
        $this->assertInstanceOf(Updates::class, new Builder());
    }

    public function testImplementsDeletes(): void
    {
        $this->assertInstanceOf(Deletes::class, new Builder());
    }

    public function testImplementsHooks(): void
    {
        $this->assertInstanceOf(Hooks::class, new Builder());
    }

    public function testStandaloneCompile(): void
    {
        $builder = new Builder();

        $filter = Query::greaterThan('age', 18);
        $sql = $filter->compile($builder);
        $this->assertSame('`age` > ?', $sql);
        $this->assertSame([18], $builder->getBindings());
    }

    public function testFluentSelectFromFilterSortLimitOffset(): void
    {
        $result = (new Builder())
            ->select(['name', 'email'])
            ->from('users')
            ->filter([
                Query::equal('status', ['active']),
                Query::greaterThan('age', 18),
            ])
            ->sortAsc('name')
            ->limit(25)
            ->offset(0)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `name`, `email` FROM `users` WHERE `status` IN (?) AND `age` > ? ORDER BY `name` ASC LIMIT ? OFFSET ?',
            $result->query
        );
        $this->assertSame(['active', 18, 25, 0], $result->bindings);
    }

    public function testBatchModeProducesSameOutput(): void
    {
        $result = (new Builder())
            ->from('users')
            ->queries([
                Query::select(['name', 'email']),
                Query::equal('status', ['active']),
                Query::greaterThan('age', 18),
                Query::orderAsc('name'),
                Query::limit(25),
                Query::offset(0),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `name`, `email` FROM `users` WHERE `status` IN (?) AND `age` > ? ORDER BY `name` ASC LIMIT ? OFFSET ?',
            $result->query
        );
        $this->assertSame(['active', 18, 25, 0], $result->bindings);
    }

    public function testEqual(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('status', ['active', 'pending'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `status` IN (?, ?)', $result->query);
        $this->assertSame(['active', 'pending'], $result->bindings);
    }

    public function testNotEqualSingle(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notEqual('role', 'guest')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `role` != ?', $result->query);
        $this->assertSame(['guest'], $result->bindings);
    }

    public function testNotEqualMultiple(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notEqual('role', ['guest', 'banned'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `role` NOT IN (?, ?)', $result->query);
        $this->assertSame(['guest', 'banned'], $result->bindings);
    }

    public function testLessThan(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::lessThan('price', 100)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `price` < ?', $result->query);
        $this->assertSame([100], $result->bindings);
    }

    public function testLessThanEqual(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::lessThanEqual('price', 100)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `price` <= ?', $result->query);
        $this->assertSame([100], $result->bindings);
    }

    public function testGreaterThan(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::greaterThan('age', 18)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `age` > ?', $result->query);
        $this->assertSame([18], $result->bindings);
    }

    public function testGreaterThanEqual(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::greaterThanEqual('score', 90)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `score` >= ?', $result->query);
        $this->assertSame([90], $result->bindings);
    }

    public function testBetween(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::between('age', 18, 65)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `age` BETWEEN ? AND ?', $result->query);
        $this->assertSame([18, 65], $result->bindings);
    }

    public function testNotBetween(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notBetween('age', 18, 65)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `age` NOT BETWEEN ? AND ?', $result->query);
        $this->assertSame([18, 65], $result->bindings);
    }

    public function testStartsWith(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::startsWith('name', 'Jo')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `name` LIKE ?', $result->query);
        $this->assertSame(['Jo%'], $result->bindings);
    }

    public function testNotStartsWith(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notStartsWith('name', 'Jo')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `name` NOT LIKE ?', $result->query);
        $this->assertSame(['Jo%'], $result->bindings);
    }

    public function testEndsWith(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::endsWith('email', '.com')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `email` LIKE ?', $result->query);
        $this->assertSame(['%.com'], $result->bindings);
    }

    public function testNotEndsWith(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notEndsWith('email', '.com')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `email` NOT LIKE ?', $result->query);
        $this->assertSame(['%.com'], $result->bindings);
    }

    public function testContainsSingle(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::containsString('bio', ['php'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `bio` LIKE ?', $result->query);
        $this->assertSame(['%php%'], $result->bindings);
    }

    public function testContainsMultiple(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::containsString('bio', ['php', 'js'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`bio` LIKE ? OR `bio` LIKE ?)', $result->query);
        $this->assertSame(['%php%', '%js%'], $result->bindings);
    }

    public function testContainsAny(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::containsAny('tags', ['a', 'b'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`tags` LIKE ? OR `tags` LIKE ?)', $result->query);
        $this->assertSame(['%a%', '%b%'], $result->bindings);
    }

    public function testContainsAll(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::containsAll('perms', ['read', 'write'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`perms` LIKE ? AND `perms` LIKE ?)', $result->query);
        $this->assertSame(['%read%', '%write%'], $result->bindings);
    }

    public function testNotContainsSingle(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notContains('bio', ['php'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `bio` NOT LIKE ?', $result->query);
        $this->assertSame(['%php%'], $result->bindings);
    }

    public function testNotContainsMultiple(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notContains('bio', ['php', 'js'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`bio` NOT LIKE ? AND `bio` NOT LIKE ?)', $result->query);
        $this->assertSame(['%php%', '%js%'], $result->bindings);
    }

    public function testSearch(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::search('content', 'hello')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE MATCH(`content`) AGAINST(? IN BOOLEAN MODE)', $result->query);
        $this->assertSame(['hello*'], $result->bindings);
    }

    public function testNotSearch(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notSearch('content', 'hello')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE NOT (MATCH(`content`) AGAINST(? IN BOOLEAN MODE))', $result->query);
        $this->assertSame(['hello*'], $result->bindings);
    }

    public function testRegex(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::regex('slug', '^[a-z]+$')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `slug` REGEXP ?', $result->query);
        $this->assertSame(['^[a-z]+$'], $result->bindings);
    }

    public function testIsNull(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::isNull('deleted')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `deleted` IS NULL', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testIsNotNull(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::isNotNull('verified')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `verified` IS NOT NULL', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testExists(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::exists(['name', 'email'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`name` IS NOT NULL AND `email` IS NOT NULL)', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testNotExists(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notExists(['legacy'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`legacy` IS NULL)', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testAndLogical(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::and([
                    Query::greaterThan('age', 18),
                    Query::equal('status', ['active']),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`age` > ? AND `status` IN (?))', $result->query);
        $this->assertSame([18, 'active'], $result->bindings);
    }

    public function testOrLogical(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::or([
                    Query::equal('role', ['admin']),
                    Query::equal('role', ['mod']),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`role` IN (?) OR `role` IN (?))', $result->query);
        $this->assertSame(['admin', 'mod'], $result->bindings);
    }

    public function testDeeplyNested(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::and([
                    Query::greaterThan('age', 18),
                    Query::or([
                        Query::equal('role', ['admin']),
                        Query::equal('role', ['mod']),
                    ]),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE (`age` > ? AND (`role` IN (?) OR `role` IN (?)))',
            $result->query
        );
        $this->assertSame([18, 'admin', 'mod'], $result->bindings);
    }

    public function testSortAsc(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sortAsc('name')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` ORDER BY `name` ASC', $result->query);
    }

    public function testSortDesc(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sortDesc('score')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` ORDER BY `score` DESC', $result->query);
    }

    public function testSortRandom(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sortRandom()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` ORDER BY RAND()', $result->query);
    }

    public function testMultipleSorts(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sortAsc('name')
            ->sortDesc('age')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` ORDER BY `name` ASC, `age` DESC', $result->query);
    }

    public function testLimitOnly(): void
    {
        $result = (new Builder())
            ->from('t')
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` LIMIT ?', $result->query);
        $this->assertSame([10], $result->bindings);
    }

    public function testOffsetOnly(): void
    {
        // OFFSET without LIMIT is invalid in MySQL/ClickHouse; the builder refuses it.
        $this->expectException(ValidationException::class);
        (new Builder())
            ->from('t')
            ->offset(50)
            ->build();
    }


    public function testCursorAfter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->cursorAfter('abc123')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `_cursor` > ?', $result->query);
        $this->assertSame(['abc123'], $result->bindings);
    }

    public function testCursorBefore(): void
    {
        $result = (new Builder())
            ->from('t')
            ->cursorBefore('xyz789')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `_cursor` < ?', $result->query);
        $this->assertSame(['xyz789'], $result->bindings);
    }

    public function testFullCombinedQuery(): void
    {
        $result = (new Builder())
            ->select(['id', 'name'])
            ->from('users')
            ->filter([
                Query::equal('status', ['active']),
                Query::greaterThan('age', 18),
            ])
            ->sortAsc('name')
            ->sortDesc('age')
            ->limit(25)
            ->offset(10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `name` FROM `users` WHERE `status` IN (?) AND `age` > ? ORDER BY `name` ASC, `age` DESC LIMIT ? OFFSET ?',
            $result->query
        );
        $this->assertSame(['active', 18, 25, 10], $result->bindings);
    }

    public function testMultipleFilterCalls(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('a', [1])])
            ->filter([Query::equal('b', [2])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `a` IN (?) AND `b` IN (?)', $result->query);
        $this->assertSame([1, 2], $result->bindings);
    }

    public function testResetClearsState(): void
    {
        $builder = (new Builder())
            ->select(['name'])
            ->from('users')
            ->filter([Query::equal('x', [1])])
            ->limit(10);

        $builder->build();

        $builder->reset();

        $result = $builder
            ->from('orders')
            ->filter([Query::greaterThan('total', 100)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `orders` WHERE `total` > ?', $result->query);
        $this->assertSame([100], $result->bindings);
    }

    public function testAttributeResolver(): void
    {
        $result = (new Builder())
            ->from('users')
            ->addHook(new AttributeMap([
                '$id' => '_uid',
                '$createdAt' => '_createdAt',
            ]))
            ->filter([Query::equal('$id', ['abc'])])
            ->sortAsc('$createdAt')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `users` WHERE `_uid` IN (?) ORDER BY `_createdAt` ASC',
            $result->query
        );
        $this->assertSame(['abc'], $result->bindings);
    }

    public function testMultipleAttributeHooksChain(): void
    {
        $prefixHook = new class () implements Attribute {
            public function resolve(string $attribute): string
            {
                return 'col_' . $attribute;
            }
        };

        $result = (new Builder())
            ->from('t')
            ->addHook(new AttributeMap(['name' => 'full_name']))
            ->addHook($prefixHook)
            ->filter([Query::equal('name', ['Alice'])])
            ->build();
        $this->assertBindingCount($result);

        // First hook maps name→full_name, second prepends col_
        $this->assertSame(
            'SELECT * FROM `t` WHERE `col_full_name` IN (?)',
            $result->query
        );
    }

    public function testDualInterfaceHook(): void
    {
        $hook = new class () implements Filter, Attribute {
            public function filter(string $table): Condition
            {
                return new Condition('_tenant = ?', ['t1']);
            }

            public function resolve(string $attribute): string
            {
                return match ($attribute) {
                    '$id' => '_uid',
                    default => $attribute,
                };
            }
        };

        $result = (new Builder())
            ->from('users')
            ->addHook($hook)
            ->filter([Query::equal('$id', ['abc'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `users` WHERE `_uid` IN (?) AND _tenant = ?',
            $result->query
        );
        $this->assertSame(['abc', 't1'], $result->bindings);
    }

    public function testConditionProvider(): void
    {
        $hook = new class () implements Filter {
            public function filter(string $table): Condition
            {
                return new Condition(
                    "_uid IN (SELECT _document FROM {$table}_perms WHERE _type = 'read')",
                );
            }
        };

        $result = (new Builder())
            ->from('users')
            ->addHook($hook)
            ->filter([Query::equal('status', ['active'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            "SELECT * FROM `users` WHERE `status` IN (?) AND _uid IN (SELECT _document FROM users_perms WHERE _type = 'read')",
            $result->query
        );
        $this->assertSame(['active'], $result->bindings);
    }

    public function testConditionProviderWithBindings(): void
    {
        $hook = new class () implements Filter {
            public function filter(string $table): Condition
            {
                return new Condition('_tenant = ?', ['tenant_abc']);
            }
        };

        $result = (new Builder())
            ->from('docs')
            ->addHook($hook)
            ->filter([Query::equal('status', ['active'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `docs` WHERE `status` IN (?) AND _tenant = ?',
            $result->query
        );
        // filter bindings first, then hook bindings
        $this->assertSame(['active', 'tenant_abc'], $result->bindings);
    }

    public function testBindingOrderingWithProviderAndCursor(): void
    {
        $hook = new class () implements Filter {
            public function filter(string $table): Condition
            {
                return new Condition('_tenant = ?', ['t1']);
            }
        };

        $result = (new Builder())
            ->from('docs')
            ->addHook($hook)
            ->filter([Query::equal('status', ['active'])])
            ->cursorAfter('cursor_val')
            ->limit(10)
            ->offset(5)
            ->build();
        $this->assertBindingCount($result);

        // binding order: filter, hook, cursor, limit, offset
        $this->assertSame(['active', 't1', 'cursor_val', 10, 5], $result->bindings);
    }

    public function testDefaultSelectStar(): void
    {
        $result = (new Builder())
            ->from('t')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t`', $result->query);
    }

    public function testCountStar(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) FROM `t`', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testCountWithAlias(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count('*', 'total')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `total` FROM `t`', $result->query);
    }

    public function testSumColumn(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->sum('price', 'total_price')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT SUM(`price`) AS `total_price` FROM `orders`', $result->query);
    }

    public function testAvgColumn(): void
    {
        $result = (new Builder())
            ->from('t')
            ->avg('score')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT AVG(`score`) FROM `t`', $result->query);
    }

    public function testMinColumn(): void
    {
        $result = (new Builder())
            ->from('t')
            ->min('price')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT MIN(`price`) FROM `t`', $result->query);
    }

    public function testMaxColumn(): void
    {
        $result = (new Builder())
            ->from('t')
            ->max('price')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT MAX(`price`) FROM `t`', $result->query);
    }

    public function testAggregationWithSelection(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'total')
            ->select(['status'])
            ->groupBy(['status'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT COUNT(*) AS `total`, `status` FROM `orders` GROUP BY `status`',
            $result->query
        );
    }

    public function testGroupBy(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'total')
            ->groupBy(['status'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT COUNT(*) AS `total` FROM `orders` GROUP BY `status`',
            $result->query
        );
    }

    public function testGroupByMultiple(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'total')
            ->groupBy(['status', 'country'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT COUNT(*) AS `total` FROM `orders` GROUP BY `status`, `country`',
            $result->query
        );
    }

    public function testHaving(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'total')
            ->groupBy(['status'])
            ->having([Query::greaterThan('total', 5)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT COUNT(*) AS `total` FROM `orders` GROUP BY `status` HAVING COUNT(*) > ?',
            $result->query
        );
        $this->assertSame([5], $result->bindings);
    }

    public function testDistinct(): void
    {
        $result = (new Builder())
            ->from('t')
            ->distinct()
            ->select(['status'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT DISTINCT `status` FROM `t`', $result->query);
    }

    public function testDistinctStar(): void
    {
        $result = (new Builder())
            ->from('t')
            ->distinct()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT DISTINCT * FROM `t`', $result->query);
    }

    public function testJoin(): void
    {
        $result = (new Builder())
            ->from('users')
            ->join('orders', 'users.id', 'orders.user_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `users` JOIN `orders` ON `users`.`id` = `orders`.`user_id`',
            $result->query
        );
    }

    public function testLeftJoin(): void
    {
        $result = (new Builder())
            ->from('users')
            ->leftJoin('profiles', 'users.id', 'profiles.user_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `users` LEFT JOIN `profiles` ON `users`.`id` = `profiles`.`user_id`',
            $result->query
        );
    }

    public function testRightJoin(): void
    {
        $result = (new Builder())
            ->from('users')
            ->rightJoin('orders', 'users.id', 'orders.user_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `users` RIGHT JOIN `orders` ON `users`.`id` = `orders`.`user_id`',
            $result->query
        );
    }

    public function testCrossJoin(): void
    {
        $result = (new Builder())
            ->from('sizes')
            ->crossJoin('colors')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `sizes` CROSS JOIN `colors`',
            $result->query
        );
    }

    public function testJoinWithFilter(): void
    {
        $result = (new Builder())
            ->from('users')
            ->join('orders', 'users.id', 'orders.user_id')
            ->filter([Query::greaterThan('orders.total', 100)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `users` JOIN `orders` ON `users`.`id` = `orders`.`user_id` WHERE `orders`.`total` > ?',
            $result->query
        );
        $this->assertSame([100], $result->bindings);
    }

    public function testRawFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::raw('score > ? AND score < ?', [10, 100])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE score > ? AND score < ?', $result->query);
        $this->assertSame([10, 100], $result->bindings);
    }

    public function testRawFilterNoBindings(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::raw('1 = 1')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE 1 = 1', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testUnion(): void
    {
        $admins = (new Builder())->from('admins')->filter([Query::equal('role', ['admin'])]);
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('status', ['active'])])
            ->union($admins)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            '(SELECT * FROM `users` WHERE `status` IN (?)) UNION (SELECT * FROM `admins` WHERE `role` IN (?))',
            $result->query
        );
        $this->assertSame(['active', 'admin'], $result->bindings);
    }

    public function testUnionAll(): void
    {
        $other = (new Builder())->from('archive');
        $result = (new Builder())
            ->from('current')
            ->unionAll($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            '(SELECT * FROM `current`) UNION ALL (SELECT * FROM `archive`)',
            $result->query
        );
    }

    public function testWhenTrue(): void
    {
        $result = (new Builder())
            ->from('t')
            ->when(true, fn (Builder $b) => $b->filter([Query::equal('status', ['active'])]))
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `status` IN (?)', $result->query);
        $this->assertSame(['active'], $result->bindings);
    }

    public function testWhenFalse(): void
    {
        $result = (new Builder())
            ->from('t')
            ->when(false, fn (Builder $b) => $b->filter([Query::equal('status', ['active'])]))
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t`', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testPage(): void
    {
        $result = (new Builder())
            ->from('t')
            ->page(3, 10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` LIMIT ? OFFSET ?', $result->query);
        $this->assertSame([10, 20], $result->bindings);
    }

    public function testPageDefaultPerPage(): void
    {
        $result = (new Builder())
            ->from('t')
            ->page(1)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` LIMIT ? OFFSET ?', $result->query);
        $this->assertSame([25, 0], $result->bindings);
    }

    public function testToRawSql(): void
    {
        $sql = (new Builder())
            ->from('users')
            ->filter([Query::equal('status', ['active'])])
            ->limit(10)
            ->toRawSql();

        $this->assertSame(
            "SELECT * FROM `users` WHERE `status` IN ('active') LIMIT 10",
            $sql
        );
    }

    public function testToRawSqlNumericBindings(): void
    {
        $sql = (new Builder())
            ->from('t')
            ->filter([Query::greaterThan('age', 18)])
            ->toRawSql();

        $this->assertSame("SELECT * FROM `t` WHERE `age` > 18", $sql);
    }

    public function testCombinedAggregationJoinGroupByHaving(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'order_count')
            ->sum('total', 'total_amount')
            ->select(['users.name'])
            ->join('users', 'orders.user_id', 'users.id')
            ->groupBy(['users.name'])
            ->having([Query::greaterThan('order_count', 5)])
            ->sortDesc('total_amount')
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT COUNT(*) AS `order_count`, SUM(`total`) AS `total_amount`, `users`.`name` FROM `orders` JOIN `users` ON `orders`.`user_id` = `users`.`id` GROUP BY `users`.`name` HAVING COUNT(*) > ? ORDER BY `total_amount` DESC LIMIT ?',
            $result->query
        );
        $this->assertSame([5, 10], $result->bindings);
    }

    public function testResetClearsUnions(): void
    {
        $other = (new Builder())->from('archive');
        $builder = (new Builder())
            ->from('current')
            ->union($other);

        $builder->build();
        $builder->reset();

        $result = $builder->from('fresh')->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `fresh`', $result->query);
    }
    //  EDGE CASES & COMBINATIONS


    public function testCountWithNamedColumn(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count('id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(`id`) FROM `t`', $result->query);
    }

    public function testCountWithEmptyStringAttribute(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count('')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) FROM `t`', $result->query);
    }

    public function testMultipleAggregations(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count('*', 'cnt')
            ->sum('price', 'total')
            ->avg('score', 'avg_score')
            ->min('age', 'youngest')
            ->max('age', 'oldest')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT COUNT(*) AS `cnt`, SUM(`price`) AS `total`, AVG(`score`) AS `avg_score`, MIN(`age`) AS `youngest`, MAX(`age`) AS `oldest` FROM `t`',
            $result->query
        );
        $this->assertSame([], $result->bindings);
    }

    public function testAggregationWithoutGroupBy(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->sum('total', 'grand_total')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT SUM(`total`) AS `grand_total` FROM `orders`', $result->query);
    }

    public function testAggregationWithFilter(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'total')
            ->filter([Query::equal('status', ['completed'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT COUNT(*) AS `total` FROM `orders` WHERE `status` IN (?)',
            $result->query
        );
        $this->assertSame(['completed'], $result->bindings);
    }

    public function testAggregationWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count()
            ->sum('price')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*), SUM(`price`) FROM `t`', $result->query);
    }

    public function testGroupByEmptyArray(): void
    {
        $result = (new Builder())
            ->from('t')
            ->groupBy([])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t`', $result->query);
    }

    public function testMultipleGroupByCalls(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count('*', 'total')
            ->groupBy(['status'])
            ->groupBy(['country'])
            ->build();
        $this->assertBindingCount($result);

        // Both groupBy calls should merge since groupByType merges values
        $this->assertSame('SELECT COUNT(*) AS `total` FROM `t` GROUP BY `status`, `country`', $result->query);
    }

    public function testHavingEmptyArray(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count('*', 'total')
            ->groupBy(['status'])
            ->having([])
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString('HAVING', $result->query);
    }

    public function testHavingMultipleConditions(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count('*', 'total')
            ->sum('price', 'sum_price')
            ->groupBy(['status'])
            ->having([
                Query::greaterThan('total', 5),
                Query::lessThan('sum_price', 1000),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT COUNT(*) AS `total`, SUM(`price`) AS `sum_price` FROM `t` GROUP BY `status` HAVING COUNT(*) > ? AND SUM(`price`) < ?',
            $result->query
        );
        $this->assertSame([5, 1000], $result->bindings);
    }

    public function testHavingWithLogicalOr(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count('*', 'total')
            ->groupBy(['status'])
            ->having([
                Query::or([
                    Query::greaterThan('total', 10),
                    Query::lessThan('total', 2),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `total` FROM `t` GROUP BY `status` HAVING (`total` > ? OR `total` < ?)', $result->query);
        $this->assertSame([10, 2], $result->bindings);
    }

    public function testHavingWithoutGroupBy(): void
    {
        // SQL allows HAVING without GROUP BY in some engines
        $result = (new Builder())
            ->from('t')
            ->count('*', 'total')
            ->having([Query::greaterThan('total', 0)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `total` FROM `t` HAVING COUNT(*) > ?', $result->query);
        $this->assertStringNotContainsString('GROUP BY', $result->query);
    }

    public function testMultipleHavingCalls(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count('*', 'total')
            ->groupBy(['status'])
            ->having([Query::greaterThan('total', 1)])
            ->having([Query::lessThan('total', 100)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `total` FROM `t` GROUP BY `status` HAVING COUNT(*) > ? AND COUNT(*) < ?', $result->query);
        $this->assertSame([1, 100], $result->bindings);
    }

    public function testDistinctWithAggregation(): void
    {
        $result = (new Builder())
            ->from('t')
            ->distinct()
            ->count('*', 'total')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT DISTINCT COUNT(*) AS `total` FROM `t`', $result->query);
    }

    public function testDistinctMultipleCalls(): void
    {
        // Multiple distinct() calls should still produce single DISTINCT keyword
        $result = (new Builder())
            ->from('t')
            ->distinct()
            ->distinct()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT DISTINCT * FROM `t`', $result->query);
    }

    public function testDistinctWithJoin(): void
    {
        $result = (new Builder())
            ->from('users')
            ->distinct()
            ->select(['users.name'])
            ->join('orders', 'users.id', 'orders.user_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT DISTINCT `users`.`name` FROM `users` JOIN `orders` ON `users`.`id` = `orders`.`user_id`',
            $result->query
        );
    }

    public function testDistinctWithFilterAndSort(): void
    {
        $result = (new Builder())
            ->from('t')
            ->distinct()
            ->select(['status'])
            ->filter([Query::isNotNull('status')])
            ->sortAsc('status')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT DISTINCT `status` FROM `t` WHERE `status` IS NOT NULL ORDER BY `status` ASC',
            $result->query
        );
    }

    public function testMultipleJoins(): void
    {
        $result = (new Builder())
            ->from('users')
            ->join('orders', 'users.id', 'orders.user_id')
            ->leftJoin('profiles', 'users.id', 'profiles.user_id')
            ->rightJoin('departments', 'users.dept_id', 'departments.id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `users` JOIN `orders` ON `users`.`id` = `orders`.`user_id` LEFT JOIN `profiles` ON `users`.`id` = `profiles`.`user_id` RIGHT JOIN `departments` ON `users`.`dept_id` = `departments`.`id`',
            $result->query
        );
    }

    public function testJoinWithAggregationAndGroupBy(): void
    {
        $result = (new Builder())
            ->from('users')
            ->count('*', 'order_count')
            ->join('orders', 'users.id', 'orders.user_id')
            ->groupBy(['users.name'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT COUNT(*) AS `order_count` FROM `users` JOIN `orders` ON `users`.`id` = `orders`.`user_id` GROUP BY `users`.`name`',
            $result->query
        );
    }

    public function testJoinWithSortAndPagination(): void
    {
        $result = (new Builder())
            ->from('users')
            ->join('orders', 'users.id', 'orders.user_id')
            ->filter([Query::greaterThan('orders.total', 50)])
            ->sortDesc('orders.total')
            ->limit(10)
            ->offset(20)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `users` JOIN `orders` ON `users`.`id` = `orders`.`user_id` WHERE `orders`.`total` > ? ORDER BY `orders`.`total` DESC LIMIT ? OFFSET ?',
            $result->query
        );
        $this->assertSame([50, 10, 20], $result->bindings);
    }

    public function testJoinWithCustomOperator(): void
    {
        $result = (new Builder())
            ->from('a')
            ->join('b', 'a.val', 'b.val', '!=')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `a` JOIN `b` ON `a`.`val` != `b`.`val`',
            $result->query
        );
    }

    public function testCrossJoinWithOtherJoins(): void
    {
        $result = (new Builder())
            ->from('sizes')
            ->crossJoin('colors')
            ->leftJoin('inventory', 'sizes.id', 'inventory.size_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `sizes` CROSS JOIN `colors` LEFT JOIN `inventory` ON `sizes`.`id` = `inventory`.`size_id`',
            $result->query
        );
    }

    public function testRawWithMixedBindings(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::raw('a = ? AND b = ? AND c = ?', ['str', 42, 3.14])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE a = ? AND b = ? AND c = ?', $result->query);
        $this->assertSame(['str', 42, 3.14], $result->bindings);
    }

    public function testRawCombinedWithRegularFilters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::equal('status', ['active']),
                Query::raw('custom_func(col) > ?', [10]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE `status` IN (?) AND custom_func(col) > ?',
            $result->query
        );
        $this->assertSame(['active', 10], $result->bindings);
    }

    public function testRawWithEmptySql(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::raw('')])
            ->build();
        $this->assertBindingCount($result);

        // Empty raw SQL still appears as a WHERE clause
        $this->assertSame('SELECT * FROM `t` WHERE 1 = 1', $result->query);
    }

    public function testMultipleUnions(): void
    {
        $q1 = (new Builder())->from('admins');
        $q2 = (new Builder())->from('mods');

        $result = (new Builder())
            ->from('users')
            ->union($q1)
            ->union($q2)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            '(SELECT * FROM `users`) UNION (SELECT * FROM `admins`) UNION (SELECT * FROM `mods`)',
            $result->query
        );
    }

    public function testMixedUnionAndUnionAll(): void
    {
        $q1 = (new Builder())->from('admins');
        $q2 = (new Builder())->from('mods');

        $result = (new Builder())
            ->from('users')
            ->union($q1)
            ->unionAll($q2)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            '(SELECT * FROM `users`) UNION (SELECT * FROM `admins`) UNION ALL (SELECT * FROM `mods`)',
            $result->query
        );
    }

    public function testUnionWithFiltersAndBindings(): void
    {
        $q1 = (new Builder())->from('admins')->filter([Query::equal('level', [1])]);
        $q2 = (new Builder())->from('mods')->filter([Query::greaterThan('score', 50)]);

        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('status', ['active'])])
            ->union($q1)
            ->unionAll($q2)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            '(SELECT * FROM `users` WHERE `status` IN (?)) UNION (SELECT * FROM `admins` WHERE `level` IN (?)) UNION ALL (SELECT * FROM `mods` WHERE `score` > ?)',
            $result->query
        );
        $this->assertSame(['active', 1, 50], $result->bindings);
    }

    public function testUnionWithAggregation(): void
    {
        $q1 = (new Builder())->from('orders_2023')->count('*', 'total');

        $result = (new Builder())
            ->from('orders_2024')
            ->count('*', 'total')
            ->unionAll($q1)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            '(SELECT COUNT(*) AS `total` FROM `orders_2024`) UNION ALL (SELECT COUNT(*) AS `total` FROM `orders_2023`)',
            $result->query
        );
    }

    public function testWhenNested(): void
    {
        $result = (new Builder())
            ->from('t')
            ->when(true, function (Builder $b) {
                $b->when(true, fn (Builder $b2) => $b2->filter([Query::equal('a', [1])]));
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `a` IN (?)', $result->query);
    }

    public function testWhenMultipleCalls(): void
    {
        $result = (new Builder())
            ->from('t')
            ->when(true, fn (Builder $b) => $b->filter([Query::equal('a', [1])]))
            ->when(false, fn (Builder $b) => $b->filter([Query::equal('b', [2])]))
            ->when(true, fn (Builder $b) => $b->filter([Query::equal('c', [3])]))
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `a` IN (?) AND `c` IN (?)', $result->query);
        $this->assertSame([1, 3], $result->bindings);
    }

    public function testPageZero(): void
    {
        $this->expectException(ValidationException::class);
        (new Builder())
            ->from('t')
            ->page(0, 10)
            ->build();
    }

    public function testPageOnePerPage(): void
    {
        $result = (new Builder())
            ->from('t')
            ->page(5, 1)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` LIMIT ? OFFSET ?', $result->query);
        $this->assertSame([1, 4], $result->bindings);
    }

    public function testPageLargeValues(): void
    {
        $result = (new Builder())
            ->from('t')
            ->page(1000, 100)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame([100, 99900], $result->bindings);
    }

    public function testToRawSqlWithBooleanBindings(): void
    {
        // Booleans must be handled in toRawSql
        $builder = (new Builder())
            ->from('t')
            ->filter([Query::raw('active = ?', [true])]);

        $sql = $builder->toRawSql();
        $this->assertSame("SELECT * FROM `t` WHERE active = 1", $sql);
    }

    public function testToRawSqlWithNullBinding(): void
    {
        $builder = (new Builder())
            ->from('t')
            ->filter([Query::raw('deleted_at = ?', [null])]);

        $sql = $builder->toRawSql();
        $this->assertSame("SELECT * FROM `t` WHERE deleted_at = NULL", $sql);
    }

    public function testToRawSqlWithFloatBinding(): void
    {
        $builder = (new Builder())
            ->from('t')
            ->filter([Query::raw('price > ?', [9.99])]);

        $sql = $builder->toRawSql();
        $this->assertSame("SELECT * FROM `t` WHERE price > 9.99", $sql);
    }

    public function testToRawSqlComplexQuery(): void
    {
        $sql = (new Builder())
            ->from('users')
            ->select(['name'])
            ->filter([
                Query::equal('status', ['active']),
                Query::greaterThan('age', 18),
            ])
            ->sortAsc('name')
            ->limit(25)
            ->offset(10)
            ->toRawSql();

        $this->assertSame(
            "SELECT `name` FROM `users` WHERE `status` IN ('active') AND `age` > 18 ORDER BY `name` ASC LIMIT 25 OFFSET 10",
            $sql
        );
    }

    public function testCompileFilterUnsupportedType(): void
    {
        $this->expectException(\ValueError::class);
        new Query('totallyInvalid', 'x', [1]);
    }

    public function testCompileOrderUnsupportedType(): void
    {
        $builder = new Builder();
        $query = new Query('equal', 'x', [1]);

        $this->expectException(UnsupportedException::class);
        $this->expectExceptionMessage('Unsupported order type: equal');
        $builder->compileOrder($query);
    }

    public function testCompileJoinUnsupportedType(): void
    {
        $builder = new Builder();
        $query = new Query('equal', 't', ['a', '=', 'b']);

        $this->expectException(UnsupportedException::class);
        $this->expectExceptionMessage('Unsupported join type: equal');
        $builder->compileJoin($query);
    }

    public function testBindingOrderFilterProviderCursorLimitOffset(): void
    {
        $hook = new class () implements Filter {
            public function filter(string $table): Condition
            {
                return new Condition('_tenant = ?', ['tenant1']);
            }
        };

        $result = (new Builder())
            ->from('t')
            ->addHook($hook)
            ->filter([
                Query::equal('a', ['x']),
                Query::greaterThan('b', 5),
            ])
            ->cursorAfter('cursor_abc')
            ->limit(10)
            ->offset(20)
            ->build();
        $this->assertBindingCount($result);

        // Order: filter bindings, hook bindings, cursor, limit, offset
        $this->assertSame(['x', 5, 'tenant1', 'cursor_abc', 10, 20], $result->bindings);
    }

    public function testBindingOrderMultipleProviders(): void
    {
        $hook1 = new class () implements Filter {
            public function filter(string $table): Condition
            {
                return new Condition('p1 = ?', ['v1']);
            }
        };
        $hook2 = new class () implements Filter {
            public function filter(string $table): Condition
            {
                return new Condition('p2 = ?', ['v2']);
            }
        };

        $result = (new Builder())
            ->from('t')
            ->addHook($hook1)
            ->addHook($hook2)
            ->filter([Query::equal('a', ['x'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['x', 'v1', 'v2'], $result->bindings);
    }

    public function testBindingOrderHavingAfterFilters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count('*', 'total')
            ->filter([Query::equal('status', ['active'])])
            ->groupBy(['status'])
            ->having([Query::greaterThan('total', 5)])
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);

        // Filter bindings, then having bindings, then limit
        $this->assertSame(['active', 5, 10], $result->bindings);
    }

    public function testBindingOrderUnionAppendedLast(): void
    {
        $sub = (new Builder())->from('other')->filter([Query::equal('x', ['y'])]);

        $result = (new Builder())
            ->from('main')
            ->filter([Query::equal('a', ['b'])])
            ->limit(5)
            ->union($sub)
            ->build();
        $this->assertBindingCount($result);

        // Main filter, main limit, then union bindings
        $this->assertSame(['b', 5, 'y'], $result->bindings);
    }

    public function testBindingOrderComplexMixed(): void
    {
        $sub = (new Builder())->from('archive')->filter([Query::equal('year', [2023])]);

        $hook = new class () implements Filter {
            public function filter(string $table): Condition
            {
                return new Condition('_org = ?', ['org1']);
            }
        };

        $result = (new Builder())
            ->from('orders')
            ->count('*', 'cnt')
            ->addHook($hook)
            ->filter([Query::equal('status', ['paid'])])
            ->groupBy(['status'])
            ->having([Query::greaterThan('cnt', 1)])
            ->cursorAfter('cur1')
            ->limit(10)
            ->offset(5)
            ->union($sub)
            ->build();
        $this->assertBindingCount($result);

        // filter, hook, cursor, having, limit, offset, union
        $this->assertSame(['paid', 'org1', 'cur1', 1, 10, 5, 2023], $result->bindings);
    }

    public function testAttributeResolverWithAggregation(): void
    {
        $result = (new Builder())
            ->from('t')
            ->addHook(new AttributeMap(['$price' => '_price']))
            ->sum('$price', 'total')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT SUM(`_price`) AS `total` FROM `t`', $result->query);
    }

    public function testAttributeResolverWithGroupBy(): void
    {
        $result = (new Builder())
            ->from('t')
            ->addHook(new AttributeMap(['$status' => '_status']))
            ->count('*', 'total')
            ->groupBy(['$status'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT COUNT(*) AS `total` FROM `t` GROUP BY `_status`',
            $result->query
        );
    }

    public function testAttributeResolverWithJoin(): void
    {
        $result = (new Builder())
            ->from('t')
            ->addHook(new AttributeMap([
                '$id' => '_uid',
                '$ref' => '_ref',
            ]))
            ->join('other', '$id', '$ref')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` JOIN `other` ON `_uid` = `_ref`',
            $result->query
        );
    }

    public function testAttributeResolverWithHaving(): void
    {
        $result = (new Builder())
            ->from('t')
            ->addHook(new AttributeMap(['$total' => '_total']))
            ->count('*', 'cnt')
            ->groupBy(['status'])
            ->having([Query::greaterThan('$total', 5)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `t` GROUP BY `status` HAVING `_total` > ?', $result->query);
    }

    public function testConditionProviderWithJoins(): void
    {
        $hook = new class () implements Filter {
            public function filter(string $table): Condition
            {
                return new Condition('users.org_id = ?', ['org1']);
            }
        };

        $result = (new Builder())
            ->from('users')
            ->join('orders', 'users.id', 'orders.user_id')
            ->addHook($hook)
            ->filter([Query::greaterThan('orders.total', 100)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `users` JOIN `orders` ON `users`.`id` = `orders`.`user_id` WHERE `orders`.`total` > ? AND users.org_id = ?',
            $result->query
        );
        $this->assertSame([100, 'org1'], $result->bindings);
    }

    public function testConditionProviderWithAggregation(): void
    {
        $hook = new class () implements Filter {
            public function filter(string $table): Condition
            {
                return new Condition('org_id = ?', ['org1']);
            }
        };

        $result = (new Builder())
            ->from('orders')
            ->count('*', 'total')
            ->addHook($hook)
            ->groupBy(['status'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `total` FROM `orders` WHERE org_id = ? GROUP BY `status`', $result->query);
        $this->assertSame(['org1'], $result->bindings);
    }

    public function testMultipleBuildsConsistentOutput(): void
    {
        $builder = (new Builder())
            ->from('t')
            ->filter([Query::equal('a', [1])])
            ->limit(10);

        $result1 = $builder->build();
        $result2 = $builder->build();

        $this->assertSame($result1->query, $result2->query);
        $this->assertSame($result1->bindings, $result2->bindings);
    }


    public function testEmptyBuilderNoFrom(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No table specified');
        (new Builder())->build();
    }

    public function testCursorWithLimitAndOffset(): void
    {
        $result = (new Builder())
            ->from('t')
            ->cursorAfter('abc')
            ->limit(10)
            ->offset(5)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE `_cursor` > ? LIMIT ? OFFSET ?',
            $result->query
        );
        $this->assertSame(['abc', 10, 5], $result->bindings);
    }

    public function testCursorWithPage(): void
    {
        $result = (new Builder())
            ->from('t')
            ->cursorAfter('abc')
            ->page(2, 10)
            ->build();
        $this->assertBindingCount($result);

        // Cursor + limit from page + offset from page; first limit/offset wins
        $this->assertSame('SELECT * FROM `t` WHERE `_cursor` > ? LIMIT ? OFFSET ?', $result->query);
    }

    public function testKitchenSinkQuery(): void
    {
        $sub = (new Builder())->from('archive')->filter([Query::equal('year', [2023])]);

        $result = (new Builder())
            ->from('orders')
            ->distinct()
            ->count('*', 'cnt')
            ->sum('total', 'sum_total')
            ->select(['status'])
            ->join('users', 'orders.user_id', 'users.id')
            ->leftJoin('coupons', 'orders.coupon_id', 'coupons.id')
            ->filter([
                Query::equal('orders.status', ['paid']),
                Query::greaterThan('orders.total', 0),
            ])
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('org = ?', ['o1']);
                }
            })
            ->groupBy(['status'])
            ->having([Query::greaterThan('cnt', 1)])
            ->sortDesc('sum_total')
            ->limit(25)
            ->offset(50)
            ->union($sub)
            ->build();
        $this->assertBindingCount($result);

        // Verify structural elements
        $this->assertSame('(SELECT DISTINCT COUNT(*) AS `cnt`, SUM(`total`) AS `sum_total`, `status` FROM `orders` JOIN `users` ON `orders`.`user_id` = `users`.`id` LEFT JOIN `coupons` ON `orders`.`coupon_id` = `coupons`.`id` WHERE `orders`.`status` IN (?) AND `orders`.`total` > ? AND org = ? GROUP BY `status` HAVING COUNT(*) > ? ORDER BY `sum_total` DESC LIMIT ? OFFSET ?) UNION (SELECT * FROM `archive` WHERE `year` IN (?))', $result->query);

        // Verify SQL clause ordering
        $query = $result->query;
        $this->assertLessThan(strpos($query, 'FROM'), strpos($query, 'SELECT'));
        $this->assertLessThan(strpos($query, 'JOIN'), (int) strpos($query, 'FROM'));
        $this->assertLessThan(strpos($query, 'WHERE'), (int) strpos($query, 'JOIN'));
        $this->assertLessThan(strpos($query, 'GROUP BY'), (int) strpos($query, 'WHERE'));
        $this->assertLessThan(strpos($query, 'HAVING'), (int) strpos($query, 'GROUP BY'));
        $this->assertLessThan(strpos($query, 'ORDER BY'), (int) strpos($query, 'HAVING'));
        $this->assertLessThan(strpos($query, 'LIMIT'), (int) strpos($query, 'ORDER BY'));
        $this->assertLessThan(strpos($query, 'OFFSET'), (int) strpos($query, 'LIMIT'));
        $this->assertLessThan(strpos($query, 'UNION'), (int) strpos($query, 'OFFSET'));
    }

    public function testFilterEmptyArray(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t`', $result->query);
    }

    public function testSelectEmptyArray(): void
    {
        $result = (new Builder())
            ->from('t')
            ->select([])
            ->build();
        $this->assertBindingCount($result);

        // Empty select produces empty column list
        $this->assertSame('SELECT  FROM `t`', $result->query);
    }

    public function testLimitZero(): void
    {
        $result = (new Builder())
            ->from('t')
            ->limit(0)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` LIMIT ?', $result->query);
        $this->assertSame([0], $result->bindings);
    }

    public function testOffsetZero(): void
    {
        // OFFSET without LIMIT is invalid in MySQL/ClickHouse; the builder refuses it.
        $this->expectException(ValidationException::class);
        (new Builder())
            ->from('t')
            ->offset(0)
            ->build();
    }


    public function testFluentChainingReturnsSameInstance(): void
    {
        $builder = new Builder();

        $this->assertSame($builder, $builder->from('t'));
        $this->assertSame($builder, $builder->select(['a']));
        $this->assertSame($builder, $builder->filter([]));
        $this->assertSame($builder, $builder->sortAsc('a'));
        $this->assertSame($builder, $builder->sortDesc('a'));
        $this->assertSame($builder, $builder->sortRandom());
        $this->assertSame($builder, $builder->limit(1));
        $this->assertSame($builder, $builder->offset(0));
        $this->assertSame($builder, $builder->cursorAfter('x'));
        $this->assertSame($builder, $builder->cursorBefore('x'));
        $this->assertSame($builder, $builder->queries([]));
        $this->assertSame($builder, $builder->count());
        $this->assertSame($builder, $builder->sum('a'));
        $this->assertSame($builder, $builder->avg('a'));
        $this->assertSame($builder, $builder->min('a'));
        $this->assertSame($builder, $builder->max('a'));
        $this->assertSame($builder, $builder->groupBy(['a']));
        $this->assertSame($builder, $builder->having([]));
        $this->assertSame($builder, $builder->distinct());
        $this->assertSame($builder, $builder->join('t', 'a', 'b'));
        $this->assertSame($builder, $builder->leftJoin('t', 'a', 'b'));
        $this->assertSame($builder, $builder->rightJoin('t', 'a', 'b'));
        $this->assertSame($builder, $builder->crossJoin('t'));
        $this->assertSame($builder, $builder->when(false, fn ($b) => $b));
        $this->assertSame($builder, $builder->page(1));
        $this->assertSame($builder, $builder->reset());
    }

    public function testUnionFluentChainingReturnsSameInstance(): void
    {
        $builder = new Builder();
        $other = (new Builder())->from('t');
        $this->assertSame($builder, $builder->from('t')->union($other));

        $builder->reset();
        $other2 = (new Builder())->from('t');
        $this->assertSame($builder, $builder->from('t')->unionAll($other2));
    }
    //  1. SQL-Specific: REGEXP

    public function testRegexWithEmptyPattern(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::regex('slug', '')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `slug` REGEXP ?', $result->query);
        $this->assertSame([''], $result->bindings);
    }

    public function testRegexWithDotChar(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::regex('name', 'a.b')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `name` REGEXP ?', $result->query);
        $this->assertSame(['a.b'], $result->bindings);
    }

    public function testRegexWithStarChar(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::regex('name', 'a*b')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['a*b'], $result->bindings);
    }

    public function testRegexWithPlusChar(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::regex('name', 'a+')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['a+'], $result->bindings);
    }

    public function testRegexWithQuestionMarkChar(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::regex('name', 'colou?r')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['colou?r'], $result->bindings);
    }

    public function testRegexWithCaretAndDollar(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::regex('code', '^[A-Z]+$')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['^[A-Z]+$'], $result->bindings);
    }

    public function testRegexWithPipeChar(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::regex('color', 'red|blue|green')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['red|blue|green'], $result->bindings);
    }

    public function testRegexWithBackslash(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::regex('path', '\\\\server\\\\share')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['\\\\server\\\\share'], $result->bindings);
    }

    public function testRegexWithBracketsAndBraces(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::regex('zip', '[0-9]{5}')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('[0-9]{5}', $result->bindings[0]);
    }

    public function testRegexWithParentheses(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::regex('phone', '(\\+1)?[0-9]{10}')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['(\\+1)?[0-9]{10}'], $result->bindings);
    }

    public function testRegexCombinedWithOtherFilters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::equal('status', ['active']),
                Query::regex('slug', '^[a-z-]+$'),
                Query::greaterThan('age', 18),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE `status` IN (?) AND `slug` REGEXP ? AND `age` > ?',
            $result->query
        );
        $this->assertSame(['active', '^[a-z-]+$', 18], $result->bindings);
    }

    public function testRegexWithAttributeResolver(): void
    {
        $result = (new Builder())
            ->from('t')
            ->addHook(new AttributeMap([
                '$slug' => '_slug',
            ]))
            ->filter([Query::regex('$slug', '^test')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `_slug` REGEXP ?', $result->query);
        $this->assertSame(['^test'], $result->bindings);
    }

    public function testRegexStandaloneCompileFilter(): void
    {
        $builder = new Builder();
        $query = Query::regex('col', '^abc');
        $sql = $builder->compileFilter($query);

        $this->assertSame('`col` REGEXP ?', $sql);
        $this->assertSame(['^abc'], $builder->getBindings());
    }

    public function testRegexBindingPreservedExactly(): void
    {
        $pattern = '^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}$';
        $result = (new Builder())
            ->from('t')
            ->filter([Query::regex('email', $pattern)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame($pattern, $result->bindings[0]);
    }

    public function testRegexWithVeryLongPattern(): void
    {
        $pattern = str_repeat('[a-z]', 500);
        $result = (new Builder())
            ->from('t')
            ->filter([Query::regex('col', $pattern)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame($pattern, $result->bindings[0]);
        $this->assertSame('SELECT * FROM `t` WHERE `col` REGEXP ?', $result->query);
    }

    public function testMultipleRegexFilters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::regex('name', '^A'),
                Query::regex('email', '@test\\.com$'),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE `name` REGEXP ? AND `email` REGEXP ?',
            $result->query
        );
        $this->assertSame(['^A', '@test\\.com$'], $result->bindings);
    }

    public function testRegexInAndLogicalGroup(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::and([
                    Query::regex('slug', '^[a-z]+$'),
                    Query::equal('status', ['active']),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE (`slug` REGEXP ? AND `status` IN (?))',
            $result->query
        );
        $this->assertSame(['^[a-z]+$', 'active'], $result->bindings);
    }

    public function testRegexInOrLogicalGroup(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::or([
                    Query::regex('name', '^Admin'),
                    Query::regex('name', '^Mod'),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE (`name` REGEXP ? OR `name` REGEXP ?)',
            $result->query
        );
        $this->assertSame(['^Admin', '^Mod'], $result->bindings);
    }
    //  2. SQL-Specific: MATCH AGAINST / Search

    public function testSearchWithEmptyString(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::search('content', '')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE 1 = 0', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testSearchWithSpecialCharacters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::search('body', 'hello "world" +required -excluded')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['hello world required excluded*'], $result->bindings);
    }

    public function testSearchCombinedWithOtherFilters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::search('content', 'hello'),
                Query::equal('status', ['published']),
                Query::greaterThan('views', 100),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE MATCH(`content`) AGAINST(? IN BOOLEAN MODE) AND `status` IN (?) AND `views` > ?',
            $result->query
        );
        $this->assertSame(['hello*', 'published', 100], $result->bindings);
    }

    public function testNotSearchCombinedWithOtherFilters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::notSearch('content', 'spam'),
                Query::equal('status', ['published']),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE NOT (MATCH(`content`) AGAINST(? IN BOOLEAN MODE)) AND `status` IN (?)',
            $result->query
        );
        $this->assertSame(['spam*', 'published'], $result->bindings);
    }

    public function testSearchWithAttributeResolver(): void
    {
        $result = (new Builder())
            ->from('t')
            ->addHook(new AttributeMap([
                '$body' => '_body',
            ]))
            ->filter([Query::search('$body', 'hello')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE MATCH(`_body`) AGAINST(? IN BOOLEAN MODE)', $result->query);
    }

    public function testSearchStandaloneCompileFilter(): void
    {
        $builder = new Builder();
        $query = Query::search('body', 'test');
        $sql = $builder->compileFilter($query);

        $this->assertSame('MATCH(`body`) AGAINST(? IN BOOLEAN MODE)', $sql);
        $this->assertSame(['test*'], $builder->getBindings());
    }

    public function testNotSearchStandaloneCompileFilter(): void
    {
        $builder = new Builder();
        $query = Query::notSearch('body', 'spam');
        $sql = $builder->compileFilter($query);

        $this->assertSame('NOT (MATCH(`body`) AGAINST(? IN BOOLEAN MODE))', $sql);
        $this->assertSame(['spam*'], $builder->getBindings());
    }

    public function testSearchBindingPreservedExactly(): void
    {
        $searchTerm = 'hello world "exact phrase" +required -excluded';
        $result = (new Builder())
            ->from('t')
            ->filter([Query::search('content', $searchTerm)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('hello world exact phrase required excluded*', $result->bindings[0]);
    }

    public function testSearchWithVeryLongText(): void
    {
        $longText = str_repeat('keyword ', 1000);
        $result = (new Builder())
            ->from('t')
            ->filter([Query::search('content', $longText)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(trim($longText) . '*', $result->bindings[0]);
    }

    public function testMultipleSearchFilters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::search('title', 'hello'),
                Query::search('body', 'world'),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE MATCH(`title`) AGAINST(? IN BOOLEAN MODE) AND MATCH(`body`) AGAINST(? IN BOOLEAN MODE)',
            $result->query
        );
        $this->assertSame(['hello*', 'world*'], $result->bindings);
    }

    public function testSearchInAndLogicalGroup(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::and([
                    Query::search('content', 'hello'),
                    Query::equal('status', ['active']),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE (MATCH(`content`) AGAINST(? IN BOOLEAN MODE) AND `status` IN (?))',
            $result->query
        );
    }

    public function testSearchInOrLogicalGroup(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::or([
                    Query::search('title', 'hello'),
                    Query::search('body', 'hello'),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE (MATCH(`title`) AGAINST(? IN BOOLEAN MODE) OR MATCH(`body`) AGAINST(? IN BOOLEAN MODE))',
            $result->query
        );
        $this->assertSame(['hello*', 'hello*'], $result->bindings);
    }

    public function testSearchAndRegexCombined(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::search('content', 'hello world'),
                Query::regex('slug', '^[a-z-]+$'),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE MATCH(`content`) AGAINST(? IN BOOLEAN MODE) AND `slug` REGEXP ?',
            $result->query
        );
        $this->assertSame(['hello world*', '^[a-z-]+$'], $result->bindings);
    }

    public function testNotSearchStandalone(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notSearch('content', 'spam')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE NOT (MATCH(`content`) AGAINST(? IN BOOLEAN MODE))', $result->query);
        $this->assertSame(['spam*'], $result->bindings);
    }
    //  3. SQL-Specific: RAND()

    public function testRandomSortStandaloneCompile(): void
    {
        $builder = new Builder();
        $query = Query::orderRandom();
        $sql = $builder->compileOrder($query);

        $this->assertSame('RAND()', $sql);
    }

    public function testRandomSortCombinedWithAscDesc(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sortAsc('name')
            ->sortRandom()
            ->sortDesc('age')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` ORDER BY `name` ASC, RAND(), `age` DESC',
            $result->query
        );
    }

    public function testRandomSortWithFilters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('status', ['active'])])
            ->sortRandom()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE `status` IN (?) ORDER BY RAND()',
            $result->query
        );
        $this->assertSame(['active'], $result->bindings);
    }

    public function testRandomSortWithLimit(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sortRandom()
            ->limit(5)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` ORDER BY RAND() LIMIT ?', $result->query);
        $this->assertSame([5], $result->bindings);
    }

    public function testRandomSortWithAggregation(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count('*', 'total')
            ->groupBy(['category'])
            ->sortRandom()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `total` FROM `t` GROUP BY `category` ORDER BY RAND()', $result->query);
    }

    public function testRandomSortWithJoins(): void
    {
        $result = (new Builder())
            ->from('users')
            ->join('orders', 'users.id', 'orders.user_id')
            ->sortRandom()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` JOIN `orders` ON `users`.`id` = `orders`.`user_id` ORDER BY RAND()', $result->query);
    }

    public function testRandomSortWithDistinct(): void
    {
        $result = (new Builder())
            ->from('t')
            ->distinct()
            ->select(['status'])
            ->sortRandom()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT DISTINCT `status` FROM `t` ORDER BY RAND()',
            $result->query
        );
    }

    public function testRandomSortInBatchMode(): void
    {
        $result = (new Builder())
            ->from('t')
            ->queries([
                Query::orderRandom(),
                Query::limit(10),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` ORDER BY RAND() LIMIT ?', $result->query);
        $this->assertSame([10], $result->bindings);
    }

    public function testRandomSortWithAttributeResolver(): void
    {
        $result = (new Builder())
            ->from('t')
            ->addHook(new class () implements Attribute {
                public function resolve(string $attribute): string
                {
                    return '_' . $attribute;
                }
            })
            ->sortRandom()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` ORDER BY RAND()', $result->query);
    }

    public function testMultipleRandomSorts(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sortRandom()
            ->sortRandom()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` ORDER BY RAND(), RAND()', $result->query);
    }

    public function testRandomSortWithOffset(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sortRandom()
            ->limit(10)
            ->offset(5)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` ORDER BY RAND() LIMIT ? OFFSET ?', $result->query);
        $this->assertSame([10, 5], $result->bindings);
    }
    //  5. Standalone Compiler method calls

    public function testCompileFilterEqual(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::equal('col', ['a', 'b']));
        $this->assertSame('`col` IN (?, ?)', $sql);
        $this->assertSame(['a', 'b'], $builder->getBindings());
    }

    public function testCompileFilterNotEqual(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::notEqual('col', 'a'));
        $this->assertSame('`col` != ?', $sql);
        $this->assertSame(['a'], $builder->getBindings());
    }

    public function testCompileFilterLessThan(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::lessThan('col', 10));
        $this->assertSame('`col` < ?', $sql);
        $this->assertSame([10], $builder->getBindings());
    }

    public function testCompileFilterLessThanEqual(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::lessThanEqual('col', 10));
        $this->assertSame('`col` <= ?', $sql);
        $this->assertSame([10], $builder->getBindings());
    }

    public function testCompileFilterGreaterThan(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::greaterThan('col', 10));
        $this->assertSame('`col` > ?', $sql);
        $this->assertSame([10], $builder->getBindings());
    }

    public function testCompileFilterGreaterThanEqual(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::greaterThanEqual('col', 10));
        $this->assertSame('`col` >= ?', $sql);
        $this->assertSame([10], $builder->getBindings());
    }

    public function testCompileFilterBetween(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::between('col', 1, 100));
        $this->assertSame('`col` BETWEEN ? AND ?', $sql);
        $this->assertSame([1, 100], $builder->getBindings());
    }

    public function testCompileFilterNotBetween(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::notBetween('col', 1, 100));
        $this->assertSame('`col` NOT BETWEEN ? AND ?', $sql);
        $this->assertSame([1, 100], $builder->getBindings());
    }

    public function testCompileFilterStartsWith(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::startsWith('col', 'abc'));
        $this->assertSame('`col` LIKE ?', $sql);
        $this->assertSame(['abc%'], $builder->getBindings());
    }

    public function testCompileFilterNotStartsWith(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::notStartsWith('col', 'abc'));
        $this->assertSame('`col` NOT LIKE ?', $sql);
        $this->assertSame(['abc%'], $builder->getBindings());
    }

    public function testCompileFilterEndsWith(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::endsWith('col', 'xyz'));
        $this->assertSame('`col` LIKE ?', $sql);
        $this->assertSame(['%xyz'], $builder->getBindings());
    }

    public function testCompileFilterNotEndsWith(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::notEndsWith('col', 'xyz'));
        $this->assertSame('`col` NOT LIKE ?', $sql);
        $this->assertSame(['%xyz'], $builder->getBindings());
    }

    public function testCompileFilterContainsSingle(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::containsString('col', ['val']));
        $this->assertSame('`col` LIKE ?', $sql);
        $this->assertSame(['%val%'], $builder->getBindings());
    }

    public function testCompileFilterContainsMultiple(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::containsString('col', ['a', 'b']));
        $this->assertSame('(`col` LIKE ? OR `col` LIKE ?)', $sql);
        $this->assertSame(['%a%', '%b%'], $builder->getBindings());
    }

    public function testCompileFilterContainsAny(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::containsAny('col', ['a', 'b']));
        $this->assertSame('(`col` LIKE ? OR `col` LIKE ?)', $sql);
        $this->assertSame(['%a%', '%b%'], $builder->getBindings());
    }

    public function testCompileFilterContainsAll(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::containsAll('col', ['a', 'b']));
        $this->assertSame('(`col` LIKE ? AND `col` LIKE ?)', $sql);
        $this->assertSame(['%a%', '%b%'], $builder->getBindings());
    }

    public function testCompileFilterNotContainsSingle(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::notContains('col', ['val']));
        $this->assertSame('`col` NOT LIKE ?', $sql);
        $this->assertSame(['%val%'], $builder->getBindings());
    }

    public function testCompileFilterNotContainsMultiple(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::notContains('col', ['a', 'b']));
        $this->assertSame('(`col` NOT LIKE ? AND `col` NOT LIKE ?)', $sql);
        $this->assertSame(['%a%', '%b%'], $builder->getBindings());
    }

    public function testCompileFilterIsNull(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::isNull('col'));
        $this->assertSame('`col` IS NULL', $sql);
        $this->assertSame([], $builder->getBindings());
    }

    public function testCompileFilterIsNotNull(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::isNotNull('col'));
        $this->assertSame('`col` IS NOT NULL', $sql);
        $this->assertSame([], $builder->getBindings());
    }

    public function testCompileFilterAnd(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::and([
            Query::equal('a', [1]),
            Query::greaterThan('b', 2),
        ]));
        $this->assertSame('(`a` IN (?) AND `b` > ?)', $sql);
        $this->assertSame([1, 2], $builder->getBindings());
    }

    public function testCompileFilterOr(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::or([
            Query::equal('a', [1]),
            Query::equal('b', [2]),
        ]));
        $this->assertSame('(`a` IN (?) OR `b` IN (?))', $sql);
        $this->assertSame([1, 2], $builder->getBindings());
    }

    public function testCompileFilterExists(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::exists(['a', 'b']));
        $this->assertSame('(`a` IS NOT NULL AND `b` IS NOT NULL)', $sql);
    }

    public function testCompileFilterNotExists(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::notExists(['a', 'b']));
        $this->assertSame('(`a` IS NULL AND `b` IS NULL)', $sql);
    }

    public function testCompileFilterRaw(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::raw('x > ? AND y < ?', [1, 2]));
        $this->assertSame('x > ? AND y < ?', $sql);
        $this->assertSame([1, 2], $builder->getBindings());
    }

    public function testCompileFilterSearch(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::search('body', 'hello'));
        $this->assertSame('MATCH(`body`) AGAINST(? IN BOOLEAN MODE)', $sql);
        $this->assertSame(['hello*'], $builder->getBindings());
    }

    public function testCompileFilterNotSearch(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::notSearch('body', 'spam'));
        $this->assertSame('NOT (MATCH(`body`) AGAINST(? IN BOOLEAN MODE))', $sql);
        $this->assertSame(['spam*'], $builder->getBindings());
    }

    public function testCompileFilterRegex(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::regex('col', '^abc'));
        $this->assertSame('`col` REGEXP ?', $sql);
        $this->assertSame(['^abc'], $builder->getBindings());
    }

    public function testCompileOrderAsc(): void
    {
        $builder = new Builder();
        $sql = $builder->compileOrder(Query::orderAsc('name'));
        $this->assertSame('`name` ASC', $sql);
    }

    public function testCompileOrderDesc(): void
    {
        $builder = new Builder();
        $sql = $builder->compileOrder(Query::orderDesc('name'));
        $this->assertSame('`name` DESC', $sql);
    }

    public function testCompileOrderRandom(): void
    {
        $builder = new Builder();
        $sql = $builder->compileOrder(Query::orderRandom());
        $this->assertSame('RAND()', $sql);
    }

    public function testCompileLimitStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileLimit(Query::limit(25));
        $this->assertSame('LIMIT ?', $sql);
        $this->assertSame([25], $builder->getBindings());
    }

    public function testCompileOffsetStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileOffset(Query::offset(50));
        $this->assertSame('OFFSET ?', $sql);
        $this->assertSame([50], $builder->getBindings());
    }

    public function testCompileSelectStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileSelect(Query::select(['a', 'b', 'c']));
        $this->assertSame('`a`, `b`, `c`', $sql);
    }

    public function testCompileCursorAfterStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileCursor(Query::cursorAfter('abc'));
        $this->assertSame('`_cursor` > ?', $sql);
        $this->assertSame(['abc'], $builder->getBindings());
    }

    public function testCompileCursorBeforeStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileCursor(Query::cursorBefore('xyz'));
        $this->assertSame('`_cursor` < ?', $sql);
        $this->assertSame(['xyz'], $builder->getBindings());
    }

    public function testCompileAggregateCountStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileAggregate(Query::count('*', 'total'));
        $this->assertSame('COUNT(*) AS `total`', $sql);
    }

    public function testCompileAggregateCountWithoutAlias(): void
    {
        $builder = new Builder();
        $sql = $builder->compileAggregate(Query::count());
        $this->assertSame('COUNT(*)', $sql);
    }

    public function testCompileAggregateSumStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileAggregate(Query::sum('price', 'total'));
        $this->assertSame('SUM(`price`) AS `total`', $sql);
    }

    public function testCompileAggregateAvgStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileAggregate(Query::avg('score', 'avg_score'));
        $this->assertSame('AVG(`score`) AS `avg_score`', $sql);
    }

    public function testCompileAggregateMinStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileAggregate(Query::min('price', 'lowest'));
        $this->assertSame('MIN(`price`) AS `lowest`', $sql);
    }

    public function testCompileAggregateMaxStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileAggregate(Query::max('price', 'highest'));
        $this->assertSame('MAX(`price`) AS `highest`', $sql);
    }

    public function testCompileGroupByStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileGroupBy(Query::groupBy(['status', 'country']));
        $this->assertSame('`status`, `country`', $sql);
    }

    public function testCompileJoinStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileJoin(Query::join('orders', 'users.id', 'orders.uid'));
        $this->assertSame('JOIN `orders` ON `users`.`id` = `orders`.`uid`', $sql);
    }

    public function testCompileLeftJoinStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileJoin(Query::leftJoin('profiles', 'users.id', 'profiles.uid'));
        $this->assertSame('LEFT JOIN `profiles` ON `users`.`id` = `profiles`.`uid`', $sql);
    }

    public function testCompileRightJoinStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileJoin(Query::rightJoin('orders', 'users.id', 'orders.uid'));
        $this->assertSame('RIGHT JOIN `orders` ON `users`.`id` = `orders`.`uid`', $sql);
    }

    public function testCompileCrossJoinStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileJoin(Query::crossJoin('colors'));
        $this->assertSame('CROSS JOIN `colors`', $sql);
    }

    public function testCompileNestedJoinOn(): void
    {
        $builder = new Builder();
        $sql = $builder->compileJoin(Query::leftJoin('orders', 'ord', [
            Query::on('users.id', 'orders.user_id'),
            Query::equal('ord.status', ['paid']),
        ]));
        $this->assertSame(
            'LEFT JOIN `orders` AS `ord` ON `users`.`id` = `orders`.`user_id` AND `ord`.`status` IN (?)',
            $sql,
        );
        $this->assertSame(['paid'], $builder->getBindings());
    }

    public function testBuildNestedJoinOn(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([
                Query::leftJoin('orders', 'ord', [
                    Query::on('users.id', 'orders.user_id'),
                    Query::equal('ord.status', ['paid']),
                ]),
            ])
            ->build();

        $this->assertSame(
            'SELECT * FROM `users` LEFT JOIN `orders` AS `ord` ON `users`.`id` = `orders`.`user_id` AND `ord`.`status` IN (?)',
            $result->query,
        );
        $this->assertSame(['paid'], $result->bindings);
    }

    public function testBuildNestedJoinOnQualifiesUnqualifiedOperandsWithBaseAlias(): void
    {
        $result = (new Builder())
            ->from('users', 'u')
            ->filter([
                Query::leftJoin('orders', 'ord', [
                    Query::on('id', 'userId'),
                ]),
            ])
            ->build();

        $this->assertSame(
            'SELECT * FROM `users` AS `u` LEFT JOIN `orders` AS `ord` ON `u`.`id` = `u`.`userId`',
            $result->query,
        );
    }
    //  6. Filter edge cases

    public function testEqualWithSingleValue(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('status', ['active'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `status` IN (?)', $result->query);
        $this->assertSame(['active'], $result->bindings);
    }

    public function testEqualWithManyValues(): void
    {
        $values = range(1, 10);
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('id', $values)])
            ->build();
        $this->assertBindingCount($result);

        $placeholders = implode(', ', array_fill(0, 10, '?'));
        $this->assertSame("SELECT * FROM `t` WHERE `id` IN ({$placeholders})", $result->query);
        $this->assertSame($values, $result->bindings);
    }

    public function testEqualWithEmptyArray(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('id', [])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE 1 = 0', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testNotEqualWithExactlyTwoValues(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notEqual('role', ['guest', 'banned'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `role` NOT IN (?, ?)', $result->query);
        $this->assertSame(['guest', 'banned'], $result->bindings);
    }

    public function testBetweenWithSameMinAndMax(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::between('age', 25, 25)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `age` BETWEEN ? AND ?', $result->query);
        $this->assertSame([25, 25], $result->bindings);
    }

    public function testStartsWithEmptyString(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::startsWith('name', '')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `name` LIKE ?', $result->query);
        $this->assertSame(['%'], $result->bindings);
    }

    public function testEndsWithEmptyString(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::endsWith('name', '')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `name` LIKE ?', $result->query);
        $this->assertSame(['%'], $result->bindings);
    }

    public function testContainsWithSingleEmptyString(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::containsString('bio', [''])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `bio` LIKE ?', $result->query);
        $this->assertSame(['%%'], $result->bindings);
    }

    public function testContainsWithManyValues(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::containsString('bio', ['a', 'b', 'c', 'd', 'e'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`bio` LIKE ? OR `bio` LIKE ? OR `bio` LIKE ? OR `bio` LIKE ? OR `bio` LIKE ?)', $result->query);
        $this->assertSame(['%a%', '%b%', '%c%', '%d%', '%e%'], $result->bindings);
    }

    public function testContainsAllWithSingleValue(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::containsAll('perms', ['read'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`perms` LIKE ?)', $result->query);
        $this->assertSame(['%read%'], $result->bindings);
    }

    public function testNotContainsWithEmptyStringValue(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notContains('bio', [''])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `bio` NOT LIKE ?', $result->query);
        $this->assertSame(['%%'], $result->bindings);
    }

    public function testComparisonWithFloatValues(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::greaterThan('price', 9.99)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `price` > ?', $result->query);
        $this->assertSame([9.99], $result->bindings);
    }

    public function testComparisonWithNegativeValues(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::lessThan('balance', -100)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `balance` < ?', $result->query);
        $this->assertSame([-100], $result->bindings);
    }

    public function testComparisonWithZero(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::greaterThanEqual('score', 0)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `score` >= ?', $result->query);
        $this->assertSame([0], $result->bindings);
    }

    public function testComparisonWithVeryLargeInteger(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::lessThan('id', 9999999999999)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame([9999999999999], $result->bindings);
    }

    public function testComparisonWithStringValues(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::greaterThan('name', 'M')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `name` > ?', $result->query);
        $this->assertSame(['M'], $result->bindings);
    }

    public function testBetweenWithStringValues(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::between('created_at', '2024-01-01', '2024-12-31')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `created_at` BETWEEN ? AND ?', $result->query);
        $this->assertSame(['2024-01-01', '2024-12-31'], $result->bindings);
    }

    public function testIsNullCombinedWithIsNotNullOnDifferentColumns(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::isNull('deleted_at'),
                Query::isNotNull('verified_at'),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE `deleted_at` IS NULL AND `verified_at` IS NOT NULL',
            $result->query
        );
        $this->assertSame([], $result->bindings);
    }

    public function testMultipleIsNullFilters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::isNull('a'),
                Query::isNull('b'),
                Query::isNull('c'),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE `a` IS NULL AND `b` IS NULL AND `c` IS NULL',
            $result->query
        );
    }

    public function testExistsWithSingleAttribute(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::exists(['name'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`name` IS NOT NULL)', $result->query);
    }

    public function testExistsWithManyAttributes(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::exists(['a', 'b', 'c', 'd'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE (`a` IS NOT NULL AND `b` IS NOT NULL AND `c` IS NOT NULL AND `d` IS NOT NULL)',
            $result->query
        );
    }

    public function testNotExistsWithManyAttributes(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notExists(['a', 'b', 'c'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE (`a` IS NULL AND `b` IS NULL AND `c` IS NULL)',
            $result->query
        );
    }

    public function testAndWithSingleSubQuery(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::and([
                    Query::equal('a', [1]),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`a` IN (?))', $result->query);
        $this->assertSame([1], $result->bindings);
    }

    public function testOrWithSingleSubQuery(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::or([
                    Query::equal('a', [1]),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`a` IN (?))', $result->query);
        $this->assertSame([1], $result->bindings);
    }

    public function testAndWithManySubQueries(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::and([
                    Query::equal('a', [1]),
                    Query::equal('b', [2]),
                    Query::equal('c', [3]),
                    Query::equal('d', [4]),
                    Query::equal('e', [5]),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE (`a` IN (?) AND `b` IN (?) AND `c` IN (?) AND `d` IN (?) AND `e` IN (?))',
            $result->query
        );
        $this->assertSame([1, 2, 3, 4, 5], $result->bindings);
    }

    public function testOrWithManySubQueries(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::or([
                    Query::equal('a', [1]),
                    Query::equal('b', [2]),
                    Query::equal('c', [3]),
                    Query::equal('d', [4]),
                    Query::equal('e', [5]),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE (`a` IN (?) OR `b` IN (?) OR `c` IN (?) OR `d` IN (?) OR `e` IN (?))',
            $result->query
        );
    }

    public function testDeeplyNestedAndOrAnd(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::and([
                    Query::or([
                        Query::and([
                            Query::equal('a', [1]),
                            Query::equal('b', [2]),
                        ]),
                        Query::equal('c', [3]),
                    ]),
                    Query::equal('d', [4]),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE (((`a` IN (?) AND `b` IN (?)) OR `c` IN (?)) AND `d` IN (?))',
            $result->query
        );
        $this->assertSame([1, 2, 3, 4], $result->bindings);
    }

    public function testRawWithManyBindings(): void
    {
        $bindings = range(1, 10);
        $placeholders = implode(' AND ', array_map(fn ($i) => "col{$i} = ?", range(1, 10)));
        $result = (new Builder())
            ->from('t')
            ->filter([Query::raw($placeholders, $bindings)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame("SELECT * FROM `t` WHERE {$placeholders}", $result->query);
        $this->assertSame($bindings, $result->bindings);
    }

    public function testFilterWithDotsInAttributeName(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('table.column', ['value'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `table`.`column` IN (?)', $result->query);
    }

    public function testFilterWithUnderscoresInAttributeName(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('my_column_name', ['value'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `my_column_name` IN (?)', $result->query);
    }

    public function testFilterWithNumericAttributeName(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('123', ['value'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `123` IN (?)', $result->query);
    }
    //  7. Aggregation edge cases

    public function testCountWithoutAliasNoAsClause(): void
    {
        $result = (new Builder())->from('t')->count()->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT COUNT(*) FROM `t`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testSumWithoutAliasNoAsClause(): void
    {
        $result = (new Builder())->from('t')->sum('price')->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT SUM(`price`) FROM `t`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testAvgWithoutAliasNoAsClause(): void
    {
        $result = (new Builder())->from('t')->avg('score')->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT AVG(`score`) FROM `t`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testMinWithoutAliasNoAsClause(): void
    {
        $result = (new Builder())->from('t')->min('price')->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT MIN(`price`) FROM `t`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testMaxWithoutAliasNoAsClause(): void
    {
        $result = (new Builder())->from('t')->max('price')->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT MAX(`price`) FROM `t`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testCountWithAlias2(): void
    {
        $result = (new Builder())->from('t')->count('*', 'cnt')->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `t`', $result->query);
    }

    public function testSumWithAlias(): void
    {
        $result = (new Builder())->from('t')->sum('price', 'total')->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT SUM(`price`) AS `total` FROM `t`', $result->query);
    }

    public function testAvgWithAlias(): void
    {
        $result = (new Builder())->from('t')->avg('score', 'avg_s')->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT AVG(`score`) AS `avg_s` FROM `t`', $result->query);
    }

    public function testMinWithAlias(): void
    {
        $result = (new Builder())->from('t')->min('price', 'lowest')->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT MIN(`price`) AS `lowest` FROM `t`', $result->query);
    }

    public function testMaxWithAlias(): void
    {
        $result = (new Builder())->from('t')->max('price', 'highest')->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT MAX(`price`) AS `highest` FROM `t`', $result->query);
    }

    public function testMultipleSameAggregationType(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count('id', 'count_id')
            ->count('*', 'count_all')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT COUNT(`id`) AS `count_id`, COUNT(*) AS `count_all` FROM `t`',
            $result->query
        );
    }

    public function testAggregationStarAndNamedColumnMixed(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count('*', 'total')
            ->sum('price', 'price_sum')
            ->select(['category'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `total`, SUM(`price`) AS `price_sum`, `category` FROM `t`', $result->query);
    }

    public function testAggregationFilterSortLimitCombined(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'cnt')
            ->filter([Query::equal('status', ['paid'])])
            ->groupBy(['category'])
            ->sortDesc('cnt')
            ->limit(5)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `orders` WHERE `status` IN (?) GROUP BY `category` ORDER BY `cnt` DESC LIMIT ?', $result->query);
        $this->assertSame(['paid', 5], $result->bindings);
    }

    public function testAggregationJoinGroupByHavingSortLimitFullPipeline(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'cnt')
            ->sum('total', 'revenue')
            ->select(['users.name'])
            ->join('users', 'orders.user_id', 'users.id')
            ->filter([Query::greaterThan('orders.total', 0)])
            ->groupBy(['users.name'])
            ->having([Query::greaterThan('cnt', 2)])
            ->sortDesc('revenue')
            ->limit(20)
            ->offset(10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt`, SUM(`total`) AS `revenue`, `users`.`name` FROM `orders` JOIN `users` ON `orders`.`user_id` = `users`.`id` WHERE `orders`.`total` > ? GROUP BY `users`.`name` HAVING COUNT(*) > ? ORDER BY `revenue` DESC LIMIT ? OFFSET ?', $result->query);
        $this->assertSame([0, 2, 20, 10], $result->bindings);
    }

    public function testAggregationWithAttributeResolver(): void
    {
        $result = (new Builder())
            ->from('t')
            ->addHook(new AttributeMap([
                '$amount' => '_amount',
            ]))
            ->sum('$amount', 'total')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT SUM(`_amount`) AS `total` FROM `t`', $result->query);
    }

    public function testMinMaxWithStringColumns(): void
    {
        $result = (new Builder())
            ->from('t')
            ->min('name', 'first_name')
            ->max('name', 'last_name')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT MIN(`name`) AS `first_name`, MAX(`name`) AS `last_name` FROM `t`',
            $result->query
        );
    }
    //  8. Join edge cases

    public function testSelfJoin(): void
    {
        $result = (new Builder())
            ->from('employees')
            ->join('employees', 'employees.manager_id', 'employees.id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `employees` JOIN `employees` ON `employees`.`manager_id` = `employees`.`id`',
            $result->query
        );
    }

    public function testJoinWithVeryLongTableAndColumnNames(): void
    {
        $longTable = str_repeat('a', 100);
        $longLeft = str_repeat('b', 100);
        $longRight = str_repeat('c', 100);
        $result = (new Builder())
            ->from('main')
            ->join($longTable, $longLeft, $longRight)
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringContainsString("JOIN `{$longTable}`", $result->query);
        $this->assertStringContainsString("ON `{$longLeft}` = `{$longRight}`", $result->query);
    }

    public function testJoinFilterSortLimitOffsetCombined(): void
    {
        $result = (new Builder())
            ->from('users')
            ->join('orders', 'users.id', 'orders.user_id')
            ->filter([
                Query::equal('orders.status', ['paid']),
                Query::greaterThan('orders.total', 100),
            ])
            ->sortDesc('orders.total')
            ->limit(25)
            ->offset(50)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` JOIN `orders` ON `users`.`id` = `orders`.`user_id` WHERE `orders`.`status` IN (?) AND `orders`.`total` > ? ORDER BY `orders`.`total` DESC LIMIT ? OFFSET ?', $result->query);
        $this->assertSame(['paid', 100, 25, 50], $result->bindings);
    }

    public function testJoinAggregationGroupByHavingCombined(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'cnt')
            ->join('users', 'orders.user_id', 'users.id')
            ->groupBy(['users.name'])
            ->having([Query::greaterThan('cnt', 3)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `orders` JOIN `users` ON `orders`.`user_id` = `users`.`id` GROUP BY `users`.`name` HAVING COUNT(*) > ?', $result->query);
        $this->assertSame([3], $result->bindings);
    }

    public function testJoinWithDistinct(): void
    {
        $result = (new Builder())
            ->from('users')
            ->distinct()
            ->select(['users.name'])
            ->join('orders', 'users.id', 'orders.user_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT DISTINCT `users`.`name` FROM `users` JOIN `orders` ON `users`.`id` = `orders`.`user_id`', $result->query);
    }

    public function testJoinWithUnion(): void
    {
        $sub = (new Builder())
            ->from('archived_users')
            ->join('archived_orders', 'archived_users.id', 'archived_orders.user_id');

        $result = (new Builder())
            ->from('users')
            ->join('orders', 'users.id', 'orders.user_id')
            ->union($sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM `users` JOIN `orders` ON `users`.`id` = `orders`.`user_id`) UNION (SELECT * FROM `archived_users` JOIN `archived_orders` ON `archived_users`.`id` = `archived_orders`.`user_id`)', $result->query);
    }

    public function testFourJoins(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->join('users', 'orders.user_id', 'users.id')
            ->leftJoin('products', 'orders.product_id', 'products.id')
            ->rightJoin('categories', 'products.cat_id', 'categories.id')
            ->crossJoin('promotions')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `orders` JOIN `users` ON `orders`.`user_id` = `users`.`id` LEFT JOIN `products` ON `orders`.`product_id` = `products`.`id` RIGHT JOIN `categories` ON `products`.`cat_id` = `categories`.`id` CROSS JOIN `promotions`', $result->query);
    }

    public function testJoinWithAttributeResolverOnJoinColumns(): void
    {
        $result = (new Builder())
            ->from('t')
            ->addHook(new AttributeMap([
                '$id' => '_uid',
                '$ref' => '_ref_id',
            ]))
            ->join('other', '$id', '$ref')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` JOIN `other` ON `_uid` = `_ref_id`',
            $result->query
        );
    }

    public function testCrossJoinCombinedWithFilter(): void
    {
        $result = (new Builder())
            ->from('sizes')
            ->crossJoin('colors')
            ->filter([Query::equal('sizes.active', [true])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `sizes` CROSS JOIN `colors` WHERE `sizes`.`active` IN (?)', $result->query);
    }

    public function testCrossJoinFollowedByRegularJoin(): void
    {
        $result = (new Builder())
            ->from('a')
            ->crossJoin('b')
            ->join('c', 'a.id', 'c.a_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `a` CROSS JOIN `b` JOIN `c` ON `a`.`id` = `c`.`a_id`',
            $result->query
        );
    }

    public function testMultipleJoinsWithFiltersOnEach(): void
    {
        $result = (new Builder())
            ->from('users')
            ->join('orders', 'users.id', 'orders.user_id')
            ->leftJoin('profiles', 'users.id', 'profiles.user_id')
            ->filter([
                Query::greaterThan('orders.total', 50),
                Query::isNotNull('profiles.avatar'),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` JOIN `orders` ON `users`.`id` = `orders`.`user_id` LEFT JOIN `profiles` ON `users`.`id` = `profiles`.`user_id` WHERE `orders`.`total` > ? AND `profiles`.`avatar` IS NOT NULL', $result->query);
    }

    public function testJoinWithCustomOperatorLessThan(): void
    {
        $result = (new Builder())
            ->from('a')
            ->join('b', 'a.start', 'b.end', '<')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `a` JOIN `b` ON `a`.`start` < `b`.`end`',
            $result->query
        );
    }

    public function testFiveJoins(): void
    {
        $result = (new Builder())
            ->from('t1')
            ->join('t2', 't1.id', 't2.t1_id')
            ->join('t3', 't2.id', 't3.t2_id')
            ->join('t4', 't3.id', 't4.t3_id')
            ->join('t5', 't4.id', 't5.t4_id')
            ->join('t6', 't5.id', 't6.t5_id')
            ->build();
        $this->assertBindingCount($result);

        $query = $result->query;
        $this->assertSame(5, substr_count($query, 'JOIN'));
    }
    //  9. Union edge cases

    public function testUnionWithThreeSubQueries(): void
    {
        $q1 = (new Builder())->from('a');
        $q2 = (new Builder())->from('b');
        $q3 = (new Builder())->from('c');

        $result = (new Builder())
            ->from('main')
            ->union($q1)
            ->union($q2)
            ->union($q3)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            '(SELECT * FROM `main`) UNION (SELECT * FROM `a`) UNION (SELECT * FROM `b`) UNION (SELECT * FROM `c`)',
            $result->query
        );
    }

    public function testUnionAllWithThreeSubQueries(): void
    {
        $q1 = (new Builder())->from('a');
        $q2 = (new Builder())->from('b');
        $q3 = (new Builder())->from('c');

        $result = (new Builder())
            ->from('main')
            ->unionAll($q1)
            ->unionAll($q2)
            ->unionAll($q3)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            '(SELECT * FROM `main`) UNION ALL (SELECT * FROM `a`) UNION ALL (SELECT * FROM `b`) UNION ALL (SELECT * FROM `c`)',
            $result->query
        );
    }

    public function testMixedUnionAndUnionAllWithThreeSubQueries(): void
    {
        $q1 = (new Builder())->from('a');
        $q2 = (new Builder())->from('b');
        $q3 = (new Builder())->from('c');

        $result = (new Builder())
            ->from('main')
            ->union($q1)
            ->unionAll($q2)
            ->union($q3)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            '(SELECT * FROM `main`) UNION (SELECT * FROM `a`) UNION ALL (SELECT * FROM `b`) UNION (SELECT * FROM `c`)',
            $result->query
        );
    }

    public function testUnionWhereSubQueryHasJoins(): void
    {
        $sub = (new Builder())
            ->from('archived_users')
            ->join('archived_orders', 'archived_users.id', 'archived_orders.user_id');

        $result = (new Builder())
            ->from('users')
            ->union($sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM `users`) UNION (SELECT * FROM `archived_users` JOIN `archived_orders` ON `archived_users`.`id` = `archived_orders`.`user_id`)', $result->query);
    }

    public function testUnionWhereSubQueryHasAggregation(): void
    {
        $sub = (new Builder())
            ->from('orders_2023')
            ->count('*', 'cnt')
            ->groupBy(['status']);

        $result = (new Builder())
            ->from('orders_2024')
            ->count('*', 'cnt')
            ->groupBy(['status'])
            ->union($sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT COUNT(*) AS `cnt` FROM `orders_2024` GROUP BY `status`) UNION (SELECT COUNT(*) AS `cnt` FROM `orders_2023` GROUP BY `status`)', $result->query);
    }

    public function testUnionWhereSubQueryHasSortAndLimit(): void
    {
        $sub = (new Builder())
            ->from('archive')
            ->sortDesc('created_at')
            ->limit(10);

        $result = (new Builder())
            ->from('current')
            ->union($sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM `current`) UNION (SELECT * FROM `archive` ORDER BY `created_at` DESC LIMIT ?)', $result->query);
    }

    public function testUnionWithConditionProviders(): void
    {
        $sub = (new Builder())
            ->from('other')
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('org = ?', ['org2']);
                }
            });

        $result = (new Builder())
            ->from('main')
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('org = ?', ['org1']);
                }
            })
            ->union($sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM `main` WHERE org = ?) UNION (SELECT * FROM `other` WHERE org = ?)', $result->query);
        $this->assertSame(['org1', 'org2'], $result->bindings);
    }

    public function testUnionBindingOrderWithComplexSubQueries(): void
    {
        $sub = (new Builder())
            ->from('archive')
            ->filter([Query::equal('year', [2023])])
            ->limit(5);

        $result = (new Builder())
            ->from('current')
            ->filter([Query::equal('status', ['active'])])
            ->limit(10)
            ->union($sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['active', 10, 2023, 5], $result->bindings);
    }

    public function testUnionWithDistinct(): void
    {
        $sub = (new Builder())
            ->from('archive')
            ->distinct()
            ->select(['name']);

        $result = (new Builder())
            ->from('current')
            ->distinct()
            ->select(['name'])
            ->union($sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT DISTINCT `name` FROM `current`) UNION (SELECT DISTINCT `name` FROM `archive`)', $result->query);
    }

    public function testUnionAfterReset(): void
    {
        $builder = (new Builder())->from('old');
        $builder->build();
        $builder->reset();

        $sub = (new Builder())->from('other');
        $result = $builder->from('fresh')->union($sub)->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            '(SELECT * FROM `fresh`) UNION (SELECT * FROM `other`)',
            $result->query
        );
    }

    public function testUnionChainedWithComplexBindings(): void
    {
        $q1 = (new Builder())
            ->from('a')
            ->filter([Query::equal('x', [1]), Query::greaterThan('y', 2)]);
        $q2 = (new Builder())
            ->from('b')
            ->filter([Query::between('z', 10, 20)]);

        $result = (new Builder())
            ->from('main')
            ->filter([Query::equal('status', ['active'])])
            ->union($q1)
            ->unionAll($q2)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['active', 1, 2, 10, 20], $result->bindings);
    }

    public function testUnionWithFourSubQueries(): void
    {
        $q1 = (new Builder())->from('t1');
        $q2 = (new Builder())->from('t2');
        $q3 = (new Builder())->from('t3');
        $q4 = (new Builder())->from('t4');

        $result = (new Builder())
            ->from('main')
            ->union($q1)
            ->union($q2)
            ->union($q3)
            ->union($q4)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(4, substr_count($result->query, 'UNION'));
    }

    public function testUnionAllWithFilteredSubQueries(): void
    {
        $q1 = (new Builder())->from('orders_2022')->filter([Query::equal('status', ['paid'])]);
        $q2 = (new Builder())->from('orders_2023')->filter([Query::equal('status', ['paid'])]);
        $q3 = (new Builder())->from('orders_2024')->filter([Query::equal('status', ['paid'])]);

        $result = (new Builder())
            ->from('orders_2025')
            ->filter([Query::equal('status', ['paid'])])
            ->unionAll($q1)
            ->unionAll($q2)
            ->unionAll($q3)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['paid', 'paid', 'paid', 'paid'], $result->bindings);
        $this->assertSame(3, substr_count($result->query, 'UNION ALL'));
    }
    //  10. toRawSql edge cases

    public function testToRawSqlWithAllBindingTypesInOneQuery(): void
    {
        $sql = (new Builder())
            ->from('t')
            ->filter([
                Query::equal('name', ['Alice']),
                Query::greaterThan('age', 18),
                Query::raw('active = ?', [true]),
                Query::raw('deleted = ?', [null]),
                Query::raw('score > ?', [9.5]),
            ])
            ->limit(10)
            ->toRawSql();

        $this->assertSame('SELECT * FROM `t` WHERE `name` IN (\'Alice\') AND `age` > 18 AND active = 1 AND deleted = NULL AND score > 9.5 LIMIT 10', $sql);
        $this->assertStringNotContainsString('?', $sql);
    }

    public function testToRawSqlWithEmptyStringBinding(): void
    {
        $sql = (new Builder())
            ->from('t')
            ->filter([Query::equal('name', [''])])
            ->toRawSql();

        $this->assertSame('SELECT * FROM `t` WHERE `name` IN (\'\')', $sql);
    }

    public function testToRawSqlWithStringContainingSingleQuotes(): void
    {
        $sql = (new Builder())
            ->from('t')
            ->filter([Query::equal('name', ["O'Brien"])])
            ->toRawSql();

        $this->assertSame('SELECT * FROM `t` WHERE `name` IN (\'O\'\'Brien\')', $sql);
    }

    public function testToRawSqlWithVeryLargeNumber(): void
    {
        $sql = (new Builder())
            ->from('t')
            ->filter([Query::greaterThan('id', 99999999999)])
            ->toRawSql();

        $this->assertSame('SELECT * FROM `t` WHERE `id` > 99999999999', $sql);
        $this->assertStringNotContainsString('?', $sql);
    }

    public function testToRawSqlWithNegativeNumber(): void
    {
        $sql = (new Builder())
            ->from('t')
            ->filter([Query::lessThan('balance', -500)])
            ->toRawSql();

        $this->assertSame('SELECT * FROM `t` WHERE `balance` < -500', $sql);
    }

    public function testToRawSqlWithZero(): void
    {
        $sql = (new Builder())
            ->from('t')
            ->filter([Query::equal('count', [0])])
            ->toRawSql();

        $this->assertSame('SELECT * FROM `t` WHERE `count` IN (0)', $sql);
        $this->assertStringNotContainsString('?', $sql);
    }

    public function testToRawSqlWithFalseBoolean(): void
    {
        $sql = (new Builder())
            ->from('t')
            ->filter([Query::raw('active = ?', [false])])
            ->toRawSql();

        $this->assertSame('SELECT * FROM `t` WHERE active = 0', $sql);
    }

    public function testToRawSqlWithMultipleNullBindings(): void
    {
        $sql = (new Builder())
            ->from('t')
            ->filter([Query::raw('a = ? AND b = ?', [null, null])])
            ->toRawSql();

        $this->assertSame("SELECT * FROM `t` WHERE a = NULL AND b = NULL", $sql);
    }

    public function testToRawSqlWithAggregationQuery(): void
    {
        $sql = (new Builder())
            ->from('orders')
            ->count('*', 'total')
            ->groupBy(['status'])
            ->having([Query::greaterThan('total', 5)])
            ->toRawSql();

        $this->assertSame('SELECT COUNT(*) AS `total` FROM `orders` GROUP BY `status` HAVING COUNT(*) > 5', $sql);
        $this->assertStringNotContainsString('?', $sql);
    }

    public function testToRawSqlWithJoinQuery(): void
    {
        $sql = (new Builder())
            ->from('users')
            ->join('orders', 'users.id', 'orders.uid')
            ->filter([Query::greaterThan('orders.total', 100)])
            ->toRawSql();

        $this->assertSame('SELECT * FROM `users` JOIN `orders` ON `users`.`id` = `orders`.`uid` WHERE `orders`.`total` > 100', $sql);
        $this->assertStringNotContainsString('?', $sql);
    }

    public function testToRawSqlWithUnionQuery(): void
    {
        $sub = (new Builder())->from('archive')->filter([Query::equal('year', [2023])]);

        $sql = (new Builder())
            ->from('current')
            ->filter([Query::equal('year', [2024])])
            ->union($sub)
            ->toRawSql();

        $this->assertSame('(SELECT * FROM `current` WHERE `year` IN (2024)) UNION (SELECT * FROM `archive` WHERE `year` IN (2023))', $sql);
        $this->assertStringNotContainsString('?', $sql);
    }

    public function testToRawSqlWithRegexAndSearch(): void
    {
        $sql = (new Builder())
            ->from('t')
            ->filter([
                Query::regex('slug', '^test'),
                Query::search('content', 'hello'),
            ])
            ->toRawSql();

        $this->assertSame('SELECT * FROM `t` WHERE `slug` REGEXP \'^test\' AND MATCH(`content`) AGAINST(\'hello*\' IN BOOLEAN MODE)', $sql);
        $this->assertStringNotContainsString('?', $sql);
    }

    public function testToRawSqlCalledTwiceGivesSameResult(): void
    {
        $builder = (new Builder())
            ->from('t')
            ->filter([Query::equal('status', ['active'])])
            ->limit(10);

        $sql1 = $builder->toRawSql();
        $sql2 = $builder->toRawSql();

        $this->assertSame($sql1, $sql2);
    }
    //  11. when() edge cases

    public function testWhenWithComplexCallbackAddingMultipleFeatures(): void
    {
        $result = (new Builder())
            ->from('t')
            ->when(true, function (Builder $b) {
                $b->filter([Query::equal('status', ['active'])])
                    ->sortAsc('name')
                    ->limit(10);
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `status` IN (?) ORDER BY `name` ASC LIMIT ?', $result->query);
        $this->assertSame(['active', 10], $result->bindings);
    }

    public function testWhenChainedFiveTimes(): void
    {
        $result = (new Builder())
            ->from('t')
            ->when(true, fn (Builder $b) => $b->filter([Query::equal('a', [1])]))
            ->when(true, fn (Builder $b) => $b->filter([Query::equal('b', [2])]))
            ->when(false, fn (Builder $b) => $b->filter([Query::equal('c', [3])]))
            ->when(true, fn (Builder $b) => $b->filter([Query::equal('d', [4])]))
            ->when(true, fn (Builder $b) => $b->filter([Query::equal('e', [5])]))
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE `a` IN (?) AND `b` IN (?) AND `d` IN (?) AND `e` IN (?)',
            $result->query
        );
        $this->assertSame([1, 2, 4, 5], $result->bindings);
    }

    public function testWhenInsideWhenThreeLevelsDeep(): void
    {
        $result = (new Builder())
            ->from('t')
            ->when(true, function (Builder $b) {
                $b->when(true, function (Builder $b2) {
                    $b2->when(true, fn (Builder $b3) => $b3->filter([Query::equal('deep', [1])]));
                });
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `deep` IN (?)', $result->query);
        $this->assertSame([1], $result->bindings);
    }

    public function testWhenThatAddsJoins(): void
    {
        $result = (new Builder())
            ->from('users')
            ->when(true, fn (Builder $b) => $b->join('orders', 'users.id', 'orders.uid'))
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` JOIN `orders` ON `users`.`id` = `orders`.`uid`', $result->query);
    }

    public function testWhenThatAddsAggregations(): void
    {
        $result = (new Builder())
            ->from('t')
            ->when(true, fn (Builder $b) => $b->count('*', 'total')->groupBy(['status']))
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `total` FROM `t` GROUP BY `status`', $result->query);
    }

    public function testWhenThatAddsUnions(): void
    {
        $sub = (new Builder())->from('archive');

        $result = (new Builder())
            ->from('current')
            ->when(true, fn (Builder $b) => $b->union($sub))
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM `current`) UNION (SELECT * FROM `archive`)', $result->query);
    }

    public function testWhenFalseDoesNotAffectFilters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->when(false, fn (Builder $b) => $b->filter([Query::equal('status', ['banned'])]))
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t`', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testWhenFalseDoesNotAffectJoins(): void
    {
        $result = (new Builder())
            ->from('t')
            ->when(false, fn (Builder $b) => $b->join('other', 'a', 'b'))
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString('JOIN', $result->query);
    }

    public function testWhenFalseDoesNotAffectAggregations(): void
    {
        $result = (new Builder())
            ->from('t')
            ->when(false, fn (Builder $b) => $b->count('*', 'total'))
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t`', $result->query);
    }

    public function testWhenFalseDoesNotAffectSort(): void
    {
        $result = (new Builder())
            ->from('t')
            ->when(false, fn (Builder $b) => $b->sortAsc('name'))
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString('ORDER BY', $result->query);
    }
    //  12. Condition provider edge cases

    public function testThreeConditionProviders(): void
    {
        $result = (new Builder())
            ->from('t')
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('p1 = ?', ['v1']);
                }
            })
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('p2 = ?', ['v2']);
                }
            })
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('p3 = ?', ['v3']);
                }
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE p1 = ? AND p2 = ? AND p3 = ?',
            $result->query
        );
        $this->assertSame(['v1', 'v2', 'v3'], $result->bindings);
    }

    public function testProviderReturningEmptyConditionString(): void
    {
        $result = (new Builder())
            ->from('t')
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('', []);
                }
            })
            ->build();
        $this->assertBindingCount($result);

        // Empty string still appears as a WHERE clause element
        $this->assertSame('SELECT * FROM `t` WHERE ', $result->query);
    }

    public function testProviderWithManyBindings(): void
    {
        $result = (new Builder())
            ->from('t')
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('a IN (?, ?, ?, ?, ?)', [1, 2, 3, 4, 5]);
                }
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE a IN (?, ?, ?, ?, ?)',
            $result->query
        );
        $this->assertSame([1, 2, 3, 4, 5], $result->bindings);
    }

    public function testProviderCombinedWithCursorFilterHaving(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count('*', 'cnt')
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('org = ?', ['org1']);
                }
            })
            ->filter([Query::equal('status', ['active'])])
            ->cursorAfter('cur1')
            ->groupBy(['status'])
            ->having([Query::greaterThan('cnt', 5)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `t` WHERE `status` IN (?) AND org = ? AND `_cursor` > ? GROUP BY `status` HAVING COUNT(*) > ?', $result->query);
        // filter, provider, cursor, having
        $this->assertSame(['active', 'org1', 'cur1', 5], $result->bindings);
    }

    public function testProviderCombinedWithJoins(): void
    {
        $result = (new Builder())
            ->from('users')
            ->join('orders', 'users.id', 'orders.uid')
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('tenant = ?', ['t1']);
                }
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` JOIN `orders` ON `users`.`id` = `orders`.`uid` WHERE tenant = ?', $result->query);
        $this->assertSame(['t1'], $result->bindings);
    }

    public function testProviderCombinedWithUnions(): void
    {
        $sub = (new Builder())->from('archive');

        $result = (new Builder())
            ->from('current')
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('org = ?', ['org1']);
                }
            })
            ->union($sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM `current` WHERE org = ?) UNION (SELECT * FROM `archive`)', $result->query);
        $this->assertSame(['org1'], $result->bindings);
    }

    public function testProviderCombinedWithAggregations(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'total')
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('org = ?', ['org1']);
                }
            })
            ->groupBy(['status'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `total` FROM `orders` WHERE org = ? GROUP BY `status`', $result->query);
    }

    public function testProviderReferencesTableName(): void
    {
        $result = (new Builder())
            ->from('users')
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition("EXISTS (SELECT 1 FROM {$table}_perms WHERE type = ?)", ['read']);
                }
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE EXISTS (SELECT 1 FROM users_perms WHERE type = ?)', $result->query);
        $this->assertSame(['read'], $result->bindings);
    }

    public function testProviderBindingOrderWithComplexQuery(): void
    {
        $result = (new Builder())
            ->from('t')
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('p1 = ?', ['pv1']);
                }
            })
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('p2 = ?', ['pv2']);
                }
            })
            ->filter([
                Query::equal('a', ['va']),
                Query::greaterThan('b', 10),
            ])
            ->cursorAfter('cur')
            ->limit(5)
            ->offset(10)
            ->build();
        $this->assertBindingCount($result);

        // filter, provider1, provider2, cursor, limit, offset
        $this->assertSame(['va', 10, 'pv1', 'pv2', 'cur', 5, 10], $result->bindings);
    }

    public function testProviderPreservedAcrossReset(): void
    {
        $builder = (new Builder())
            ->from('t')
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('org = ?', ['org1']);
                }
            });

        $builder->build();
        $builder->reset();

        $result = $builder->from('t2')->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t2` WHERE org = ?', $result->query);
        $this->assertSame(['org1'], $result->bindings);
    }

    public function testFourConditionProviders(): void
    {
        $result = (new Builder())
            ->from('t')
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('a = ?', [1]);
                }
            })
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('b = ?', [2]);
                }
            })
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('c = ?', [3]);
                }
            })
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('d = ?', [4]);
                }
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE a = ? AND b = ? AND c = ? AND d = ?',
            $result->query
        );
        $this->assertSame([1, 2, 3, 4], $result->bindings);
    }

    public function testProviderWithNoBindings(): void
    {
        $result = (new Builder())
            ->from('t')
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('1 = 1', []);
                }
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE 1 = 1', $result->query);
        $this->assertSame([], $result->bindings);
    }
    //  13. Reset edge cases

    public function testResetPreservesAttributeResolver(): void
    {
        $builder = (new Builder())
            ->from('t')
            ->addHook(new class () implements Attribute {
                public function resolve(string $attribute): string
                {
                    return '_' . $attribute;
                }
            })
            ->filter([Query::equal('x', [1])]);

        $builder->build();
        $builder->reset();

        $result = $builder->from('t2')->filter([Query::equal('y', [2])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t2` WHERE `_y` IN (?)', $result->query);
    }

    public function testResetPreservesConditionProviders(): void
    {
        $builder = (new Builder())
            ->from('t')
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('org = ?', ['org1']);
                }
            });

        $builder->build();
        $builder->reset();

        $result = $builder->from('t2')->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t2` WHERE org = ?', $result->query);
        $this->assertSame(['org1'], $result->bindings);
    }

    public function testResetClearsPendingQueries(): void
    {
        $builder = (new Builder())
            ->from('t')
            ->filter([Query::equal('a', [1])])
            ->sortAsc('name')
            ->limit(10);

        $builder->build();
        $builder->reset();

        $result = $builder->from('t2')->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t2`', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testResetClearsBindings(): void
    {
        $builder = (new Builder())
            ->from('t')
            ->filter([Query::equal('a', [1])]);

        $builder->build();
        $this->assertNotEmpty($builder->getBindings());

        $builder->reset();
        $result = $builder->from('t2')->build();
        $this->assertBindingCount($result);
        $this->assertSame([], $result->bindings);
    }

    public function testResetClearsTable(): void
    {
        $builder = (new Builder())->from('old_table');
        $builder->build();
        $builder->reset();

        $result = $builder->from('new_table')->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `new_table`', $result->query);
        $this->assertStringNotContainsString('`old_table`', $result->query);
    }

    public function testResetClearsUnionsAfterBuild(): void
    {
        $sub = (new Builder())->from('other');
        $builder = (new Builder())->from('main')->union($sub);
        $builder->build();
        $builder->reset();

        $result = $builder->from('fresh')->build();
        $this->assertBindingCount($result);
        $this->assertStringNotContainsString('UNION', $result->query);
    }

    public function testBuildAfterResetProducesMinimalQuery(): void
    {
        $builder = (new Builder())
            ->from('complex')
            ->select(['a', 'b'])
            ->filter([Query::equal('x', [1])])
            ->sortAsc('a')
            ->limit(10)
            ->offset(5);

        $builder->build();
        $builder->reset();

        $result = $builder->from('t')->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t`', $result->query);
    }

    public function testMultipleResetCalls(): void
    {
        $builder = (new Builder())->from('t')->filter([Query::equal('a', [1])]);
        $builder->build();
        $builder->reset();
        $builder->reset();
        $builder->reset();

        $result = $builder->from('t2')->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t2`', $result->query);
    }

    public function testResetBetweenDifferentQueryTypes(): void
    {
        $builder = new Builder();

        // First: aggregation query
        $builder->from('orders')->count('*', 'total')->groupBy(['status']);
        $result1 = $builder->build();
        $this->assertSame('SELECT COUNT(*) AS `total` FROM `orders` GROUP BY `status`', $result1->query);

        $builder->reset();

        // Second: simple select query
        $builder->from('users')->select(['name'])->filter([Query::equal('active', [true])]);
        $result2 = $builder->build();
        $this->assertStringNotContainsString('COUNT', $result2->query);
        $this->assertSame('SELECT `name` FROM `users` WHERE `active` IN (?)', $result2->query);
    }

    public function testResetAfterUnion(): void
    {
        $sub = (new Builder())->from('other');
        $builder = (new Builder())->from('main')->union($sub);
        $builder->build();
        $builder->reset();

        $result = $builder->from('new')->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `new`', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testResetAfterComplexQueryWithAllFeatures(): void
    {
        $sub = (new Builder())->from('archive')->filter([Query::equal('year', [2023])]);

        $builder = (new Builder())
            ->from('orders')
            ->distinct()
            ->count('*', 'cnt')
            ->select(['status'])
            ->join('users', 'orders.uid', 'users.id')
            ->filter([Query::equal('status', ['paid'])])
            ->groupBy(['status'])
            ->having([Query::greaterThan('cnt', 1)])
            ->sortDesc('cnt')
            ->limit(10)
            ->offset(5)
            ->union($sub);

        $builder->build();
        $builder->reset();

        $result = $builder->from('simple')->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `simple`', $result->query);
        $this->assertSame([], $result->bindings);
    }
    //  14. Multiple build() calls

    public function testBuildTwiceModifyInBetween(): void
    {
        $builder = (new Builder())
            ->from('t')
            ->filter([Query::equal('a', [1])]);

        $result1 = $builder->build();

        $builder->filter([Query::equal('b', [2])]);
        $result2 = $builder->build();

        $this->assertStringNotContainsString('`b`', $result1->query);
        $this->assertSame('SELECT * FROM `t` WHERE `a` IN (?) AND `b` IN (?)', $result2->query);
    }

    public function testBuildDoesNotMutatePendingQueries(): void
    {
        $builder = (new Builder())
            ->from('t')
            ->filter([Query::equal('a', [1])])
            ->limit(10);

        $result1 = $builder->build();
        $result2 = $builder->build();

        $this->assertSame($result1->query, $result2->query);
        $this->assertSame($result1->bindings, $result2->bindings);
    }

    public function testBuildResetsBindingsEachTime(): void
    {
        $builder = (new Builder())
            ->from('t')
            ->filter([Query::equal('a', [1])]);

        $builder->build();
        $bindings1 = $builder->getBindings();

        $builder->build();
        $bindings2 = $builder->getBindings();

        $this->assertSame($bindings1, $bindings2);
        $this->assertCount(1, $bindings2);
    }

    public function testBuildWithConditionProducesConsistentBindings(): void
    {
        $builder = (new Builder())
            ->from('t')
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('org = ?', ['org1']);
                }
            })
            ->filter([Query::equal('status', ['active'])]);

        $result1 = $builder->build();
        $result2 = $builder->build();
        $result3 = $builder->build();

        $this->assertSame($result1->bindings, $result2->bindings);
        $this->assertSame($result2->bindings, $result3->bindings);
    }

    public function testBuildAfterAddingMoreQueries(): void
    {
        $builder = (new Builder())->from('t');

        $result1 = $builder->build();
        $this->assertSame('SELECT * FROM `t`', $result1->query);

        $builder->filter([Query::equal('a', [1])]);
        $result2 = $builder->build();
        $this->assertSame('SELECT * FROM `t` WHERE `a` IN (?)', $result2->query);

        $builder->sortAsc('a');
        $result3 = $builder->build();
        $this->assertSame('SELECT * FROM `t` WHERE `a` IN (?) ORDER BY `a` ASC', $result3->query);
    }

    public function testBuildWithUnionProducesConsistentResults(): void
    {
        $sub = (new Builder())->from('other')->filter([Query::equal('x', [1])]);
        $builder = (new Builder())->from('main')->union($sub);

        $result1 = $builder->build();
        $result2 = $builder->build();

        $this->assertSame($result1->query, $result2->query);
        $this->assertSame($result1->bindings, $result2->bindings);
    }

    public function testBuildThreeTimesWithIncreasingComplexity(): void
    {
        $builder = (new Builder())->from('t');

        $r1 = $builder->build();
        $this->assertSame('SELECT * FROM `t`', $r1->query);

        $builder->filter([Query::equal('a', [1])]);
        $r2 = $builder->build();
        $this->assertSame('SELECT * FROM `t` WHERE `a` IN (?)', $r2->query);

        $builder->limit(10)->offset(5);
        $r3 = $builder->build();
        $this->assertSame('SELECT * FROM `t` WHERE `a` IN (?) LIMIT ? OFFSET ?', $r3->query);
    }

    public function testBuildBindingsNotAccumulated(): void
    {
        $builder = (new Builder())
            ->from('t')
            ->filter([Query::equal('a', [1])])
            ->limit(10);

        $builder->build();
        $builder->build();
        $builder->build();

        $this->assertCount(2, $builder->getBindings());
    }

    public function testMultipleBuildWithHavingBindings(): void
    {
        $builder = (new Builder())
            ->from('t')
            ->count('*', 'cnt')
            ->groupBy(['status'])
            ->having([Query::greaterThan('cnt', 5)]);

        $r1 = $builder->build();
        $r2 = $builder->build();

        $this->assertSame([5], $r1->bindings);
        $this->assertSame([5], $r2->bindings);
    }
    //  15. Binding ordering comprehensive

    public function testBindingOrderMultipleFilters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::equal('a', ['v1']),
                Query::greaterThan('b', 10),
                Query::between('c', 1, 100),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['v1', 10, 1, 100], $result->bindings);
    }

    public function testBindingOrderThreeProviders(): void
    {
        $result = (new Builder())
            ->from('t')
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('p1 = ?', ['pv1']);
                }
            })
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('p2 = ?', ['pv2']);
                }
            })
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('p3 = ?', ['pv3']);
                }
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['pv1', 'pv2', 'pv3'], $result->bindings);
    }

    public function testBindingOrderMultipleUnions(): void
    {
        $q1 = (new Builder())->from('a')->filter([Query::equal('x', [1])]);
        $q2 = (new Builder())->from('b')->filter([Query::equal('y', [2])]);

        $result = (new Builder())
            ->from('main')
            ->filter([Query::equal('z', [3])])
            ->limit(5)
            ->union($q1)
            ->unionAll($q2)
            ->build();
        $this->assertBindingCount($result);

        // main filter, main limit, union1 bindings, union2 bindings
        $this->assertSame([3, 5, 1, 2], $result->bindings);
    }

    public function testBindingOrderLogicalAndWithMultipleSubFilters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::and([
                    Query::equal('a', [1]),
                    Query::greaterThan('b', 2),
                    Query::lessThan('c', 3),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame([1, 2, 3], $result->bindings);
    }

    public function testBindingOrderLogicalOrWithMultipleSubFilters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::or([
                    Query::equal('a', [1]),
                    Query::equal('b', [2]),
                    Query::equal('c', [3]),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame([1, 2, 3], $result->bindings);
    }

    public function testBindingOrderNestedAndOr(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::and([
                    Query::equal('a', [1]),
                    Query::or([
                        Query::equal('b', [2]),
                        Query::equal('c', [3]),
                    ]),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame([1, 2, 3], $result->bindings);
    }

    public function testBindingOrderRawMixedWithRegularFilters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::equal('a', ['v1']),
                Query::raw('custom > ?', [10]),
                Query::greaterThan('b', 20),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['v1', 10, 20], $result->bindings);
    }

    public function testBindingOrderAggregationHavingComplexConditions(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count('*', 'cnt')
            ->sum('price', 'total')
            ->filter([Query::equal('status', ['active'])])
            ->groupBy(['category'])
            ->having([
                Query::greaterThan('cnt', 5),
                Query::lessThan('total', 10000),
            ])
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);

        // filter, having1, having2, limit
        $this->assertSame(['active', 5, 10000, 10], $result->bindings);
    }

    public function testBindingOrderFullPipelineWithEverything(): void
    {
        $sub = (new Builder())->from('archive')->filter([Query::equal('archived', [true])]);

        $result = (new Builder())
            ->from('orders')
            ->count('*', 'cnt')
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('tenant = ?', ['t1']);
                }
            })
            ->filter([
                Query::equal('status', ['paid']),
                Query::greaterThan('total', 0),
            ])
            ->cursorAfter('cursor_val')
            ->groupBy(['status'])
            ->having([Query::greaterThan('cnt', 1)])
            ->limit(25)
            ->offset(50)
            ->union($sub)
            ->build();
        $this->assertBindingCount($result);

        // filter(paid, 0), provider(t1), cursor(cursor_val), having(1), limit(25), offset(50), union(true)
        $this->assertSame(['paid', 0, 't1', 'cursor_val', 1, 25, 50, true], $result->bindings);
    }

    public function testBindingOrderContainsMultipleValues(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::containsString('bio', ['php', 'js', 'go']),
                Query::equal('status', ['active']),
            ])
            ->build();
        $this->assertBindingCount($result);

        // contains produces three LIKE bindings, then equal
        $this->assertSame(['%php%', '%js%', '%go%', 'active'], $result->bindings);
    }

    public function testBindingOrderBetweenAndComparisons(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::between('age', 18, 65),
                Query::greaterThan('score', 50),
                Query::lessThan('rank', 100),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame([18, 65, 50, 100], $result->bindings);
    }

    public function testBindingOrderStartsWithEndsWith(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::startsWith('name', 'A'),
                Query::endsWith('email', '.com'),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['A%', '%.com'], $result->bindings);
    }

    public function testBindingOrderSearchAndRegex(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::search('content', 'hello'),
                Query::regex('slug', '^test'),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['hello*', '^test'], $result->bindings);
    }

    public function testBindingOrderWithCursorBeforeFilterAndLimit(): void
    {
        $result = (new Builder())
            ->from('t')
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('org = ?', ['org1']);
                }
            })
            ->filter([Query::equal('a', ['x'])])
            ->cursorBefore('my_cursor')
            ->limit(10)
            ->offset(0)
            ->build();
        $this->assertBindingCount($result);

        // filter, provider, cursor, limit, offset
        $this->assertSame(['x', 'org1', 'my_cursor', 10, 0], $result->bindings);
    }
    //  16. Empty/minimal queries

    public function testBuildWithNoFromNoFilters(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No table specified');
        (new Builder())->build();
    }

    public function testBuildWithOnlyLimit(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No table specified');
        (new Builder())
            ->limit(10)
            ->build();
    }

    public function testBuildWithOnlyOffset(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No table specified');
        (new Builder())
            ->offset(50)
            ->build();
    }

    public function testBuildWithOnlySort(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No table specified');
        (new Builder())
            ->sortAsc('name')
            ->build();
    }

    public function testBuildWithOnlySelect(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No table specified');
        (new Builder())
            ->select(['a', 'b'])
            ->build();
    }

    public function testBuildWithOnlyAggregationNoFrom(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No table specified');
        (new Builder())
            ->count('*', 'total')
            ->build();
    }

    public function testBuildWithEmptyFilterArray(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t`', $result->query);
    }

    public function testBuildWithEmptySelectArray(): void
    {
        $result = (new Builder())
            ->from('t')
            ->select([])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT  FROM `t`', $result->query);
    }

    public function testBuildWithOnlyHavingNoGroupBy(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count('*', 'cnt')
            ->having([Query::greaterThan('cnt', 0)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `t` HAVING COUNT(*) > ?', $result->query);
        $this->assertStringNotContainsString('GROUP BY', $result->query);
    }

    public function testBuildWithOnlyDistinct(): void
    {
        $result = (new Builder())
            ->from('t')
            ->distinct()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT DISTINCT * FROM `t`', $result->query);
    }
    //  Spatial/Vector/ElemMatch Exception Tests


    public function testSpatialCrosses(): void
    {
        $result = (new Builder())->from('t')->filter([Query::crosses('attr', [1.0, 2.0])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE ST_Crosses(`attr`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testSpatialDistanceLessThan(): void
    {
        $result = (new Builder())->from('t')->filter([Query::distanceLessThan('attr', [0, 0], 1000, true)])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE ST_Distance(ST_SRID(`attr`, 4326), ST_GeomFromText(?, 4326, \'axis-order=long-lat\'), \'metre\') < ?', $result->query);
    }

    public function testSpatialIntersects(): void
    {
        $result = (new Builder())->from('t')->filter([Query::intersects('attr', [1.0, 2.0])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE ST_Intersects(`attr`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testSpatialOverlaps(): void
    {
        $result = (new Builder())->from('t')->filter([Query::overlaps('attr', [[0, 0], [1, 1]])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE ST_Overlaps(`attr`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testSpatialTouches(): void
    {
        $result = (new Builder())->from('t')->filter([Query::touches('attr', [1.0, 2.0])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE ST_Touches(`attr`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testSpatialNotIntersects(): void
    {
        $result = (new Builder())->from('t')->filter([Query::notIntersects('attr', [1.0, 2.0])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE NOT ST_Intersects(`attr`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testUnsupportedFilterTypeVectorDot(): void
    {
        $this->expectException(UnsupportedException::class);
        (new Builder())->from('t')->filter([Query::vectorDot('attr', [1.0, 2.0])])->build();
    }

    public function testUnsupportedFilterTypeVectorCosine(): void
    {
        $this->expectException(UnsupportedException::class);
        (new Builder())->from('t')->filter([Query::vectorCosine('attr', [1.0, 2.0])])->build();
    }

    public function testUnsupportedFilterTypeVectorEuclidean(): void
    {
        $this->expectException(UnsupportedException::class);
        (new Builder())->from('t')->filter([Query::vectorEuclidean('attr', [1.0, 2.0])])->build();
    }

    public function testUnsupportedFilterTypeElemMatch(): void
    {
        $this->expectException(UnsupportedException::class);
        (new Builder())->from('t')->filter([Query::elemMatch('attr', [Query::equal('x', [1])])])->build();
    }
    //  toRawSql Edge Cases

    public function testToRawSqlWithBoolFalse(): void
    {
        $sql = (new Builder())->from('t')->filter([Query::equal('active', [false])])->toRawSql();
        $this->assertSame("SELECT * FROM `t` WHERE `active` IN (0)", $sql);
    }

    public function testToRawSqlMixedBindingTypes(): void
    {
        $sql = (new Builder())->from('t')
            ->filter([
                Query::equal('name', ['str']),
                Query::greaterThan('age', 42),
                Query::lessThan('score', 9.99),
                Query::equal('active', [true]),
            ])->toRawSql();
        $this->assertSame('SELECT * FROM `t` WHERE `name` IN (\'str\') AND `age` > 42 AND `score` < 9.99 AND `active` IN (1)', $sql);
    }

    public function testToRawSqlWithNull(): void
    {
        $sql = (new Builder())->from('t')
            ->filter([Query::raw('col = ?', [null])])
            ->toRawSql();
        $this->assertSame('SELECT * FROM `t` WHERE col = NULL', $sql);
    }

    public function testToRawSqlWithUnion(): void
    {
        $other = (new Builder())->from('b')->filter([Query::equal('x', [1])]);
        $sql = (new Builder())->from('a')->filter([Query::equal('y', [2])])->union($other)->toRawSql();
        $this->assertSame('(SELECT * FROM `a` WHERE `y` IN (2)) UNION (SELECT * FROM `b` WHERE `x` IN (1))', $sql);
    }

    public function testToRawSqlWithAggregationJoinGroupByHaving(): void
    {
        $sql = (new Builder())->from('orders')
            ->count('*', 'total')
            ->join('users', 'orders.uid', 'users.id')
            ->select(['users.country'])
            ->groupBy(['users.country'])
            ->having([Query::greaterThan('total', 5)])
            ->toRawSql();
        $this->assertSame('SELECT COUNT(*) AS `total`, `users`.`country` FROM `orders` JOIN `users` ON `orders`.`uid` = `users`.`id` GROUP BY `users`.`country` HAVING COUNT(*) > 5', $sql);
    }
    //  Kitchen Sink Exact SQL

    public function testKitchenSinkExactSql(): void
    {
        $other = (new Builder())->from('archive')->filter([Query::equal('status', ['closed'])]);
        $result = (new Builder())
            ->from('orders')
            ->distinct()
            ->count('*', 'total')
            ->select(['status'])
            ->join('users', 'orders.uid', 'users.id')
            ->filter([Query::greaterThan('amount', 100)])
            ->groupBy(['status'])
            ->having([Query::greaterThan('total', 5)])
            ->sortAsc('status')
            ->limit(10)
            ->offset(20)
            ->union($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            '(SELECT DISTINCT COUNT(*) AS `total`, `status` FROM `orders` JOIN `users` ON `orders`.`uid` = `users`.`id` WHERE `amount` > ? GROUP BY `status` HAVING COUNT(*) > ? ORDER BY `status` ASC LIMIT ? OFFSET ?) UNION (SELECT * FROM `archive` WHERE `status` IN (?))',
            $result->query
        );
        $this->assertSame([100, 5, 10, 20, 'closed'], $result->bindings);
    }
    //  Feature Combination Tests

    public function testDistinctWithUnion(): void
    {
        $other = (new Builder())->from('b');
        $result = (new Builder())->from('a')->distinct()->union($other)->build();
        $this->assertBindingCount($result);
        $this->assertSame('(SELECT DISTINCT * FROM `a`) UNION (SELECT * FROM `b`)', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testRawInsideLogicalAnd(): void
    {
        $result = (new Builder())->from('t')
            ->filter([Query::and([
                Query::greaterThan('x', 1),
                Query::raw('custom_func(y) > ?', [5]),
            ])])
            ->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE (`x` > ? AND custom_func(y) > ?)', $result->query);
        $this->assertSame([1, 5], $result->bindings);
    }

    public function testRawInsideLogicalOr(): void
    {
        $result = (new Builder())->from('t')
            ->filter([Query::or([
                Query::equal('a', [1]),
                Query::raw('b IS NOT NULL', []),
            ])])
            ->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE (`a` IN (?) OR b IS NOT NULL)', $result->query);
        $this->assertSame([1], $result->bindings);
    }

    public function testAggregationWithCursor(): void
    {
        $result = (new Builder())->from('t')
            ->count('*', 'total')
            ->cursorAfter('abc')
            ->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT COUNT(*) AS `total` FROM `t` WHERE `_cursor` > ?', $result->query);
        $this->assertContains('abc', $result->bindings);
    }

    public function testGroupBySortCursorUnion(): void
    {
        $other = (new Builder())->from('b');
        $result = (new Builder())->from('a')
            ->count('*', 'total')
            ->groupBy(['status'])
            ->sortDesc('total')
            ->cursorAfter('xyz')
            ->union($other)
            ->build();
        $this->assertBindingCount($result);
        $this->assertSame('(SELECT COUNT(*) AS `total` FROM `a` WHERE `_cursor` > ? GROUP BY `status` ORDER BY `total` DESC) UNION (SELECT * FROM `b`)', $result->query);
    }

    public function testConditionProviderWithNoFilters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('_tenant = ?', ['t1']);
                }
            })
            ->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE _tenant = ?', $result->query);
        $this->assertSame(['t1'], $result->bindings);
    }

    public function testConditionProviderWithCursorNoFilters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('_tenant = ?', ['t1']);
                }
            })
            ->cursorAfter('abc')
            ->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE _tenant = ? AND `_cursor` > ?', $result->query);
        // Provider bindings come before cursor bindings
        $this->assertSame(['t1', 'abc'], $result->bindings);
    }

    public function testConditionProviderWithDistinct(): void
    {
        $result = (new Builder())
            ->from('t')
            ->distinct()
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('_tenant = ?', ['t1']);
                }
            })
            ->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT DISTINCT * FROM `t` WHERE _tenant = ?', $result->query);
        $this->assertSame(['t1'], $result->bindings);
    }

    public function testConditionProviderPersistsAfterReset(): void
    {
        $builder = (new Builder())
            ->from('t')
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('_tenant = ?', ['t1']);
                }
            });
        $builder->build();
        $builder->reset()->from('other');
        $result = $builder->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `other` WHERE _tenant = ?', $result->query);
        $this->assertSame(['t1'], $result->bindings);
    }

    public function testConditionProviderWithHaving(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count('*', 'total')
            ->groupBy(['status'])
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('_tenant = ?', ['t1']);
                }
            })
            ->having([Query::greaterThan('total', 5)])
            ->build();
        $this->assertBindingCount($result);
        // Provider should be in WHERE, not HAVING
        $this->assertSame('SELECT COUNT(*) AS `total` FROM `t` WHERE _tenant = ? GROUP BY `status` HAVING COUNT(*) > ?', $result->query);
        // Provider bindings before having bindings
        $this->assertSame(['t1', 5], $result->bindings);
    }

    public function testUnionWithConditionProvider(): void
    {
        $sub = (new Builder())
            ->from('b')
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('_deleted = ?', [0]);
                }
            });
        $result = (new Builder())
            ->from('a')
            ->union($sub)
            ->build();
        $this->assertBindingCount($result);
        // Sub-query should include the condition provider
        $this->assertSame('(SELECT * FROM `a`) UNION (SELECT * FROM `b` WHERE _deleted = ?)', $result->query);
        $this->assertSame([0], $result->bindings);
    }
    //  Boundary Value Tests

    public function testNegativeLimit(): void
    {
        $result = (new Builder())->from('t')->limit(-1)->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` LIMIT ?', $result->query);
        $this->assertSame([-1], $result->bindings);
    }

    public function testNegativeOffset(): void
    {
        $this->expectException(ValidationException::class);
        (new Builder())->from('t')->offset(-5)->build();
    }


    public function testEqualWithNullOnly(): void
    {
        $result = (new Builder())->from('t')->filter([Query::equal('col', [null])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE `col` IS NULL', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testEqualWithNullAndNonNull(): void
    {
        $result = (new Builder())->from('t')->filter([Query::equal('col', ['a', null])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE (`col` IN (?) OR `col` IS NULL)', $result->query);
        $this->assertSame(['a'], $result->bindings);
    }

    public function testNotEqualWithNullOnly(): void
    {
        $result = (new Builder())->from('t')->filter([Query::notEqual('col', [null])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE `col` IS NOT NULL', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testNotEqualWithNullAndNonNull(): void
    {
        $result = (new Builder())->from('t')->filter([Query::notEqual('col', ['a', null])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE (`col` != ? AND `col` IS NOT NULL)', $result->query);
        $this->assertSame(['a'], $result->bindings);
    }

    public function testNotEqualWithMultipleNonNullAndNull(): void
    {
        $result = (new Builder())->from('t')->filter([Query::notEqual('col', ['a', 'b', null])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE (`col` NOT IN (?, ?) AND `col` IS NOT NULL)', $result->query);
        $this->assertSame(['a', 'b'], $result->bindings);
    }

    public function testBetweenReversedMinMax(): void
    {
        $result = (new Builder())->from('t')->filter([Query::between('age', 65, 18)])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE `age` BETWEEN ? AND ?', $result->query);
        $this->assertSame([65, 18], $result->bindings);
    }

    public function testContainsWithSqlWildcard(): void
    {
        $result = (new Builder())->from('t')->filter([Query::containsString('bio', ['100%'])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE `bio` LIKE ?', $result->query);
        $this->assertSame(['%100\%%'], $result->bindings);
    }

    public function testStartsWithWithWildcard(): void
    {
        $result = (new Builder())->from('t')->filter([Query::startsWith('name', '%admin')])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE `name` LIKE ?', $result->query);
        $this->assertSame(['\%admin%'], $result->bindings);
    }

    public function testCursorWithNullValue(): void
    {
        // Null cursor value is ignored by groupByType since cursor stays null
        $result = (new Builder())->from('t')->cursorAfter(null)->build();
        $this->assertBindingCount($result);
        $this->assertStringNotContainsString('_cursor', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testCursorWithIntegerValue(): void
    {
        $result = (new Builder())->from('t')->cursorAfter(42)->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE `_cursor` > ?', $result->query);
        $this->assertSame([42], $result->bindings);
    }

    public function testCursorWithFloatValue(): void
    {
        $result = (new Builder())->from('t')->cursorAfter(3.14)->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE `_cursor` > ?', $result->query);
        $this->assertSame([3.14], $result->bindings);
    }

    public function testMultipleLimitsFirstWins(): void
    {
        $result = (new Builder())->from('t')->limit(10)->limit(20)->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` LIMIT ?', $result->query);
        $this->assertSame([10], $result->bindings);
    }

    public function testMultipleOffsetsFirstWins(): void
    {
        $this->expectException(ValidationException::class);
        (new Builder())->from('t')->offset(5)->offset(50)->build();
    }


    public function testCursorAfterAndBeforeFirstWins(): void
    {
        $result = (new Builder())->from('t')->cursorAfter('a')->cursorBefore('b')->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE `_cursor` > ?', $result->query);
        $this->assertStringNotContainsString('`_cursor` < ?', $result->query);
    }

    public function testEmptyTableWithJoin(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No table specified');
        (new Builder())->join('other', 'a', 'b')->build();
    }

    public function testBuildWithoutFromCall(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No table specified');
        (new Builder())->filter([Query::equal('x', [1])])->build();
    }
    //  Standalone Compiler Method Tests

    public function testCompileSelectEmpty(): void
    {
        $builder = new Builder();
        $result = $builder->compileSelect(Query::select([]));
        $this->assertSame('', $result);
    }

    public function testCompileGroupByEmpty(): void
    {
        $builder = new Builder();
        $result = $builder->compileGroupBy(Query::groupBy([]));
        $this->assertSame('', $result);
    }

    public function testCompileGroupBySingleColumn(): void
    {
        $builder = new Builder();
        $result = $builder->compileGroupBy(Query::groupBy(['status']));
        $this->assertSame('`status`', $result);
    }

    public function testCompileSumWithoutAlias(): void
    {
        $builder = new Builder();
        $sql = $builder->compileAggregate(Query::sum('price'));
        $this->assertSame('SUM(`price`)', $sql);
    }

    public function testCompileAvgWithoutAlias(): void
    {
        $builder = new Builder();
        $sql = $builder->compileAggregate(Query::avg('score'));
        $this->assertSame('AVG(`score`)', $sql);
    }

    public function testCompileMinWithoutAlias(): void
    {
        $builder = new Builder();
        $sql = $builder->compileAggregate(Query::min('price'));
        $this->assertSame('MIN(`price`)', $sql);
    }

    public function testCompileMaxWithoutAlias(): void
    {
        $builder = new Builder();
        $sql = $builder->compileAggregate(Query::max('price'));
        $this->assertSame('MAX(`price`)', $sql);
    }

    public function testCompileLimitZero(): void
    {
        $builder = new Builder();
        $sql = $builder->compileLimit(Query::limit(0));
        $this->assertSame('LIMIT ?', $sql);
        $this->assertSame([0], $builder->getBindings());
    }

    public function testCompileOffsetZero(): void
    {
        $builder = new Builder();
        $sql = $builder->compileOffset(Query::offset(0));
        $this->assertSame('OFFSET ?', $sql);
        $this->assertSame([0], $builder->getBindings());
    }

    public function testCompileOrderException(): void
    {
        $builder = new Builder();
        $this->expectException(UnsupportedException::class);
        $builder->compileOrder(Query::limit(10));
    }

    public function testCompileJoinException(): void
    {
        $builder = new Builder();
        $this->expectException(UnsupportedException::class);
        $builder->compileJoin(Query::equal('x', [1]));
    }
    //  Query::compile() Integration Tests

    public function testQueryCompileOrderAsc(): void
    {
        $builder = new Builder();
        $this->assertSame('`name` ASC', Query::orderAsc('name')->compile($builder));
    }

    public function testQueryCompileOrderDesc(): void
    {
        $builder = new Builder();
        $this->assertSame('`name` DESC', Query::orderDesc('name')->compile($builder));
    }

    public function testQueryCompileOrderRandom(): void
    {
        $builder = new Builder();
        $this->assertSame('RAND()', Query::orderRandom()->compile($builder));
    }

    public function testQueryCompileLimit(): void
    {
        $builder = new Builder();
        $this->assertSame('LIMIT ?', Query::limit(10)->compile($builder));
        $this->assertSame([10], $builder->getBindings());
    }

    public function testQueryCompileOffset(): void
    {
        $builder = new Builder();
        $this->assertSame('OFFSET ?', Query::offset(5)->compile($builder));
        $this->assertSame([5], $builder->getBindings());
    }

    public function testQueryCompileCursorAfter(): void
    {
        $builder = new Builder();
        $this->assertSame('`_cursor` > ?', Query::cursorAfter('x')->compile($builder));
        $this->assertSame(['x'], $builder->getBindings());
    }

    public function testQueryCompileCursorBefore(): void
    {
        $builder = new Builder();
        $this->assertSame('`_cursor` < ?', Query::cursorBefore('x')->compile($builder));
        $this->assertSame(['x'], $builder->getBindings());
    }

    public function testQueryCompileSelect(): void
    {
        $builder = new Builder();
        $this->assertSame('`a`, `b`', Query::select(['a', 'b'])->compile($builder));
    }

    public function testQueryCompileGroupBy(): void
    {
        $builder = new Builder();
        $this->assertSame('`status`', Query::groupBy(['status'])->compile($builder));
    }
    //  Reset Behavior

    public function testResetFollowedByUnion(): void
    {
        $builder = (new Builder())
            ->from('a')
            ->union((new Builder())->from('old'));
        $builder->reset()->from('b');
        $result = $builder->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `b`', $result->query);
        $this->assertStringNotContainsString('UNION', $result->query);
    }

    public function testResetClearsBindingsAfterBuild(): void
    {
        $builder = (new Builder())->from('t')->filter([Query::equal('x', [1])]);
        $builder->build();
        $this->assertNotEmpty($builder->getBindings());
        $builder->reset()->from('t');
        $result = $builder->build();
        $this->assertBindingCount($result);
        $this->assertSame([], $result->bindings);
    }
    //  Missing Binding Assertions

    public function testSortAscBindingsEmpty(): void
    {
        $result = (new Builder())->from('t')->sortAsc('name')->build();
        $this->assertBindingCount($result);
        $this->assertSame([], $result->bindings);
    }

    public function testSortDescBindingsEmpty(): void
    {
        $result = (new Builder())->from('t')->sortDesc('name')->build();
        $this->assertBindingCount($result);
        $this->assertSame([], $result->bindings);
    }

    public function testSortRandomBindingsEmpty(): void
    {
        $result = (new Builder())->from('t')->sortRandom()->build();
        $this->assertBindingCount($result);
        $this->assertSame([], $result->bindings);
    }

    public function testDistinctBindingsEmpty(): void
    {
        $result = (new Builder())->from('t')->distinct()->build();
        $this->assertBindingCount($result);
        $this->assertSame([], $result->bindings);
    }

    public function testJoinBindingsEmpty(): void
    {
        $result = (new Builder())->from('t')->join('other', 'a', 'b')->build();
        $this->assertBindingCount($result);
        $this->assertSame([], $result->bindings);
    }

    public function testCrossJoinBindingsEmpty(): void
    {
        $result = (new Builder())->from('t')->crossJoin('other')->build();
        $this->assertBindingCount($result);
        $this->assertSame([], $result->bindings);
    }

    public function testGroupByBindingsEmpty(): void
    {
        $result = (new Builder())->from('t')->groupBy(['status'])->build();
        $this->assertBindingCount($result);
        $this->assertSame([], $result->bindings);
    }

    public function testCountWithAliasBindingsEmpty(): void
    {
        $result = (new Builder())->from('t')->count('*', 'total')->build();
        $this->assertBindingCount($result);
        $this->assertSame([], $result->bindings);
    }
    // DML: INSERT

    public function testInsertSingleRow(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'Alice', 'email' => 'a@b.com'])
            ->insert();
        $this->assertBindingCount($result);

        $this->assertSame(
            'INSERT INTO `users` (`name`, `email`) VALUES (?, ?)',
            $result->query
        );
        $this->assertSame(['Alice', 'a@b.com'], $result->bindings);
    }

    public function testInsertBatch(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'Alice', 'email' => 'a@b.com'])
            ->set(['name' => 'Bob', 'email' => 'b@b.com'])
            ->insert();
        $this->assertBindingCount($result);

        $this->assertSame(
            'INSERT INTO `users` (`name`, `email`) VALUES (?, ?), (?, ?)',
            $result->query
        );
        $this->assertSame(['Alice', 'a@b.com', 'Bob', 'b@b.com'], $result->bindings);
    }

    public function testInsertNoRowsThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->into('users')
            ->insert();
    }

    public function testIntoAliasesFrom(): void
    {
        $builder = new Builder();
        $builder->into('users')->set(['name' => 'Alice'])->insert();
        $this->assertSame('INSERT INTO `users` (`name`) VALUES (?)', $builder->insert()->query);
    }
    // DML: UPSERT

    public function testUpsertSingleRow(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['id' => 1, 'name' => 'Alice', 'email' => 'a@b.com'])
            ->onConflict(['id'], ['name', 'email'])
            ->upsert();
        $this->assertBindingCount($result);

        $this->assertSame(
            'INSERT INTO `users` (`id`, `name`, `email`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `email` = VALUES(`email`)',
            $result->query
        );
        $this->assertSame([1, 'Alice', 'a@b.com'], $result->bindings);
    }

    public function testUpsertMultipleConflictColumns(): void
    {
        $result = (new Builder())
            ->into('user_roles')
            ->set(['user_id' => 1, 'role_id' => 2, 'granted_at' => '2024-01-01'])
            ->onConflict(['user_id', 'role_id'], ['granted_at'])
            ->upsert();
        $this->assertBindingCount($result);

        $this->assertSame(
            'INSERT INTO `user_roles` (`user_id`, `role_id`, `granted_at`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `granted_at` = VALUES(`granted_at`)',
            $result->query
        );
        $this->assertSame([1, 2, '2024-01-01'], $result->bindings);
    }
    // DML: UPDATE

    public function testUpdateWithWhere(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['status' => 'archived'])
            ->filter([Query::equal('status', ['inactive'])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame(
            'UPDATE `users` SET `status` = ? WHERE `status` IN (?)',
            $result->query
        );
        $this->assertSame(['archived', 'inactive'], $result->bindings);
    }

    public function testUpdateWithSetRaw(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['name' => 'Alice'])
            ->setRaw('login_count', 'login_count + 1')
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame(
            'UPDATE `users` SET `name` = ?, `login_count` = login_count + 1 WHERE `id` IN (?)',
            $result->query
        );
        $this->assertSame(['Alice', 1], $result->bindings);
    }

    public function testUpdateWithFilterHook(): void
    {
        $hook = new class () implements Filter, Hook {
            public function filter(string $table): Condition
            {
                return new Condition('`_tenant` = ?', ['tenant_123']);
            }
        };

        $result = (new Builder())
            ->from('users')
            ->set(['status' => 'active'])
            ->filter([Query::equal('id', [1])])
            ->addHook($hook)
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame(
            'UPDATE `users` SET `status` = ? WHERE `id` IN (?) AND `_tenant` = ?',
            $result->query
        );
        $this->assertSame(['active', 1, 'tenant_123'], $result->bindings);
    }

    public function testUpdateWithoutWhere(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['status' => 'active'])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `users` SET `status` = ?', $result->query);
        $this->assertSame(['active'], $result->bindings);
    }

    public function testUpdateWithOrderByAndLimit(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['status' => 'archived'])
            ->filter([Query::equal('active', [false])])
            ->sortAsc('created_at')
            ->limit(100)
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame(
            'UPDATE `users` SET `status` = ? WHERE `active` IN (?) ORDER BY `created_at` ASC LIMIT ?',
            $result->query
        );
        $this->assertSame(['archived', false, 100], $result->bindings);
    }

    public function testUpdateNoAssignmentsThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->from('users')
            ->update();
    }
    // DML: DELETE

    public function testDeleteWithWhere(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::lessThan('last_login', '2024-01-01')])
            ->delete();
        $this->assertBindingCount($result);

        $this->assertSame(
            'DELETE FROM `users` WHERE `last_login` < ?',
            $result->query
        );
        $this->assertSame(['2024-01-01'], $result->bindings);
    }

    public function testDeleteWithFilterHook(): void
    {
        $hook = new class () implements Filter, Hook {
            public function filter(string $table): Condition
            {
                return new Condition('`_tenant` = ?', ['tenant_123']);
            }
        };

        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('status', ['deleted'])])
            ->addHook($hook)
            ->delete();
        $this->assertBindingCount($result);

        $this->assertSame(
            'DELETE FROM `users` WHERE `status` IN (?) AND `_tenant` = ?',
            $result->query
        );
        $this->assertSame(['deleted', 'tenant_123'], $result->bindings);
    }

    public function testDeleteWithoutWhere(): void
    {
        $result = (new Builder())
            ->from('users')
            ->delete();
        $this->assertBindingCount($result);

        $this->assertSame('DELETE FROM `users`', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testDeleteWithOrderByAndLimit(): void
    {
        $result = (new Builder())
            ->from('logs')
            ->filter([Query::lessThan('created_at', '2023-01-01')])
            ->sortAsc('created_at')
            ->limit(1000)
            ->delete();
        $this->assertBindingCount($result);

        $this->assertSame(
            'DELETE FROM `logs` WHERE `created_at` < ? ORDER BY `created_at` ASC LIMIT ?',
            $result->query
        );
        $this->assertSame(['2023-01-01', 1000], $result->bindings);
    }
    // DML: Reset clears new state

    public function testResetClearsDmlState(): void
    {
        $builder = (new Builder())
            ->into('users')
            ->set(['name' => 'Alice'])
            ->setRaw('count', 'count + 1')
            ->onConflict(['id'], ['name']);

        $builder->reset();

        $this->expectException(ValidationException::class);
        $builder->into('users')->insert();
    }
    // Validation: Missing table

    public function testInsertWithoutTableThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No table specified');

        (new Builder())->set(['name' => 'Alice'])->insert();
    }

    public function testUpdateWithoutTableThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No table specified');

        (new Builder())->set(['name' => 'Alice'])->update();
    }

    public function testDeleteWithoutTableThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No table specified');

        (new Builder())->delete();
    }

    public function testSelectWithoutTableThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No table specified');

        (new Builder())->build();
    }
    // Validation: Empty rows

    public function testInsertEmptyRowThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('empty row');

        (new Builder())->into('users')->set([])->insert();
    }
    // Validation: Inconsistent batch columns

    public function testInsertInconsistentBatchThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('different columns');

        (new Builder())
            ->into('users')
            ->set(['name' => 'Alice', 'email' => 'a@b.com'])
            ->set(['name' => 'Bob', 'phone' => '555-1234'])
            ->insert();
    }
    // Validation: Upsert without onConflict

    public function testUpsertWithoutConflictKeysThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No conflict keys');

        (new Builder())
            ->into('users')
            ->set(['id' => 1, 'name' => 'Alice'])
            ->upsert();
    }

    public function testUpsertWithoutConflictUpdateColumnsThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No conflict update columns');

        (new Builder())
            ->into('users')
            ->set(['id' => 1, 'name' => 'Alice'])
            ->onConflict(['id'], [])
            ->upsert();
    }

    public function testUpsertConflictColumnNotInRowThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("not present in the row data");

        (new Builder())
            ->into('users')
            ->set(['id' => 1, 'name' => 'Alice'])
            ->onConflict(['id'], ['email'])
            ->upsert();
    }
    //  INTERSECT / EXCEPT

    public function testIntersect(): void
    {
        $other = (new Builder())->from('admins');
        $result = (new Builder())
            ->from('users')
            ->intersect($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            '(SELECT * FROM `users`) INTERSECT (SELECT * FROM `admins`)',
            $result->query
        );
    }

    public function testIntersectAll(): void
    {
        $other = (new Builder())->from('admins');
        $result = (new Builder())
            ->from('users')
            ->intersectAll($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            '(SELECT * FROM `users`) INTERSECT ALL (SELECT * FROM `admins`)',
            $result->query
        );
    }

    public function testExcept(): void
    {
        $other = (new Builder())->from('banned');
        $result = (new Builder())
            ->from('users')
            ->except($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            '(SELECT * FROM `users`) EXCEPT (SELECT * FROM `banned`)',
            $result->query
        );
    }

    public function testExceptAll(): void
    {
        $other = (new Builder())->from('banned');
        $result = (new Builder())
            ->from('users')
            ->exceptAll($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            '(SELECT * FROM `users`) EXCEPT ALL (SELECT * FROM `banned`)',
            $result->query
        );
    }

    public function testIntersectWithBindings(): void
    {
        $other = (new Builder())->from('admins')->filter([Query::equal('role', ['admin'])]);
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('status', ['active'])])
            ->intersect($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            '(SELECT * FROM `users` WHERE `status` IN (?)) INTERSECT (SELECT * FROM `admins` WHERE `role` IN (?))',
            $result->query
        );
        $this->assertSame(['active', 'admin'], $result->bindings);
    }

    public function testExceptWithBindings(): void
    {
        $other = (new Builder())->from('banned')->filter([Query::equal('reason', ['spam'])]);
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('status', ['active'])])
            ->except($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['active', 'spam'], $result->bindings);
    }

    public function testMixedSetOperations(): void
    {
        $q1 = (new Builder())->from('a');
        $q2 = (new Builder())->from('b');
        $q3 = (new Builder())->from('c');

        $result = (new Builder())
            ->from('main')
            ->union($q1)
            ->intersect($q2)
            ->except($q3)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM `main`) UNION (SELECT * FROM `a`) INTERSECT (SELECT * FROM `b`) EXCEPT (SELECT * FROM `c`)', $result->query);
    }

    public function testIntersectFluentReturnsSameInstance(): void
    {
        $builder = new Builder();
        $other = (new Builder())->from('t');
        $this->assertSame($builder, $builder->from('t')->intersect($other));
    }

    public function testExceptFluentReturnsSameInstance(): void
    {
        $builder = new Builder();
        $other = (new Builder())->from('t');
        $this->assertSame($builder, $builder->from('t')->except($other));
    }
    //  Row Locking

    public function testForUpdate(): void
    {
        $result = (new Builder())
            ->from('accounts')
            ->filter([Query::equal('id', [1])])
            ->forUpdate()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `accounts` WHERE `id` IN (?) FOR UPDATE',
            $result->query
        );
        $this->assertSame([1], $result->bindings);
    }

    public function testForShare(): void
    {
        $result = (new Builder())
            ->from('accounts')
            ->filter([Query::equal('id', [1])])
            ->forShare()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `accounts` WHERE `id` IN (?) FOR SHARE',
            $result->query
        );
    }

    public function testForUpdateWithLimitAndOffset(): void
    {
        $result = (new Builder())
            ->from('accounts')
            ->limit(10)
            ->offset(5)
            ->forUpdate()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `accounts` LIMIT ? OFFSET ? FOR UPDATE',
            $result->query
        );
        $this->assertSame([10, 5], $result->bindings);
    }

    public function testLockModeResetClears(): void
    {
        $builder = (new Builder())->from('t')->forUpdate();
        $builder->build();
        $builder->reset();

        $result = $builder->from('t')->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t`', $result->query);
    }
    //  Transaction Statements

    public function testBegin(): void
    {
        $result = (new Builder())->begin();
        $this->assertSame('BEGIN', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testCommit(): void
    {
        $result = (new Builder())->commit();
        $this->assertSame('COMMIT', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testRollback(): void
    {
        $result = (new Builder())->rollback();
        $this->assertSame('ROLLBACK', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testSavepoint(): void
    {
        $result = (new Builder())->savepoint('sp1');
        $this->assertSame('SAVEPOINT `sp1`', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testReleaseSavepoint(): void
    {
        $result = (new Builder())->releaseSavepoint('sp1');
        $this->assertSame('RELEASE SAVEPOINT `sp1`', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testRollbackToSavepoint(): void
    {
        $result = (new Builder())->rollbackToSavepoint('sp1');
        $this->assertSame('ROLLBACK TO SAVEPOINT `sp1`', $result->query);
        $this->assertSame([], $result->bindings);
    }
    //  INSERT...SELECT

    public function testInsertSelect(): void
    {
        $source = (new Builder())
            ->from('users')
            ->select(['name', 'email'])
            ->filter([Query::equal('status', ['active'])]);

        $result = (new Builder())
            ->into('archive')
            ->fromSelect(['name', 'email'], $source)
            ->insertSelect();

        $this->assertSame(
            'INSERT INTO `archive` (`name`, `email`) SELECT `name`, `email` FROM `users` WHERE `status` IN (?)',
            $result->query
        );
        $this->assertSame(['active'], $result->bindings);
    }

    public function testInsertSelectWithoutSourceThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No SELECT source specified');

        (new Builder())
            ->into('archive')
            ->insertSelect();
    }

    public function testInsertSelectWithoutTableThrows(): void
    {
        $this->expectException(ValidationException::class);

        $source = (new Builder())->from('users');

        (new Builder())
            ->fromSelect(['name'], $source)
            ->insertSelect();
    }

    public function testInsertSelectWithAggregation(): void
    {
        $source = (new Builder())
            ->from('orders')
            ->select(['customer_id'])
            ->count('*', 'order_count')
            ->groupBy(['customer_id']);

        $result = (new Builder())
            ->into('customer_stats')
            ->fromSelect(['customer_id', 'order_count'], $source)
            ->insertSelect();

        $this->assertSame('INSERT INTO `customer_stats` (`customer_id`, `order_count`) SELECT COUNT(*) AS `order_count`, `customer_id` FROM `orders` GROUP BY `customer_id`', $result->query);
    }

    public function testInsertSelectResetClears(): void
    {
        $source = (new Builder())->from('users');
        $builder = (new Builder())
            ->into('archive')
            ->fromSelect(['name'], $source);

        $builder->reset();

        $this->expectException(ValidationException::class);
        $builder->into('archive')->insertSelect();
    }
    //  CTEs (WITH)

    public function testCteWith(): void
    {
        $cte = (new Builder())
            ->from('orders')
            ->filter([Query::equal('status', ['paid'])]);

        $result = (new Builder())
            ->with('paid_orders', $cte)
            ->from('paid_orders')
            ->select(['customer_id'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'WITH `paid_orders` AS (SELECT * FROM `orders` WHERE `status` IN (?)) SELECT `customer_id` FROM `paid_orders`',
            $result->query
        );
        $this->assertSame(['paid'], $result->bindings);
    }

    public function testCteWithRecursive(): void
    {
        $cte = (new Builder())->from('categories');

        $result = (new Builder())
            ->withRecursive('tree', $cte)
            ->from('tree')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'WITH RECURSIVE `tree` AS (SELECT * FROM `categories`) SELECT * FROM `tree`',
            $result->query
        );
    }

    public function testMultipleCtes(): void
    {
        $cte1 = (new Builder())->from('orders')->filter([Query::equal('status', ['paid'])]);
        $cte2 = (new Builder())->from('returns')->filter([Query::equal('status', ['approved'])]);

        $result = (new Builder())
            ->with('paid', $cte1)
            ->with('approved_returns', $cte2)
            ->from('paid')
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringStartsWith('WITH `paid` AS', $result->query);
        $this->assertSame('WITH `paid` AS (SELECT * FROM `orders` WHERE `status` IN (?)), `approved_returns` AS (SELECT * FROM `returns` WHERE `status` IN (?)) SELECT * FROM `paid`', $result->query);
        $this->assertSame(['paid', 'approved'], $result->bindings);
    }

    public function testCteBindingsComeBefore(): void
    {
        $cte = (new Builder())->from('orders')->filter([Query::equal('year', [2024])]);

        $result = (new Builder())
            ->with('recent', $cte)
            ->from('recent')
            ->filter([Query::greaterThan('amount', 100)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame([2024, 100], $result->bindings);
    }

    public function testCteResetClears(): void
    {
        $cte = (new Builder())->from('orders');
        $builder = (new Builder())->with('o', $cte)->from('o');
        $builder->build();
        $builder->reset();

        $result = $builder->from('t')->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t`', $result->query);
    }

    public function testMixedRecursiveAndNonRecursiveCte(): void
    {
        $cte1 = (new Builder())->from('categories');
        $cte2 = (new Builder())->from('products');

        $result = (new Builder())
            ->with('prods', $cte2)
            ->withRecursive('tree', $cte1)
            ->from('tree')
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringStartsWith('WITH RECURSIVE', $result->query);
        $this->assertSame('WITH RECURSIVE `prods` AS (SELECT * FROM `products`), `tree` AS (SELECT * FROM `categories`) SELECT * FROM `tree`', $result->query);
    }
    //  CASE/WHEN + selectRaw()

    public function testCaseBuilder(): void
    {
        $case = (new CaseExpression())
            ->when('status', Operator::Equal, 'active', 'Active')
            ->when('status', Operator::Equal, 'inactive', 'Inactive')
            ->else('Unknown')
            ->alias('label');

        $result = (new Builder())
            ->from('t')
            ->selectCase($case)
            ->build();

        $this->assertSame('SELECT CASE WHEN `status` = ? THEN ? WHEN `status` = ? THEN ? ELSE ? END AS `label` FROM `t`', $result->query);
        $this->assertSame(['active', 'Active', 'inactive', 'Inactive', 'Unknown'], $result->bindings);
    }

    public function testCaseBuilderWithoutElse(): void
    {
        $case = (new CaseExpression())
            ->when('x', Operator::GreaterThan, 10, 1);

        $result = (new Builder())
            ->from('t')
            ->selectCase($case)
            ->build();

        $this->assertSame('SELECT CASE WHEN `x` > ? THEN ? END FROM `t`', $result->query);
        $this->assertSame([10, 1], $result->bindings);
    }

    public function testCaseBuilderWithoutAlias(): void
    {
        $case = (new CaseExpression())
            ->whenRaw('x = 1', 'yes')
            ->else('no');

        $result = (new Builder())
            ->from('t')
            ->selectCase($case)
            ->build();

        $this->assertSame('SELECT CASE WHEN x = 1 THEN ? ELSE ? END FROM `t`', $result->query);
        $this->assertStringNotContainsString('END AS', $result->query);
        $this->assertSame(['yes', 'no'], $result->bindings);
    }

    public function testCaseBuilderNoWhensThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('at least one WHEN');

        (new Builder())
            ->from('t')
            ->selectCase(new CaseExpression())
            ->build();
    }

    public function testCaseExpressionToSql(): void
    {
        $case = (new CaseExpression())
            ->whenRaw('a = ?', 1, [1]);

        $result = (new Builder())
            ->from('t')
            ->selectCase($case)
            ->build();

        $this->assertSame('SELECT CASE WHEN a = ? THEN ? END FROM `t`', $result->query);
        $this->assertSame([1, 1], $result->bindings);
    }

    public function testSelectRaw(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->select('SUM(amount) AS total')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT SUM(amount) AS total FROM `orders`', $result->query);
    }

    public function testSelectRawWithBindings(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->select('IF(amount > ?, 1, 0) AS big_order', [1000])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT IF(amount > ?, 1, 0) AS big_order FROM `orders`', $result->query);
        $this->assertSame([1000], $result->bindings);
    }

    public function testSelectRawCombinedWithSelect(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->select(['id', 'customer_id'])
            ->select('SUM(amount) AS total')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `id`, `customer_id`, SUM(amount) AS total FROM `orders`', $result->query);
    }

    public function testSelectRawWithCaseExpression(): void
    {
        $case = (new CaseExpression())
            ->when('status', Operator::Equal, 'active', 'Active')
            ->else('Other')
            ->alias('label');

        $result = (new Builder())
            ->from('users')
            ->select(['id'])
            ->selectCase($case)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `id`, CASE WHEN `status` = ? THEN ? ELSE ? END AS `label` FROM `users`', $result->query);
        $this->assertSame(['active', 'Active', 'Other'], $result->bindings);
    }

    public function testSelectRawResetClears(): void
    {
        $builder = (new Builder())->from('t')->select('1 AS one');
        $builder->build();
        $builder->reset();

        $result = $builder->from('t')->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t`', $result->query);
    }

    public function testSetRawWithBindings(): void
    {
        $result = (new Builder())
            ->from('accounts')
            ->set(['name' => 'Alice'])
            ->setRaw('balance', 'balance + ?', [100])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame(
            'UPDATE `accounts` SET `name` = ?, `balance` = balance + ? WHERE `id` IN (?)',
            $result->query
        );
        $this->assertSame(['Alice', 100, 1], $result->bindings);
    }

    public function testSetRawWithBindingsResetClears(): void
    {
        $builder = (new Builder())->from('t')->setRaw('x', 'x + ?', [1]);
        $builder->reset();

        $this->expectException(ValidationException::class);
        $builder->from('t')->update();
    }

    public function testMultipleSelectRaw(): void
    {
        $result = (new Builder())
            ->from('t')
            ->select('COUNT(*) AS cnt')
            ->select('MAX(price) AS max_price')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS cnt, MAX(price) AS max_price FROM `t`', $result->query);
    }

    public function testForUpdateNotInUnion(): void
    {
        $other = (new Builder())->from('b');
        $result = (new Builder())
            ->from('a')
            ->forUpdate()
            ->union($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM `a` FOR UPDATE) UNION (SELECT * FROM `b`)', $result->query);
    }

    public function testCteWithUnion(): void
    {
        $cte = (new Builder())->from('orders');
        $other = (new Builder())->from('archive_orders');

        $result = (new Builder())
            ->with('o', $cte)
            ->from('o')
            ->union($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringStartsWith('WITH `o` AS', $result->query);
        $this->assertSame('WITH `o` AS (SELECT * FROM `orders`) (SELECT * FROM `o`) UNION (SELECT * FROM `archive_orders`)', $result->query);
    }
    //  Spatial feature interface

    public function testImplementsSpatial(): void
    {
        $this->assertInstanceOf(Spatial::class, new Builder());
    }

    public function testFilterDistanceMeters(): void
    {
        $result = (new Builder())
            ->from('locations')
            ->filterDistance('coords', [40.7128, -74.0060], '<', 5000.0, true)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `locations` WHERE ST_Distance(ST_SRID(`coords`, 4326), ST_GeomFromText(?, 4326, \'axis-order=long-lat\'), \'metre\') < ?', $result->query);
        $this->assertSame('POINT(40.7128 -74.006)', $result->bindings[0]);
        $this->assertSame(5000.0, $result->bindings[1]);
    }

    public function testFilterDistanceNoMeters(): void
    {
        $result = (new Builder())
            ->from('locations')
            ->filterDistance('coords', [1.0, 2.0], '>', 100.0)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `locations` WHERE ST_Distance(ST_SRID(`coords`, 0), ST_GeomFromText(?, 0, \'axis-order=long-lat\')) > ?', $result->query);
    }

    public function testFilterIntersectsPoint(): void
    {
        $result = (new Builder())
            ->from('zones')
            ->filterIntersects('area', [1.0, 2.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `zones` WHERE ST_Intersects(`area`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
        $this->assertSame('POINT(1 2)', $result->bindings[0]);
    }

    public function testFilterNotIntersects(): void
    {
        $result = (new Builder())
            ->from('zones')
            ->filterNotIntersects('area', [1.0, 2.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `zones` WHERE NOT ST_Intersects(`area`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testFilterCovers(): void
    {
        $result = (new Builder())
            ->from('zones')
            ->filterCovers('area', [1.0, 2.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `zones` WHERE ST_Contains(`area`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testFilterSpatialEquals(): void
    {
        $result = (new Builder())
            ->from('zones')
            ->filterSpatialEquals('area', [1.0, 2.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `zones` WHERE ST_Equals(`area`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testSpatialWithLinestring(): void
    {
        $result = (new Builder())
            ->from('roads')
            ->filterIntersects('path', [[0, 0], [1, 1], [2, 2]])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('LINESTRING(0 0, 1 1, 2 2)', $result->bindings[0]);
    }

    public function testSpatialWithPolygon(): void
    {
        $result = (new Builder())
            ->from('areas')
            ->filterIntersects('zone', [[[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]]])
            ->build();
        $this->assertBindingCount($result);

        /** @var string $wkt */
        $wkt = $result->bindings[0];
        $this->assertSame('POLYGON((0 0, 1 0, 1 1, 0 1, 0 0))', $wkt);
    }
    //  JSON feature interface

    public function testImplementsJson(): void
    {
        $this->assertInstanceOf(Json::class, new Builder());
    }

    public function testFilterJsonContains(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->filterJsonContains('tags', 'php')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `docs` WHERE JSON_CONTAINS(`tags`, ?)', $result->query);
        $this->assertSame('"php"', $result->bindings[0]);
    }

    public function testFilterJsonNotContains(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->filterJsonNotContains('tags', 'old')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `docs` WHERE NOT JSON_CONTAINS(`tags`, ?)', $result->query);
    }

    public function testFilterJsonOverlaps(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->filterJsonOverlaps('tags', ['php', 'go'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `docs` WHERE JSON_OVERLAPS(`tags`, ?)', $result->query);
        $this->assertSame('["php","go"]', $result->bindings[0]);
    }

    public function testFilterJsonPath(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filterJsonPath('metadata', 'level', '>', 5)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE JSON_EXTRACT(`metadata`, \'$.level\') > ?', $result->query);
        $this->assertSame(5, $result->bindings[0]);
    }

    public function testSetJsonAppend(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->setJsonAppend('tags', ['new_tag'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `docs` SET `tags` = JSON_MERGE_PRESERVE(IFNULL(`tags`, JSON_ARRAY()), ?) WHERE `id` IN (?)', $result->query);
    }

    public function testSetJsonPrepend(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->setJsonPrepend('tags', ['first'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `docs` SET `tags` = JSON_MERGE_PRESERVE(?, IFNULL(`tags`, JSON_ARRAY())) WHERE `id` IN (?)', $result->query);
    }

    public function testSetJsonInsert(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->setJsonInsert('tags', 0, 'inserted')
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `docs` SET `tags` = JSON_ARRAY_INSERT(`tags`, ?, ?) WHERE `id` IN (?)', $result->query);
    }

    public function testSetJsonRemove(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->setJsonRemove('tags', 'old_tag')
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `docs` SET `tags` = JSON_REMOVE(`tags`, JSON_UNQUOTE(JSON_SEARCH(`tags`, \'one\', ?))) WHERE `id` IN (?)', $result->query);
    }

    public function testSetJsonPath(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->setJsonPath('data', '$.name', 'NewValue')
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame(
            'UPDATE `docs` SET `data` = JSON_SET(`data`, ?, ?) WHERE `id` IN (?)',
            $result->query
        );
        $this->assertSame('$.name', $result->bindings[0]);
        $this->assertSame('NewValue', $result->bindings[1]);
        $this->assertSame(1, $result->bindings[2]);
    }

    public function testSetJsonPathRejectsInvalidPath(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->from('docs')
            ->setJsonPath('data', 'name', 'NewValue');
    }
    //  Hints feature interface

    public function testImplementsHints(): void
    {
        $this->assertInstanceOf(Hints::class, new Builder());
    }

    public function testHintInSelect(): void
    {
        $result = (new Builder())
            ->from('users')
            ->hint('NO_INDEX_MERGE(users)')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT /*+ NO_INDEX_MERGE(users) */ * FROM `users`', $result->query);
    }

    public function testMaxExecutionTime(): void
    {
        $result = (new Builder())
            ->from('users')
            ->maxExecutionTime(5000)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT /*+ MAX_EXECUTION_TIME(5000) */ * FROM `users`', $result->query);
    }

    public function testMultipleHints(): void
    {
        $result = (new Builder())
            ->from('users')
            ->hint('NO_INDEX_MERGE(users)')
            ->hint('BKA(users)')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT /*+ NO_INDEX_MERGE(users) BKA(users) */ * FROM `users`', $result->query);
    }
    //  Window functions

    public function testImplementsWindows(): void
    {
        $this->assertInstanceOf(Windows::class, new Builder());
    }

    public function testSelectWindowRowNumber(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->selectWindow('ROW_NUMBER()', 'rn', ['customer_id'], ['created_at'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT ROW_NUMBER() OVER (PARTITION BY `customer_id` ORDER BY `created_at` ASC) AS `rn` FROM `orders`', $result->query);
    }

    public function testSelectWindowRank(): void
    {
        $result = (new Builder())
            ->from('scores')
            ->selectWindow('RANK()', 'rank', null, ['-score'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT RANK() OVER (ORDER BY `score` DESC) AS `rank` FROM `scores`', $result->query);
    }

    public function testSelectWindowPartitionOnly(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->selectWindow('SUM(amount)', 'total', ['dept'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT SUM(amount) OVER (PARTITION BY `dept`) AS `total` FROM `orders`', $result->query);
    }

    public function testSelectWindowNoPartitionNoOrder(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->selectWindow('COUNT(*)', 'total')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) OVER () AS `total` FROM `orders`', $result->query);
    }
    //  CASE integration

    public function testSelectCaseExpression(): void
    {
        $case = (new CaseExpression())
            ->when('status', Operator::Equal, 'active', 'Active')
            ->else('Other')
            ->alias('label');

        $result = (new Builder())
            ->from('users')
            ->select(['id'])
            ->selectCase($case)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `id`, CASE WHEN `status` = ? THEN ? ELSE ? END AS `label` FROM `users`', $result->query);
        $this->assertSame(['active', 'Active', 'Other'], $result->bindings);
    }

    public function testSetCaseExpression(): void
    {
        $case = (new CaseExpression())
            ->when('age', Operator::GreaterThanEqual, 18, 'adult')
            ->else('minor');

        $result = (new Builder())
            ->from('users')
            ->setCase('category', $case)
            ->filter([Query::greaterThan('id', 0)])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `users` SET `category` = CASE WHEN `age` >= ? THEN ? ELSE ? END WHERE `id` > ?', $result->query);
        $this->assertSame([18, 'adult', 'minor', 0], $result->bindings);
    }
    //  Query factory methods for JSON

    public function testQueryJsonContainsFactory(): void
    {
        $q = Query::jsonContains('tags', 'php');
        $this->assertSame(Method::JsonContains, $q->getMethod());
        $this->assertSame('tags', $q->getAttribute());
    }

    public function testQueryJsonOverlapsFactory(): void
    {
        $q = Query::jsonOverlaps('tags', ['php', 'go']);
        $this->assertSame(Method::JsonOverlaps, $q->getMethod());
    }

    public function testQueryJsonPathFactory(): void
    {
        $q = Query::jsonPath('meta', 'level', '>', 5);
        $this->assertSame(Method::JsonPath, $q->getMethod());
        $this->assertSame(['level', '>', 5], $q->getValues());
    }
    //  Does NOT implement VectorSearch

    public function testDoesNotImplementVectorSearch(): void
    {
        $builder = new Builder();
        $this->assertNotInstanceOf(VectorSearch::class, $builder); // @phpstan-ignore method.alreadyNarrowedType
    }
    //  Reset clears new state

    public function testResetClearsHintsAndJsonSets(): void
    {
        $builder = (new Builder())
            ->from('users')
            ->hint('test')
            ->setJsonAppend('tags', ['a']);

        $builder->reset();

        $result = $builder->from('users')->build();
        $this->assertBindingCount($result);
        $this->assertStringNotContainsString('/*+', $result->query);
    }

    public function testFilterNotIntersectsPoint(): void
    {
        $result = (new Builder())
            ->from('zones')
            ->filterNotIntersects('zone', [1.0, 2.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `zones` WHERE NOT ST_Intersects(`zone`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
        $this->assertSame('POINT(1 2)', $result->bindings[0]);
    }

    public function testFilterNotCrossesLinestring(): void
    {
        $result = (new Builder())
            ->from('roads')
            ->filterNotCrosses('path', [[0, 0], [1, 1]])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `roads` WHERE NOT ST_Crosses(`path`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
        /** @var string $binding */
        $binding = $result->bindings[0];
        $this->assertSame('LINESTRING(0 0, 1 1)', $binding);
    }

    public function testFilterOverlapsPolygon(): void
    {
        $result = (new Builder())
            ->from('regions')
            ->filterOverlaps('area', [[[0, 0], [1, 0], [1, 1], [0, 0]]])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `regions` WHERE ST_Overlaps(`area`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
        /** @var string $binding */
        $binding = $result->bindings[0];
        $this->assertSame('POLYGON((0 0, 1 0, 1 1, 0 0))', $binding);
    }

    public function testFilterNotOverlaps(): void
    {
        $result = (new Builder())
            ->from('regions')
            ->filterNotOverlaps('area', [1.0, 2.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `regions` WHERE NOT ST_Overlaps(`area`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testFilterTouches(): void
    {
        $result = (new Builder())
            ->from('zones')
            ->filterTouches('zone', [5.0, 10.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `zones` WHERE ST_Touches(`zone`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testFilterNotTouches(): void
    {
        $result = (new Builder())
            ->from('zones')
            ->filterNotTouches('zone', [5.0, 10.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `zones` WHERE NOT ST_Touches(`zone`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testFilterNotCovers(): void
    {
        $result = (new Builder())
            ->from('zones')
            ->filterNotCovers('region', [1.0, 2.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `zones` WHERE NOT ST_Contains(`region`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testFilterNotSpatialEquals(): void
    {
        $result = (new Builder())
            ->from('zones')
            ->filterNotSpatialEquals('geom', [3.0, 4.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `zones` WHERE NOT ST_Equals(`geom`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testFilterDistanceGreaterThan(): void
    {
        $result = (new Builder())
            ->from('locations')
            ->filterDistance('loc', [1.0, 2.0], '>', 500.0)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `locations` WHERE ST_Distance(ST_SRID(`loc`, 0), ST_GeomFromText(?, 0, \'axis-order=long-lat\')) > ?', $result->query);
        $this->assertSame('POINT(1 2)', $result->bindings[0]);
        $this->assertSame(500.0, $result->bindings[1]);
    }

    public function testFilterDistanceEqual(): void
    {
        $result = (new Builder())
            ->from('locations')
            ->filterDistance('loc', [1.0, 2.0], '=', 0.0)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `locations` WHERE ST_Distance(ST_SRID(`loc`, 0), ST_GeomFromText(?, 0, \'axis-order=long-lat\')) = ?', $result->query);
        $this->assertSame('POINT(1 2)', $result->bindings[0]);
        $this->assertSame(0.0, $result->bindings[1]);
    }

    public function testFilterDistanceNotEqual(): void
    {
        $result = (new Builder())
            ->from('locations')
            ->filterDistance('loc', [1.0, 2.0], '!=', 100.0)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `locations` WHERE ST_Distance(ST_SRID(`loc`, 0), ST_GeomFromText(?, 0, \'axis-order=long-lat\')) != ?', $result->query);
        $this->assertSame('POINT(1 2)', $result->bindings[0]);
        $this->assertSame(100.0, $result->bindings[1]);
    }

    public function testFilterDistanceWithoutMeters(): void
    {
        $result = (new Builder())
            ->from('locations')
            ->filterDistance('loc', [1.0, 2.0], '<', 50.0, false)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `locations` WHERE ST_Distance(ST_SRID(`loc`, 0), ST_GeomFromText(?, 0, \'axis-order=long-lat\')) < ?', $result->query);
        $this->assertSame('POINT(1 2)', $result->bindings[0]);
        $this->assertSame(50.0, $result->bindings[1]);
    }

    public function testFilterIntersectsLinestring(): void
    {
        $result = (new Builder())
            ->from('roads')
            ->filterIntersects('path', [[0, 0], [1, 1], [2, 2]])
            ->build();
        $this->assertBindingCount($result);

        /** @var string $binding */
        $binding = $result->bindings[0];
        $this->assertSame('LINESTRING(0 0, 1 1, 2 2)', $binding);
    }

    public function testFilterSpatialEqualsPoint(): void
    {
        $result = (new Builder())
            ->from('places')
            ->filterSpatialEquals('pos', [42.5, -73.2])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `places` WHERE ST_Equals(`pos`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
        $this->assertSame('POINT(42.5 -73.2)', $result->bindings[0]);
    }

    public function testSetJsonIntersect(): void
    {
        $result = (new Builder())
            ->from('t')
            ->setJsonIntersect('tags', ['a', 'b'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `t` SET `tags` = (SELECT JSON_ARRAYAGG(val) FROM JSON_TABLE(`tags`, \'$[*]\' COLUMNS(val JSON PATH \'$\')) AS jt WHERE JSON_CONTAINS(?, val)) WHERE `id` IN (?)', $result->query);
    }

    public function testSetJsonDiff(): void
    {
        $result = (new Builder())
            ->from('t')
            ->setJsonDiff('tags', ['x'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `t` SET `tags` = (SELECT JSON_ARRAYAGG(val) FROM JSON_TABLE(`tags`, \'$[*]\' COLUMNS(val JSON PATH \'$\')) AS jt WHERE NOT JSON_CONTAINS(?, val)) WHERE `id` IN (?)', $result->query);
        $this->assertContains(\json_encode(['x']), $result->bindings);
    }

    public function testSetJsonUnique(): void
    {
        $result = (new Builder())
            ->from('t')
            ->setJsonUnique('tags')
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `t` SET `tags` = (SELECT JSON_ARRAYAGG(val) FROM (SELECT DISTINCT val FROM JSON_TABLE(`tags`, \'$[*]\' COLUMNS(val JSON PATH \'$\')) AS jt) AS dt) WHERE `id` IN (?)', $result->query);
    }

    public function testSetJsonPrependMergeOrder(): void
    {
        $result = (new Builder())
            ->from('t')
            ->setJsonPrepend('items', ['first'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `t` SET `items` = JSON_MERGE_PRESERVE(?, IFNULL(`items`, JSON_ARRAY())) WHERE `id` IN (?)', $result->query);
    }

    public function testSetJsonInsertWithIndex(): void
    {
        $result = (new Builder())
            ->from('t')
            ->setJsonInsert('items', 2, 'value')
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `t` SET `items` = JSON_ARRAY_INSERT(`items`, ?, ?) WHERE `id` IN (?)', $result->query);
        $this->assertContains('$[2]', $result->bindings);
        $this->assertContains('value', $result->bindings);
    }

    public function testFilterJsonNotContainsCompiles(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->filterJsonNotContains('meta', 'admin')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `docs` WHERE NOT JSON_CONTAINS(`meta`, ?)', $result->query);
    }

    public function testFilterJsonOverlapsCompiles(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->filterJsonOverlaps('tags', ['php', 'js'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `docs` WHERE JSON_OVERLAPS(`tags`, ?)', $result->query);
    }

    public function testFilterJsonPathCompiles(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filterJsonPath('data', 'age', '>=', 21)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE JSON_EXTRACT(`data`, \'$.age\') >= ?', $result->query);
        $this->assertSame(21, $result->bindings[0]);
    }

    public function testMultipleHintsNoIcpAndBka(): void
    {
        $result = (new Builder())
            ->from('t')
            ->hint('NO_ICP(t)')
            ->hint('BKA(t)')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT /*+ NO_ICP(t) BKA(t) */ * FROM `t`', $result->query);
    }

    public function testHintWithDistinct(): void
    {
        $result = (new Builder())
            ->from('t')
            ->distinct()
            ->hint('SET_VAR(sort_buffer_size=16M)')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT DISTINCT /*+ SET_VAR(sort_buffer_size=16M) */ * FROM `t`', $result->query);
    }

    public function testHintPreservesBindings(): void
    {
        $result = (new Builder())
            ->from('t')
            ->hint('NO_ICP(t)')
            ->filter([Query::equal('status', ['active'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['active'], $result->bindings);
    }

    public function testMaxExecutionTimeValue(): void
    {
        $result = (new Builder())
            ->from('t')
            ->maxExecutionTime(5000)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT /*+ MAX_EXECUTION_TIME(5000) */ * FROM `t`', $result->query);
    }

    public function testSelectWindowWithPartitionOnly(): void
    {
        $result = (new Builder())
            ->from('t')
            ->selectWindow('SUM(amount)', 'total', ['dept'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT SUM(amount) OVER (PARTITION BY `dept`) AS `total` FROM `t`', $result->query);
    }

    public function testSelectWindowWithOrderOnly(): void
    {
        $result = (new Builder())
            ->from('t')
            ->selectWindow('ROW_NUMBER()', 'rn', null, ['created_at'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT ROW_NUMBER() OVER (ORDER BY `created_at` ASC) AS `rn` FROM `t`', $result->query);
    }

    public function testSelectWindowNoPartitionNoOrderEmpty(): void
    {
        $result = (new Builder())
            ->from('t')
            ->selectWindow('COUNT(*)', 'cnt')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) OVER () AS `cnt` FROM `t`', $result->query);
    }

    public function testMultipleWindowFunctions(): void
    {
        $result = (new Builder())
            ->from('t')
            ->selectWindow('ROW_NUMBER()', 'rn', null, ['id'])
            ->selectWindow('SUM(amount)', 'running_total', null, ['id'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT ROW_NUMBER() OVER (ORDER BY `id` ASC) AS `rn`, SUM(amount) OVER (ORDER BY `id` ASC) AS `running_total` FROM `t`', $result->query);
    }

    public function testSelectWindowWithDescOrder(): void
    {
        $result = (new Builder())
            ->from('t')
            ->selectWindow('RANK()', 'r', null, ['-score'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT RANK() OVER (ORDER BY `score` DESC) AS `r` FROM `t`', $result->query);
    }

    public function testCaseWithMultipleWhens(): void
    {
        $case = (new CaseExpression())
            ->when('x', Operator::Equal, 1, 'one')
            ->when('x', Operator::Equal, 2, 'two')
            ->when('x', Operator::Equal, 3, 'three');

        $result = (new Builder())
            ->from('t')
            ->selectCase($case)
            ->build();

        $this->assertSame('SELECT CASE WHEN `x` = ? THEN ? WHEN `x` = ? THEN ? WHEN `x` = ? THEN ? END FROM `t`', $result->query);
        $this->assertSame([1, 'one', 2, 'two', 3, 'three'], $result->bindings);
    }

    public function testCaseExpressionWithoutElseClause(): void
    {
        $case = (new CaseExpression())
            ->when('x', Operator::GreaterThan, 10, 1)
            ->when('x', Operator::LessThan, 0, 0);

        $result = (new Builder())
            ->from('t')
            ->selectCase($case)
            ->build();

        $this->assertStringNotContainsString('ELSE', $result->query);
    }

    public function testCaseExpressionWithoutAliasClause(): void
    {
        $case = (new CaseExpression())
            ->whenRaw('x = 1', 'yes');

        $result = (new Builder())
            ->from('t')
            ->selectCase($case)
            ->build();

        $this->assertStringNotContainsString('END AS', $result->query);
    }

    public function testSetCaseInUpdate(): void
    {
        $case = (new CaseExpression())
            ->when('age', Operator::GreaterThanEqual, 18, 'adult')
            ->else('minor');

        $result = (new Builder())
            ->from('users')
            ->setCase('status', $case)
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `users` SET `status` = CASE WHEN `age` >= ? THEN ? ELSE ? END WHERE `id` IN (?)', $result->query);
    }

    public function testCaseBuilderThrowsWhenNoWhensAdded(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->from('t')
            ->selectCase(new CaseExpression())
            ->build();
    }

    public function testMultipleCTEsWithTwoSources(): void
    {
        $cte1 = (new Builder())->from('orders');
        $cte2 = (new Builder())->from('returns');

        $result = (new Builder())
            ->with('a', $cte1)
            ->with('b', $cte2)
            ->from('a')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('WITH `a` AS (SELECT * FROM `orders`), `b` AS (SELECT * FROM `returns`) SELECT * FROM `a`', $result->query);
    }

    public function testCTEWithBindings(): void
    {
        $cte = (new Builder())->from('orders')->filter([Query::equal('status', ['paid'])]);

        $result = (new Builder())
            ->with('paid_orders', $cte)
            ->from('paid_orders')
            ->filter([Query::greaterThan('amount', 100)])
            ->build();
        $this->assertBindingCount($result);

        // CTE bindings come BEFORE main query bindings
        $this->assertSame('paid', $result->bindings[0]);
        $this->assertSame(100, $result->bindings[1]);
    }

    public function testCTEWithRecursiveMixed(): void
    {
        $cte1 = (new Builder())->from('products');
        $cte2 = (new Builder())->from('categories');

        $result = (new Builder())
            ->with('prods', $cte1)
            ->withRecursive('tree', $cte2)
            ->from('tree')
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringStartsWith('WITH RECURSIVE', $result->query);
        $this->assertSame('WITH RECURSIVE `prods` AS (SELECT * FROM `products`), `tree` AS (SELECT * FROM `categories`) SELECT * FROM `tree`', $result->query);
    }

    public function testCTEResetClearedAfterBuild(): void
    {
        $cte = (new Builder())->from('orders');
        $builder = (new Builder())
            ->with('o', $cte)
            ->from('o');

        $builder->reset();

        $result = $builder->from('users')->build();
        $this->assertBindingCount($result);
        $this->assertStringNotContainsString('WITH', $result->query);
    }

    public function testInsertSelectWithFilter(): void
    {
        $source = (new Builder())
            ->from('users')
            ->select(['name', 'email'])
            ->filter([Query::equal('status', ['active'])]);

        $result = (new Builder())
            ->into('archive')
            ->fromSelect(['name', 'email'], $source)
            ->insertSelect();

        $this->assertSame('INSERT INTO `archive` (`name`, `email`) SELECT `name`, `email` FROM `users` WHERE `status` IN (?)', $result->query);
        $this->assertSame(['active'], $result->bindings);
    }

    public function testInsertSelectThrowsWithoutSource(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->into('archive')
            ->insertSelect();
    }

    public function testInsertSelectThrowsWithoutColumns(): void
    {
        $this->expectException(ValidationException::class);

        $source = (new Builder())->from('users');

        (new Builder())
            ->into('archive')
            ->fromSelect([], $source)
            ->insertSelect();
    }

    public function testInsertSelectMultipleColumns(): void
    {
        $source = (new Builder())
            ->from('users')
            ->select(['name', 'email', 'age']);

        $result = (new Builder())
            ->into('archive')
            ->fromSelect(['name', 'email', 'age'], $source)
            ->insertSelect();

        $this->assertSame('INSERT INTO `archive` (`name`, `email`, `age`) SELECT `name`, `email`, `age` FROM `users`', $result->query);
    }

    public function testUnionAllCompiles(): void
    {
        $other = (new Builder())->from('archive');
        $result = (new Builder())
            ->from('current')
            ->unionAll($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM `current`) UNION ALL (SELECT * FROM `archive`)', $result->query);
    }

    public function testIntersectCompiles(): void
    {
        $other = (new Builder())->from('admins');
        $result = (new Builder())
            ->from('users')
            ->intersect($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM `users`) INTERSECT (SELECT * FROM `admins`)', $result->query);
    }

    public function testIntersectAllCompiles(): void
    {
        $other = (new Builder())->from('admins');
        $result = (new Builder())
            ->from('users')
            ->intersectAll($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM `users`) INTERSECT ALL (SELECT * FROM `admins`)', $result->query);
    }

    public function testExceptCompiles(): void
    {
        $other = (new Builder())->from('banned');
        $result = (new Builder())
            ->from('users')
            ->except($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM `users`) EXCEPT (SELECT * FROM `banned`)', $result->query);
    }

    public function testExceptAllCompiles(): void
    {
        $other = (new Builder())->from('banned');
        $result = (new Builder())
            ->from('users')
            ->exceptAll($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM `users`) EXCEPT ALL (SELECT * FROM `banned`)', $result->query);
    }

    public function testUnionWithBindings(): void
    {
        $other = (new Builder())->from('admins')->filter([Query::equal('role', ['admin'])]);
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('status', ['active'])])
            ->union($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['active', 'admin'], $result->bindings);
    }

    public function testPageThreeWithTen(): void
    {
        $result = (new Builder())
            ->from('t')
            ->page(3, 10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` LIMIT ? OFFSET ?', $result->query);
        $this->assertSame([10, 20], $result->bindings);
    }

    public function testPageFirstPage(): void
    {
        $result = (new Builder())
            ->from('t')
            ->page(1, 25)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` LIMIT ? OFFSET ?', $result->query);
        $this->assertSame([25, 0], $result->bindings);
    }

    public function testCursorAfterWithSort(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sortAsc('id')
            ->cursorAfter(5)
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `_cursor` > ? ORDER BY `id` ASC LIMIT ?', $result->query);
        $this->assertContains(5, $result->bindings);
        $this->assertContains(10, $result->bindings);
    }

    public function testCursorBeforeWithSort(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sortAsc('id')
            ->cursorBefore(5)
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `_cursor` < ? ORDER BY `id` ASC LIMIT ?', $result->query);
        $this->assertContains(5, $result->bindings);
        $this->assertContains(10, $result->bindings);
    }

    public function testToRawSqlWithStrings(): void
    {
        $sql = (new Builder())
            ->from('t')
            ->filter([Query::equal('name', ['Alice'])])
            ->toRawSql();

        $this->assertSame('SELECT * FROM `t` WHERE `name` IN (\'Alice\')', $sql);
    }

    public function testToRawSqlWithIntegers(): void
    {
        $sql = (new Builder())
            ->from('t')
            ->filter([Query::greaterThan('age', 30)])
            ->toRawSql();

        $this->assertSame('SELECT * FROM `t` WHERE `age` > 30', $sql);
        $this->assertStringNotContainsString("'30'", $sql);
    }

    public function testToRawSqlWithNullValue(): void
    {
        $sql = (new Builder())
            ->from('t')
            ->filter([Query::raw('deleted_at = ?', [null])])
            ->toRawSql();

        $this->assertSame('SELECT * FROM `t` WHERE deleted_at = NULL', $sql);
    }

    public function testToRawSqlWithBooleans(): void
    {
        $sqlTrue = (new Builder())
            ->from('t')
            ->filter([Query::raw('active = ?', [true])])
            ->toRawSql();

        $sqlFalse = (new Builder())
            ->from('t')
            ->filter([Query::raw('active = ?', [false])])
            ->toRawSql();

        $this->assertSame('SELECT * FROM `t` WHERE active = 1', $sqlTrue);
        $this->assertSame('SELECT * FROM `t` WHERE active = 0', $sqlFalse);
    }

    public function testWhenTrueAppliesLimit(): void
    {
        $result = (new Builder())
            ->from('t')
            ->when(true, fn (Builder $b) => $b->limit(5))
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` LIMIT ?', $result->query);
    }

    public function testWhenFalseSkipsLimit(): void
    {
        $result = (new Builder())
            ->from('t')
            ->when(false, fn (Builder $b) => $b->limit(5))
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString('LIMIT', $result->query);
    }

    public function testBuildWithoutTableThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())->build();
    }

    public function testInsertWithoutRowsThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())->into('t')->insert();
    }

    public function testInsertWithEmptyRowThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())->into('t')->set([])->insert();
    }

    public function testUpdateWithoutAssignmentsThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())->from('t')->update();
    }

    public function testUpsertWithoutConflictKeysThrowsValidation(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->into('t')
            ->set(['id' => 1, 'name' => 'Alice'])
            ->upsert();
    }

    public function testBatchInsertMultipleRows(): void
    {
        $result = (new Builder())
            ->into('t')
            ->set(['a' => 1, 'b' => 2])
            ->set(['a' => 3, 'b' => 4])
            ->insert();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO `t` (`a`, `b`) VALUES (?, ?), (?, ?)', $result->query);
        $this->assertSame([1, 2, 3, 4], $result->bindings);
    }

    public function testBatchInsertMismatchedColumnsThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->into('t')
            ->set(['a' => 1, 'b' => 2])
            ->set(['a' => 3, 'c' => 4])
            ->insert();
    }

    public function testEmptyColumnNameThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->into('t')
            ->set(['' => 'val'])
            ->insert();
    }

    public function testSearchNotCompiles(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notSearch('body', 'spam')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE NOT (MATCH(`body`) AGAINST(? IN BOOLEAN MODE))', $result->query);
    }

    public function testRegexpCompiles(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::regex('slug', '^test')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `slug` REGEXP ?', $result->query);
    }

    public function testUpsertUsesOnDuplicateKey(): void
    {
        $result = (new Builder())
            ->into('t')
            ->set(['id' => 1, 'name' => 'Alice'])
            ->onConflict(['id'], ['name'])
            ->upsert();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO `t` (`id`, `name`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `name` = VALUES(`name`)', $result->query);
    }

    public function testForUpdateCompiles(): void
    {
        $result = (new Builder())
            ->from('accounts')
            ->forUpdate()
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringEndsWith('FOR UPDATE', $result->query);
    }

    public function testForShareCompiles(): void
    {
        $result = (new Builder())
            ->from('accounts')
            ->forShare()
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringEndsWith('FOR SHARE', $result->query);
    }

    public function testForUpdateWithFilters(): void
    {
        $result = (new Builder())
            ->from('accounts')
            ->filter([Query::equal('id', [1])])
            ->forUpdate()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `accounts` WHERE `id` IN (?) FOR UPDATE', $result->query);
        $this->assertStringEndsWith('FOR UPDATE', $result->query);
    }

    public function testBeginTransaction(): void
    {
        $result = (new Builder())->begin();
        $this->assertSame('BEGIN', $result->query);
    }

    public function testCommitTransaction(): void
    {
        $result = (new Builder())->commit();
        $this->assertSame('COMMIT', $result->query);
    }

    public function testRollbackTransaction(): void
    {
        $result = (new Builder())->rollback();
        $this->assertSame('ROLLBACK', $result->query);
    }

    public function testReleaseSavepointCompiles(): void
    {
        $result = (new Builder())->releaseSavepoint('sp1');
        $this->assertSame('RELEASE SAVEPOINT `sp1`', $result->query);
    }

    public function testResetClearsCTEs(): void
    {
        $cte = (new Builder())->from('orders');
        $builder = (new Builder())
            ->with('o', $cte)
            ->from('o');

        $builder->reset();

        $result = $builder->from('items')->build();
        $this->assertBindingCount($result);
        $this->assertStringNotContainsString('WITH', $result->query);
    }

    public function testResetClearsUnionsComprehensive(): void
    {
        $other = (new Builder())->from('archive');
        $builder = (new Builder())
            ->from('current')
            ->union($other);

        $builder->reset();

        $result = $builder->from('items')->build();
        $this->assertBindingCount($result);
        $this->assertStringNotContainsString('UNION', $result->query);
    }

    public function testGroupByWithHavingCount(): void
    {
        $result = (new Builder())
            ->from('employees')
            ->count('*', 'cnt')
            ->groupBy(['dept'])
            ->having([Query::and([Query::greaterThan('COUNT(*)', 5)])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `employees` GROUP BY `dept` HAVING (`COUNT(*)` > ?)', $result->query);
    }

    public function testGroupByMultipleColumnsAB(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count('*', 'total')
            ->groupBy(['a', 'b'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `total` FROM `t` GROUP BY `a`, `b`', $result->query);
    }

    public function testEqualEmptyArrayReturnsFalse(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('x', [])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE 1 = 0', $result->query);
    }

    public function testEqualWithNullOnlyCompileIn(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('x', [null])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `x` IS NULL', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testEqualWithNullAndValues(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('x', [1, null])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`x` IN (?) OR `x` IS NULL)', $result->query);
        $this->assertSame([1], $result->bindings);
    }

    public function testEqualMultipleValues(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('x', [1, 2, 3])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `x` IN (?, ?, ?)', $result->query);
        $this->assertSame([1, 2, 3], $result->bindings);
    }

    public function testNotEqualEmptyArrayReturnsTrue(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notEqual('x', [])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE 1 = 1', $result->query);
    }

    public function testNotEqualSingleValue(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notEqual('x', 5)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `x` != ?', $result->query);
        $this->assertSame([5], $result->bindings);
    }

    public function testNotEqualWithNullOnlyCompileNotIn(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notEqual('x', [null])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `x` IS NOT NULL', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testNotEqualWithNullAndValues(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notEqual('x', [1, null])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`x` != ? AND `x` IS NOT NULL)', $result->query);
        $this->assertSame([1], $result->bindings);
    }

    public function testNotEqualMultipleValues(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notEqual('x', [1, 2, 3])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `x` NOT IN (?, ?, ?)', $result->query);
        $this->assertSame([1, 2, 3], $result->bindings);
    }

    public function testNotEqualSingleNonNull(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notEqual('x', 42)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `x` != ?', $result->query);
        $this->assertSame([42], $result->bindings);
    }

    public function testBetweenFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::between('age', 18, 65)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `age` BETWEEN ? AND ?', $result->query);
        $this->assertSame([18, 65], $result->bindings);
    }

    public function testNotBetweenFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notBetween('score', 0, 50)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `score` NOT BETWEEN ? AND ?', $result->query);
        $this->assertSame([0, 50], $result->bindings);
    }

    public function testBetweenWithStrings(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::between('date', '2024-01-01', '2024-12-31')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `date` BETWEEN ? AND ?', $result->query);
        $this->assertSame(['2024-01-01', '2024-12-31'], $result->bindings);
    }

    public function testAndWithTwoFilters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::and([Query::greaterThan('age', 18), Query::lessThan('age', 65)])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`age` > ? AND `age` < ?)', $result->query);
        $this->assertSame([18, 65], $result->bindings);
    }

    public function testOrWithTwoFilters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::or([Query::equal('role', ['admin']), Query::equal('role', ['mod'])])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`role` IN (?) OR `role` IN (?))', $result->query);
        $this->assertSame(['admin', 'mod'], $result->bindings);
    }

    public function testNestedAndInsideOr(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::or([
                    Query::and([Query::greaterThan('a', 1), Query::lessThan('b', 2)]),
                    Query::equal('c', [3]),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE ((`a` > ? AND `b` < ?) OR `c` IN (?))', $result->query);
        $this->assertSame([1, 2, 3], $result->bindings);
    }

    public function testEmptyAndReturnsTrue(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::and([])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE 1 = 1', $result->query);
    }

    public function testEmptyOrReturnsFalse(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::or([])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE 1 = 0', $result->query);
    }

    public function testExistsSingleAttribute(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::exists(['name'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`name` IS NOT NULL)', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testExistsMultipleAttributes(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::exists(['name', 'email'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`name` IS NOT NULL AND `email` IS NOT NULL)', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testNotExistsSingleAttribute(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notExists('name')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`name` IS NULL)', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testNotExistsMultipleAttributes(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notExists(['a', 'b'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`a` IS NULL AND `b` IS NULL)', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testRawFilterWithSql(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::raw('score > ?', [10])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE score > ?', $result->query);
        $this->assertContains(10, $result->bindings);
    }

    public function testRawFilterWithoutBindings(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::raw('active = 1')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE active = 1', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testRawFilterEmpty(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::raw('')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE 1 = 1', $result->query);
    }

    public function testStartsWithEscapesPercent(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::startsWith('name', '100%')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['100\\%%'], $result->bindings);
    }

    public function testStartsWithEscapesUnderscore(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::startsWith('name', 'a_b')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['a\\_b%'], $result->bindings);
    }

    public function testStartsWithEscapesBackslash(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::startsWith('name', 'path\\')])
            ->build();
        $this->assertBindingCount($result);

        /** @var string $binding */
        $binding = $result->bindings[0];
        $this->assertSame("path\\\\%", $binding);
    }

    public function testEndsWithEscapesSpecialChars(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::endsWith('name', '%test_')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['%\\%test\\_'], $result->bindings);
    }

    public function testContainsMultipleValuesUsesOr(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::containsString('bio', ['php', 'js'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`bio` LIKE ? OR `bio` LIKE ?)', $result->query);
        $this->assertSame(['%php%', '%js%'], $result->bindings);
    }

    public function testContainsAllUsesAnd(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::containsAll('bio', ['php', 'js'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`bio` LIKE ? AND `bio` LIKE ?)', $result->query);
        $this->assertSame(['%php%', '%js%'], $result->bindings);
    }

    public function testNotContainsMultipleValues(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notContains('bio', ['x', 'y'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`bio` NOT LIKE ? AND `bio` NOT LIKE ?)', $result->query);
        $this->assertSame(['%x%', '%y%'], $result->bindings);
    }

    public function testContainsSingleValueNoParentheses(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::containsString('bio', ['php'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `bio` LIKE ?', $result->query);
        $this->assertStringNotContainsString('(', $result->query);
    }

    public function testDottedIdentifierInSelect(): void
    {
        $result = (new Builder())
            ->from('t')
            ->select(['users.name', 'users.email'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `users`.`name`, `users`.`email` FROM `t`', $result->query);
    }

    public function testDottedIdentifierInFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('users.id', [1])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `users`.`id` IN (?)', $result->query);
    }

    public function testMultipleOrderBy(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sortAsc('name')
            ->sortDesc('age')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` ORDER BY `name` ASC, `age` DESC', $result->query);
    }

    public function testOrderByWithRandomAndRegular(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sortAsc('name')
            ->sortRandom()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` ORDER BY `name` ASC, RAND()', $result->query);
    }

    public function testDistinctWithSelect(): void
    {
        $result = (new Builder())
            ->from('t')
            ->distinct()
            ->select(['name'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT DISTINCT `name` FROM `t`', $result->query);
    }

    public function testDistinctWithAggregate(): void
    {
        $result = (new Builder())
            ->from('t')
            ->distinct()
            ->count()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT DISTINCT COUNT(*) FROM `t`', $result->query);
    }

    public function testSumWithAlias2(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sum('amount', 'total')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT SUM(`amount`) AS `total` FROM `t`', $result->query);
    }

    public function testAvgWithAlias2(): void
    {
        $result = (new Builder())
            ->from('t')
            ->avg('score', 'avg_score')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT AVG(`score`) AS `avg_score` FROM `t`', $result->query);
    }

    public function testMinWithAlias2(): void
    {
        $result = (new Builder())
            ->from('t')
            ->min('price', 'cheapest')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT MIN(`price`) AS `cheapest` FROM `t`', $result->query);
    }

    public function testMaxWithAlias2(): void
    {
        $result = (new Builder())
            ->from('t')
            ->max('price', 'priciest')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT MAX(`price`) AS `priciest` FROM `t`', $result->query);
    }

    public function testCountWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) FROM `t`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testMultipleAggregates(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count('*', 'cnt')
            ->sum('amount', 'total')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt`, SUM(`amount`) AS `total` FROM `t`', $result->query);
    }

    public function testSelectRawWithRegularSelect(): void
    {
        $result = (new Builder())
            ->from('t')
            ->select(['id'])
            ->select('NOW() as current_time')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `id`, NOW() as current_time FROM `t`', $result->query);
    }

    public function testSelectRawWithBindings2(): void
    {
        $result = (new Builder())
            ->from('t')
            ->select('COALESCE(?, ?) as result', ['a', 'b'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['a', 'b'], $result->bindings);
    }

    public function testRightJoin2(): void
    {
        $result = (new Builder())
            ->from('a')
            ->rightJoin('b', 'a.id', 'b.a_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `a` RIGHT JOIN `b` ON `a`.`id` = `b`.`a_id`', $result->query);
    }

    public function testCrossJoin2(): void
    {
        $result = (new Builder())
            ->from('a')
            ->crossJoin('b')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `a` CROSS JOIN `b`', $result->query);
        $this->assertStringNotContainsString(' ON ', $result->query);
    }

    public function testJoinWithNonEqualOperator(): void
    {
        $result = (new Builder())
            ->from('a')
            ->join('b', 'a.id', 'b.a_id', '!=')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `a` JOIN `b` ON `a`.`id` != `b`.`a_id`', $result->query);
    }

    public function testJoinInvalidOperatorThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->from('a')
            ->join('b', 'a.id', 'b.a_id', 'INVALID')
            ->build();
    }

    public function testMultipleFiltersJoinedWithAnd(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::equal('a', [1]),
                Query::greaterThan('b', 2),
                Query::lessThan('c', 3),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `a` IN (?) AND `b` > ? AND `c` < ?', $result->query);
        $this->assertSame([1, 2, 3], $result->bindings);
    }

    public function testFilterWithRawCombined(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::equal('x', [1]),
                Query::raw('y > 5'),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `x` IN (?) AND y > 5', $result->query);
    }

    public function testResetClearsRawSelects2(): void
    {
        $builder = (new Builder())->from('t')->select('1 AS one');
        $builder->build();
        $builder->reset();

        $result = $builder->from('t')->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t`', $result->query);
        $this->assertStringNotContainsString('one', $result->query);
    }

    public function testAttributeHookResolvesColumn(): void
    {
        $hook = new class () implements Attribute {
            public function resolve(string $attribute): string
            {
                return match ($attribute) {
                    'alias' => 'real_column',
                    default => $attribute,
                };
            }
        };

        $result = (new Builder())
            ->from('t')
            ->addHook($hook)
            ->filter([Query::equal('alias', [1])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `real_column` IN (?)', $result->query);
        $this->assertStringNotContainsString('`alias`', $result->query);
    }

    public function testAttributeHookWithSelect(): void
    {
        $hook = new class () implements Attribute {
            public function resolve(string $attribute): string
            {
                return match ($attribute) {
                    'alias' => 'real_column',
                    default => $attribute,
                };
            }
        };

        $result = (new Builder())
            ->from('t')
            ->addHook($hook)
            ->select(['alias'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `real_column` FROM `t`', $result->query);
    }

    public function testMultipleFilterHooks(): void
    {
        $hook1 = new class () implements Filter {
            public function filter(string $table): Condition
            {
                return new Condition('`tenant` = ?', ['t1']);
            }
        };

        $hook2 = new class () implements Filter {
            public function filter(string $table): Condition
            {
                return new Condition('`org` = ?', ['o1']);
            }
        };

        $result = (new Builder())
            ->from('t')
            ->addHook($hook1)
            ->addHook($hook2)
            ->filter([Query::equal('x', [1])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `x` IN (?) AND `tenant` = ? AND `org` = ?', $result->query);
        $this->assertContains('t1', $result->bindings);
        $this->assertContains('o1', $result->bindings);
    }

    public function testSearchFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::search('body', 'hello world')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE MATCH(`body`) AGAINST(? IN BOOLEAN MODE)', $result->query);
        $this->assertContains('hello world*', $result->bindings);
    }

    public function testNotSearchFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notSearch('body', 'spam')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE NOT (MATCH(`body`) AGAINST(? IN BOOLEAN MODE))', $result->query);
        $this->assertContains('spam*', $result->bindings);
    }

    public function testIsNullFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::isNull('deleted_at')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `deleted_at` IS NULL', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testIsNotNullFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::isNotNull('name')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `name` IS NOT NULL', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testLessThanFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::lessThan('age', 30)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `age` < ?', $result->query);
        $this->assertSame([30], $result->bindings);
    }

    public function testLessThanEqualFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::lessThanEqual('age', 30)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `age` <= ?', $result->query);
        $this->assertSame([30], $result->bindings);
    }

    public function testGreaterThanFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::greaterThan('age', 18)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `age` > ?', $result->query);
        $this->assertSame([18], $result->bindings);
    }

    public function testGreaterThanEqualFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::greaterThanEqual('age', 21)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `age` >= ?', $result->query);
        $this->assertSame([21], $result->bindings);
    }

    public function testNotStartsWithFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notStartsWith('name', 'foo')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `name` NOT LIKE ?', $result->query);
        $this->assertSame(['foo%'], $result->bindings);
    }

    public function testNotEndsWithFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notEndsWith('name', 'bar')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `name` NOT LIKE ?', $result->query);
        $this->assertSame(['%bar'], $result->bindings);
    }

    public function testDeleteWithOrderAndLimit(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::lessThan('age', 18)])
            ->sortAsc('id')
            ->limit(10)
            ->delete();
        $this->assertBindingCount($result);

        $this->assertSame('DELETE FROM `t` WHERE `age` < ? ORDER BY `id` ASC LIMIT ?', $result->query);
    }

    public function testUpdateWithOrderAndLimit(): void
    {
        $result = (new Builder())
            ->from('t')
            ->set(['status' => 'archived'])
            ->filter([Query::lessThan('age', 18)])
            ->sortAsc('id')
            ->limit(10)
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `t` SET `status` = ? WHERE `age` < ? ORDER BY `id` ASC LIMIT ?', $result->query);
    }

    // Feature 1: Table Aliases

    public function testTableAlias(): void
    {
        $result = (new Builder())
            ->from('users', 'u')
            ->select(['u.name', 'u.email'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `u`.`name`, `u`.`email` FROM `users` AS `u`', $result->query);
    }

    public function testJoinAlias(): void
    {
        $result = (new Builder())
            ->from('users', 'u')
            ->join('orders', 'u.id', 'o.user_id', '=', 'o')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` AS `u` JOIN `orders` AS `o` ON `u`.`id` = `o`.`user_id`', $result->query);
    }

    public function testLeftJoinAlias(): void
    {
        $result = (new Builder())
            ->from('users')
            ->leftJoin('orders', 'users.id', 'o.user_id', '=', 'o')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` LEFT JOIN `orders` AS `o` ON `users`.`id` = `o`.`user_id`', $result->query);
    }

    public function testRightJoinAlias(): void
    {
        $result = (new Builder())
            ->from('users')
            ->rightJoin('orders', 'users.id', 'o.user_id', '=', 'o')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` RIGHT JOIN `orders` AS `o` ON `users`.`id` = `o`.`user_id`', $result->query);
    }

    public function testCrossJoinAlias(): void
    {
        $result = (new Builder())
            ->from('users')
            ->crossJoin('colors', 'c')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` CROSS JOIN `colors` AS `c`', $result->query);
    }

    // Feature 2: Subqueries

    public function testFilterWhereIn(): void
    {
        $sub = (new Builder())->from('orders')->select(['user_id'])->filter([Query::greaterThan('total', 100)]);
        $result = (new Builder())
            ->from('users')
            ->filterWhereIn('id', $sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `users` WHERE `id` IN (SELECT `user_id` FROM `orders` WHERE `total` > ?)',
            $result->query
        );
        $this->assertSame([100], $result->bindings);
    }

    public function testFilterWhereNotIn(): void
    {
        $sub = (new Builder())->from('blacklist')->select(['user_id']);
        $result = (new Builder())
            ->from('users')
            ->filterWhereNotIn('id', $sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE `id` NOT IN (SELECT `user_id` FROM `blacklist`)', $result->query);
    }

    public function testSelectSub(): void
    {
        $sub = (new Builder())->from('orders')->count('*', 'cnt')->filter([Query::raw('`orders`.`user_id` = `users`.`id`')]);
        $result = (new Builder())
            ->from('users')
            ->select(['name'])
            ->selectSub($sub, 'order_count')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `name`, (SELECT COUNT(*) AS `cnt` FROM `orders` WHERE `orders`.`user_id` = `users`.`id`) AS `order_count` FROM `users`', $result->query);
    }

    public function testFromSub(): void
    {
        $sub = (new Builder())->from('orders')->select(['user_id'])->groupBy(['user_id']);
        $result = (new Builder())
            ->fromSub($sub, 'sub')
            ->select(['user_id'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `user_id` FROM (SELECT `user_id` FROM `orders` GROUP BY `user_id`) AS `sub`',
            $result->query
        );
    }

    // Feature 3: Raw ORDER BY / GROUP BY / HAVING

    public function testOrderByRaw(): void
    {
        $result = (new Builder())
            ->from('users')
            ->orderByRaw('FIELD(`status`, ?, ?, ?)', ['active', 'pending', 'inactive'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` ORDER BY FIELD(`status`, ?, ?, ?)', $result->query);
        $this->assertSame(['active', 'pending', 'inactive'], $result->bindings);
    }

    public function testGroupByRaw(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'cnt')
            ->groupByRaw('YEAR(`created_at`)')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `orders` GROUP BY YEAR(`created_at`)', $result->query);
    }

    public function testHavingRaw(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'cnt')
            ->groupBy(['user_id'])
            ->havingRaw('COUNT(*) > ?', [5])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `orders` GROUP BY `user_id` HAVING COUNT(*) > ?', $result->query);
        $this->assertContains(5, $result->bindings);
    }

    public function testWhereRawAppendsFragmentAndBindings(): void
    {
        $result = (new Builder())
            ->from('users')
            ->whereRaw('a = ?', [1])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE a = ?', $result->query);
        $this->assertSame([1], $result->bindings);
    }

    public function testWhereRawCombinesWithFilter(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('b', [2])])
            ->whereRaw('a = ?', [1])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE `b` IN (?) AND a = ?', $result->query);
        $this->assertContains(1, $result->bindings);
        $this->assertContains(2, $result->bindings);
    }

    // Feature 4: countDistinct

    public function testCountDistinct(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->countDistinct('user_id', 'unique_users')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT COUNT(DISTINCT `user_id`) AS `unique_users` FROM `orders`',
            $result->query
        );
    }

    public function testCountDistinctNoAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->countDistinct('user_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT COUNT(DISTINCT `user_id`) FROM `orders`',
            $result->query
        );
    }

    // Feature 5: JoinBuilder (complex JOIN ON)

    public function testJoinWhere(): void
    {
        $result = (new Builder())
            ->from('users')
            ->joinWhere('orders', function (JoinBuilder $join): void {
                $join->on('users.id', 'orders.user_id')
                    ->where('orders.status', '=', 'active');
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` JOIN `orders` ON `users`.`id` = `orders`.`user_id` AND orders.status = ?', $result->query);
        $this->assertSame(['active'], $result->bindings);
    }

    public function testJoinWhereMultipleOns(): void
    {
        $result = (new Builder())
            ->from('users')
            ->joinWhere('orders', function (JoinBuilder $join): void {
                $join->on('users.id', 'orders.user_id')
                    ->on('users.org_id', 'orders.org_id');
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` JOIN `orders` ON `users`.`id` = `orders`.`user_id` AND `users`.`org_id` = `orders`.`org_id`', $result->query);
    }

    public function testJoinWhereLeftJoin(): void
    {
        $result = (new Builder())
            ->from('users')
            ->joinWhere('orders', function (JoinBuilder $join): void {
                $join->on('users.id', 'orders.user_id');
            }, JoinType::Left)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` LEFT JOIN `orders` ON `users`.`id` = `orders`.`user_id`', $result->query);
    }

    public function testJoinWhereWithAlias(): void
    {
        $result = (new Builder())
            ->from('users', 'u')
            ->joinWhere('orders', function (JoinBuilder $join): void {
                $join->on('u.id', 'o.user_id');
            }, JoinType::Inner, 'o')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` AS `u` JOIN `orders` AS `o` ON `u`.`id` = `o`.`user_id`', $result->query);
    }

    // Feature 6: EXISTS Subquery

    public function testFilterExists(): void
    {
        $sub = (new Builder())
            ->from('orders')
            ->select(['id'])
            ->filter([Query::raw('`orders`.`user_id` = `users`.`id`')]);

        $result = (new Builder())
            ->from('users')
            ->filterExists($sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE EXISTS (SELECT `id` FROM `orders` WHERE `orders`.`user_id` = `users`.`id`)', $result->query);
    }

    public function testFilterNotExists(): void
    {
        $sub = (new Builder())
            ->from('orders')
            ->select(['id'])
            ->filter([Query::raw('`orders`.`user_id` = `users`.`id`')]);

        $result = (new Builder())
            ->from('users')
            ->filterNotExists($sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE NOT EXISTS (SELECT `id` FROM `orders` WHERE `orders`.`user_id` = `users`.`id`)', $result->query);
    }

    // Feature 7: insertOrIgnore

    public function testInsertOrIgnore(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'John', 'email' => 'john@example.com'])
            ->insertOrIgnore();

        $this->assertSame(
            'INSERT IGNORE INTO `users` (`name`, `email`) VALUES (?, ?)',
            $result->query
        );
        $this->assertSame(['John', 'john@example.com'], $result->bindings);
    }

    // Feature 9: EXPLAIN

    public function testExplain(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('status', ['active'])])
            ->explain();

        $this->assertStringStartsWith('EXPLAIN SELECT', $result->query);
        $this->assertSame('EXPLAIN SELECT * FROM `users` WHERE `status` IN (?)', $result->query);
    }

    public function testExplainAnalyze(): void
    {
        $result = (new Builder())
            ->from('users')
            ->explain(true);

        $this->assertStringStartsWith('EXPLAIN ANALYZE SELECT', $result->query);
    }

    // Feature 10: Locking Variants

    public function testForUpdateSkipLocked(): void
    {
        $result = (new Builder())
            ->from('users')
            ->forUpdateSkipLocked()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` FOR UPDATE SKIP LOCKED', $result->query);
    }

    public function testForUpdateNoWait(): void
    {
        $result = (new Builder())
            ->from('users')
            ->forUpdateNoWait()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` FOR UPDATE NOWAIT', $result->query);
    }

    public function testForShareSkipLocked(): void
    {
        $result = (new Builder())
            ->from('users')
            ->forShareSkipLocked()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` FOR SHARE SKIP LOCKED', $result->query);
    }

    public function testForShareNoWait(): void
    {
        $result = (new Builder())
            ->from('users')
            ->forShareNoWait()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` FOR SHARE NOWAIT', $result->query);
    }

    // Reset clears new properties

    public function testResetClearsNewProperties(): void
    {
        $builder = new Builder();
        $sub = (new Builder())->from('t')->select(['id']);

        $builder->from('users', 'u')
            ->filterWhereIn('id', $sub)
            ->selectSub($sub, 'cnt')
            ->orderByRaw('RAND()')
            ->groupByRaw('YEAR(created_at)')
            ->havingRaw('COUNT(*) > 1')
            ->countDistinct('id')
            ->filterExists($sub)
            ->reset();

        // After reset, building without setting table should throw
        $this->expectException(ValidationException::class);
        $builder->build();
    }

    // Case Builder — unit-level tests

    public function testCaseBuilderEmptyWhenThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('at least one WHEN');

        (new Builder())
            ->from('t')
            ->selectCase(new CaseExpression())
            ->build();
    }

    public function testCaseBuilderMultipleWhens(): void
    {
        $case = (new CaseExpression())
            ->when('status', Operator::Equal, 'active', 'Active')
            ->when('status', Operator::Equal, 'inactive', 'Inactive')
            ->else('Unknown')
            ->alias('label');

        $result = (new Builder())
            ->from('t')
            ->selectCase($case)
            ->build();

        $this->assertSame('SELECT CASE WHEN `status` = ? THEN ? WHEN `status` = ? THEN ? ELSE ? END AS `label` FROM `t`', $result->query);
        $this->assertSame(['active', 'Active', 'inactive', 'Inactive', 'Unknown'], $result->bindings);
    }

    public function testCaseBuilderWithoutElseClause(): void
    {
        $case = (new CaseExpression())
            ->when('x', Operator::GreaterThan, 10, 1);

        $result = (new Builder())
            ->from('t')
            ->selectCase($case)
            ->build();

        $this->assertSame('SELECT CASE WHEN `x` > ? THEN ? END FROM `t`', $result->query);
        $this->assertSame([10, 1], $result->bindings);
    }

    public function testCaseBuilderWithoutAliasClause(): void
    {
        $case = (new CaseExpression())
            ->whenRaw('1=1', 'yes');

        $result = (new Builder())
            ->from('t')
            ->selectCase($case)
            ->build();

        $this->assertStringNotContainsString('END AS', $result->query);
    }

    // JoinBuilder — unit-level tests

    public function testJoinBuilderOnReturnsConditions(): void
    {
        $jb = new JoinBuilder();
        $jb->on('a.id', 'b.a_id')
           ->on('a.tenant', 'b.tenant', '=');

        $ons = $jb->ons;
        $this->assertCount(2, $ons);
        $this->assertSame('a.id', $ons[0]->left);
        $this->assertSame('b.a_id', $ons[0]->right);
        $this->assertSame('=', $ons[0]->operator);
    }

    public function testJoinBuilderWhereAddsCondition(): void
    {
        $jb = new JoinBuilder();
        $jb->where('status', '=', 'active');

        $wheres = $jb->wheres;
        $this->assertCount(1, $wheres);
        $this->assertSame('status = ?', $wheres[0]->expression);
        $this->assertSame(['active'], $wheres[0]->bindings);
    }

    public function testJoinBuilderOnRaw(): void
    {
        $jb = new JoinBuilder();
        $jb->onRaw('a.created_at > NOW() - INTERVAL ? DAY', [30]);

        $wheres = $jb->wheres;
        $this->assertCount(1, $wheres);
        $this->assertSame([30], $wheres[0]->bindings);
    }

    public function testJoinBuilderWhereRaw(): void
    {
        $jb = new JoinBuilder();
        $jb->whereRaw('`deleted_at` IS NULL');

        $wheres = $jb->wheres;
        $this->assertCount(1, $wheres);
        $this->assertSame('`deleted_at` IS NULL', $wheres[0]->expression);
        $this->assertSame([], $wheres[0]->bindings);
    }

    public function testJoinBuilderCombinedOnAndWhere(): void
    {
        $jb = new JoinBuilder();
        $jb->on('a.id', 'b.a_id')
           ->where('b.active', '=', true)
           ->onRaw('b.score > ?', [50]);

        $this->assertCount(1, $jb->ons);
        $this->assertCount(2, $jb->wheres);
    }

    // Subquery binding order

    public function testSubqueryBindingOrderIsCorrect(): void
    {
        $sub = (new Builder())->from('orders')
            ->select(['user_id'])
            ->filter([Query::equal('status', ['completed'])]);

        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('role', ['admin'])])
            ->filterWhereIn('id', $sub)
            ->build();
        $this->assertBindingCount($result);

        // Main filter bindings come before subquery bindings
        $this->assertSame(['admin', 'completed'], $result->bindings);
    }

    public function testSelectSubBindingOrder(): void
    {
        $sub = (new Builder())->from('orders')
            ->select('COUNT(*)')
            ->filter([Query::equal('orders.user_id', ['matched'])]);

        $result = (new Builder())
            ->from('users')
            ->selectSub($sub, 'order_count')
            ->filter([Query::equal('active', [true])])
            ->build();
        $this->assertBindingCount($result);

        // Sub-select bindings come before main WHERE bindings
        $this->assertSame(['matched', true], $result->bindings);
    }

    public function testFromSubBindingOrder(): void
    {
        $sub = (new Builder())->from('orders')
            ->filter([Query::greaterThan('amount', 100)]);

        $result = (new Builder())
            ->fromSub($sub, 'expensive')
            ->filter([Query::equal('status', ['shipped'])])
            ->build();
        $this->assertBindingCount($result);

        // FROM sub bindings come before main WHERE bindings
        $this->assertSame([100, 'shipped'], $result->bindings);
    }

    // EXISTS with bindings

    public function testFilterExistsBindings(): void
    {
        $sub = (new Builder())->from('orders')
            ->select(['id'])
            ->filter([Query::equal('status', ['paid'])]);

        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('active', [true])])
            ->filterExists($sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE `active` IN (?) AND EXISTS (SELECT `id` FROM `orders` WHERE `status` IN (?))', $result->query);
        $this->assertSame([true, 'paid'], $result->bindings);
    }

    public function testFilterNotExistsQuery(): void
    {
        $sub = (new Builder())->from('bans')->select(['id']);

        $result = (new Builder())
            ->from('users')
            ->filterNotExists($sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE NOT EXISTS (SELECT `id` FROM `bans`)', $result->query);
    }

    // Combined features

    public function testExplainWithFilters(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('active', [true])])
            ->explain();

        $this->assertStringStartsWith('EXPLAIN SELECT', $result->query);
        $this->assertSame([true], $result->bindings);
    }

    public function testExplainAnalyzeWithFilters(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('active', [true])])
            ->explain(true);

        $this->assertStringStartsWith('EXPLAIN ANALYZE SELECT', $result->query);
        $this->assertSame([true], $result->bindings);
    }

    public function testTableAliasClearsOnNewFrom(): void
    {
        $builder = (new Builder())
            ->from('users', 'u');

        // Reset with new from() should clear alias
        $result = $builder->from('orders')->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `orders`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testFromSubClearsTable(): void
    {
        $sub = (new Builder())->from('orders')->select(['id']);

        $builder = (new Builder())
            ->from('users')
            ->fromSub($sub, 'sub');

        $result = $builder->build();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString('`users`', $result->query);
        $this->assertSame('SELECT * FROM (SELECT `id` FROM `orders`) AS `sub`', $result->query);
    }

    public function testFromClearsFromSub(): void
    {
        $sub = (new Builder())->from('orders')->select(['id']);

        $builder = (new Builder())
            ->fromSub($sub, 'sub')
            ->from('users');

        $result = $builder->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users`', $result->query);
        $this->assertStringNotContainsString('sub', $result->query);
    }

    // Raw clauses with bindings

    public function testOrderByRawWithBindings(): void
    {
        $result = (new Builder())
            ->from('users')
            ->orderByRaw('FIELD(`status`, ?, ?, ?)', ['active', 'pending', 'inactive'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` ORDER BY FIELD(`status`, ?, ?, ?)', $result->query);
        $this->assertSame(['active', 'pending', 'inactive'], $result->bindings);
    }

    public function testGroupByRawWithBindings(): void
    {
        $result = (new Builder())
            ->from('events')
            ->count('*', 'cnt')
            ->groupByRaw('DATE_FORMAT(`created_at`, ?)', ['%Y-%m'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `events` GROUP BY DATE_FORMAT(`created_at`, ?)', $result->query);
        $this->assertSame(['%Y-%m'], $result->bindings);
    }

    public function testHavingRawWithBindings(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'cnt')
            ->groupBy(['user_id'])
            ->havingRaw('SUM(`amount`) > ?', [1000])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `orders` GROUP BY `user_id` HAVING SUM(`amount`) > ?', $result->query);
        $this->assertSame([1000], $result->bindings);
    }

    public function testMultipleRawOrdersCombined(): void
    {
        $result = (new Builder())
            ->from('users')
            ->sortAsc('name')
            ->orderByRaw('FIELD(`role`, ?)', ['admin'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` ORDER BY FIELD(`role`, ?), `name` ASC', $result->query);
    }

    public function testMultipleRawGroupsCombined(): void
    {
        $result = (new Builder())
            ->from('events')
            ->count('*', 'cnt')
            ->groupBy(['type'])
            ->groupByRaw('YEAR(`created_at`)')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `events` GROUP BY `type`, YEAR(`created_at`)', $result->query);
    }

    // countDistinct with alias and without

    public function testCountDistinctWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('users')
            ->countDistinct('email')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(DISTINCT `email`) FROM `users`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    // Join alias with various join types

    public function testLeftJoinWithAlias(): void
    {
        $result = (new Builder())
            ->from('users', 'u')
            ->leftJoin('orders', 'u.id', 'o.user_id', '=', 'o')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` AS `u` LEFT JOIN `orders` AS `o` ON `u`.`id` = `o`.`user_id`', $result->query);
    }

    public function testRightJoinWithAlias(): void
    {
        $result = (new Builder())
            ->from('users', 'u')
            ->rightJoin('orders', 'u.id', 'o.user_id', '=', 'o')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` AS `u` RIGHT JOIN `orders` AS `o` ON `u`.`id` = `o`.`user_id`', $result->query);
    }

    public function testCrossJoinWithAlias(): void
    {
        $result = (new Builder())
            ->from('users')
            ->crossJoin('roles', 'r')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` CROSS JOIN `roles` AS `r`', $result->query);
    }

    // JoinWhere with LEFT JOIN

    public function testJoinWhereWithLeftJoinType(): void
    {
        $result = (new Builder())
            ->from('users')
            ->joinWhere('orders', function (JoinBuilder $join): void {
                $join->on('users.id', 'orders.user_id')
                     ->where('orders.status', '=', 'active');
            }, JoinType::Left)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` LEFT JOIN `orders` ON `users`.`id` = `orders`.`user_id` AND orders.status = ?', $result->query);
        $this->assertSame(['active'], $result->bindings);
    }

    public function testJoinWhereWithTableAlias(): void
    {
        $result = (new Builder())
            ->from('users', 'u')
            ->joinWhere('orders', function (JoinBuilder $join): void {
                $join->on('u.id', 'o.user_id');
            }, JoinType::Inner, 'o')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` AS `u` JOIN `orders` AS `o` ON `u`.`id` = `o`.`user_id`', $result->query);
    }

    public function testJoinWhereWithMultipleOnConditions(): void
    {
        $result = (new Builder())
            ->from('users')
            ->joinWhere('orders', function (JoinBuilder $join): void {
                $join->on('users.id', 'orders.user_id')
                     ->on('users.tenant_id', 'orders.tenant_id');
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` JOIN `orders` ON `users`.`id` = `orders`.`user_id` AND `users`.`tenant_id` = `orders`.`tenant_id`', $result->query);
    }

    // WHERE IN subquery combined with regular filters

    public function testWhereInSubqueryWithRegularFilters(): void
    {
        $sub = (new Builder())->from('vip_users')->select(['id']);

        $result = (new Builder())
            ->from('orders')
            ->filter([
                Query::greaterThan('amount', 100),
                Query::equal('status', ['paid']),
            ])
            ->filterWhereIn('user_id', $sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `orders` WHERE `amount` > ? AND `status` IN (?) AND `user_id` IN (SELECT `id` FROM `vip_users`)', $result->query);
    }

    // Multiple subqueries

    public function testMultipleWhereInSubqueries(): void
    {
        $sub1 = (new Builder())->from('admins')->select(['id']);
        $sub2 = (new Builder())->from('departments')->select(['id']);

        $result = (new Builder())
            ->from('users')
            ->filterWhereIn('id', $sub1)
            ->filterWhereNotIn('dept_id', $sub2)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE `id` IN (SELECT `id` FROM `admins`) AND `dept_id` NOT IN (SELECT `id` FROM `departments`)', $result->query);
    }

    // insertOrIgnore

    public function testInsertOrIgnoreMySQL(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'John', 'email' => 'john@example.com'])
            ->insertOrIgnore();

        $this->assertStringStartsWith('INSERT IGNORE INTO', $result->query);
        $this->assertSame(['John', 'john@example.com'], $result->bindings);
    }

    // toRawSql with various types

    public function testToRawSqlWithMixedTypes(): void
    {
        $sql = (new Builder())
            ->from('users')
            ->filter([
                Query::equal('name', ['O\'Brien']),
                Query::equal('active', [true]),
                Query::equal('age', [25]),
            ])
            ->toRawSql();

        $this->assertSame('SELECT * FROM `users` WHERE `name` IN (\'O\'\'Brien\') AND `active` IN (1) AND `age` IN (25)', $sql);
    }

    // page() helper

    public function testPageFirstPageOffsetZero(): void
    {
        $result = (new Builder())
            ->from('users')
            ->page(1, 10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` LIMIT ? OFFSET ?', $result->query);
        $this->assertContains(10, $result->bindings);
        $this->assertContains(0, $result->bindings);
    }

    public function testPageThirdPage(): void
    {
        $result = (new Builder())
            ->from('users')
            ->page(3, 25)
            ->build();
        $this->assertBindingCount($result);

        $this->assertContains(25, $result->bindings);
        $this->assertContains(50, $result->bindings);
    }

    // when() conditional

    public function testWhenTrueAppliesCallback(): void
    {
        $result = (new Builder())
            ->from('users')
            ->when(true, fn (Builder $b) => $b->filter([Query::equal('active', [true])]))
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE `active` IN (?)', $result->query);
    }

    public function testWhenFalseSkipsCallback(): void
    {
        $result = (new Builder())
            ->from('users')
            ->when(false, fn (Builder $b) => $b->filter([Query::equal('active', [true])]))
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString('WHERE', $result->query);
    }

    // Locking combined with query

    public function testLockingAppearsAtEnd(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('id', [1])])
            ->limit(1)
            ->forUpdate()
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringEndsWith('FOR UPDATE', $result->query);
    }

    // CTE with main query bindings

    public function testCteBindingOrder(): void
    {
        $cte = (new Builder())->from('orders')
            ->filter([Query::equal('status', ['paid'])]);

        $result = (new Builder())
            ->with('paid_orders', $cte)
            ->from('paid_orders')
            ->filter([Query::greaterThan('amount', 100)])
            ->build();
        $this->assertBindingCount($result);

        // CTE bindings come first
        $this->assertSame(['paid', 100], $result->bindings);
    }

    public function testExactSimpleSelect(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name', 'email'])
            ->filter([Query::equal('status', ['active'])])
            ->sortAsc('name')
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `name`, `email` FROM `users` WHERE `status` IN (?) ORDER BY `name` ASC LIMIT ?',
            $result->query
        );
        $this->assertSame(['active', 10], $result->bindings);
    }

    public function testExactSelectWithMultipleFilters(): void
    {
        $result = (new Builder())
            ->from('products')
            ->select(['id', 'name', 'price'])
            ->filter([
                Query::greaterThan('price', 10),
                Query::lessThanEqual('price', 500),
                Query::equal('category', ['electronics']),
                Query::startsWith('name', 'Pro'),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `name`, `price` FROM `products` WHERE `price` > ? AND `price` <= ? AND `category` IN (?) AND `name` LIKE ?',
            $result->query
        );
        $this->assertSame([10, 500, 'electronics', 'Pro%'], $result->bindings);
    }

    public function testExactMultipleJoins(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->select(['orders.id', 'users.name', 'products.title'])
            ->join('users', 'orders.user_id', 'users.id')
            ->leftJoin('products', 'orders.product_id', 'products.id')
            ->rightJoin('categories', 'products.category_id', 'categories.id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `orders`.`id`, `users`.`name`, `products`.`title` FROM `orders` JOIN `users` ON `orders`.`user_id` = `users`.`id` LEFT JOIN `products` ON `orders`.`product_id` = `products`.`id` RIGHT JOIN `categories` ON `products`.`category_id` = `categories`.`id`',
            $result->query
        );
        $this->assertSame([], $result->bindings);
    }

    public function testExactCrossJoin(): void
    {
        $result = (new Builder())
            ->from('sizes')
            ->select(['sizes.label', 'colors.name'])
            ->crossJoin('colors')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `sizes`.`label`, `colors`.`name` FROM `sizes` CROSS JOIN `colors`',
            $result->query
        );
        $this->assertSame([], $result->bindings);
    }

    public function testExactInsertMultipleRows(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'Alice', 'email' => 'alice@test.com'])
            ->set(['name' => 'Bob', 'email' => 'bob@test.com'])
            ->set(['name' => 'Charlie', 'email' => 'charlie@test.com'])
            ->insert();
        $this->assertBindingCount($result);

        $this->assertSame(
            'INSERT INTO `users` (`name`, `email`) VALUES (?, ?), (?, ?), (?, ?)',
            $result->query
        );
        $this->assertSame(['Alice', 'alice@test.com', 'Bob', 'bob@test.com', 'Charlie', 'charlie@test.com'], $result->bindings);
    }

    public function testExactUpdateWithOrderAndLimit(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['status' => 'archived'])
            ->filter([Query::lessThan('last_login', '2023-06-01')])
            ->sortAsc('last_login')
            ->limit(50)
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame(
            'UPDATE `users` SET `status` = ? WHERE `last_login` < ? ORDER BY `last_login` ASC LIMIT ?',
            $result->query
        );
        $this->assertSame(['archived', '2023-06-01', 50], $result->bindings);
    }

    public function testExactDeleteWithOrderAndLimit(): void
    {
        $result = (new Builder())
            ->from('logs')
            ->filter([Query::lessThan('created_at', '2023-01-01')])
            ->sortAsc('created_at')
            ->limit(500)
            ->delete();
        $this->assertBindingCount($result);

        $this->assertSame(
            'DELETE FROM `logs` WHERE `created_at` < ? ORDER BY `created_at` ASC LIMIT ?',
            $result->query
        );
        $this->assertSame(['2023-01-01', 500], $result->bindings);
    }

    public function testExactUpsertOnDuplicateKey(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['id' => 1, 'name' => 'Alice', 'email' => 'alice@new.com'])
            ->onConflict(['id'], ['name', 'email'])
            ->upsert();
        $this->assertBindingCount($result);

        $this->assertSame(
            'INSERT INTO `users` (`id`, `name`, `email`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `email` = VALUES(`email`)',
            $result->query
        );
        $this->assertSame([1, 'Alice', 'alice@new.com'], $result->bindings);
    }

    public function testExactSubqueryWhereIn(): void
    {
        $sub = (new Builder())
            ->from('orders')
            ->select(['user_id'])
            ->filter([Query::greaterThan('total', 1000)]);

        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->filterWhereIn('id', $sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `name` FROM `users` WHERE `id` IN (SELECT `user_id` FROM `orders` WHERE `total` > ?)',
            $result->query
        );
        $this->assertSame([1000], $result->bindings);
    }

    public function testExactExistsSubquery(): void
    {
        $sub = (new Builder())
            ->from('orders')
            ->select(['id'])
            ->filter([Query::raw('`orders`.`user_id` = `users`.`id`')]);

        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->filterExists($sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `name` FROM `users` WHERE EXISTS (SELECT `id` FROM `orders` WHERE `orders`.`user_id` = `users`.`id`)',
            $result->query
        );
        $this->assertSame([], $result->bindings);
    }

    public function testExactCte(): void
    {
        $cte = (new Builder())
            ->from('orders')
            ->select(['user_id', 'total'])
            ->filter([Query::equal('status', ['paid'])]);

        $result = (new Builder())
            ->with('paid_orders', $cte)
            ->from('paid_orders')
            ->select(['user_id'])
            ->sum('total', 'total_spent')
            ->groupBy(['user_id'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'WITH `paid_orders` AS (SELECT `user_id`, `total` FROM `orders` WHERE `status` IN (?)) SELECT SUM(`total`) AS `total_spent`, `user_id` FROM `paid_orders` GROUP BY `user_id`',
            $result->query
        );
        $this->assertSame(['paid'], $result->bindings);
    }

    public function testExactCaseInSelect(): void
    {
        $case = (new CaseExpression())
            ->when('status', Operator::Equal, 'active', 'Active')
            ->when('status', Operator::Equal, 'inactive', 'Inactive')
            ->else('Unknown')
            ->alias('status_label');

        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->selectCase($case)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `name`, CASE WHEN `status` = ? THEN ? WHEN `status` = ? THEN ? ELSE ? END AS `status_label` FROM `users`',
            $result->query
        );
        $this->assertSame(['active', 'Active', 'inactive', 'Inactive', 'Unknown'], $result->bindings);
    }

    public function testExactAggregationGroupByHaving(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->select(['user_id'])
            ->count('*', 'order_count')
            ->sum('total', 'total_spent')
            ->groupBy(['user_id'])
            ->having([Query::greaterThan('order_count', 5)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT COUNT(*) AS `order_count`, SUM(`total`) AS `total_spent`, `user_id` FROM `orders` GROUP BY `user_id` HAVING COUNT(*) > ?',
            $result->query
        );
        $this->assertSame([5], $result->bindings);
    }

    public function testExactUnion(): void
    {
        $admins = (new Builder())
            ->from('admins')
            ->select(['id', 'name'])
            ->filter([Query::equal('role', ['admin'])]);

        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->filter([Query::equal('status', ['active'])])
            ->union($admins)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            '(SELECT `id`, `name` FROM `users` WHERE `status` IN (?)) UNION (SELECT `id`, `name` FROM `admins` WHERE `role` IN (?))',
            $result->query
        );
        $this->assertSame(['active', 'admin'], $result->bindings);
    }

    public function testExactUnionAll(): void
    {
        $archive = (new Builder())
            ->from('orders_archive')
            ->select(['id', 'total', 'created_at']);

        $result = (new Builder())
            ->from('orders')
            ->select(['id', 'total', 'created_at'])
            ->unionAll($archive)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            '(SELECT `id`, `total`, `created_at` FROM `orders`) UNION ALL (SELECT `id`, `total`, `created_at` FROM `orders_archive`)',
            $result->query
        );
        $this->assertSame([], $result->bindings);
    }

    public function testExactWindowFunction(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->select(['id', 'customer_id', 'total'])
            ->selectWindow('ROW_NUMBER()', 'rn', ['customer_id'], ['total'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `customer_id`, `total`, ROW_NUMBER() OVER (PARTITION BY `customer_id` ORDER BY `total` ASC) AS `rn` FROM `orders`',
            $result->query
        );
        $this->assertSame([], $result->bindings);
    }

    public function testExactForUpdate(): void
    {
        $result = (new Builder())
            ->from('accounts')
            ->select(['id', 'balance'])
            ->filter([Query::equal('id', [42])])
            ->forUpdate()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `balance` FROM `accounts` WHERE `id` IN (?) FOR UPDATE',
            $result->query
        );
        $this->assertSame([42], $result->bindings);
    }

    public function testExactForShareSkipLocked(): void
    {
        $result = (new Builder())
            ->from('inventory')
            ->select(['id', 'quantity'])
            ->filter([Query::greaterThan('quantity', 0)])
            ->limit(5)
            ->forShareSkipLocked()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `quantity` FROM `inventory` WHERE `quantity` > ? LIMIT ? FOR SHARE SKIP LOCKED',
            $result->query
        );
        $this->assertSame([0, 5], $result->bindings);
    }

    public function testExactHintMaxExecutionTime(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->maxExecutionTime(5000)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT /*+ MAX_EXECUTION_TIME(5000) */ `id`, `name` FROM `users`',
            $result->query
        );
        $this->assertSame([], $result->bindings);
    }

    public function testExactRawExpressions(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select('COUNT(*) AS `total`')
            ->select('MAX(`created_at`) AS `latest`')
            ->filter([Query::equal('active', [true])])
            ->orderByRaw('FIELD(`role`, ?, ?, ?)', ['admin', 'editor', 'viewer'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT COUNT(*) AS `total`, MAX(`created_at`) AS `latest` FROM `users` WHERE `active` IN (?) ORDER BY FIELD(`role`, ?, ?, ?)',
            $result->query
        );
        $this->assertSame([true, 'admin', 'editor', 'viewer'], $result->bindings);
    }

    public function testExactNestedWhereGroups(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->filter([
                Query::and([
                    Query::equal('active', [true]),
                    Query::or([
                        Query::equal('role', ['admin']),
                        Query::greaterThan('karma', 100),
                    ]),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `name` FROM `users` WHERE (`active` IN (?) AND (`role` IN (?) OR `karma` > ?))',
            $result->query
        );
        $this->assertSame([true, 'admin', 100], $result->bindings);
    }

    public function testExactDistinctWithOffset(): void
    {
        $result = (new Builder())
            ->from('tags')
            ->distinct()
            ->select(['name'])
            ->limit(20)
            ->offset(10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT DISTINCT `name` FROM `tags` LIMIT ? OFFSET ?',
            $result->query
        );
        $this->assertSame([20, 10], $result->bindings);
    }

    public function testExactInsertOrIgnore(): void
    {
        $result = (new Builder())
            ->into('tags')
            ->set(['name' => 'php', 'slug' => 'php'])
            ->set(['name' => 'mysql', 'slug' => 'mysql'])
            ->insertOrIgnore();
        $this->assertBindingCount($result);

        $this->assertSame(
            'INSERT IGNORE INTO `tags` (`name`, `slug`) VALUES (?, ?), (?, ?)',
            $result->query
        );
        $this->assertSame(['php', 'php', 'mysql', 'mysql'], $result->bindings);
    }

    public function testExactFromSubquery(): void
    {
        $sub = (new Builder())
            ->from('orders')
            ->select(['user_id'])
            ->sum('total', 'user_total')
            ->groupBy(['user_id']);

        $result = (new Builder())
            ->fromSub($sub, 'sub')
            ->select(['user_id', 'user_total'])
            ->filter([Query::greaterThan('user_total', 500)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `user_id`, `user_total` FROM (SELECT SUM(`total`) AS `user_total`, `user_id` FROM `orders` GROUP BY `user_id`) AS `sub` WHERE `user_total` > ?',
            $result->query
        );
        $this->assertSame([500], $result->bindings);
    }

    public function testExactSelectSubquery(): void
    {
        $sub = (new Builder())
            ->from('orders')
            ->select('COUNT(*)')
            ->filter([Query::raw('`orders`.`user_id` = `users`.`id`')]);

        $result = (new Builder())
            ->from('users')
            ->selectSub($sub, 'order_count')
            ->select(['name'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `name`, (SELECT COUNT(*) FROM `orders` WHERE `orders`.`user_id` = `users`.`id`) AS `order_count` FROM `users`',
            $result->query
        );
        $this->assertSame([], $result->bindings);
    }

    public function testExactAdvancedWhenTrue(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->when(true, function (Builder $b) {
                $b->filter([Query::equal('status', ['active'])]);
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `name` FROM `users` WHERE `status` IN (?)',
            $result->query
        );
        $this->assertSame(['active'], $result->bindings);
    }

    public function testExactAdvancedWhenFalse(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->when(false, function (Builder $b) {
                $b->filter([Query::equal('status', ['active'])]);
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `name` FROM `users`',
            $result->query
        );
        $this->assertSame([], $result->bindings);
    }

    public function testExactAdvancedWhenSequence(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->when(true, function (Builder $b) {
                $b->filter([Query::equal('status', ['active'])]);
            })
            ->when(false, function (Builder $b) {
                $b->filter([Query::equal('role', ['admin'])]);
            })
            ->when(true, function (Builder $b) {
                $b->filter([Query::greaterThan('age', 18)]);
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `name` FROM `users` WHERE `status` IN (?) AND `age` > ?',
            $result->query
        );
        $this->assertSame(['active', 18], $result->bindings);
    }

    public function testExactAdvancedExplain(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->filter([Query::equal('status', ['active'])])
            ->explain();
        $this->assertBindingCount($result);

        $this->assertSame(
            'EXPLAIN SELECT `id`, `name` FROM `users` WHERE `status` IN (?)',
            $result->query
        );
        $this->assertSame(['active'], $result->bindings);
    }

    public function testExactAdvancedExplainAnalyze(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->filter([Query::equal('status', ['active'])])
            ->explain(true);
        $this->assertBindingCount($result);

        $this->assertSame(
            'EXPLAIN ANALYZE SELECT `id`, `name` FROM `users` WHERE `status` IN (?)',
            $result->query
        );
        $this->assertSame(['active'], $result->bindings);
    }

    public function testExactAdvancedCursorAfter(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->sortAsc('name')
            ->cursorAfter('abc123')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `name` FROM `users` WHERE `_cursor` > ? ORDER BY `name` ASC',
            $result->query
        );
        $this->assertSame(['abc123'], $result->bindings);
    }

    public function testExactAdvancedCursorBefore(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->sortDesc('name')
            ->cursorBefore('xyz789')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `name` FROM `users` WHERE `_cursor` < ? ORDER BY `name` DESC',
            $result->query
        );
        $this->assertSame(['xyz789'], $result->bindings);
    }

    public function testExactAdvancedTransactionBegin(): void
    {
        $result = (new Builder())->begin();
        $this->assertBindingCount($result);

        $this->assertSame('BEGIN', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testExactAdvancedTransactionCommit(): void
    {
        $result = (new Builder())->commit();
        $this->assertBindingCount($result);

        $this->assertSame('COMMIT', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testExactAdvancedTransactionRollback(): void
    {
        $result = (new Builder())->rollback();
        $this->assertBindingCount($result);

        $this->assertSame('ROLLBACK', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testExactAdvancedSavepoint(): void
    {
        $result = (new Builder())->savepoint('sp1');
        $this->assertBindingCount($result);

        $this->assertSame('SAVEPOINT `sp1`', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testExactAdvancedReleaseSavepoint(): void
    {
        $result = (new Builder())->releaseSavepoint('sp1');
        $this->assertBindingCount($result);

        $this->assertSame('RELEASE SAVEPOINT `sp1`', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testExactAdvancedRollbackToSavepoint(): void
    {
        $result = (new Builder())->rollbackToSavepoint('sp1');
        $this->assertBindingCount($result);

        $this->assertSame('ROLLBACK TO SAVEPOINT `sp1`', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testExactAdvancedMultipleCtes(): void
    {
        $cteA = (new Builder())
            ->from('orders')
            ->select(['user_id', 'total'])
            ->filter([Query::equal('status', ['paid'])]);

        $cteB = (new Builder())
            ->from('returns')
            ->select(['user_id', 'amount'])
            ->filter([Query::equal('status', ['approved'])]);

        $result = (new Builder())
            ->with('a', $cteA)
            ->with('b', $cteB)
            ->from('a')
            ->select(['user_id'])
            ->sum('total', 'total_paid')
            ->groupBy(['user_id'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'WITH `a` AS (SELECT `user_id`, `total` FROM `orders` WHERE `status` IN (?)), `b` AS (SELECT `user_id`, `amount` FROM `returns` WHERE `status` IN (?)) SELECT SUM(`total`) AS `total_paid`, `user_id` FROM `a` GROUP BY `user_id`',
            $result->query
        );
        $this->assertSame(['paid', 'approved'], $result->bindings);
    }

    public function testExactAdvancedMultipleWindowFunctions(): void
    {
        $result = (new Builder())
            ->from('employees')
            ->select(['id', 'department', 'salary'])
            ->selectWindow('ROW_NUMBER()', 'row_num', ['department'], ['salary'])
            ->selectWindow('RANK()', 'salary_rank', ['department'], ['-salary'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `department`, `salary`, ROW_NUMBER() OVER (PARTITION BY `department` ORDER BY `salary` ASC) AS `row_num`, RANK() OVER (PARTITION BY `department` ORDER BY `salary` DESC) AS `salary_rank` FROM `employees`',
            $result->query
        );
        $this->assertSame([], $result->bindings);
    }

    public function testExactAdvancedUnionWithOrderAndLimit(): void
    {
        $archive = (new Builder())
            ->from('orders_archive')
            ->select(['id', 'total', 'created_at']);

        $result = (new Builder())
            ->from('orders')
            ->select(['id', 'total', 'created_at'])
            ->sortDesc('created_at')
            ->limit(10)
            ->unionAll($archive)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            '(SELECT `id`, `total`, `created_at` FROM `orders` ORDER BY `created_at` DESC LIMIT ?) UNION ALL (SELECT `id`, `total`, `created_at` FROM `orders_archive`)',
            $result->query
        );
        $this->assertSame([10], $result->bindings);
    }

    public function testExactAdvancedDeeplyNestedConditions(): void
    {
        $result = (new Builder())
            ->from('products')
            ->select(['id', 'name'])
            ->filter([
                Query::and([
                    Query::equal('category', ['electronics']),
                    Query::or([
                        Query::greaterThan('price', 100),
                        Query::and([
                            Query::equal('brand', ['acme']),
                            Query::lessThan('stock', 50),
                        ]),
                    ]),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `name` FROM `products` WHERE (`category` IN (?) AND (`price` > ? OR (`brand` IN (?) AND `stock` < ?)))',
            $result->query
        );
        $this->assertSame(['electronics', 100, 'acme', 50], $result->bindings);
    }

    public function testExactAdvancedForUpdateNoWait(): void
    {
        $result = (new Builder())
            ->from('accounts')
            ->select(['id', 'balance'])
            ->filter([Query::equal('id', [1])])
            ->forUpdateNoWait()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `balance` FROM `accounts` WHERE `id` IN (?) FOR UPDATE NOWAIT',
            $result->query
        );
        $this->assertSame([1], $result->bindings);
    }

    public function testExactAdvancedForShareNoWait(): void
    {
        $result = (new Builder())
            ->from('accounts')
            ->select(['id', 'balance'])
            ->filter([Query::equal('id', [1])])
            ->forShareNoWait()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `balance` FROM `accounts` WHERE `id` IN (?) FOR SHARE NOWAIT',
            $result->query
        );
        $this->assertSame([1], $result->bindings);
    }

    public function testExactAdvancedConflictSetRaw(): void
    {
        $result = (new Builder())
            ->from('counters')
            ->set(['id' => 1, 'count' => 1, 'updated_at' => '2024-01-01'])
            ->onConflict(['id'], ['count', 'updated_at'])
            ->conflictSetRaw('count', '`count` + VALUES(`count`)')
            ->upsert();
        $this->assertBindingCount($result);

        $this->assertSame(
            'INSERT INTO `counters` (`id`, `count`, `updated_at`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `count` = `count` + VALUES(`count`), `updated_at` = VALUES(`updated_at`)',
            $result->query
        );
        $this->assertSame([1, 1, '2024-01-01'], $result->bindings);
    }

    public function testExactAdvancedSetRawWithBindings(): void
    {
        $result = (new Builder())
            ->from('products')
            ->setRaw('price', '`price` * ?', [1.1])
            ->filter([Query::equal('category', ['electronics'])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame(
            'UPDATE `products` SET `price` = `price` * ? WHERE `category` IN (?)',
            $result->query
        );
        $this->assertSame([1.1, 'electronics'], $result->bindings);
    }

    public function testExactAdvancedSetCaseInUpdate(): void
    {
        $case = (new CaseExpression())
            ->when('category', Operator::Equal, 'electronics', 1.2)
            ->when('category', Operator::Equal, 'clothing', 0.8)
            ->else(1.0);

        $result = (new Builder())
            ->from('products')
            ->setCase('price', $case)
            ->filter([Query::greaterThan('stock', 0)])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame(
            'UPDATE `products` SET `price` = CASE WHEN `category` = ? THEN ? WHEN `category` = ? THEN ? ELSE ? END WHERE `stock` > ?',
            $result->query
        );
        $this->assertSame(['electronics', 1.2, 'clothing', 0.8, 1.0, 0], $result->bindings);
    }

    public function testExactAdvancedEmptyFilterArray(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->filter([])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `name` FROM `users`',
            $result->query
        );
        $this->assertSame([], $result->bindings);
    }

    public function testExactAdvancedEmptyInClause(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->filter([Query::equal('id', [])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `name` FROM `users` WHERE 1 = 0',
            $result->query
        );
        $this->assertSame([], $result->bindings);
    }

    public function testExactAdvancedEmptyAndGroup(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->filter([Query::and([])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `name` FROM `users` WHERE 1 = 1',
            $result->query
        );
        $this->assertSame([], $result->bindings);
    }

    public function testExactAdvancedEmptyOrGroup(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->filter([Query::or([])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `name` FROM `users` WHERE 1 = 0',
            $result->query
        );
        $this->assertSame([], $result->bindings);
    }

    public function testExactAdvancedSelectRawWithGroupByRawAndHavingRaw(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->select('DATE(`created_at`) AS `order_date`')
            ->select('SUM(`total`) AS `daily_total`')
            ->groupByRaw('DATE(`created_at`)')
            ->havingRaw('SUM(`total`) > ?', [1000])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT DATE(`created_at`) AS `order_date`, SUM(`total`) AS `daily_total` FROM `orders` GROUP BY DATE(`created_at`) HAVING SUM(`total`) > ?',
            $result->query
        );
        $this->assertSame([1000], $result->bindings);
    }

    public function testExactAdvancedMultipleHooks(): void
    {
        $result = (new Builder())
            ->from('documents')
            ->select(['id', 'title'])
            ->filter([Query::equal('status', ['published'])])
            ->addHook(new Tenant(['tenant_a', 'tenant_b']))
            ->addHook(new Permission(
                ['role:member', 'role:admin'],
                fn (string $table) => $table . '_permissions',
            ))
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `title` FROM `documents` WHERE `status` IN (?) AND tenant_id IN (?, ?) AND id IN (SELECT DISTINCT document_id FROM documents_permissions WHERE role IN (?, ?) AND type = ?)',
            $result->query
        );
        $this->assertSame(['published', 'tenant_a', 'tenant_b', 'role:member', 'role:admin', 'read'], $result->bindings);
    }

    public function testExactAdvancedAttributeMapHook(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id', 'display_name', 'email_address'])
            ->filter([Query::equal('display_name', ['Alice'])])
            ->addHook(new AttributeMap([
                'display_name' => 'full_name',
                'email_address' => 'email',
            ]))
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `full_name`, `email` FROM `users` WHERE `full_name` IN (?)',
            $result->query
        );
        $this->assertSame(['Alice'], $result->bindings);
    }

    public function testExactAdvancedResetClearsState(): void
    {
        $builder = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->filter([Query::equal('status', ['active'])]);

        $builder->build();

        $builder->reset();

        $result = $builder
            ->from('orders')
            ->select(['id', 'total'])
            ->filter([Query::greaterThan('total', 100)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `total` FROM `orders` WHERE `total` > ?',
            $result->query
        );
        $this->assertSame([100], $result->bindings);
    }

    public function testCountWhenWithAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->countWhen('status = ?', 'active_count', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT COUNT(CASE WHEN status = ? THEN 1 END) AS `active_count` FROM `orders`',
            $result->query
        );
        $this->assertSame(['active'], $result->bindings);
    }

    public function testCountWhenWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->countWhen('status = ?', '', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT COUNT(CASE WHEN status = ? THEN 1 END) FROM `orders`',
            $result->query
        );
        $this->assertSame(['active'], $result->bindings);
    }

    public function testSumWhenWithAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->sumWhen('amount', 'status = ?', 'active_total', 'completed')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT SUM(CASE WHEN status = ? THEN `amount` END) AS `active_total` FROM `orders`',
            $result->query
        );
        $this->assertSame(['completed'], $result->bindings);
    }

    public function testSumWhenWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->sumWhen('amount', 'status = ?', '', 'completed')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT SUM(CASE WHEN status = ? THEN `amount` END) FROM `orders`',
            $result->query
        );
        $this->assertSame(['completed'], $result->bindings);
    }

    public function testAvgWhenWithAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->avgWhen('amount', 'status = ?', 'avg_completed', 'completed')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT AVG(CASE WHEN status = ? THEN `amount` END) AS `avg_completed` FROM `orders`',
            $result->query
        );
        $this->assertSame(['completed'], $result->bindings);
    }

    public function testAvgWhenWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->avgWhen('amount', 'status = ?', '', 'completed')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT AVG(CASE WHEN status = ? THEN `amount` END) FROM `orders`',
            $result->query
        );
        $this->assertSame(['completed'], $result->bindings);
    }

    public function testMinWhenWithAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->minWhen('amount', 'status = ?', 'min_completed', 'completed')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT MIN(CASE WHEN status = ? THEN `amount` END) AS `min_completed` FROM `orders`',
            $result->query
        );
        $this->assertSame(['completed'], $result->bindings);
    }

    public function testMinWhenWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->minWhen('amount', 'status = ?', '', 'completed')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT MIN(CASE WHEN status = ? THEN `amount` END) FROM `orders`',
            $result->query
        );
        $this->assertSame(['completed'], $result->bindings);
    }

    public function testMaxWhenWithAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->maxWhen('amount', 'status = ?', 'max_completed', 'completed')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT MAX(CASE WHEN status = ? THEN `amount` END) AS `max_completed` FROM `orders`',
            $result->query
        );
        $this->assertSame(['completed'], $result->bindings);
    }

    public function testMaxWhenWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->maxWhen('amount', 'status = ?', '', 'completed')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT MAX(CASE WHEN status = ? THEN `amount` END) FROM `orders`',
            $result->query
        );
        $this->assertSame(['completed'], $result->bindings);
    }

    public function testJoinLateral(): void
    {
        $subquery = (new Builder())
            ->from('orders')
            ->select(['total'])
            ->filter([Query::greaterThan('total', 100)])
            ->limit(1);

        $result = (new Builder())
            ->from('users')
            ->select(['users.id', 'users.name'])
            ->joinLateral($subquery, 'top_order')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `users`.`id`, `users`.`name` FROM `users` JOIN LATERAL (SELECT `total` FROM `orders` WHERE `total` > ? LIMIT ?) AS `top_order` ON true', $result->query);
    }

    public function testLeftJoinLateral(): void
    {
        $subquery = (new Builder())
            ->from('orders')
            ->select(['total'])
            ->limit(3);

        $result = (new Builder())
            ->from('users')
            ->leftJoinLateral($subquery, 'recent_orders')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` LEFT JOIN LATERAL (SELECT `total` FROM `orders` LIMIT ?) AS `recent_orders` ON true', $result->query);
    }

    public function testHint(): void
    {
        $result = (new Builder())
            ->from('users')
            ->hint('NO_INDEX_MERGE')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT /*+ NO_INDEX_MERGE */ * FROM `users`',
            $result->query
        );
    }

    public function testHintInvalidThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid hint');

        (new Builder())
            ->from('users')
            ->hint('DROP TABLE users; --');
    }

    public function testHintAcceptsBacktickedIndex(): void
    {
        $result = (new Builder())
            ->from('users')
            ->hint('INDEX(`users` `idx_users_age`)')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT /*+ INDEX(`users` `idx_users_age`) */ * FROM `users`',
            $result->query
        );
    }

    public function testHintAcceptsForceIndex(): void
    {
        $result = (new Builder())
            ->from('users')
            ->hint('FORCE INDEX (`idx_age`)')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT /*+ FORCE INDEX (`idx_age`) */ * FROM `users`',
            $result->query
        );
    }

    public function testHintRejectsSemicolonInjection(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid hint');

        (new Builder())
            ->from('users')
            ->hint('; DROP TABLE users;');
    }

    public function testHintRejectsNullByteInjection(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid hint');

        (new Builder())
            ->from('users')
            ->hint("INDEX(`idx`)\x00");
    }

    public function testHintRejectsNewlineInjection(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid hint');

        (new Builder())
            ->from('users')
            ->hint("INDEX(`idx`)\nDROP TABLE users");
    }

    public function testHintRejectsBlockCommentInjection(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid hint');

        (new Builder())
            ->from('users')
            ->hint('INDEX(`idx`) */ DROP TABLE users /*');
    }

    public function testHintRejectsQuoteInjection(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid hint');

        (new Builder())
            ->from('users')
            ->hint("INDEX('idx')");
    }

    public function testMaxExecutionTimeExactQuery(): void
    {
        $result = (new Builder())
            ->from('users')
            ->maxExecutionTime(5000)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT /*+ MAX_EXECUTION_TIME(5000) */ * FROM `users`',
            $result->query
        );
    }

    public function testUpdateJoin(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->set(['orders.status' => 'shipped'])
            ->updateJoin('users', 'orders.user_id', 'users.id')
            ->filter([Query::equal('users.active', [true])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `orders` JOIN `users` ON `orders`.`user_id` = `users`.`id` SET `orders`.`status` = ? WHERE `users`.`active` IN (?)', $result->query);
    }

    public function testUpdateJoinWithAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->set(['orders.status' => 'shipped'])
            ->updateJoin('users', 'orders.user_id', 'u.id', 'u')
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `orders` JOIN `users` AS `u` ON `orders`.`user_id` = `u`.`id` SET `orders`.`status` = ?', $result->query);
    }

    public function testUpdateJoinWithoutSetThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No assignments for UPDATE');

        (new Builder())
            ->from('orders')
            ->updateJoin('users', 'orders.user_id', 'users.id')
            ->update();
    }

    public function testDeleteJoin(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->deleteJoin('o', 'users', 'o.user_id', 'users.id')
            ->filter([Query::equal('users.active', [false])])
            ->delete();
        $this->assertBindingCount($result);

        $this->assertSame('DELETE `o` FROM `orders` AS `o` JOIN `users` ON `o`.`user_id` = `users`.`id` WHERE `users`.`active` IN (?)', $result->query);
    }

    public function testExplainWithFormat(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('status', ['active'])])
            ->explain(false, 'json');
        $this->assertBindingCount($result);

        $this->assertStringStartsWith('EXPLAIN FORMAT=JSON', $result->query);
    }

    public function testExplainAnalyzeWithFormat(): void
    {
        $result = (new Builder())
            ->from('users')
            ->explain(true, 'tree');
        $this->assertBindingCount($result);

        $this->assertStringStartsWith('EXPLAIN ANALYZE FORMAT=TREE', $result->query);
    }

    public function testCompileSearchExprExactMatch(): void
    {
        $result = (new Builder())
            ->from('articles')
            ->filterSearch('title', '"exact phrase"')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `articles` WHERE MATCH(`title`) AGAINST(? IN BOOLEAN MODE)', $result->query);
        $this->assertSame(['"exact phrase"'], $result->bindings);
    }

    public function testConflictClauseWithRawSets(): void
    {
        $result = (new Builder())
            ->into('counters')
            ->set(['name' => 'views', 'count' => 1])
            ->onConflict(['name'], ['count'])
            ->conflictSetRaw('count', '`count` + ?', [1])
            ->upsert();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO `counters` (`name`, `count`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `count` = `count` + ?', $result->query);
    }

    public function testJsonPathValidation(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid JSON path');

        (new Builder())
            ->from('t')
            ->filter([Query::jsonPath('data', 'path; DROP TABLE', '=', 'x')])
            ->build();
    }

    public function testJsonPathOperatorValidation(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid JSON path operator');

        (new Builder())
            ->from('t')
            ->filter([Query::jsonPath('data', 'name', 'LIKE', 'x')])
            ->build();
    }

    public function testResetClearsUpdateJoinAndDeleteJoin(): void
    {
        $builder = (new Builder())
            ->from('orders')
            ->set(['status' => 'cancelled'])
            ->updateJoin('users', 'orders.user_id', 'users.id')
            ->deleteJoin('o', 'users', 'o.user_id', 'users.id');

        $builder->reset();

        $result = $builder
            ->from('products')
            ->select(['id', 'name'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `id`, `name` FROM `products`',
            $result->query
        );
    }

    public function testFromNone(): void
    {
        $result = (new Builder())
            ->from()
            ->select('1 AS one')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT 1 AS one', $result->query);
    }

    public function testFromSubquery(): void
    {
        $sub = (new Builder())->from('orders')->select(['user_id'])->filter([Query::greaterThan('total', 100)]);
        $result = (new Builder())
            ->fromSub($sub, 'high_orders')
            ->select(['user_id'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `user_id` FROM (SELECT `user_id` FROM `orders` WHERE `total` > ?) AS `high_orders`',
            $result->query
        );
        $this->assertSame([100], $result->bindings);
    }

    public function testInsertAs(): void
    {
        $result = (new Builder())
            ->into('users')
            ->insertAs('new_row')
            ->set(['name' => 'Alice', 'email' => 'alice@test.com'])
            ->onConflict(['email'], ['name'])
            ->upsert();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO `users` AS `new_row` (`name`, `email`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `name` = VALUES(`name`)', $result->query);
    }

    public function testInsertColumnExpression(): void
    {
        $result = (new Builder())
            ->into('locations')
            ->insertColumnExpression('coords', 'ST_GeomFromText(?, ?)', [4326])
            ->set(['name' => 'HQ', 'coords' => 'POINT(1 2)'])
            ->insert();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO `locations` (`name`, `coords`) VALUES (?, ST_GeomFromText(?, ?))', $result->query);
    }

    public function testNaturalJoin(): void
    {
        $result = (new Builder())
            ->from('users')
            ->naturalJoin('accounts')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` NATURAL JOIN `accounts`', $result->query);
    }

    public function testNaturalJoinWithAlias(): void
    {
        $result = (new Builder())
            ->from('users')
            ->naturalJoin('accounts', 'a')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` NATURAL JOIN `accounts` AS `a`', $result->query);
    }

    public function testWithRecursiveSeedStep(): void
    {
        $seed = (new Builder())->from()->select('1 AS n');
        $step = (new Builder())->from('cte')->select('n + 1')->filter([Query::lessThan('n', 10)]);
        $result = (new Builder())
            ->from('cte')
            ->withRecursiveSeedStep('cte', $seed, $step)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('WITH RECURSIVE `cte` AS (SELECT 1 AS n UNION ALL SELECT n + 1 FROM `cte` WHERE `n` < ?) SELECT * FROM `cte`', $result->query);
    }

    public function testSelectWindowWithNamedWindow(): void
    {
        $result = (new Builder())
            ->from('employees')
            ->select(['name', 'salary'])
            ->selectWindow('ROW_NUMBER()', 'rn', windowName: 'w')
            ->window('w', partitionBy: ['department'], orderBy: ['salary'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `name`, `salary`, ROW_NUMBER() OVER `w` AS `rn` FROM `employees` WINDOW `w` AS (PARTITION BY `department` ORDER BY `salary` ASC)', $result->query);
    }

    public function testWindowDefinitionWithDescOrder(): void
    {
        $result = (new Builder())
            ->from('employees')
            ->select(['name'])
            ->selectWindow('RANK()', 'rnk', windowName: 'w')
            ->window('w', partitionBy: ['department'], orderBy: ['-salary'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `name`, RANK() OVER `w` AS `rnk` FROM `employees` WINDOW `w` AS (PARTITION BY `department` ORDER BY `salary` DESC)', $result->query);
    }

    public function testBeforeBuildCallback(): void
    {
        $result = (new Builder())
            ->from('users')
            ->beforeBuild(function (Builder $builder) {
                $builder->filter([Query::equal('active', [true])]);
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE `active` IN (?)', $result->query);
    }

    public function testAfterBuildCallback(): void
    {
        $result = (new Builder())
            ->from('users')
            ->afterBuild(function (Statement $result) {
                return new Statement(
                    '/* traced */ ' . $result->query,
                    $result->bindings,
                    $result->readOnly
                );
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringStartsWith('/* traced */ SELECT', $result->query);
    }

    public function testPagePerPageValidation(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Per page must be >= 1');

        (new Builder())->from('users')->page(1, 0);
    }

    public function testFilterWhereInSubquery(): void
    {
        $sub = (new Builder())->from('orders')->select(['user_id'])->filter([Query::greaterThan('total', 100)]);
        $result = (new Builder())
            ->from('users')
            ->filterWhereIn('id', $sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE `id` IN (SELECT `user_id` FROM `orders` WHERE `total` > ?)', $result->query);
    }

    public function testFilterWhereNotInSubquery(): void
    {
        $sub = (new Builder())->from('banned')->select(['user_id']);
        $result = (new Builder())
            ->from('users')
            ->filterWhereNotIn('id', $sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE `id` NOT IN (SELECT `user_id` FROM `banned`)', $result->query);
    }

    public function testFilterExistsSubquery(): void
    {
        $sub = (new Builder())->from('orders')->filter([Query::equal('user_id', [1])]);
        $result = (new Builder())
            ->from('users')
            ->filterExists($sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE EXISTS (SELECT * FROM `orders` WHERE `user_id` IN (?))', $result->query);
    }

    public function testFilterNotExistsSubquery(): void
    {
        $sub = (new Builder())->from('orders')->filter([Query::equal('user_id', [1])]);
        $result = (new Builder())
            ->from('users')
            ->filterNotExists($sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE NOT EXISTS (SELECT * FROM `orders` WHERE `user_id` IN (?))', $result->query);
    }

    public function testSelectSubquery(): void
    {
        $sub = (new Builder())->from()->select('COUNT(*)');
        $result = (new Builder())
            ->from('users')
            ->select(['name'])
            ->selectSub($sub, 'total')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `name`, (SELECT COUNT(*)) AS `total` FROM `users`', $result->query);
    }

    public function testInsertOrIgnoreBindingCount(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'Alice', 'email' => 'alice@test.com'])
            ->insertOrIgnore();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT IGNORE INTO `users` (`name`, `email`) VALUES (?, ?)', $result->query);
        $this->assertSame(['Alice', 'alice@test.com'], $result->bindings);
    }

    public function testUpsertSelectFromBuilder(): void
    {
        $source = (new Builder())->from('staging')->select(['id', 'name', 'email']);
        $result = (new Builder())
            ->into('users')
            ->fromSelect(['id', 'name', 'email'], $source)
            ->onConflict(['id'], ['name', 'email'])
            ->upsertSelect();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO `users` (`id`, `name`, `email`) SELECT `id`, `name`, `email` FROM `staging` ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `email` = VALUES(`email`)', $result->query);
    }

    public function testLateralJoin(): void
    {
        $sub = (new Builder())->from('orders')->select(['total'])->filter([Query::greaterThan('total', 100)])->limit(5);
        $result = (new Builder())
            ->from('users')
            ->joinLateral($sub, 'top_orders')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` JOIN LATERAL (SELECT `total` FROM `orders` WHERE `total` > ? LIMIT ?) AS `top_orders` ON true', $result->query);
    }

    public function testLeftLateralJoin(): void
    {
        $sub = (new Builder())->from('orders')->select(['total'])->limit(3);
        $result = (new Builder())
            ->from('users')
            ->leftJoinLateral($sub, 'recent_orders')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` LEFT JOIN LATERAL (SELECT `total` FROM `orders` LIMIT ?) AS `recent_orders` ON true', $result->query);
    }

    public function testJoinWhereWithCallback(): void
    {
        $result = (new Builder())
            ->from('users')
            ->joinWhere('orders', function (JoinBuilder $join) {
                $join->on('users.id', 'orders.user_id');
                $join->where('orders.status', '=', 'active');
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` JOIN `orders` ON `users`.`id` = `orders`.`user_id` AND orders.status = ?', $result->query);
    }

    public function testJoinWhereLeftJoinCompilation(): void
    {
        $result = (new Builder())
            ->from('users')
            ->joinWhere('orders', function (JoinBuilder $join) {
                $join->on('users.id', 'orders.user_id');
            }, JoinType::Left)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` LEFT JOIN `orders` ON `users`.`id` = `orders`.`user_id`', $result->query);
    }

    public function testJoinWhereRightJoin(): void
    {
        $result = (new Builder())
            ->from('users')
            ->joinWhere('departments', function (JoinBuilder $join) {
                $join->on('users.dept_id', 'departments.id');
            }, JoinType::Right)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` RIGHT JOIN `departments` ON `users`.`dept_id` = `departments`.`id`', $result->query);
    }

    public function testJoinWhereFullOuterJoin(): void
    {
        $result = (new Builder())
            ->from('users')
            ->joinWhere('accounts', function (JoinBuilder $join) {
                $join->on('users.id', 'accounts.user_id');
            }, JoinType::FullOuter)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` FULL OUTER JOIN `accounts` ON `users`.`id` = `accounts`.`user_id`', $result->query);
    }

    public function testJoinWhereNaturalJoin(): void
    {
        $result = (new Builder())
            ->from('users')
            ->joinWhere('accounts', function (JoinBuilder $join) {
                // natural join doesn't need ON
            }, JoinType::Natural)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` NATURAL JOIN `accounts`', $result->query);
    }

    public function testJoinWhereCrossJoinWithAlias(): void
    {
        $result = (new Builder())
            ->from('users')
            ->joinWhere('numbers', function (JoinBuilder $join) {
                // cross join doesn't need ON
            }, JoinType::Cross, 'n')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` CROSS JOIN `numbers` AS `n`', $result->query);
    }

    public function testJoinBuilderWhereInvalidColumn(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid column name');

        $join = new JoinBuilder();
        $join->where('invalid column!', '=', 'value');
    }

    public function testJoinBuilderWhereInvalidOperator(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid join operator');

        $join = new JoinBuilder();
        $join->where('col', 'LIKE', 'value');
    }

    public function testJoinBuilderOnInvalidOperator(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid join operator');

        $join = new JoinBuilder();
        $join->on('left', 'right', 'LIKE');
    }

    public function testJoinWhereWithAliasOnInnerJoin(): void
    {
        $result = (new Builder())
            ->from('users')
            ->joinWhere('orders', function (JoinBuilder $join) {
                $join->on('users.id', 'orders.user_id');
            }, JoinType::Inner, 'o')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` JOIN `orders` AS `o` ON `users`.`id` = `orders`.`user_id`', $result->query);
    }

    public function testJoinWithBuilderEmptyOnsReturnsNoOnClause(): void
    {
        $result = (new Builder())
            ->from('users')
            ->joinWhere('numbers', function (JoinBuilder $join) {
                // intentionally empty
            }, JoinType::Cross)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` CROSS JOIN `numbers`', $result->query);
        $this->assertStringNotContainsString(' ON ', $result->query);
    }

    public function testHavingRawWithGroupBy(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->select(['user_id'])
            ->queries([Query::groupBy(['user_id'])])
            ->havingRaw('COUNT(*) > ?', [5])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `user_id` FROM `orders` GROUP BY `user_id` HAVING COUNT(*) > ?', $result->query);
    }

    public function testCompileExistsEmptyValues(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::exists([]));
        $this->assertSame('1 = 1', $sql);
    }

    public function testCompileNotExistsEmptyValues(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::notExists([]));
        $this->assertSame('1 = 1', $sql);
    }

    public function testEscapeLikeValueWithArray(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::containsString('data', [['nested' => 'value']])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `data` LIKE ?', $result->query);
    }

    public function testEscapeLikeValueWithNumeric(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::containsString('col', [42])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `col` LIKE ?', $result->query);
        $this->assertSame(['%42%'], $result->bindings);
    }

    public function testEscapeLikeValueWithBoolean(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::containsString('col', [true])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `col` LIKE ?', $result->query);
        $this->assertSame(['%1%'], $result->bindings);
    }

    public function testCloneWithSubqueries(): void
    {
        $sub = (new Builder())->from('orders')->select(['user_id']);
        $original = (new Builder())
            ->from('users')
            ->filterWhereIn('id', $sub)
            ->filterExists((new Builder())->from('accounts'));

        $cloned = $original->clone();

        $originalResult = $original->build();
        $clonedResult = $cloned->build();

        $this->assertBindingCount($originalResult);
        $this->assertBindingCount($clonedResult);

        $this->assertSame($originalResult->query, $clonedResult->query);
    }

    public function testCloneWithFromSubquery(): void
    {
        $sub = (new Builder())->from('orders')->select(['user_id']);
        $original = (new Builder())
            ->fromSub($sub, 'sub')
            ->select(['user_id']);

        $cloned = $original->clone();
        $result = $cloned->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `user_id` FROM (SELECT `user_id` FROM `orders`) AS `sub`', $result->query);
    }

    public function testCloneWithInsertSelectSource(): void
    {
        $source = (new Builder())->from('staging')->select(['id', 'name']);
        $original = (new Builder())
            ->into('users')
            ->fromSelect(['id', 'name'], $source);

        $cloned = $original->clone();
        $result = $cloned->insertSelect();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO `users` (`id`, `name`) SELECT `id`, `name` FROM `staging`', $result->query);
    }

    public function testWhereInSubqueryInUpdate(): void
    {
        $sub = (new Builder())->from('banned_users')->select(['id']);
        $result = (new Builder())
            ->from('users')
            ->set(['active' => false])
            ->filterWhereIn('id', $sub)
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `users` SET `active` = ? WHERE `id` IN (SELECT `id` FROM `banned_users`)', $result->query);
    }

    public function testExistsSubqueryInUpdate(): void
    {
        $sub = (new Builder())->from('orders')->filter([Query::greaterThan('total', 1000)]);
        $result = (new Builder())
            ->from('users')
            ->set(['vip' => true])
            ->filterExists($sub)
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `users` SET `vip` = ? WHERE EXISTS (SELECT * FROM `orders` WHERE `total` > ?)', $result->query);
    }

    public function testWhereInSubqueryInDelete(): void
    {
        $sub = (new Builder())->from('banned_users')->select(['id']);
        $result = (new Builder())
            ->from('users')
            ->filterWhereIn('id', $sub)
            ->delete();
        $this->assertBindingCount($result);

        $this->assertSame('DELETE FROM `users` WHERE `id` IN (SELECT `id` FROM `banned_users`)', $result->query);
    }

    public function testExistsSubqueryInDelete(): void
    {
        $sub = (new Builder())->from('audit_log')->filter([Query::equal('action', ['delete'])]);
        $result = (new Builder())
            ->from('sessions')
            ->filterExists($sub)
            ->delete();
        $this->assertBindingCount($result);

        $this->assertSame('DELETE FROM `sessions` WHERE EXISTS (SELECT * FROM `audit_log` WHERE `action` IN (?))', $result->query);
    }

    public function testOrderByRawInUpdate(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['status' => 'archived'])
            ->filter([Query::lessThan('last_login', '2020-01-01')])
            ->orderByRaw('FIELD(status, ?, ?)', ['active', 'inactive'])
            ->limit(100)
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `users` SET `status` = ? WHERE `last_login` < ? ORDER BY FIELD(status, ?, ?) LIMIT ?', $result->query);
    }

    public function testFilterSearchFluent(): void
    {
        $result = (new Builder())
            ->from('posts')
            ->filterSearch('content', 'hello world')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `posts` WHERE MATCH(`content`) AGAINST(? IN BOOLEAN MODE)', $result->query);
    }

    public function testFilterNotSearchFluent(): void
    {
        $result = (new Builder())
            ->from('posts')
            ->filterNotSearch('content', 'spam')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `posts` WHERE NOT (MATCH(`content`) AGAINST(? IN BOOLEAN MODE))', $result->query);
    }

    public function testFilterDistanceFluent(): void
    {
        $result = (new Builder())
            ->from('locations')
            ->filterDistance('coords', [1.0, 2.0], '<', 1000.0)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `locations` WHERE ST_Distance(ST_SRID(`coords`, 0), ST_GeomFromText(?, 0, \'axis-order=long-lat\')) < ?', $result->query);
    }

    public function testFilterDistanceDefaultOperator(): void
    {
        $result = (new Builder())
            ->from('locations')
            ->filterDistance('coords', [1.0, 2.0], 'unknown', 500.0)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `locations` WHERE ST_Distance(ST_SRID(`coords`, 0), ST_GeomFromText(?, 0, \'axis-order=long-lat\')) < ?', $result->query);
    }

    public function testFilterIntersectsFluent(): void
    {
        $result = (new Builder())
            ->from('areas')
            ->filterIntersects('geom', [[0.0, 0.0], [1.0, 1.0], [2.0, 0.0], [0.0, 0.0]])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `areas` WHERE ST_Intersects(`geom`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testFilterNotIntersectsFluent(): void
    {
        $result = (new Builder())
            ->from('areas')
            ->filterNotIntersects('geom', [1.0, 2.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `areas` WHERE NOT ST_Intersects(`geom`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testFilterCrossesFluent(): void
    {
        $result = (new Builder())
            ->from('paths')
            ->filterCrosses('geom', [[0.0, 0.0], [1.0, 1.0]])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `paths` WHERE ST_Crosses(`geom`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testFilterNotCrossesFluent(): void
    {
        $result = (new Builder())
            ->from('paths')
            ->filterNotCrosses('geom', [[0.0, 0.0], [1.0, 1.0]])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `paths` WHERE NOT ST_Crosses(`geom`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testFilterOverlapsFluent(): void
    {
        $result = (new Builder())
            ->from('areas')
            ->filterOverlaps('geom', [[[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0], [0.0, 0.0]]])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `areas` WHERE ST_Overlaps(`geom`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testFilterNotOverlapsFluent(): void
    {
        $result = (new Builder())
            ->from('areas')
            ->filterNotOverlaps('geom', [1.0, 2.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `areas` WHERE NOT ST_Overlaps(`geom`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testFilterTouchesFluent(): void
    {
        $result = (new Builder())
            ->from('areas')
            ->filterTouches('geom', [1.0, 2.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `areas` WHERE ST_Touches(`geom`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testFilterNotTouchesFluent(): void
    {
        $result = (new Builder())
            ->from('areas')
            ->filterNotTouches('geom', [1.0, 2.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `areas` WHERE NOT ST_Touches(`geom`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testFilterCoversFluent(): void
    {
        $result = (new Builder())
            ->from('areas')
            ->filterCovers('geom', [1.0, 2.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `areas` WHERE ST_Contains(`geom`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testFilterNotCoversFluent(): void
    {
        $result = (new Builder())
            ->from('areas')
            ->filterNotCovers('geom', [1.0, 2.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `areas` WHERE NOT ST_Contains(`geom`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testFilterSpatialEqualsFluent(): void
    {
        $result = (new Builder())
            ->from('areas')
            ->filterSpatialEquals('geom', [1.0, 2.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `areas` WHERE ST_Equals(`geom`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testFilterNotSpatialEqualsFluent(): void
    {
        $result = (new Builder())
            ->from('areas')
            ->filterNotSpatialEquals('geom', [1.0, 2.0])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `areas` WHERE NOT ST_Equals(`geom`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testFilterJsonContainsFluent(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->filterJsonContains('data', 'hello')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `docs` WHERE JSON_CONTAINS(`data`, ?)', $result->query);
    }

    public function testFilterJsonNotContainsFluent(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->filterJsonNotContains('data', 'hello')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `docs` WHERE NOT JSON_CONTAINS(`data`, ?)', $result->query);
    }

    public function testFilterJsonOverlapsFluent(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->filterJsonOverlaps('tags', ['php', 'js'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `docs` WHERE JSON_OVERLAPS(`tags`, ?)', $result->query);
    }

    public function testFilterJsonPathFluent(): void
    {
        $result = (new Builder())
            ->from('docs')
            ->filterJsonPath('data', 'user.age', '>', 18)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `docs` WHERE JSON_EXTRACT(`data`, \'$.user.age\') > ?', $result->query);
    }

    public function testGeometryToWktSinglePointWrapped(): void
    {
        $result = (new Builder())
            ->from('locations')
            ->filterIntersects('geom', [[1.5, 2.5]])
            ->build();
        $this->assertBindingCount($result);

        /** @var string $binding */
        $binding = $result->bindings[0];
        $this->assertSame('POINT(1.5 2.5)', $binding);
    }

    public function testGeometryToWktLinestring(): void
    {
        $result = (new Builder())
            ->from('paths')
            ->filterIntersects('geom', [[0.0, 0.0], [1.0, 1.0], [2.0, 2.0]])
            ->build();
        $this->assertBindingCount($result);

        /** @var string $binding */
        $binding = $result->bindings[0];
        $this->assertSame('LINESTRING(0 0, 1 1, 2 2)', $binding);
    }

    public function testGeometryToWktPolygon(): void
    {
        $geometry = [
            [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 0.0]],
        ];
        $result = (new Builder())
            ->from('areas')
            ->filterIntersects('geom', $geometry)
            ->build();
        $this->assertBindingCount($result);

        /** @var string $binding */
        $binding = $result->bindings[0];
        $this->assertSame('POLYGON((0 0, 1 0, 1 1, 0 0))', $binding);
    }

    public function testGeometryToWktFallbackPoint(): void
    {
        $result = (new Builder())
            ->from('locations')
            ->filterIntersects('geom', ['10', '20', 'extra'])
            ->build();
        $this->assertBindingCount($result);

        /** @var string $binding */
        $binding = $result->bindings[0];
        $this->assertSame('POINT(10 20)', $binding);
    }

    public function testSpatialAttributeTypeRedirectToSpatialEquals(): void
    {
        $query = Query::equal('geom', [[1.0, 2.0]]);
        $query->setAttributeType('point');

        $builder = new Builder();
        $sql = $builder->compileFilter($query);

        $this->assertSame('ST_Equals(`geom`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $sql);
    }

    public function testSpatialAttributeTypeRedirectToNotSpatialEquals(): void
    {
        $query = Query::notEqual('geom', [[1.0, 2.0]]);
        $query->setAttributeType('point');

        $builder = new Builder();
        $sql = $builder->compileFilter($query);

        $this->assertSame('NOT ST_Equals(`geom`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $sql);
    }

    public function testSpatialAttributeTypeRedirectToCovers(): void
    {
        $query = Query::containsString('geom', [[1.0, 2.0]]);
        $query->setAttributeType('point');
        $query->setOnArray(false);

        $builder = new Builder();
        $sql = $builder->compileFilter($query);

        $this->assertSame('ST_Contains(`geom`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $sql);
    }

    public function testSpatialAttributeTypeRedirectToNotCovers(): void
    {
        $query = Query::notContains('geom', [[1.0, 2.0]]);
        $query->setAttributeType('point');
        $query->setOnArray(false);

        $builder = new Builder();
        $sql = $builder->compileFilter($query);

        $this->assertSame('NOT ST_Contains(`geom`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $sql);
    }

    public function testArrayFilterContains(): void
    {
        $query = Query::containsString('tags', ['php', 'js']);
        $query->setOnArray(true);

        $builder = new Builder();
        $sql = $builder->compileFilter($query);

        $this->assertSame('JSON_OVERLAPS(`tags`, ?)', $sql);
    }

    public function testArrayFilterNotContains(): void
    {
        $query = Query::notContains('tags', ['php']);
        $query->setOnArray(true);

        $builder = new Builder();
        $sql = $builder->compileFilter($query);

        $this->assertSame('NOT JSON_OVERLAPS(`tags`, ?)', $sql);
    }

    public function testArrayFilterContainsAll(): void
    {
        $query = Query::containsAll('tags', ['php', 'js']);
        $query->setOnArray(true);

        $builder = new Builder();
        $sql = $builder->compileFilter($query);

        $this->assertSame('JSON_CONTAINS(`tags`, ?)', $sql);
    }

    public function testInsertAliasInInsertBody(): void
    {
        $result = (new Builder())
            ->into('users')
            ->insertAs('u')
            ->set(['name' => 'Bob'])
            ->insert();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO `users` AS `u` (`name`) VALUES (?)', $result->query);
    }

    public function testInsertColumnExpressionInInsertBody(): void
    {
        $result = (new Builder())
            ->into('locations')
            ->insertColumnExpression('coords', 'ST_GeomFromText(?, ?)', [4326])
            ->set(['name' => 'Place', 'coords' => 'POINT(1 2)'])
            ->insert();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO `locations` (`name`, `coords`) VALUES (?, ST_GeomFromText(?, ?))', $result->query);
    }

    public function testIndexInvalidMethodThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid index method');

        new Index('idx', ['col'], method: 'DROP TABLE;');
    }

    public function testIndexInvalidOperatorClassThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid operator class');

        new Index('idx', ['col'], operatorClass: 'DROP TABLE;');
    }

    public function testIndexInvalidCollationThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid collation');

        new Index('idx', ['col'], collations: ['col' => 'DROP TABLE;']);
    }

    public function testUpsertWithInsertColumnExpression(): void
    {
        $result = (new Builder())
            ->into('locations')
            ->insertColumnExpression('coords', 'ST_GeomFromText(?, ?)', [4326])
            ->set(['name' => 'HQ', 'coords' => 'POINT(1 2)'])
            ->onConflict(['name'], ['coords'])
            ->upsert();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO `locations` (`name`, `coords`) VALUES (?, ST_GeomFromText(?, ?)) ON DUPLICATE KEY UPDATE `coords` = VALUES(`coords`)', $result->query);
    }

    public function testWindowSelectInlinePartitionAndOrder(): void
    {
        $result = (new Builder())
            ->from('employees')
            ->select(['name'])
            ->selectWindow('ROW_NUMBER()', 'rn', partitionBy: ['dept'], orderBy: ['-salary', 'name'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `name`, ROW_NUMBER() OVER (PARTITION BY `dept` ORDER BY `salary` DESC, `name` ASC) AS `rn` FROM `employees`', $result->query);
    }

    public function testFullOuterJoinCompilation(): void
    {
        $result = (new Builder())
            ->from('left_table')
            ->queries([new Query(Method::FullOuterJoin, 'right_table', ['left_table.id', '=', 'right_table.id'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `left_table` FULL OUTER JOIN `right_table` ON `left_table`.`id` = `right_table`.`id`', $result->query);
    }

    public function testNaturalJoinCompilation(): void
    {
        $result = (new Builder())
            ->from('users')
            ->queries([Query::naturalJoin('profiles')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` NATURAL JOIN `profiles`', $result->query);
    }

    public function testCloneWithLateralJoins(): void
    {
        $sub = (new Builder())->from('orders')->select(['total'])->limit(3);
        $original = (new Builder())
            ->from('users')
            ->joinLateral($sub, 'top');

        $cloned = $original->clone();
        $result = $cloned->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` JOIN LATERAL (SELECT `total` FROM `orders` LIMIT ?) AS `top` ON true', $result->query);
    }

    public function testValidateTableFromNone(): void
    {
        $result = (new Builder())
            ->from()
            ->select('CONNECTION_ID() AS cid')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT CONNECTION_ID() AS cid', $result->query);
    }

    public function testUpsertInsertAlias(): void
    {
        $result = (new Builder())
            ->into('users')
            ->insertAs('new')
            ->set(['name' => 'Alice', 'email' => 'a@b.com'])
            ->onConflict(['email'], ['name'])
            ->upsert();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO `users` AS `new` (`name`, `email`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `name` = VALUES(`name`)', $result->query);
    }

    public function testUpsertSelectValidationNoSource(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No SELECT source specified');

        (new Builder())
            ->into('users')
            ->onConflict(['id'], ['name'])
            ->upsertSelect();
    }

    public function testUpsertSelectValidationNoColumns(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No columns specified');

        $source = (new Builder())->from('staging');
        (new Builder())
            ->into('users')
            ->fromSelect([], $source)
            ->onConflict(['id'], ['name'])
            ->upsertSelect();
    }

    public function testUpsertSelectValidationNoConflictKeys(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No conflict keys specified');

        $source = (new Builder())->from('staging');
        (new Builder())
            ->into('users')
            ->fromSelect(['id', 'name'], $source)
            ->upsertSelect();
    }

    public function testUpsertSelectValidationNoConflictUpdateColumns(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No conflict update columns specified');

        $source = (new Builder())->from('staging');
        (new Builder())
            ->into('users')
            ->fromSelect(['id', 'name'], $source)
            ->onConflict(['id'], [])
            ->upsertSelect();
    }

    public function testCteWithJoinWhereGroupByHavingOrderLimitOffset(): void
    {
        $cteQuery = (new Builder())
            ->from('orders')
            ->select(['user_id'])
            ->count('*', 'order_count')
            ->groupBy(['user_id'])
            ->having([Query::greaterThan('order_count', 3)]);

        $result = (new Builder())
            ->with('active_buyers', $cteQuery)
            ->from('users')
            ->select(['users.name', 'ab.order_count'])
            ->join('active_buyers', 'users.id', 'active_buyers.user_id', '=', 'ab')
            ->filter([Query::equal('users.status', ['active'])])
            ->sortDesc('ab.order_count')
            ->limit(10)
            ->offset(5)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('WITH `active_buyers` AS (SELECT COUNT(*) AS `order_count`, `user_id` FROM `orders` GROUP BY `user_id` HAVING COUNT(*) > ?) SELECT `users`.`name`, `ab`.`order_count` FROM `users` JOIN `active_buyers` AS `ab` ON `users`.`id` = `active_buyers`.`user_id` WHERE `users`.`status` IN (?) ORDER BY `ab`.`order_count` DESC LIMIT ? OFFSET ?', $result->query);
        $this->assertSame([3, 'active', 10, 5], $result->bindings);
    }

    public function testCteWithUnionCombiningComplexSubqueries(): void
    {
        $cteQuery = (new Builder())
            ->from('products')
            ->filter([Query::equal('active', [true])]);

        $q2 = (new Builder())
            ->from('archived_products')
            ->filter([Query::greaterThan('sales', 1000)]);

        $result = (new Builder())
            ->with('active_products', $cteQuery)
            ->from('active_products')
            ->select(['name', 'price'])
            ->union($q2)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('WITH `active_products` AS (SELECT * FROM `products` WHERE `active` IN (?)) (SELECT `name`, `price` FROM `active_products`) UNION (SELECT * FROM `archived_products` WHERE `sales` > ?)', $result->query);
        $this->assertSame([true, 1000], $result->bindings);
    }

    public function testCteReferencedInJoin(): void
    {
        $cte = (new Builder())
            ->from('departments')
            ->filter([Query::equal('active', [true])]);

        $result = (new Builder())
            ->with('active_depts', $cte)
            ->from('employees')
            ->join('active_depts', 'employees.dept_id', 'active_depts.id')
            ->filter([Query::greaterThan('employees.salary', 50000)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('WITH `active_depts` AS (SELECT * FROM `departments` WHERE `active` IN (?)) SELECT * FROM `employees` JOIN `active_depts` ON `employees`.`dept_id` = `active_depts`.`id` WHERE `employees`.`salary` > ?', $result->query);
        $this->assertSame([true, 50000], $result->bindings);
    }

    public function testRecursiveCteWithWhereFilter(): void
    {
        $seed = (new Builder())
            ->from('categories')
            ->filter([Query::isNull('parent_id')]);

        $step = (new Builder())
            ->from('categories')
            ->select(['categories.id', 'categories.name', 'categories.parent_id'])
            ->join('tree', 'categories.parent_id', 'tree.id');

        $result = (new Builder())
            ->withRecursiveSeedStep('tree', $seed, $step)
            ->from('tree')
            ->filter([Query::notEqual('name', 'Excluded')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('WITH RECURSIVE `tree` AS (SELECT * FROM `categories` WHERE `parent_id` IS NULL UNION ALL SELECT `categories`.`id`, `categories`.`name`, `categories`.`parent_id` FROM `categories` JOIN `tree` ON `categories`.`parent_id` = `tree`.`id`) SELECT * FROM `tree` WHERE `name` != ?', $result->query);
        $this->assertSame(['Excluded'], $result->bindings);
    }

    public function testMultipleCtesWhereSecondReferencesFirst(): void
    {
        $cte1 = (new Builder())
            ->from('orders')
            ->filter([Query::equal('status', ['completed'])]);

        $cte2 = (new Builder())
            ->from('completed_orders')
            ->sum('total', 'grand_total');

        $result = (new Builder())
            ->with('completed_orders', $cte1)
            ->with('order_totals', $cte2)
            ->from('order_totals')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('WITH `completed_orders` AS (SELECT * FROM `orders` WHERE `status` IN (?)), `order_totals` AS (SELECT SUM(`total`) AS `grand_total` FROM `completed_orders`) SELECT * FROM `order_totals`', $result->query);
        $this->assertSame(['completed'], $result->bindings);
    }

    public function testWindowFunctionWithJoinAndWhere(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->select(['orders.id', 'users.name'])
            ->selectWindow('ROW_NUMBER()', 'rn', ['users.name'], ['-orders.total'])
            ->join('users', 'orders.user_id', 'users.id')
            ->filter([Query::greaterThan('orders.total', 100)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `orders`.`id`, `users`.`name`, ROW_NUMBER() OVER (PARTITION BY `users`.`name` ORDER BY `orders`.`total` DESC) AS `rn` FROM `orders` JOIN `users` ON `orders`.`user_id` = `users`.`id` WHERE `orders`.`total` > ?', $result->query);
        $this->assertSame([100], $result->bindings);
    }

    public function testWindowFunctionCombinedWithGroupBy(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->select(['category'])
            ->sum('amount', 'total_sales')
            ->selectWindow('RANK()', 'sales_rank', null, ['-total_sales'])
            ->groupBy(['category'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT SUM(`amount`) AS `total_sales`, `category`, RANK() OVER (ORDER BY `total_sales` DESC) AS `sales_rank` FROM `sales` GROUP BY `category`', $result->query);
    }

    public function testMultipleWindowFunctionsWithDifferentPartitions(): void
    {
        $result = (new Builder())
            ->from('employees')
            ->select(['name', 'department', 'salary'])
            ->selectWindow('ROW_NUMBER()', 'dept_rank', ['department'], ['-salary'])
            ->selectWindow('ROW_NUMBER()', 'global_rank', null, ['-salary'])
            ->selectWindow('SUM(salary)', 'dept_total', ['department'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `name`, `department`, `salary`, ROW_NUMBER() OVER (PARTITION BY `department` ORDER BY `salary` DESC) AS `dept_rank`, ROW_NUMBER() OVER (ORDER BY `salary` DESC) AS `global_rank`, SUM(salary) OVER (PARTITION BY `department`) AS `dept_total` FROM `employees`', $result->query);
    }

    public function testNamedWindowUsedByMultipleSelectWindowCalls(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->select(['date', 'amount'])
            ->window('w', ['category'], ['date'])
            ->selectWindow('ROW_NUMBER()', 'rn', windowName: 'w')
            ->selectWindow('SUM(amount)', 'running_total', windowName: 'w')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `date`, `amount`, ROW_NUMBER() OVER `w` AS `rn`, SUM(amount) OVER `w` AS `running_total` FROM `sales` WINDOW `w` AS (PARTITION BY `category` ORDER BY `date` ASC)', $result->query);
    }

    public function testSubSelectWithJoinAndWhere(): void
    {
        $sub = (new Builder())
            ->from('orders')
            ->count('*', 'cnt')
            ->filter([new Query(Method::Raw, 'orders.user_id = users.id')]);

        $result = (new Builder())
            ->from('users')
            ->select(['users.name'])
            ->selectSub($sub, 'order_count')
            ->join('departments', 'users.dept_id', 'departments.id')
            ->filter([Query::equal('departments.name', ['Engineering'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `users`.`name`, (SELECT COUNT(*) AS `cnt` FROM `orders` WHERE orders.user_id = users.id) AS `order_count` FROM `users` JOIN `departments` ON `users`.`dept_id` = `departments`.`id` WHERE `departments`.`name` IN (?)', $result->query);
    }

    public function testFromSubqueryWithJoinWhereOrder(): void
    {
        $sub = (new Builder())
            ->from('orders')
            ->filter([Query::equal('status', ['paid'])])
            ->select(['user_id', 'total']);

        $result = (new Builder())
            ->fromSub($sub, 'paid_orders')
            ->join('users', 'paid_orders.user_id', 'users.id')
            ->filter([Query::greaterThan('paid_orders.total', 100)])
            ->sortDesc('paid_orders.total')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM (SELECT `user_id`, `total` FROM `orders` WHERE `status` IN (?)) AS `paid_orders` JOIN `users` ON `paid_orders`.`user_id` = `users`.`id` WHERE `paid_orders`.`total` > ? ORDER BY `paid_orders`.`total` DESC', $result->query);
        $this->assertSame(['paid', 100], $result->bindings);
    }

    public function testFilterWhereInWithSubqueryAndJoin(): void
    {
        $sub = (new Builder())
            ->from('vip_users')
            ->select(['id'])
            ->filter([Query::equal('tier', ['gold'])]);

        $result = (new Builder())
            ->from('orders')
            ->join('products', 'orders.product_id', 'products.id')
            ->filterWhereIn('orders.user_id', $sub)
            ->filter([Query::greaterThan('orders.total', 50)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `orders` JOIN `products` ON `orders`.`product_id` = `products`.`id` WHERE `orders`.`total` > ? AND `orders`.`user_id` IN (SELECT `id` FROM `vip_users` WHERE `tier` IN (?))', $result->query);
        $this->assertSame([50, 'gold'], $result->bindings);
    }

    public function testExistsSubqueryWithOtherWhereFilters(): void
    {
        $sub = (new Builder())
            ->from('orders')
            ->select(['id'])
            ->filter([Query::raw('orders.user_id = users.id')]);

        $result = (new Builder())
            ->from('users')
            ->filter([
                Query::equal('status', ['active']),
                Query::greaterThan('age', 18),
            ])
            ->filterExists($sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE `status` IN (?) AND `age` > ? AND EXISTS (SELECT `id` FROM `orders` WHERE orders.user_id = users.id)', $result->query);
        $this->assertSame(['active', 18], $result->bindings);
    }

    public function testUnionWithOrderByAndLimit(): void
    {
        $q2 = (new Builder())
            ->from('archived')
            ->filter([Query::equal('type', ['premium'])]);

        $result = (new Builder())
            ->from('current')
            ->filter([Query::equal('type', ['premium'])])
            ->sortDesc('created_at')
            ->limit(20)
            ->union($q2)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM `current` WHERE `type` IN (?) ORDER BY `created_at` DESC LIMIT ?) UNION (SELECT * FROM `archived` WHERE `type` IN (?))', $result->query);
        $this->assertSame(['premium', 20, 'premium'], $result->bindings);
    }

    public function testThreeUnionQueries(): void
    {
        $q1 = (new Builder())->from('t1')->filter([Query::equal('a', [1])]);
        $q2 = (new Builder())->from('t2')->filter([Query::equal('b', [2])]);
        $q3 = (new Builder())->from('t3')->filter([Query::equal('c', [3])]);

        $result = (new Builder())
            ->from('t0')
            ->filter([Query::equal('d', [0])])
            ->union($q1)
            ->unionAll($q2)
            ->union($q3)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(2, substr_count($result->query, ') UNION ('));
        $this->assertSame('(SELECT * FROM `t0` WHERE `d` IN (?)) UNION (SELECT * FROM `t1` WHERE `a` IN (?)) UNION ALL (SELECT * FROM `t2` WHERE `b` IN (?)) UNION (SELECT * FROM `t3` WHERE `c` IN (?))', $result->query);
        $this->assertSame([0, 1, 2, 3], $result->bindings);
    }

    public function testInsertSelectWithJoinedSource(): void
    {
        $source = (new Builder())
            ->from('staging')
            ->select(['staging.name', 'departments.code'])
            ->join('departments', 'staging.dept_id', 'departments.id')
            ->filter([Query::equal('staging.verified', [true])]);

        $result = (new Builder())
            ->into('employees')
            ->fromSelect(['name', 'dept_code'], $source)
            ->insertSelect();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO `employees` (`name`, `dept_code`) SELECT `staging`.`name`, `departments`.`code` FROM `staging` JOIN `departments` ON `staging`.`dept_id` = `departments`.`id` WHERE `staging`.`verified` IN (?)', $result->query);
        $this->assertSame([true], $result->bindings);
    }

    public function testUpdateJoinWithFilter(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->updateJoin('users', 'orders.user_id', 'users.id')
            ->set(['orders.status' => 'upgraded'])
            ->filter([Query::equal('users.tier', ['gold'])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `orders` JOIN `users` ON `orders`.`user_id` = `users`.`id` SET `orders`.`status` = ? WHERE `users`.`tier` IN (?)', $result->query);
        $this->assertSame(['upgraded', 'gold'], $result->bindings);
    }

    public function testDeleteWithSubqueryFilter(): void
    {
        $sub = (new Builder())
            ->from('blacklist')
            ->select(['user_id']);

        $result = (new Builder())
            ->from('sessions')
            ->filterWhereIn('user_id', $sub)
            ->delete();
        $this->assertBindingCount($result);

        $this->assertSame('DELETE FROM `sessions` WHERE `user_id` IN (SELECT `user_id` FROM `blacklist`)', $result->query);
    }

    public function testUpsertWithConflictSetRaw(): void
    {
        $result = (new Builder())
            ->into('counters')
            ->set(['id' => 1, 'hits' => 1, 'updated_at' => '2024-01-01'])
            ->onConflict(['id'], ['hits', 'updated_at'])
            ->conflictSetRaw('hits', '`hits` + VALUES(`hits`)')
            ->upsert();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO `counters` (`id`, `hits`, `updated_at`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `hits` = `hits` + VALUES(`hits`), `updated_at` = VALUES(`updated_at`)', $result->query);
    }

    public function testCaseExpressionInSelectWithWhereAndOrderBy(): void
    {
        $case = (new CaseExpression())
            ->when('status', Operator::Equal, 'active', 'Active')
            ->when('status', Operator::Equal, 'inactive', 'Inactive')
            ->else('Unknown')
            ->alias('status_label');

        $result = (new Builder())
            ->from('users')
            ->select(['name'])
            ->selectCase($case)
            ->filter([Query::isNotNull('status')])
            ->sortAsc('name')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `name`, CASE WHEN `status` = ? THEN ? WHEN `status` = ? THEN ? ELSE ? END AS `status_label` FROM `users` WHERE `status` IS NOT NULL ORDER BY `name` ASC', $result->query);
        $this->assertSame(['active', 'Active', 'inactive', 'Inactive', 'Unknown'], $result->bindings);
    }

    public function testCaseExpressionWithMultipleWhensAndAggregate(): void
    {
        $case = (new CaseExpression())
            ->when('score', Operator::GreaterThanEqual, 90, 'A')
            ->when('score', Operator::GreaterThanEqual, 80, 'B')
            ->when('score', Operator::GreaterThanEqual, 70, 'C')
            ->else('F')
            ->alias('grade');

        $result = (new Builder())
            ->from('students')
            ->selectCase($case)
            ->count('*', 'student_count')
            ->groupBy(['grade'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `student_count`, CASE WHEN `score` >= ? THEN ? WHEN `score` >= ? THEN ? WHEN `score` >= ? THEN ? ELSE ? END AS `grade` FROM `students` GROUP BY `grade`', $result->query);
        $this->assertSame([90, 'A', 80, 'B', 70, 'C', 'F'], $result->bindings);
    }

    public function testLateralJoinWithWhereAndOrder(): void
    {
        $lateral = (new Builder())
            ->from('orders')
            ->select(['total', 'created_at'])
            ->filter([Query::raw('orders.user_id = users.id')])
            ->sortDesc('created_at')
            ->limit(3);

        $result = (new Builder())
            ->from('users')
            ->select(['users.name'])
            ->joinLateral($lateral, 'recent_orders')
            ->filter([Query::greaterThan('recent_orders.total', 50)])
            ->sortAsc('users.name')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `users`.`name` FROM `users` JOIN LATERAL (SELECT `total`, `created_at` FROM `orders` WHERE orders.user_id = users.id ORDER BY `created_at` DESC LIMIT ?) AS `recent_orders` ON true WHERE `recent_orders`.`total` > ? ORDER BY `users`.`name` ASC', $result->query);
    }

    public function testFullTextSearchWithRegularWhereAndJoin(): void
    {
        $result = (new Builder())
            ->from('articles')
            ->select(['articles.title', 'authors.name'])
            ->join('authors', 'articles.author_id', 'authors.id')
            ->filter([
                Query::search('articles.content', 'database optimization'),
                Query::equal('articles.published', [true]),
            ])
            ->sortDesc('articles.created_at')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `articles`.`title`, `authors`.`name` FROM `articles` JOIN `authors` ON `articles`.`author_id` = `authors`.`id` WHERE MATCH(`articles`.`content`) AGAINST(? IN BOOLEAN MODE) AND `articles`.`published` IN (?) ORDER BY `articles`.`created_at` DESC', $result->query);
        $this->assertSame(['database optimization*', true], $result->bindings);
    }

    public function testForUpdateWithJoinAndSubquery(): void
    {
        $sub = (new Builder())
            ->from('locked_users')
            ->select(['id']);

        $result = (new Builder())
            ->from('accounts')
            ->join('users', 'accounts.user_id', 'users.id')
            ->filterWhereIn('users.id', $sub)
            ->forUpdate()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `accounts` JOIN `users` ON `accounts`.`user_id` = `users`.`id` WHERE `users`.`id` IN (SELECT `id` FROM `locked_users`) FOR UPDATE', $result->query);
    }

    public function testMultipleAggregatesWithGroupByAndHaving(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->count('*', 'sale_count')
            ->sum('amount', 'total_amount')
            ->avg('amount', 'avg_amount')
            ->select(['region'])
            ->groupBy(['region'])
            ->having([
                Query::greaterThan('sale_count', 10),
                Query::greaterThan('avg_amount', 50),
            ])
            ->sortDesc('total_amount')
            ->limit(5)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `sale_count`, SUM(`amount`) AS `total_amount`, AVG(`amount`) AS `avg_amount`, `region` FROM `sales` GROUP BY `region` HAVING COUNT(*) > ? AND AVG(`amount`) > ? ORDER BY `total_amount` DESC LIMIT ?', $result->query);
        $this->assertSame([10, 50, 5], $result->bindings);
    }

    public function testCountDistinctColumn(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->countDistinct('user_id', 'unique_buyers')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(DISTINCT `user_id`) AS `unique_buyers` FROM `orders`', $result->query);
    }

    public function testSelfJoinWithAlias(): void
    {
        $result = (new Builder())
            ->from('employees', 'e')
            ->select(['e.name', 'mgr.name'])
            ->leftJoin('employees', 'e.manager_id', 'mgr.id', '=', 'mgr')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `e`.`name`, `mgr`.`name` FROM `employees` AS `e` LEFT JOIN `employees` AS `mgr` ON `e`.`manager_id` = `mgr`.`id`', $result->query);
    }

    public function testTripleJoinWithFilters(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->select(['orders.id', 'users.name', 'products.title'])
            ->join('users', 'orders.user_id', 'users.id')
            ->join('order_items', 'orders.id', 'order_items.order_id')
            ->join('products', 'order_items.product_id', 'products.id')
            ->filter([
                Query::greaterThan('orders.total', 100),
                Query::equal('products.category', ['electronics']),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(3, substr_count($result->query, ' JOIN '));
        $this->assertSame('SELECT `orders`.`id`, `users`.`name`, `products`.`title` FROM `orders` JOIN `users` ON `orders`.`user_id` = `users`.`id` JOIN `order_items` ON `orders`.`id` = `order_items`.`order_id` JOIN `products` ON `order_items`.`product_id` = `products`.`id` WHERE `orders`.`total` > ? AND `products`.`category` IN (?)', $result->query);
        $this->assertSame([100, 'electronics'], $result->bindings);
    }

    public function testCrossJoinWithLeftAndInnerJoinCombined(): void
    {
        $result = (new Builder())
            ->from('sizes')
            ->crossJoin('colors')
            ->leftJoin('inventory', 'sizes.id', 'inventory.size_id')
            ->join('warehouses', 'inventory.warehouse_id', 'warehouses.id')
            ->filter([Query::equal('warehouses.active', [true])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `sizes` CROSS JOIN `colors` LEFT JOIN `inventory` ON `sizes`.`id` = `inventory`.`size_id` JOIN `warehouses` ON `inventory`.`warehouse_id` = `warehouses`.`id` WHERE `warehouses`.`active` IN (?)', $result->query);
        $this->assertSame([true], $result->bindings);
    }

    public function testExplainWithComplexQuery(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->join('users', 'orders.user_id', 'users.id')
            ->filter([Query::greaterThan('orders.total', 100)])
            ->sortDesc('orders.total')
            ->limit(10)
            ->explain();
        $this->assertBindingCount($result);

        $this->assertStringStartsWith('EXPLAIN ', $result->query);
        $this->assertSame('EXPLAIN SELECT * FROM `orders` JOIN `users` ON `orders`.`user_id` = `users`.`id` WHERE `orders`.`total` > ? ORDER BY `orders`.`total` DESC LIMIT ?', $result->query);
        $this->assertTrue($result->readOnly);
    }

    public function testFilterSingleElementArray(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('x', [1])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `x` IN (?)', $result->query);
        $this->assertSame([1], $result->bindings);
    }

    public function testFilterMultiElementArray(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('x', [1, 2, 3])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `x` IN (?, ?, ?)', $result->query);
        $this->assertSame([1, 2, 3], $result->bindings);
    }

    public function testIsNullCombinedWithEqual(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::isNull('deleted_at'),
                Query::equal('status', ['active', 'pending']),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE `deleted_at` IS NULL AND `status` IN (?, ?)',
            $result->query
        );
        $this->assertSame(['active', 'pending'], $result->bindings);
    }

    public function testIsNotNullWithGreaterThan(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::isNotNull('verified_at'),
                Query::greaterThan('login_count', 5),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE `verified_at` IS NOT NULL AND `login_count` > ?',
            $result->query
        );
        $this->assertSame([5], $result->bindings);
    }

    public function testBetweenCombinedWithNotEqual(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::between('age', 18, 65),
                Query::notEqual('status', 'banned'),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE `age` BETWEEN ? AND ? AND `status` != ?',
            $result->query
        );
        $this->assertSame([18, 65, 'banned'], $result->bindings);
    }

    public function testOrWrappingMultipleDifferentOperatorTypes(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::or([
                    Query::equal('role', ['admin']),
                    Query::greaterThan('score', 95),
                    Query::isNull('suspended_at'),
                    Query::startsWith('email', 'vip'),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE (`role` IN (?) OR `score` > ? OR `suspended_at` IS NULL OR `email` LIKE ?)',
            $result->query
        );
        $this->assertSame(['admin', 95, 'vip%'], $result->bindings);
    }

    public function testNestedOrInsideAnd(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::and([
                    Query::equal('active', [true]),
                    Query::or([
                        Query::greaterThan('age', 21),
                        Query::equal('verified', [true]),
                    ]),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE (`active` IN (?) AND (`age` > ? OR `verified` IN (?)))',
            $result->query
        );
        $this->assertSame([true, 21, true], $result->bindings);
    }

    public function testAndInsideOr(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::or([
                    Query::and([
                        Query::equal('role', ['admin']),
                        Query::greaterThan('level', 5),
                    ]),
                    Query::and([
                        Query::equal('role', ['superuser']),
                        Query::greaterThan('level', 1),
                    ]),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE ((`role` IN (?) AND `level` > ?) OR (`role` IN (?) AND `level` > ?))',
            $result->query
        );
        $this->assertSame(['admin', 5, 'superuser', 1], $result->bindings);
    }

    public function testTripleNestedLogicalOrAndEqGtAndLtNe(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::or([
                    Query::and([
                        Query::equal('a', [1]),
                        Query::greaterThan('b', 2),
                    ]),
                    Query::and([
                        Query::lessThan('c', 3),
                        Query::notEqual('d', 4),
                    ]),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE ((`a` IN (?) AND `b` > ?) OR (`c` < ? AND `d` != ?))',
            $result->query
        );
        $this->assertSame([1, 2, 3, 4], $result->bindings);
    }

    public function testEqualWithEmptyStringValue(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('name', [''])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `name` IN (?)', $result->query);
        $this->assertSame([''], $result->bindings);
    }

    public function testContainsWithSqlWildcardPercentAndUnderscore(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::containsString('bio', ['100%_test'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `bio` LIKE ?', $result->query);
        $this->assertSame(['%100\%\_test%'], $result->bindings);
    }

    public function testCompoundSortAscDesc(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sortAsc('last_name')
            ->sortAsc('first_name')
            ->sortDesc('created_at')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` ORDER BY `last_name` ASC, `first_name` ASC, `created_at` DESC',
            $result->query
        );
    }

    public function testLimitOneEdgeCase(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('status', ['active'])])
            ->sortDesc('score')
            ->limit(1)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE `status` IN (?) ORDER BY `score` DESC LIMIT ?',
            $result->query
        );
        $this->assertSame(['active', 1], $result->bindings);
    }

    public function testExplicitOffsetZeroWithLimit(): void
    {
        $result = (new Builder())
            ->from('t')
            ->limit(10)
            ->offset(0)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` LIMIT ? OFFSET ?', $result->query);
        $this->assertSame([10, 0], $result->bindings);
    }

    public function testLargeOffset(): void
    {
        $result = (new Builder())
            ->from('t')
            ->limit(25)
            ->offset(999999)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` LIMIT ? OFFSET ?', $result->query);
        $this->assertSame([25, 999999], $result->bindings);
    }

    public function testDistinctWithCountStar(): void
    {
        $result = (new Builder())
            ->from('t')
            ->distinct()
            ->count('*', 'total')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT DISTINCT COUNT(*) AS `total` FROM `t`', $result->query);
    }

    public function testDistinctWithOrderByOnNonSelectedColumn(): void
    {
        $result = (new Builder())
            ->from('t')
            ->distinct()
            ->select(['name'])
            ->sortAsc('created_at')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT DISTINCT `name` FROM `t` ORDER BY `created_at` ASC', $result->query);
    }

    public function testMultipleSetCallsForUpdate(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `users` SET `name` = ?, `email` = ?, `age` = ? WHERE `id` IN (?)', $result->query);
        $this->assertSame(['Alice', 'alice@example.com', 30, 1], $result->bindings);
    }

    public function testMultipleSetCallsForInsert(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'Alice', 'email' => 'a@b.com'])
            ->set(['name' => 'Bob', 'email' => 'b@b.com'])
            ->set(['name' => 'Charlie', 'email' => 'c@b.com'])
            ->insert();
        $this->assertBindingCount($result);

        $this->assertSame(
            'INSERT INTO `users` (`name`, `email`) VALUES (?, ?), (?, ?), (?, ?)',
            $result->query
        );
        $this->assertSame(['Alice', 'a@b.com', 'Bob', 'b@b.com', 'Charlie', 'c@b.com'], $result->bindings);
    }

    public function testGroupBySingleColumn(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'total')
            ->groupBy(['status'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `total` FROM `orders` GROUP BY `status`', $result->query);
    }

    public function testGroupByMultipleColumnsList(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'total')
            ->groupBy(['status', 'region', 'year'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT COUNT(*) AS `total` FROM `orders` GROUP BY `status`, `region`, `year`',
            $result->query
        );
    }

    public function testFilterOnAliasedColumnFromJoin(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->join('users', 'orders.user_id', 'users.id', '=', 'u')
            ->filter([Query::equal('u.status', ['active'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `orders` JOIN `users` AS `u` ON `orders`.`user_id` = `users`.`id` WHERE `u`.`status` IN (?)', $result->query);
    }

    public function testFilterAfterJoinOnJoinedTableColumn(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->leftJoin('refunds', 'orders.id', 'refunds.order_id')
            ->filter([
                Query::isNull('refunds.id'),
                Query::greaterThan('orders.total', 50),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `orders` LEFT JOIN `refunds` ON `orders`.`id` = `refunds`.`order_id` WHERE `refunds`.`id` IS NULL AND `orders`.`total` > ?', $result->query);
        $this->assertSame([50], $result->bindings);
    }

    public function testBindingOrderComplexFilterHavingSubquery(): void
    {
        $sub = (new Builder())
            ->from('blacklist')
            ->select(['user_id'])
            ->filter([Query::equal('reason', ['fraud'])]);

        $hook = new class () implements Filter {
            public function filter(string $table): Condition
            {
                return new Condition('tenant_id = ?', ['t1']);
            }
        };

        $result = (new Builder())
            ->from('orders')
            ->count('*', 'cnt')
            ->sum('total', 'revenue')
            ->addHook($hook)
            ->filter([Query::greaterThan('total', 0)])
            ->filterWhereNotIn('user_id', $sub)
            ->groupBy(['status'])
            ->having([Query::greaterThan('cnt', 5)])
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt`, SUM(`total`) AS `revenue` FROM `orders` WHERE `total` > ? AND tenant_id = ? AND `user_id` NOT IN (SELECT `user_id` FROM `blacklist` WHERE `reason` IN (?)) GROUP BY `status` HAVING COUNT(*) > ? LIMIT ?', $result->query);
        $this->assertSame([0, 't1', 'fraud', 5, 10], $result->bindings);
    }

    public function testCloneThenModifyOriginalUnchanged(): void
    {
        $original = (new Builder())
            ->from('users')
            ->filter([Query::equal('status', ['active'])])
            ->limit(10);

        $cloned = $original->clone();
        $cloned->filter([Query::greaterThan('age', 30)]);
        $cloned->limit(5);

        $originalResult = $original->build();
        $clonedResult = $cloned->build();

        $this->assertStringNotContainsString('`age`', $originalResult->query);
        $this->assertSame('SELECT * FROM `users` WHERE `status` IN (?) AND `age` > ? LIMIT ?', $clonedResult->query);
        $this->assertSame(['active', 10], $originalResult->bindings);
    }

    public function testResetThenRebuildEntirelyDifferentQueryType(): void
    {
        $builder = new Builder();

        $selectResult = $builder
            ->from('users')
            ->select(['name', 'email'])
            ->filter([Query::equal('status', ['active'])])
            ->sortAsc('name')
            ->limit(10)
            ->build();
        $this->assertSame('SELECT `name`, `email` FROM `users` WHERE `status` IN (?) ORDER BY `name` ASC LIMIT ?', $selectResult->query);

        $builder->reset();

        $insertResult = $builder
            ->into('users')
            ->set(['name' => 'New User', 'email' => 'new@example.com'])
            ->insert();
        $this->assertSame('INSERT INTO `users` (`name`, `email`) VALUES (?, ?)', $insertResult->query);
        $this->assertStringNotContainsString('SELECT', $insertResult->query);
    }

    public function testSelectRawWithBindingsPlusRegularSelect(): void
    {
        $result = (new Builder())
            ->from('t')
            ->select(['name'])
            ->select('COALESCE(bio, ?) AS bio_display', ['N/A'])
            ->filter([Query::equal('active', [true])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `name`, COALESCE(bio, ?) AS bio_display FROM `t` WHERE `active` IN (?)', $result->query);
        $this->assertSame(['N/A', true], $result->bindings);
    }

    public function testWhereRawWithRegularFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::equal('status', ['active']),
                Query::raw('YEAR(created_at) = ?', [2024]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE `status` IN (?) AND YEAR(created_at) = ?',
            $result->query
        );
        $this->assertSame(['active', 2024], $result->bindings);
    }

    public function testHavingWithMultipleConditionsAndLogicalOr(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count('*', 'cnt')
            ->sum('amount', 'total')
            ->groupBy(['category'])
            ->having([
                Query::greaterThan('cnt', 5),
                Query::or([
                    Query::greaterThan('total', 10000),
                    Query::lessThan('total', 100),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt`, SUM(`amount`) AS `total` FROM `t` GROUP BY `category` HAVING COUNT(*) > ? AND (`total` > ? OR `total` < ?)', $result->query);
        $this->assertSame([5, 10000, 100], $result->bindings);
    }

    public function testCountStarVsCountColumnName(): void
    {
        $starResult = (new Builder())
            ->from('t')
            ->count('*', 'total')
            ->build();
        $this->assertBindingCount($starResult);

        $colResult = (new Builder())
            ->from('t')
            ->count('name', 'total')
            ->build();
        $this->assertBindingCount($colResult);

        $this->assertSame('SELECT COUNT(*) AS `total` FROM `t`', $starResult->query);
        $this->assertSame('SELECT COUNT(`name`) AS `total` FROM `t`', $colResult->query);
    }

    public function testAggregatesOnlyNoGroupBy(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'total_orders')
            ->sum('total', 'revenue')
            ->avg('total', 'avg_order')
            ->min('total', 'smallest_order')
            ->max('total', 'largest_order')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT COUNT(*) AS `total_orders`, SUM(`total`) AS `revenue`, AVG(`total`) AS `avg_order`, MIN(`total`) AS `smallest_order`, MAX(`total`) AS `largest_order` FROM `orders`',
            $result->query
        );
        $this->assertStringNotContainsString('GROUP BY', $result->query);
    }

    public function testInsertOrIgnoreMultipleRows(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['id' => 1, 'name' => 'Alice'])
            ->set(['id' => 2, 'name' => 'Bob'])
            ->set(['id' => 3, 'name' => 'Charlie'])
            ->insertOrIgnore();
        $this->assertBindingCount($result);

        $this->assertStringStartsWith('INSERT IGNORE INTO', $result->query);
        $this->assertSame('INSERT IGNORE INTO `users` (`id`, `name`) VALUES (?, ?), (?, ?), (?, ?)', $result->query);
        $this->assertSame([1, 'Alice', 2, 'Bob', 3, 'Charlie'], $result->bindings);
    }

    public function testSelectReadOnlyFlag(): void
    {
        $selectResult = (new Builder())
            ->from('t')
            ->build();
        $this->assertTrue($selectResult->readOnly);
    }

    public function testInsertNotReadOnly(): void
    {
        $insertResult = (new Builder())
            ->into('t')
            ->set(['a' => 1])
            ->insert();
        $this->assertFalse($insertResult->readOnly);
    }

    public function testUpdateNotReadOnly(): void
    {
        $updateResult = (new Builder())
            ->from('t')
            ->set(['a' => 1])
            ->update();
        $this->assertFalse($updateResult->readOnly);
    }

    public function testDeleteNotReadOnly(): void
    {
        $deleteResult = (new Builder())
            ->from('t')
            ->filter([Query::equal('id', [1])])
            ->delete();
        $this->assertFalse($deleteResult->readOnly);
    }

    public function testHavingRawWithRegularHaving(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'cnt')
            ->groupBy(['status'])
            ->having([Query::greaterThan('cnt', 5)])
            ->havingRaw('SUM(total) > ?', [1000])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `orders` GROUP BY `status` HAVING COUNT(*) > ? AND SUM(total) > ?', $result->query);
        $this->assertSame([5, 1000], $result->bindings);
    }

    public function testOrderByRawWithRegularSort(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sortAsc('name')
            ->orderByRaw('FIELD(status, ?, ?, ?)', ['active', 'pending', 'inactive'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` ORDER BY FIELD(status, ?, ?, ?), `name` ASC', $result->query);
        $this->assertSame(['active', 'pending', 'inactive'], $result->bindings);
    }

    public function testGroupByRawWithRegularGroupBy(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'cnt')
            ->groupBy(['status'])
            ->groupByRaw('YEAR(created_at)')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `orders` GROUP BY `status`, YEAR(created_at)', $result->query);
    }

    public function testDeleteJoinWithFilter(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->deleteJoin('o', 'blacklist', 'o.user_id', 'blacklist.user_id')
            ->filter([Query::equal('blacklist.reason', ['fraud'])])
            ->delete();
        $this->assertBindingCount($result);

        $this->assertSame('DELETE `o` FROM `orders` AS `o` JOIN `blacklist` ON `o`.`user_id` = `blacklist`.`user_id` WHERE `blacklist`.`reason` IN (?)', $result->query);
        $this->assertSame(['fraud'], $result->bindings);
    }

    public function testMaxExecutionTimeHint(): void
    {
        $result = (new Builder())
            ->from('t')
            ->maxExecutionTime(5000)
            ->filter([Query::equal('status', ['active'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT /*+ MAX_EXECUTION_TIME(5000) */ * FROM `t` WHERE `status` IN (?)', $result->query);
    }

    public function testMultipleHintsWithComplexQuery(): void
    {
        $result = (new Builder())
            ->from('t')
            ->maxExecutionTime(1000)
            ->hint('NO_RANGE_OPTIMIZATION(t)')
            ->filter([Query::greaterThan('id', 100)])
            ->limit(50)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT /*+ MAX_EXECUTION_TIME(1000) NO_RANGE_OPTIMIZATION(t) */ * FROM `t` WHERE `id` > ? LIMIT ?', $result->query);
    }

    public function testJoinWhereWithMultipleConditions(): void
    {
        $result = (new Builder())
            ->from('users')
            ->joinWhere('orders', function (JoinBuilder $j) {
                $j->on('users.id', 'orders.user_id')
                    ->where('orders.status', '=', 'completed')
                    ->where('orders.total', '>', 100);
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` JOIN `orders` ON `users`.`id` = `orders`.`user_id` AND orders.status = ? AND orders.total > ?', $result->query);
        $this->assertSame(['completed', 100], $result->bindings);
    }

    public function testFromNoneWithSelectRaw(): void
    {
        $result = (new Builder())
            ->from()
            ->select('1 + 1 AS result')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT 1 + 1 AS result', $result->query);
    }

    public function testCteBindingOrderPrecedesMainQuery(): void
    {
        $cte = (new Builder())
            ->from('source')
            ->filter([Query::equal('type', ['premium'])]);

        $result = (new Builder())
            ->with('filtered', $cte)
            ->from('filtered')
            ->filter([Query::greaterThan('score', 80)])
            ->limit(5)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['premium', 80, 5], $result->bindings);
    }

    public function testWindowFunctionWithJoinFilterGroupBy(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->select(['products.category'])
            ->sum('sales.amount', 'total_sales')
            ->selectWindow('RANK()', 'category_rank', null, ['-total_sales'])
            ->join('products', 'sales.product_id', 'products.id')
            ->filter([Query::greaterThan('sales.amount', 0)])
            ->groupBy(['products.category'])
            ->sortAsc('category_rank')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT SUM(`sales`.`amount`) AS `total_sales`, `products`.`category`, RANK() OVER (ORDER BY `total_sales` DESC) AS `category_rank` FROM `sales` JOIN `products` ON `sales`.`product_id` = `products`.`id` WHERE `sales`.`amount` > ? GROUP BY `products`.`category` ORDER BY `category_rank` ASC', $result->query);
    }

    public function testSubSelectWithFilterBindingOrder(): void
    {
        $sub = (new Builder())
            ->from('orders')
            ->count('*')
            ->filter([Query::raw('orders.user_id = users.id'), Query::equal('orders.status', ['paid'])]);

        $result = (new Builder())
            ->from('users')
            ->select(['users.name'])
            ->selectSub($sub, 'paid_order_count')
            ->filter([Query::equal('users.active', [true])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `users`.`name`, (SELECT COUNT(*) FROM `orders` WHERE orders.user_id = users.id AND `orders`.`status` IN (?)) AS `paid_order_count` FROM `users` WHERE `users`.`active` IN (?)', $result->query);
        $this->assertSame(['paid', true], $result->bindings);
    }

    public function testFilterWhereNotInSubqueryWithAdditionalFilter(): void
    {
        $sub = (new Builder())
            ->from('banned_users')
            ->select(['id']);

        $result = (new Builder())
            ->from('comments')
            ->filterWhereNotIn('user_id', $sub)
            ->filter([Query::equal('approved', [true])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `comments` WHERE `approved` IN (?) AND `user_id` NOT IN (SELECT `id` FROM `banned_users`)', $result->query);
    }

    public function testNotExistsSubqueryWithFilter(): void
    {
        $sub = (new Builder())
            ->from('refunds')
            ->select(['id'])
            ->filter([Query::raw('refunds.order_id = orders.id')]);

        $result = (new Builder())
            ->from('orders')
            ->filter([Query::equal('status', ['completed'])])
            ->filterNotExists($sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `orders` WHERE `status` IN (?) AND NOT EXISTS (SELECT `id` FROM `refunds` WHERE refunds.order_id = orders.id)', $result->query);
        $this->assertSame(['completed'], $result->bindings);
    }

    public function testCountWhenWithFilterAndGroupBy(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->countWhen('status = ?', 'paid_count', 'paid')
            ->countWhen('status = ?', 'pending_count', 'pending')
            ->sum('total', 'revenue')
            ->groupBy(['region'])
            ->filter([Query::greaterThan('total', 0)])
            ->having([Query::greaterThan('paid_count', 1)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT SUM(`total`) AS `revenue`, COUNT(CASE WHEN status = ? THEN 1 END) AS `paid_count`, COUNT(CASE WHEN status = ? THEN 1 END) AS `pending_count` FROM `orders` WHERE `total` > ? GROUP BY `region` HAVING `paid_count` > ?', $result->query);
    }

    public function testSumWhenConditionalAggregate(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->sumWhen('total', 'status = ?', 'paid_revenue', 'paid')
            ->sumWhen('total', 'status = ?', 'refunded_amount', 'refunded')
            ->groupBy(['region'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT SUM(CASE WHEN status = ? THEN `total` END) AS `paid_revenue`, SUM(CASE WHEN status = ? THEN `total` END) AS `refunded_amount` FROM `orders` GROUP BY `region`', $result->query);
    }

    public function testForShareLockWithJoin(): void
    {
        $result = (new Builder())
            ->from('accounts')
            ->join('users', 'accounts.user_id', 'users.id')
            ->filter([Query::equal('users.status', ['active'])])
            ->forShare()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `accounts` JOIN `users` ON `accounts`.`user_id` = `users`.`id` WHERE `users`.`status` IN (?) FOR SHARE', $result->query);
    }

    public function testForUpdateSkipLockedWithFilter(): void
    {
        $result = (new Builder())
            ->from('jobs')
            ->filter([Query::equal('status', ['pending'])])
            ->sortAsc('created_at')
            ->limit(1)
            ->forUpdateSkipLocked()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `jobs` WHERE `status` IN (?) ORDER BY `created_at` ASC LIMIT ? FOR UPDATE SKIP LOCKED', $result->query);
    }

    public function testBeforeBuildCallbackModifiesQuery(): void
    {
        $result = (new Builder())
            ->from('t')
            ->beforeBuild(function (Builder $b) {
                $b->filter([Query::equal('injected', [true])]);
            })
            ->filter([Query::equal('status', ['active'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `status` IN (?) AND `injected` IN (?)', $result->query);
    }

    public function testAfterBuildCallbackTransformsResult(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('status', ['active'])])
            ->afterBuild(function (Statement $r) {
                return new Statement(
                    '/* traced */ ' . $r->query,
                    $r->bindings,
                    $r->readOnly,
                );
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringStartsWith('/* traced */ SELECT', $result->query);
    }

    public function testJsonSetAppendAndUpdate(): void
    {
        $result = (new Builder())
            ->from('documents')
            ->setJsonAppend('tags', ['newTag'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `documents` SET `tags` = JSON_MERGE_PRESERVE(IFNULL(`tags`, JSON_ARRAY()), ?) WHERE `id` IN (?)', $result->query);
    }

    public function testJsonSetPrependAndUpdate(): void
    {
        $result = (new Builder())
            ->from('documents')
            ->setJsonPrepend('tags', ['firstTag'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `documents` SET `tags` = JSON_MERGE_PRESERVE(?, IFNULL(`tags`, JSON_ARRAY())) WHERE `id` IN (?)', $result->query);
    }

    public function testJsonSetRemoveAndUpdate(): void
    {
        $result = (new Builder())
            ->from('documents')
            ->setJsonRemove('tags', 'oldTag')
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `documents` SET `tags` = JSON_REMOVE(`tags`, JSON_UNQUOTE(JSON_SEARCH(`tags`, \'one\', ?))) WHERE `id` IN (?)', $result->query);
    }

    public function testUpdateWithCaseExpression(): void
    {
        $case = (new CaseExpression())
            ->when('priority', Operator::Equal, 'high', 1)
            ->when('priority', Operator::Equal, 'medium', 2)
            ->else(3);

        $result = (new Builder())
            ->from('tasks')
            ->setCase('sort_order', $case)
            ->filter([Query::isNotNull('priority')])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `tasks` SET `sort_order` = CASE WHEN `priority` = ? THEN ? WHEN `priority` = ? THEN ? ELSE ? END WHERE `priority` IS NOT NULL', $result->query);
        $this->assertSame(['high', 1, 'medium', 2, 3], $result->bindings);
    }

    public function testLeftLateralJoinWithFilters(): void
    {
        $lateral = (new Builder())
            ->from('scores')
            ->select(['value'])
            ->filter([Query::raw('scores.player_id = players.id')])
            ->sortDesc('value')
            ->limit(1);

        $result = (new Builder())
            ->from('players')
            ->select(['players.name', 'top_score.value'])
            ->leftJoinLateral($lateral, 'top_score')
            ->filter([Query::equal('players.active', [true])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `players`.`name`, `top_score`.`value` FROM `players` LEFT JOIN LATERAL (SELECT `value` FROM `scores` WHERE scores.player_id = players.id ORDER BY `value` DESC LIMIT ?) AS `top_score` ON true WHERE `players`.`active` IN (?)', $result->query);
    }

    public function testCteWithWindowFunction(): void
    {
        $cte = (new Builder())
            ->from('sales')
            ->select(['region', 'amount'])
            ->selectWindow('ROW_NUMBER()', 'rn', ['region'], ['-amount']);

        $result = (new Builder())
            ->with('ranked_sales', $cte)
            ->from('ranked_sales')
            ->filter([Query::equal('rn', [1])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('WITH `ranked_sales` AS (SELECT `region`, `amount`, ROW_NUMBER() OVER (PARTITION BY `region` ORDER BY `amount` DESC) AS `rn` FROM `sales`) SELECT * FROM `ranked_sales` WHERE `rn` IN (?)', $result->query);
    }

    public function testComplexBindingOrderCteFilterHookSubqueryHavingLimitUnion(): void
    {
        $cte = (new Builder())
            ->from('source')
            ->filter([Query::equal('type', ['A'])]);

        $sub = (new Builder())
            ->from('exclude')
            ->select(['id'])
            ->filter([Query::equal('reason', ['banned'])]);

        $unionQuery = (new Builder())
            ->from('archive')
            ->filter([Query::equal('year', [2023])]);

        $hook = new class () implements Filter {
            public function filter(string $table): Condition
            {
                return new Condition('org_id = ?', ['org1']);
            }
        };

        $result = (new Builder())
            ->with('cte_source', $cte)
            ->from('cte_source')
            ->count('*', 'cnt')
            ->addHook($hook)
            ->filter([Query::greaterThan('score', 50)])
            ->filterWhereNotIn('id', $sub)
            ->groupBy(['category'])
            ->having([Query::greaterThan('cnt', 2)])
            ->limit(10)
            ->union($unionQuery)
            ->build();
        $this->assertBindingCount($result);

        $expectedBindings = ['A', 50, 'org1', 'banned', 2, 10, 2023];
        $this->assertSame($expectedBindings, $result->bindings);
    }

    public function testSearchExactPhraseMatch(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::search('content', '"exact phrase"')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('"exact phrase"', $result->bindings[0]);
        /** @var string $firstBinding */
        $firstBinding = $result->bindings[0];
        $this->assertStringNotContainsString('*', $firstBinding);
    }

    public function testNotSearchEmptyString(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notSearch('content', '')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE 1 = 1', $result->query);
    }

    public function testSearchExactPhraseInExactMode(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::search('title', '"hello world"')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['"hello world"'], $result->bindings);
    }

    public function testUpsertSelectWithFilteredSource(): void
    {
        $source = (new Builder())
            ->from('staging')
            ->select(['id', 'name', 'email'])
            ->filter([Query::equal('verified', [true])]);

        $result = (new Builder())
            ->into('users')
            ->fromSelect(['id', 'name', 'email'], $source)
            ->onConflict(['id'], ['name', 'email'])
            ->upsertSelect();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO `users` (`id`, `name`, `email`) SELECT `id`, `name`, `email` FROM `staging` WHERE `verified` IN (?) ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `email` = VALUES(`email`)', $result->query);
        $this->assertSame([true], $result->bindings);
    }

    public function testExplainAnalyzeWithFormatJson(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('status', ['active'])])
            ->explain(true, 'JSON');
        $this->assertBindingCount($result);

        $this->assertStringStartsWith('EXPLAIN ANALYZE FORMAT=JSON SELECT', $result->query);
        $this->assertTrue($result->readOnly);
    }

    public function testMultipleSelectRawExpressions(): void
    {
        $result = (new Builder())
            ->from('t')
            ->select('NOW() AS current_time')
            ->select('CONCAT(first_name, ?, last_name) AS full_name', [' '])
            ->select('? AS constant_val', [42])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT NOW() AS current_time, CONCAT(first_name, ?, last_name) AS full_name, ? AS constant_val FROM `t`', $result->query);
        $this->assertSame([' ', 42], $result->bindings);
    }

    public function testFromSubqueryWithAggregation(): void
    {
        $sub = (new Builder())
            ->from('orders')
            ->select(['user_id'])
            ->sum('total', 'user_total')
            ->groupBy(['user_id']);

        $result = (new Builder())
            ->fromSub($sub, 'user_totals')
            ->avg('user_total', 'avg_user_spend')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT AVG(`user_total`) AS `avg_user_spend` FROM (SELECT SUM(`total`) AS `user_total`, `user_id` FROM `orders` GROUP BY `user_id`) AS `user_totals`', $result->query);
    }

    public function testMultipleWhereInSubqueriesOnDifferentColumns(): void
    {
        $sub1 = (new Builder())->from('vip_users')->select(['id']);
        $sub2 = (new Builder())->from('active_products')->select(['id']);

        $result = (new Builder())
            ->from('orders')
            ->filterWhereIn('user_id', $sub1)
            ->filterWhereIn('product_id', $sub2)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `orders` WHERE `user_id` IN (SELECT `id` FROM `vip_users`) AND `product_id` IN (SELECT `id` FROM `active_products`)', $result->query);
    }

    public function testExistsAndNotExistsCombined(): void
    {
        $existsSub = (new Builder())
            ->from('orders')
            ->select('1')
            ->filter([Query::raw('orders.user_id = users.id')]);

        $notExistsSub = (new Builder())
            ->from('bans')
            ->select('1')
            ->filter([Query::raw('bans.user_id = users.id')]);

        $result = (new Builder())
            ->from('users')
            ->filterExists($existsSub)
            ->filterNotExists($notExistsSub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE EXISTS (SELECT 1 FROM `orders` WHERE orders.user_id = users.id) AND NOT EXISTS (SELECT 1 FROM `bans` WHERE bans.user_id = users.id)', $result->query);
    }

    public function testCteWithDeleteJoin(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->deleteJoin('o', 'expired_users', 'o.user_id', 'expired_users.id')
            ->filter([Query::lessThan('o.created_at', '2023-01-01')])
            ->delete();
        $this->assertBindingCount($result);

        $this->assertSame('DELETE `o` FROM `orders` AS `o` JOIN `expired_users` ON `o`.`user_id` = `expired_users`.`id` WHERE `o`.`created_at` < ?', $result->query);
        $this->assertSame(['2023-01-01'], $result->bindings);
    }

    public function testUpdateJoinWithAliasAndFilter(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->updateJoin('users', 'orders.user_id', 'u.id', 'u')
            ->set(['orders.discount' => 10])
            ->filter([Query::equal('u.tier', ['gold'])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `orders` JOIN `users` AS `u` ON `orders`.`user_id` = `u`.`id` SET `orders`.`discount` = ? WHERE `u`.`tier` IN (?)', $result->query);
    }

    public function testJsonContainsFilter(): void
    {
        $result = (new Builder())
            ->from('documents')
            ->filterJsonContains('tags', ['php', 'mysql'])
            ->filter([Query::equal('active', [true])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `documents` WHERE JSON_CONTAINS(`tags`, ?) AND `active` IN (?)', $result->query);
    }

    public function testJsonPathFilter(): void
    {
        $result = (new Builder())
            ->from('config')
            ->filterJsonPath('settings', 'theme.color', '=', 'blue')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `config` WHERE JSON_EXTRACT(`settings`, \'$.theme.color\') = ?', $result->query);
        $this->assertSame(['blue'], $result->bindings);
    }

    public function testCloneIndependenceWithWhereInSubquery(): void
    {
        $sub = (new Builder())->from('vips')->select(['id']);

        $original = (new Builder())
            ->from('orders')
            ->filterWhereIn('user_id', $sub);

        $cloned = $original->clone();
        $cloned->filter([Query::greaterThan('total', 100)]);

        $originalResult = $original->build();
        $clonedResult = $cloned->build();

        $this->assertStringNotContainsString('`total`', $originalResult->query);
        $this->assertSame('SELECT * FROM `orders` WHERE `total` > ? AND `user_id` IN (SELECT `id` FROM `vips`)', $clonedResult->query);
    }

    public function testCteWithJoinAndConditionProvider(): void
    {
        $cte = (new Builder())
            ->from('monthly_sales')
            ->filter([Query::greaterThan('month', 6)]);

        $hook = new class () implements Filter {
            public function filter(string $table): Condition
            {
                return new Condition('region = ?', ['US']);
            }
        };

        $result = (new Builder())
            ->with('recent_sales', $cte)
            ->from('recent_sales')
            ->addHook($hook)
            ->join('products', 'recent_sales.product_id', 'products.id')
            ->sum('recent_sales.amount', 'total')
            ->groupBy(['products.category'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('WITH `recent_sales` AS (SELECT * FROM `monthly_sales` WHERE `month` > ?) SELECT SUM(`recent_sales`.`amount`) AS `total` FROM `recent_sales` JOIN `products` ON `recent_sales`.`product_id` = `products`.`id` WHERE region = ? GROUP BY `products`.`category`', $result->query);
        $this->assertSame([6, 'US'], $result->bindings);
    }

    public function testEndsWithWithUnderscoreWildcard(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::endsWith('code', '_test')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['%\_test'], $result->bindings);
    }

    public function testStartsWithWithPercentWildcard(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::startsWith('label', '50%')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['50\%%'], $result->bindings);
    }

    public function testNotBetweenCombinedWithOrFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::or([
                    Query::notBetween('age', 18, 65),
                    Query::equal('status', ['exempt']),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `t` WHERE (`age` NOT BETWEEN ? AND ? OR `status` IN (?))',
            $result->query
        );
        $this->assertSame([18, 65, 'exempt'], $result->bindings);
    }

    public function testUpdateWithMultipleRawSets(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['name' => 'Updated'])
            ->setRaw('login_count', 'login_count + 1')
            ->setRaw('last_login', 'NOW()')
            ->filter([Query::equal('id', [42])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('UPDATE `users` SET `name` = ?, `login_count` = login_count + 1, `last_login` = NOW() WHERE `id` IN (?)', $result->query);
    }

    public function testInsertWithNullValues(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'Alice', 'bio' => null, 'age' => null])
            ->insert();
        $this->assertBindingCount($result);

        $this->assertSame(
            'INSERT INTO `users` (`name`, `bio`, `age`) VALUES (?, ?, ?)',
            $result->query
        );
        $this->assertSame(['Alice', null, null], $result->bindings);
    }

    public function testResetClearsLateralJoins(): void
    {
        $lateral = (new Builder())->from('sub')->limit(1);
        $builder = (new Builder())
            ->from('t')
            ->joinLateral($lateral, 'lat');
        $builder->build();

        $builder->reset();

        $result = $builder->from('fresh')->build();
        $this->assertBindingCount($result);
        $this->assertStringNotContainsString('LATERAL', $result->query);
        $this->assertSame('SELECT * FROM `fresh`', $result->query);
    }

    public function testResetClearsWindowDefinitions(): void
    {
        $builder = (new Builder())
            ->from('t')
            ->window('w', ['category'])
            ->selectWindow('ROW_NUMBER()', 'rn', windowName: 'w');
        $builder->build();

        $builder->reset();

        $result = $builder->from('fresh')->build();
        $this->assertBindingCount($result);
        $this->assertStringNotContainsString('WINDOW', $result->query);
    }

    public function testResetClearsCteDefinitions(): void
    {
        $cte = (new Builder())->from('source');
        $builder = (new Builder())
            ->with('src', $cte)
            ->from('src');
        $builder->build();

        $builder->reset();

        $result = $builder->from('fresh')->build();
        $this->assertBindingCount($result);
        $this->assertStringNotContainsString('WITH', $result->query);
    }

    public function testComplexQueryClauseOrdering(): void
    {
        $cte = (new Builder())
            ->from('source')
            ->filter([Query::equal('type', ['A'])]);

        $result = (new Builder())
            ->with('src', $cte)
            ->from('src')
            ->select(['category'])
            ->count('*', 'cnt')
            ->join('meta', 'src.id', 'meta.src_id')
            ->filter([Query::greaterThan('score', 50)])
            ->groupBy(['category'])
            ->having([Query::greaterThan('cnt', 2)])
            ->sortDesc('cnt')
            ->limit(10)
            ->offset(5)
            ->build();
        $this->assertBindingCount($result);

        $query = $result->query;
        $withPos = strpos($query, 'WITH');
        $fromPos = strpos($query, 'FROM `src`');
        $this->assertNotFalse($fromPos);
        $joinPos = strpos($query, 'JOIN', $fromPos);
        $this->assertNotFalse($joinPos);
        $wherePos = strpos($query, 'WHERE', $joinPos);
        $groupPos = strpos($query, 'GROUP BY');
        $havingPos = strpos($query, 'HAVING');
        $orderPos = strpos($query, 'ORDER BY');
        $limitPos = strpos($query, 'LIMIT');
        $offsetPos = strpos($query, 'OFFSET');

        $this->assertNotFalse($withPos);
        $this->assertLessThan($fromPos, $withPos);
        $this->assertLessThan($joinPos, $fromPos);
        $this->assertLessThan($wherePos, $joinPos);
        $this->assertLessThan($groupPos, $wherePos);
        $this->assertLessThan($havingPos, $groupPos);
        $this->assertLessThan($orderPos, $havingPos);
        $this->assertLessThan($limitPos, $orderPos);
        $this->assertLessThan($offsetPos, $limitPos);
    }

    public function testFromTableAlias(): void
    {
        $result = (new Builder())
            ->from('users', 'u')
            ->select(['u.name', 'u.email'])
            ->filter([Query::equal('u.status', ['active'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `u`.`name`, `u`.`email` FROM `users` AS `u` WHERE `u`.`status` IN (?)', $result->query);
    }

    public function testJoinWhereWithOnRaw(): void
    {
        $result = (new Builder())
            ->from('users')
            ->joinWhere('orders', function (JoinBuilder $j) {
                $j->on('users.id', 'orders.user_id')
                    ->onRaw('orders.created_at > NOW() - INTERVAL ? DAY', [30]);
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` JOIN `orders` ON `users`.`id` = `orders`.`user_id` AND orders.created_at > NOW() - INTERVAL ? DAY', $result->query);
        $this->assertSame([30], $result->bindings);
    }

    public function testFromNoneEmitsNoFromClause(): void
    {
        $result = (new Builder())
            ->fromNone()
            ->selectRaw('1 + 1')
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString('FROM', $result->query);
        $this->assertSame('SELECT 1 + 1', $result->query);
    }

    public function testSelectCastEmitsCastExpression(): void
    {
        $result = (new Builder())
            ->from('products')
            ->selectCast('price', 'DECIMAL(10, 2)', 'price_decimal')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT CAST(`price` AS DECIMAL(10, 2)) AS `price_decimal` FROM `products`', $result->query);
    }

    public function testSelectCastAcceptsValidTypes(): void
    {
        foreach (['INT', 'VARCHAR(255)', 'DECIMAL(10, 2)', 'UNSIGNED INTEGER'] as $type) {
            $result = (new Builder())
                ->from('t')
                ->selectCast('c', $type, 'a')
                ->build();
            $this->assertStringContainsString('CAST(`c` AS ' . $type . ')', $result->query);
        }
    }

    public function testSelectCastRejectsInvalidType(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid cast type');

        (new Builder())
            ->from('t')
            ->selectCast('c', 'INT); DROP TABLE x;--', 'a');
    }

    public function testSelectWindowAcceptsValidFunctions(): void
    {
        foreach (['ROW_NUMBER()', 'RANK()', 'SUM(amount)', 'LAG(x, 1, 0)'] as $function) {
            $builder = (new Builder())
                ->from('t')
                ->selectWindow($function, 'w', ['cat'], ['price']);
            $this->assertInstanceOf(Builder::class, $builder);
        }
    }

    public function testSelectWindowRejectsInvalidFunction(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid window function');

        (new Builder())
            ->from('t')
            ->selectWindow('ROW_NUMBER()); DROP --', 'w');
    }

    /**
     * @return list<array{0: string}>
     */
    public static function reservedWordsProvider(): array
    {
        return [
            ['select'],
            ['from'],
            ['where'],
            ['order'],
            ['group'],
            ['having'],
            ['user'],
            ['table'],
            ['insert'],
            ['update'],
            ['delete'],
            ['join'],
            ['on'],
            ['and'],
            ['or'],
            ['not'],
            ['in'],
            ['between'],
            ['like'],
            ['is'],
            ['null'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reservedWordsProvider')]
    public function testReservedWordInSelect(string $word): void
    {
        $result = (new Builder())
            ->from('t')
            ->select([$word])
            ->build();

        $this->assertStringContainsString('`' . $word . '`', $result->query);
        $stripped = \preg_replace('/`[^`]+`/', '', $result->query) ?? '';
        // Lowercase reserved word must not appear bare outside quotes
        $this->assertDoesNotMatchRegularExpression(
            '/(?<![A-Za-z0-9_])' . \preg_quote($word, '/') . '(?![A-Za-z0-9_])/',
            $stripped
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reservedWordsProvider')]
    public function testReservedWordInFrom(string $word): void
    {
        $result = (new Builder())
            ->from($word)
            ->build();

        $this->assertStringContainsString('FROM `' . $word . '`', $result->query);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reservedWordsProvider')]
    public function testReservedWordInFilter(string $word): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal($word, ['x'])])
            ->build();

        $this->assertStringContainsString('`' . $word . '`', $result->query);
        $this->assertSame(['x'], $result->bindings);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function unicodeIdentifiersProvider(): array
    {
        return [
            ['café'],
            ['日本'],
            ['column_with_émoji'],
            ['Ω_omega'],
            ['данные'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unicodeIdentifiersProvider')]
    public function testUnicodeIdentifierInSelect(string $identifier): void
    {
        $result = (new Builder())
            ->from('t')
            ->select([$identifier])
            ->build();

        $this->assertStringContainsString('`' . $identifier . '`', $result->query);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unicodeIdentifiersProvider')]
    public function testUnicodeIdentifierInFrom(string $identifier): void
    {
        $result = (new Builder())
            ->from($identifier)
            ->build();

        $this->assertStringContainsString('`' . $identifier . '`', $result->query);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unicodeIdentifiersProvider')]
    public function testUnicodeIdentifierInFilter(string $identifier): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal($identifier, ['x'])])
            ->build();

        $this->assertStringContainsString('`' . $identifier . '`', $result->query);
        $this->assertSame(['x'], $result->bindings);
    }

    public function testWhereColumnEmitsQualifiedIdentifiers(): void
    {
        $result = (new Builder())
            ->from('users')
            ->whereColumn('users.id', '=', 'orders.user_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE `users`.`id` = `orders`.`user_id`', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testWhereColumnRejectsUnknownOperator(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid whereColumn operator: NOT_AN_OP');

        (new Builder())
            ->from('users')
            ->whereColumn('a', 'NOT_AN_OP', 'b');
    }

    public function testWhereColumnCombinesWithFilter(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('status', ['active'])])
            ->whereColumn('users.id', '=', 'orders.user_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE `status` IN (?) AND `users`.`id` = `orders`.`user_id`', $result->query);
        $this->assertContains('active', $result->bindings);
    }

}
