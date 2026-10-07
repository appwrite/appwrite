<?php

namespace Utopia\Query\Tests\API;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Query\Builder\MySQL;
use Utopia\Query\Exception as QueryException;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Method;
use Utopia\Query\Query;

class JoinTest extends TestCase
{
    /**
     * @return array<string, array{Method, Closure(string, string, list<Query>): Query}>
     */
    public static function conditionedJoins(): array
    {
        return [
            'join' => [Method::Join, Query::join(...)],
            'leftJoin' => [Method::LeftJoin, Query::leftJoin(...)],
            'rightJoin' => [Method::RightJoin, Query::rightJoin(...)],
            'fullOuterJoin' => [Method::FullOuterJoin, Query::fullOuterJoin(...)],
        ];
    }

    /**
     * @return array<string, array{Method, Closure(string, string): Query}>
     */
    public static function unconditionedJoins(): array
    {
        return [
            'crossJoin' => [Method::CrossJoin, Query::crossJoin(...)],
            'naturalJoin' => [Method::NaturalJoin, Query::naturalJoin(...)],
        ];
    }

    /**
     * @param  Closure(string, string, list<Query>): Query  $factory
     */
    #[DataProvider('conditionedJoins')]
    public function testConditionedJoinKeepsCollectionAliasAndConditions(Method $method, Closure $factory): void
    {
        $on = Query::on('users.id', 'ord.user_id');
        $status = Query::equal('ord.status', ['paid']);

        $query = $factory('orders', 'ord', [$on, $status]);

        $this->assertSame($method, $query->getMethod());
        $this->assertSame('orders', $query->getAttribute());
        $this->assertSame('ord', $query->getAlias());
        $this->assertSame([$on, $status], $query->getValues());
        $this->assertSame([$on, $status], $query->getJoinOnQueries());
        $this->assertTrue($query->isNestedJoin());
    }

    /**
     * @param  Closure(string, string): Query  $factory
     */
    #[DataProvider('unconditionedJoins')]
    public function testUnconditionedJoinKeepsCollectionAndAlias(Method $method, Closure $factory): void
    {
        $query = $factory('colors', 'c');

        $this->assertSame($method, $query->getMethod());
        $this->assertSame('colors', $query->getAttribute());
        $this->assertSame('c', $query->getAlias());
        $this->assertSame([], $query->getValues());
        $this->assertSame([], $query->getJoinOnQueries());
    }

    /**
     * @param  Closure(string, string, list<Query>): Query  $factory
     */
    #[DataProvider('conditionedJoins')]
    public function testConditionedJoinRequiresAlias(Method $method, Closure $factory): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Join alias is required: ' . $method->value . ' orders');

        $factory('orders', '', [Query::on('users.id', 'orders.user_id')]);
    }

    /**
     * @param  Closure(string, string): Query  $factory
     */
    #[DataProvider('unconditionedJoins')]
    public function testUnconditionedJoinRequiresAlias(Method $method, Closure $factory): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Join alias is required: ' . $method->value . ' colors');

        $factory('colors', '');
    }

    /**
     * @param  Closure(string, string, list<Query>): Query  $factory
     */
    #[DataProvider('conditionedJoins')]
    public function testConditionedJoinRequiresACondition(Method $method, Closure $factory): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Join ON requires at least one condition');

        $factory('orders', 'ord', []);
    }

    public function testJoinRejectsConditionThatIsNotAQuery(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Join ON conditions must be Query objects');

        /** @phpstan-ignore argument.type */
        Query::leftJoin('orders', 'ord', [Query::on('$id', 'customerId')->toString()]);
    }

    /**
     * @return array<string, array{Query}>
     */
    public static function nonConditions(): array
    {
        return [
            'limit' => [Query::limit(5)],
            'offset' => [Query::offset(5)],
            'select' => [Query::select(['id'])],
            'orderAsc' => [Query::orderAsc('id')],
            'orderDesc' => [Query::orderDesc('id')],
            'orderRandom' => [Query::orderRandom()],
            'cursorAfter' => [Query::cursorAfter(['id' => 'a'])],
            'cursorBefore' => [Query::cursorBefore(['id' => 'a'])],
            'count' => [Query::count('*', 'total')],
            'sum' => [Query::sum('amount')],
            'groupBy' => [Query::groupBy(['status'])],
            'having' => [Query::having([Query::greaterThan('total', 1)])],
            'distinct' => [Query::distinct()],
            'join' => [Query::join('items', 'i', [Query::on('ord.id', 'i.order_id')])],
            'crossJoin' => [Query::crossJoin('colors', 'c')],
            'union' => [Query::union([Query::equal('a', [1])])],
            'vectorCosine' => [Query::vectorCosine('embedding', [0.1, 0.2])],
            'elemMatch' => [Query::elemMatch('tags', [Query::equal('name', ['a'])])],
            'raw' => [Query::raw('1 = 1')],
        ];
    }

    #[DataProvider('nonConditions')]
    public function testOnListRejectsMemberThatIsNotACondition(Query $member): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Join ON accepts only on() and filter conditions, got: ' . $member->getMethod()->value);

        Query::join('orders', 'ord', [Query::on('users.id', 'ord.user_id'), $member]);
    }

    #[DataProvider('nonConditions')]
    public function testOnListRejectsMemberNestedInLogicalCondition(Query $member): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Join ON accepts only on() and filter conditions, got: ' . $member->getMethod()->value);

        Query::join('orders', 'ord', [
            Query::on('users.id', 'ord.user_id'),
            Query::or([Query::equal('ord.status', ['paid']), $member]),
        ]);
    }

    /**
     * @return array<string, array{Query}>
     */
    public static function conditions(): array
    {
        return [
            'on' => [Query::on('users.id', 'ord.user_id', '!=')],
            'equal' => [Query::equal('ord.status', ['paid'])],
            'between' => [Query::between('ord.total', 1, 10)],
            'isNull' => [Query::isNull('ord.deleted_at')],
            'search' => [Query::search('ord.note', 'gift')],
            'containsAll' => [Query::containsAll('ord.tags', ['a', 'b'])],
            'intersects' => [Query::intersects('ord.area', [[0, 0], [1, 1]])],
            'jsonContains' => [Query::jsonContains('ord.meta', 'x')],
            'and' => [Query::and([Query::equal('ord.status', ['paid']), Query::isNotNull('ord.paid_at')])],
            'or' => [Query::or([Query::equal('ord.status', ['paid']), Query::equal('ord.status', ['refunded'])])],
        ];
    }

    #[DataProvider('conditions')]
    public function testOnListAcceptsCondition(Query $condition): void
    {
        $query = Query::join('orders', 'ord', [$condition]);

        $this->assertSame([$condition], $query->getJoinOnQueries());
    }

    public function testCrossJoinRejectsConditionsWhenParsed(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Join ON conditions are not allowed in crossJoin');

        Query::parseQuery([
            'method' => 'crossJoin',
            'attribute' => 'colors',
            'alias' => 'c',
            'values' => [Query::on('a', 'b')->toArray()],
        ]);
    }

    public function testAliasSurvivesIntoSql(): void
    {
        $query = Query::leftJoin('orders', 'ord', [
            Query::on('users.id', 'ord.customer_id'),
            Query::equal('ord.status', ['paid']),
        ]);
        $builder = new MySQL();

        $this->assertSame(
            'LEFT JOIN `orders` AS `ord` ON `users`.`id` = `ord`.`customer_id` AND `ord`.`status` IN (?)',
            $query->compile($builder),
        );
        $this->assertSame(['paid'], $builder->getBindings());
    }

    public function testJoinCompileDispatch(): void
    {
        $this->assertSame(
            'JOIN `orders` AS `o` ON `users`.`id` = `o`.`uid`',
            Query::join('orders', 'o', [Query::on('users.id', 'o.uid')])->compile(new MySQL()),
        );
        $this->assertSame(
            'RIGHT JOIN `orders` AS `o` ON `users`.`id` = `o`.`uid`',
            Query::rightJoin('orders', 'o', [Query::on('users.id', 'o.uid')])->compile(new MySQL()),
        );
        $this->assertSame(
            'CROSS JOIN `colors` AS `c`',
            Query::crossJoin('colors', 'c')->compile(new MySQL()),
        );
    }

    /**
     * @param  Closure(string, string, list<Query>): Query  $factory
     */
    #[DataProvider('conditionedJoins')]
    public function testConditionedJoinRoundTripsThroughArray(Method $method, Closure $factory): void
    {
        $query = $factory('orders', 'ord', [
            Query::on('users.id', 'ord.user_id'),
            Query::equal('ord.status', ['paid']),
        ]);

        $array = $query->toArray();
        $this->assertSame('ord', $array['alias']);
        $this->assertSame([
            Query::on('users.id', 'ord.user_id')->toArray(),
            Query::equal('ord.status', ['paid'])->toArray(),
        ], $array['values']);

        $parsed = Query::parseQuery($array);
        $this->assertSame($method, $parsed->getMethod());
        $this->assertSame('orders', $parsed->getAttribute());
        $this->assertSame('ord', $parsed->getAlias());
        $this->assertSame($array, $parsed->toArray());
    }

    /**
     * @param  Closure(string, string): Query  $factory
     */
    #[DataProvider('unconditionedJoins')]
    public function testUnconditionedJoinRoundTripsThroughString(Method $method, Closure $factory): void
    {
        $parsed = Query::parse($factory('colors', 'c')->toString());

        $this->assertSame($method, $parsed->getMethod());
        $this->assertSame('colors', $parsed->getAttribute());
        $this->assertSame('c', $parsed->getAlias());
        $this->assertSame([], $parsed->getValues());
    }

    public function testParsesLegacyColumnFormWithAliasAtValueThree(): void
    {
        $parsed = Query::parseQuery([
            'method' => 'leftJoin',
            'attribute' => 'orders',
            'values' => ['users.id', '!=', 'ord.user_id', 'ord'],
        ]);

        $this->assertSame(Method::LeftJoin, $parsed->getMethod());
        $this->assertSame('ord', $parsed->getAlias());
        $this->assertSame([Query::on('users.id', 'ord.user_id', '!=')->toArray()], $parsed->toArray()['values']);
        $this->assertSame('LEFT JOIN `orders` AS `ord` ON `users`.`id` != `ord`.`user_id`', $parsed->compile(new MySQL()));
    }

    public function testParsesLegacyAliasFirstJoin(): void
    {
        $parsed = Query::parseQuery([
            'method' => 'join',
            'attribute' => 'orders',
            'values' => ['ord', Query::on('users.id', 'ord.user_id')->toArray()],
        ]);

        $this->assertSame('ord', $parsed->getAlias());
        $this->assertCount(1, $parsed->getJoinOnQueries());
        $this->assertSame(Method::On, $parsed->getJoinOnQueries()[0]->getMethod());
    }

    /**
     * @param  Closure(string, string): Query  $factory
     */
    #[DataProvider('unconditionedJoins')]
    public function testParsesLegacyUnconditionedJoinWithAliasAtValueZero(Method $method, Closure $factory): void
    {
        $parsed = Query::parseQuery([
            'method' => $method->value,
            'attribute' => 'colors',
            'values' => ['c'],
        ]);

        $this->assertSame('c', $parsed->getAlias());
        $this->assertSame([], $parsed->getValues());
    }

    public function testParsedAliasKeyWinsOverLegacyPosition(): void
    {
        $parsed = Query::parseQuery([
            'method' => 'crossJoin',
            'attribute' => 'colors',
            'alias' => 'c',
            'values' => ['legacy'],
        ]);

        $this->assertSame('c', $parsed->getAlias());
    }

    public function testParseRejectsJoinWithoutAlias(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Join alias is required: join orders');

        Query::parseQuery([
            'method' => 'join',
            'attribute' => 'orders',
            'values' => ['users.id', '=', 'orders.user_id'],
        ]);
    }

    public function testParseRejectsNonConditionInOnList(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Join ON accepts only on() and filter conditions, got: limit');

        Query::parseQuery([
            'method' => 'join',
            'attribute' => 'orders',
            'alias' => 'ord',
            'values' => [Query::on('users.id', 'ord.user_id')->toArray(), Query::limit(1)->toArray()],
        ]);
    }

    public function testParseRejectsNonStringAlias(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Invalid query alias. Must be a string, got integer');

        Query::parseQuery(['method' => 'join', 'attribute' => 'orders', 'alias' => 5, 'values' => []]);
    }

    public function testParseRejectsUnexpectedJoinValue(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Invalid join value. Must be a query or a string, got integer');

        Query::parseQuery(['method' => 'join', 'attribute' => 'orders', 'alias' => 'ord', 'values' => [5]]);
    }

    public function testParseRejectsExtraLegacyNames(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Invalid join values for join orders');

        Query::parseQuery([
            'method' => 'join',
            'attribute' => 'orders',
            'values' => ['ord', 'extra', Query::on('users.id', 'ord.user_id')->toArray()],
        ]);
    }

    public function testJoinMethodsAreJoin(): void
    {
        $joinMethods = \array_filter(Method::cases(), fn (Method $method): bool => $method->isJoin());

        $this->assertCount(6, $joinMethods);
        $this->assertFalse(Method::On->isJoin());
    }

    public function testOn(): void
    {
        $query = Query::on('$id', 'customerId');
        $this->assertSame(Method::On, $query->getMethod());
        $this->assertSame('', $query->getAttribute());
        $this->assertSame(['$id', '=', 'customerId'], $query->getValues());
    }

    public function testOnWithOperator(): void
    {
        $query = Query::on('a.id', 'b.aid', '!=');
        $this->assertSame(['a.id', '!=', 'b.aid'], $query->getValues());
    }

    public function testOnCompileRequiresColumns(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Join ON requires left and right columns');
        Query::on('', 'customerId')->compile(new MySQL());
    }

    public function testOnCompileRejectsInvalidOperator(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid join operator: LIKE');
        Query::on('$id', 'customerId', 'LIKE')->compile(new MySQL());
    }

    public function testNestedJoinRejectsSearchOnCompile(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unsupported join ON condition: search');
        Query::leftJoin('orders', 'ord', [
            Query::on('$id', 'customerId'),
            Query::search('ord.status', 'paid'),
        ])->compile(new MySQL());
    }

    public function testNestedJoinRejectsRegexOnCompile(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unsupported join ON condition: regex');
        Query::leftJoin('orders', 'ord', [
            Query::on('$id', 'customerId'),
            Query::regex('ord.status', 'paid'),
        ])->compile(new MySQL());
    }

    public function testNestedJoinShapeIncludesOnQueries(): void
    {
        $query = Query::leftJoin('orders', 'ord', [
            Query::on('$id', 'customerId'),
            Query::equal('ord.status', ['paid']),
        ]);

        $this->assertSame('leftJoin:orders(equal:ord.status|on:)', $query->shape());
    }

    public function testJoinIsNotNested(): void
    {
        $this->assertFalse(Query::join('t', 'a', [Query::on('a.id', 'b.id')])->isNested());
    }
}
