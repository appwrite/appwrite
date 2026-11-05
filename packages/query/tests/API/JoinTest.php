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
    public function testConditionedJoinWithoutAliasIsReadUnderItsCollection(Method $method, Closure $factory): void
    {
        $on = Query::on('users.id', 'orders.user_id');

        $query = $factory('orders', '', [$on]);

        $this->assertSame($method, $query->getMethod());
        $this->assertSame('orders', $query->getAttribute());
        $this->assertSame('orders', $query->getAlias());
        $this->assertTrue($query->isAliasDefaulted());
        $this->assertSame([$on], $query->getJoinOnQueries());
    }

    /**
     * @param  Closure(string, string): Query  $factory
     */
    #[DataProvider('unconditionedJoins')]
    public function testUnconditionedJoinWithoutAliasIsReadUnderItsCollection(Method $method, Closure $factory): void
    {
        $query = $factory('colors', '');

        $this->assertSame($method, $query->getMethod());
        $this->assertSame('colors', $query->getAlias());
        $this->assertTrue($query->isAliasDefaulted());
        $this->assertSame([], $query->getValues());
    }

    /**
     * @param  Closure(string, string, list<Query>): Query  $factory
     */
    #[DataProvider('conditionedJoins')]
    public function testConditionedJoinWithAliasIsNotDefaulted(Method $method, Closure $factory): void
    {
        $this->assertFalse($factory('orders', 'ord', [Query::on('users.id', 'ord.user_id')])->isAliasDefaulted());
    }

    /**
     * @param  Closure(string, string): Query  $factory
     */
    #[DataProvider('unconditionedJoins')]
    public function testUnconditionedJoinWithAliasIsNotDefaulted(Method $method, Closure $factory): void
    {
        $this->assertFalse($factory('colors', 'c')->isAliasDefaulted());
    }

    public function testJoinAliasedAsItsCollectionCountsAsDefaulted(): void
    {
        $this->assertTrue(Query::join('orders', 'orders', [Query::on('users.id', 'orders.user_id')])->isAliasDefaulted());
    }

    public function testQueriesOtherThanJoinsAreNeverDefaulted(): void
    {
        $this->assertFalse(Query::count('total', 'total')->isAliasDefaulted());
        $this->assertFalse(new Query(Method::Equal, 'status', ['paid'], 'status')->isAliasDefaulted());
    }

    public function testBuilderJoinWithoutAliasStaysUnaliased(): void
    {
        $query = new Query(Method::Join, 'orders', [Query::on('users.id', 'orders.user_id')]);

        $this->assertSame('', $query->getAlias());
        $this->assertFalse($query->isAliasDefaulted());
        $this->assertSame('JOIN `orders` ON `users`.`id` = `orders`.`user_id`', $query->compile(new MySQL()));
    }

    public function testDefaultedJoinCompilesUnderItsCollection(): void
    {
        $this->assertSame(
            'JOIN `orders` AS `orders` ON `users`.`id` = `orders`.`user_id`',
            Query::join('orders', '', [Query::on('users.id', 'orders.user_id')])->compile(new MySQL()),
        );
        $this->assertSame('CROSS JOIN `colors` AS `colors`', Query::crossJoin('colors', '')->compile(new MySQL()));
    }

    /**
     * A consumer may point the join at another table after it is built, as Appwrite points a
     * public collection ID at its internal table. The alias and the defaulted state stay with the
     * name the caller gave, so rows are never keyed by the internal table.
     */
    public function testDefaultedAliasSurvivesRenamingTheCollection(): void
    {
        $query = Query::join('orders', '', [Query::on('users.id', 'orders.user_id')]);

        $renamed = (clone $query)->setAttribute('database_1_collection_7');

        $this->assertSame('orders', $renamed->getAlias());
        $this->assertTrue($renamed->isAliasDefaulted());
        $this->assertSame('orders', $renamed->toArray()['alias']);
    }

    /**
     * The serialized form carries the alias itself, so a join serialized after its collection was
     * renamed is parsed under the same alias, and read as an alias given explicitly.
     */
    public function testRenamedJoinSerializesItsAliasExplicitly(): void
    {
        $renamed = (clone Query::join('orders', '', [Query::on('users.id', 'orders.user_id')]))->setAttribute('database_1_collection_7');

        $parsed = Query::parseQuery($renamed->toArray());

        $this->assertSame('database_1_collection_7', $parsed->getAttribute());
        $this->assertSame('orders', $parsed->getAlias());
        $this->assertFalse($parsed->isAliasDefaulted());
    }

    /**
     * An unaliased builder join has no alias to serialize, so parsing it back reads it under its
     * collection like any join given no alias.
     */
    public function testBuilderJoinWithoutAliasParsesUnderItsCollection(): void
    {
        $parsed = Query::parseQuery(new Query(Method::Join, 'orders', [Query::on('users.id', 'orders.user_id')])->toArray());

        $this->assertSame('orders', $parsed->getAlias());
        $this->assertTrue($parsed->isAliasDefaulted());
    }

    /**
     * @param  Closure(string, string, list<Query>): Query  $factory
     */
    #[DataProvider('conditionedJoins')]
    public function testConditionedJoinWithoutAliasOrCollectionIsRejected(Method $method, Closure $factory): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Join alias is required: ' . $method->value . ' ');

        $factory('', '', [Query::on('users.id', 'orders.user_id')]);
    }

    /**
     * @param  Closure(string, string): Query  $factory
     */
    #[DataProvider('unconditionedJoins')]
    public function testUnconditionedJoinWithoutAliasOrCollectionIsRejected(Method $method, Closure $factory): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Join alias is required: ' . $method->value . ' ');

        $factory('', '');
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
            'search' => [Query::search('ord.note', 'gift')],
            'regex' => [Query::regex('ord.note', '^g')],
            'containsAll' => [Query::containsAll('ord.tags', ['a', 'b'])],
            'exists' => [Query::exists(['ord.note'])],
            'intersects' => [Query::intersects('ord.area', [[0, 0], [1, 1]])],
            'jsonContains' => [Query::jsonContains('ord.meta', 'x')],
        ];
    }

    #[DataProvider('nonConditions')]
    public function testOnListRejectsMemberThatIsNotACondition(Query $member): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unsupported join ON condition: ' . $member->getMethod()->value);

        Query::join('orders', 'ord', [Query::on('users.id', 'ord.user_id'), $member]);
    }

    #[DataProvider('nonConditions')]
    public function testOnListRejectsMemberNestedInLogicalCondition(Query $member): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unsupported join ON condition: ' . $member->getMethod()->value);

        Query::join('orders', 'ord', [
            Query::on('users.id', 'ord.user_id'),
            Query::or([Query::equal('ord.status', ['paid']), $member]),
        ]);
    }

    /**
     * @return array<string, array{Query, string}>
     */
    public static function conditions(): array
    {
        return [
            'on' => [Query::on('users.id', 'ord.user_id', '!='), '`users`.`id` != `ord`.`user_id`'],
            'equal' => [Query::equal('ord.status', ['paid']), '`ord`.`status` IN (?)'],
            'notEqual' => [Query::notEqual('ord.status', 'void'), '`ord`.`status` != ?'],
            'greaterThan' => [Query::greaterThan('ord.total', 5), '`ord`.`total` > ?'],
            'between' => [Query::between('ord.total', 1, 10), '`ord`.`total` BETWEEN ? AND ?'],
            'isNull' => [Query::isNull('ord.deleted_at'), '`ord`.`deleted_at` IS NULL'],
            'startsWith' => [Query::startsWith('ord.code', 'A'), '`ord`.`code` LIKE ?'],
            'and' => [
                Query::and([Query::equal('ord.status', ['paid']), Query::isNotNull('ord.paid_at')]),
                '(`ord`.`status` IN (?) AND `ord`.`paid_at` IS NOT NULL)',
            ],
            'or' => [
                Query::or([Query::equal('ord.status', ['paid']), Query::equal('ord.status', ['refunded'])]),
                '(`ord`.`status` IN (?) OR `ord`.`status` IN (?))',
            ],
        ];
    }

    #[DataProvider('conditions')]
    public function testOnListAcceptsAndCompilesCondition(Query $condition, string $sql): void
    {
        $query = Query::join('orders', 'ord', [$condition]);

        $this->assertSame([$condition], $query->getJoinOnQueries());
        $this->assertSame('JOIN `orders` AS `ord` ON ' . $sql, $query->compile(new MySQL()));
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

    /**
     * @return array<string, array{array<string, mixed>, Method, list<array<string, mixed>>}>
     */
    public static function joinsWithoutAlias(): array
    {
        $on = Query::on('users.id', 'orders.user_id')->toArray();

        return [
            'nested ON form' => [['method' => 'join', 'attribute' => 'orders', 'values' => [$on]], Method::Join, [$on]],
            'empty alias property' => [['method' => 'leftJoin', 'attribute' => 'orders', 'alias' => '', 'values' => [$on]], Method::LeftJoin, [$on]],
            'empty legacy alias first' => [['method' => 'rightJoin', 'attribute' => 'orders', 'values' => ['', $on]], Method::RightJoin, [$on]],
            'column form' => [['method' => 'join', 'attribute' => 'orders', 'values' => ['users.id', '=', 'orders.user_id']], Method::Join, [$on]],
            'column form with an empty alias' => [['method' => 'fullOuterJoin', 'attribute' => 'orders', 'values' => ['users.id', '=', 'orders.user_id', '']], Method::FullOuterJoin, [$on]],
            'crossJoin' => [['method' => 'crossJoin', 'attribute' => 'orders', 'values' => []], Method::CrossJoin, []],
            'crossJoin with an empty legacy alias' => [['method' => 'crossJoin', 'attribute' => 'orders', 'values' => ['']], Method::CrossJoin, []],
            'naturalJoin' => [['method' => 'naturalJoin', 'attribute' => 'orders'], Method::NaturalJoin, []],
        ];
    }

    /**
     * @param  array<string, mixed>  $array
     * @param  list<array<string, mixed>>  $on
     */
    #[DataProvider('joinsWithoutAlias')]
    public function testParsedJoinWithoutAliasIsReadUnderItsCollection(array $array, Method $method, array $on): void
    {
        $parsed = Query::parseQuery($array);

        $this->assertSame($method, $parsed->getMethod());
        $this->assertSame('orders', $parsed->getAttribute());
        $this->assertSame('orders', $parsed->getAlias());
        $this->assertTrue($parsed->isAliasDefaulted());
        $this->assertSame($on, $parsed->toArray()['values']);
    }

    /**
     * @param  array<string, mixed>  $array
     * @param  list<array<string, mixed>>  $on
     */
    #[DataProvider('joinsWithoutAlias')]
    public function testParsedJoinWithoutAliasRoundTrips(array $array, Method $method, array $on): void
    {
        $parsed = Query::parseQuery($array);
        $serialized = $parsed->toArray();

        $this->assertSame('orders', $serialized['alias']);

        $fromArray = Query::parseQuery($serialized);
        $this->assertSame($serialized, $fromArray->toArray());
        $this->assertTrue($fromArray->isAliasDefaulted());

        $fromString = Query::parse($parsed->toString());
        $this->assertSame($serialized, $fromString->toArray());
        $this->assertTrue($fromString->isAliasDefaulted());
    }

    public function testParsedJoinWithAliasRoundTripsAsNotDefaulted(): void
    {
        $parsed = Query::parse(Query::join('orders', 'ord', [Query::on('users.id', 'ord.user_id')])->toString());

        $this->assertSame('ord', $parsed->getAlias());
        $this->assertFalse($parsed->isAliasDefaulted());
    }

    public function testParseRejectsJoinWithoutAliasOrCollection(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Join alias is required: join ');

        Query::parseQuery([
            'method' => 'join',
            'values' => ['users.id', '=', 'orders.user_id'],
        ]);
    }

    public function testParseRejectsNonConditionInOnList(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unsupported join ON condition: limit');

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

    public function testBuilderRejectsConditionOfAJoinBuiltWithoutTheFactory(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unsupported join ON condition: search');

        new Query(Method::LeftJoin, 'orders', [
            Query::on('$id', 'customerId'),
            Query::search('ord.status', 'paid'),
        ], 'ord')->compile(new MySQL());
    }

    public function testJoinConditionMethods(): void
    {
        $this->assertTrue(Method::On->isJoinCondition());
        $this->assertTrue(Method::Equal->isJoinCondition());
        $this->assertTrue(Method::Or->isJoinCondition());
        $this->assertFalse(Method::Search->isJoinCondition());
        $this->assertFalse(Method::Limit->isJoinCondition());
        $this->assertFalse(Method::Join->isJoinCondition());
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
