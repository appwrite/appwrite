<?php

namespace Tests\Query\Hook\Join;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Tests\Query\Fixture\PermissionFilter as Permission;
use Utopia\Query\Builder\Condition;
use Utopia\Query\Builder\JoinType;
use Utopia\Query\Builder\MySQL as Builder;
use Utopia\Query\Hook\Filter;
use Utopia\Query\Hook\Filter\Tenant;
use Utopia\Query\Hook\Join\Condition as JoinCondition;
use Utopia\Query\Hook\Join\Filter as JoinFilter;
use Utopia\Query\Hook\Join\Placement;
use Utopia\Query\Query;

class FilterTest extends TestCase
{
    use AssertsBindingCount;
    public function testOnPlacementForLeftJoin(): void
    {
        $hook = new class () implements JoinFilter {
            public function filterJoin(string $table, JoinType $joinType): JoinCondition
            {
                return new JoinCondition(
                    new Condition('active = ?', [1]),
                    Placement::On,
                );
            }
        };

        $result = (new Builder())
            ->from('users')
            ->addHook($hook)
            ->leftJoin('orders', 'users.id', 'orders.user_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` LEFT JOIN `orders` ON `users`.`id` = `orders`.`user_id` AND active = ?', $result->query);
        $this->assertStringNotContainsString('WHERE', $result->query);
        $this->assertSame([1], $result->bindings);
    }

    public function testWherePlacementForInnerJoin(): void
    {
        $hook = new class () implements JoinFilter {
            public function filterJoin(string $table, JoinType $joinType): JoinCondition
            {
                return new JoinCondition(
                    new Condition('active = ?', [1]),
                    Placement::Where,
                );
            }
        };

        $result = (new Builder())
            ->from('users')
            ->addHook($hook)
            ->join('orders', 'users.id', 'orders.user_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` JOIN `orders` ON `users`.`id` = `orders`.`user_id` WHERE active = ?', $result->query);
        $this->assertStringNotContainsString('ON `users`.`id` = `orders`.`user_id` AND', $result->query);
        $this->assertSame('SELECT * FROM `users` JOIN `orders` ON `users`.`id` = `orders`.`user_id` WHERE active = ?', $result->query);
        $this->assertSame([1], $result->bindings);
    }

    public function testReturnsNullSkipsJoin(): void
    {
        $hook = new class () implements JoinFilter {
            public function filterJoin(string $table, JoinType $joinType): ?JoinCondition
            {
                return null;
            }
        };

        $result = (new Builder())
            ->from('users')
            ->addHook($hook)
            ->leftJoin('orders', 'users.id', 'orders.user_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` LEFT JOIN `orders` ON `users`.`id` = `orders`.`user_id`', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testCrossJoinForcesOnToWhere(): void
    {
        $hook = new class () implements JoinFilter {
            public function filterJoin(string $table, JoinType $joinType): JoinCondition
            {
                return new JoinCondition(
                    new Condition('active = ?', [1]),
                    Placement::On,
                );
            }
        };

        $result = (new Builder())
            ->from('users')
            ->addHook($hook)
            ->crossJoin('settings')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` CROSS JOIN `settings` WHERE active = ?', $result->query);
        $this->assertStringNotContainsString('CROSS JOIN `settings` AND', $result->query);
        $this->assertSame('SELECT * FROM `users` CROSS JOIN `settings` WHERE active = ?', $result->query);
        $this->assertSame([1], $result->bindings);
    }

    public function testMultipleHooksOnSameJoin(): void
    {
        $hook1 = new class () implements JoinFilter {
            public function filterJoin(string $table, JoinType $joinType): JoinCondition
            {
                return new JoinCondition(
                    new Condition('active = ?', [1]),
                    Placement::On,
                );
            }
        };

        $hook2 = new class () implements JoinFilter {
            public function filterJoin(string $table, JoinType $joinType): JoinCondition
            {
                return new JoinCondition(
                    new Condition('visible = ?', [true]),
                    Placement::On,
                );
            }
        };

        $result = (new Builder())
            ->from('users')
            ->addHook($hook1)
            ->addHook($hook2)
            ->leftJoin('orders', 'users.id', 'orders.user_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` LEFT JOIN `orders` ON `users`.`id` = `orders`.`user_id` AND active = ? AND visible = ?', $result->query);
        $this->assertSame([1, true], $result->bindings);
    }

    public function testBindingOrderCorrectness(): void
    {
        $onHook = new class () implements JoinFilter {
            public function filterJoin(string $table, JoinType $joinType): JoinCondition
            {
                return new JoinCondition(
                    new Condition('on_col = ?', ['on_val']),
                    Placement::On,
                );
            }
        };

        $whereHook = new class () implements JoinFilter {
            public function filterJoin(string $table, JoinType $joinType): JoinCondition
            {
                return new JoinCondition(
                    new Condition('where_col = ?', ['where_val']),
                    Placement::Where,
                );
            }
        };

        $result = (new Builder())
            ->from('users')
            ->addHook($onHook)
            ->addHook($whereHook)
            ->leftJoin('orders', 'users.id', 'orders.user_id')
            ->filter([Query::equal('status', ['active'])])
            ->build();
        $this->assertBindingCount($result);

        // ON bindings come first (during join compilation), then filter bindings, then WHERE join filter bindings
        $this->assertSame(['on_val', 'active', 'where_val'], $result->bindings);
    }

    public function testFilterOnlyBackwardCompat(): void
    {
        $hook = new class () implements Filter {
            public function filter(string $table): Condition
            {
                return new Condition('deleted = ?', [0]);
            }
        };

        $result = (new Builder())
            ->from('users')
            ->addHook($hook)
            ->leftJoin('orders', 'users.id', 'orders.user_id')
            ->build();
        $this->assertBindingCount($result);

        // Filter-only hooks should still apply to WHERE, not to joins
        $this->assertSame('SELECT * FROM `users` LEFT JOIN `orders` ON `users`.`id` = `orders`.`user_id` WHERE deleted = ?', $result->query);
        $this->assertStringNotContainsString('ON `users`.`id` = `orders`.`user_id` AND', $result->query);
        $this->assertSame('SELECT * FROM `users` LEFT JOIN `orders` ON `users`.`id` = `orders`.`user_id` WHERE deleted = ?', $result->query);
        $this->assertSame([0], $result->bindings);
    }

    public function testDualInterfaceHook(): void
    {
        $hook = new class () implements Filter, JoinFilter {
            public function filter(string $table): Condition
            {
                return new Condition('main_active = ?', [1]);
            }

            public function filterJoin(string $table, JoinType $joinType): JoinCondition
            {
                return new JoinCondition(
                    new Condition('join_active = ?', [1]),
                    Placement::On,
                );
            }
        };

        $result = (new Builder())
            ->from('users')
            ->addHook($hook)
            ->leftJoin('orders', 'users.id', 'orders.user_id')
            ->build();
        $this->assertBindingCount($result);

        // Filter applies to WHERE for main table
        $this->assertSame('SELECT * FROM `users` LEFT JOIN `orders` ON `users`.`id` = `orders`.`user_id` AND join_active = ? WHERE main_active = ?', $result->query);
        // JoinFilter applies to ON for join
        $this->assertSame('SELECT * FROM `users` LEFT JOIN `orders` ON `users`.`id` = `orders`.`user_id` AND join_active = ? WHERE main_active = ?', $result->query);
        // ON binding first, then WHERE binding
        $this->assertSame([1, 1], $result->bindings);
    }

    public function testPermissionLeftJoinOnPlacement(): void
    {
        $hook = new Permission(
            roles: ['role:admin'],
            permissionsTable: fn (string $table) => "mydb_{$table}_perms",
        );
        $condition = $hook->filterJoin('orders', JoinType::Left);

        $this->assertNotNull($condition);
        $this->assertSame(Placement::On, $condition->placement);
        $this->assertSame('id IN (SELECT DISTINCT document_id FROM mydb_orders_perms WHERE role IN (?) AND type = ?)', $condition->condition->expression);
    }

    public function testPermissionInnerJoinWherePlacement(): void
    {
        $hook = new Permission(
            roles: ['role:admin'],
            permissionsTable: fn (string $table) => "mydb_{$table}_perms",
        );
        $condition = $hook->filterJoin('orders', JoinType::Inner);

        $this->assertNotNull($condition);
        $this->assertSame(Placement::Where, $condition->placement);
    }

    public function testTenantLeftJoinOnPlacement(): void
    {
        $hook = new Tenant(['t1']);
        $condition = $hook->filterJoin('orders', JoinType::Left);

        $this->assertNotNull($condition);
        $this->assertSame(Placement::On, $condition->placement);
        $this->assertSame('tenant_id IN (?)', $condition->condition->expression);
    }

    public function testTenantInnerJoinWherePlacement(): void
    {
        $hook = new Tenant(['t1']);
        $condition = $hook->filterJoin('orders', JoinType::Inner);

        $this->assertNotNull($condition);
        $this->assertSame(Placement::Where, $condition->placement);
    }

    public function testHookReceivesCorrectTableAndJoinType(): void
    {
        // Tenant returns On for RIGHT JOIN — verifying it received the correct joinType
        $hook = new Tenant(['t1']);

        $rightJoinResult = $hook->filterJoin('orders', JoinType::Right);
        $this->assertNotNull($rightJoinResult);
        $this->assertSame(Placement::On, $rightJoinResult->placement);

        // Same hook returns Where for JOIN — verifying joinType discrimination
        $innerJoinResult = $hook->filterJoin('orders', JoinType::Inner);
        $this->assertNotNull($innerJoinResult);
        $this->assertSame(Placement::Where, $innerJoinResult->placement);

        // Verify table name is used in the condition expression
        $permHook = new Permission(
            roles: ['role:admin'],
            permissionsTable: fn (string $table) => "mydb_{$table}_perms",
        );
        $result = $permHook->filterJoin('orders', JoinType::Left);
        $this->assertNotNull($result);
        $this->assertSame('id IN (SELECT DISTINCT document_id FROM mydb_orders_perms WHERE role IN (?) AND type = ?)', $result->condition->expression);
    }
}
