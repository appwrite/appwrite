<?php

namespace Tests\Query\Builder;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\Feature\Aggregates;
use Utopia\Query\Builder\Feature\CrossJoins;
use Utopia\Query\Builder\Feature\CTEs;
use Utopia\Query\Builder\Feature\Deletes;
use Utopia\Query\Builder\Feature\FullTextSearch;
use Utopia\Query\Builder\Feature\Hooks;
use Utopia\Query\Builder\Feature\Inserts;
use Utopia\Query\Builder\Feature\Joins;
use Utopia\Query\Builder\Feature\MongoDB\ArrayPushModifiers;
use Utopia\Query\Builder\Feature\MongoDB\AtlasSearch;
use Utopia\Query\Builder\Feature\MongoDB\ConditionalArrayUpdates;
use Utopia\Query\Builder\Feature\MongoDB\FieldUpdates;
use Utopia\Query\Builder\Feature\MongoDB\PipelineStages;
use Utopia\Query\Builder\Feature\NegatedFullTextSearch;
use Utopia\Query\Builder\Feature\RawSql;
use Utopia\Query\Builder\Feature\Selects;
use Utopia\Query\Builder\Feature\TableSampling;
use Utopia\Query\Builder\Feature\Unions;
use Utopia\Query\Builder\Feature\Updates;
use Utopia\Query\Builder\Feature\Upsert;
use Utopia\Query\Builder\Feature\Windows;
use Utopia\Query\Builder\MongoDB as Builder;
use Utopia\Query\Builder\MongoDB\Operation;
use Utopia\Query\Builder\MongoDB\UpdateOperator;
use Utopia\Query\Builder\Statement;
use Utopia\Query\Compiler;
use Utopia\Query\Exception\UnsupportedException;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Method;
use Utopia\Query\Query;

class MongoDBTest extends TestCase
{
    use AssertsBindingCount;

    public function testImplementsCompiler(): void
    {
        $this->assertInstanceOf(Compiler::class, new Builder());
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

    public function testImplementsWindows(): void
    {
        $this->assertInstanceOf(Windows::class, new Builder());
    }

    public function testImplementsUpsert(): void
    {
        $this->assertInstanceOf(Upsert::class, new Builder());
    }

    public function testImplementsFullTextSearch(): void
    {
        $this->assertInstanceOf(FullTextSearch::class, new Builder());
    }

    public function testImplementsTableSampling(): void
    {
        $this->assertInstanceOf(TableSampling::class, new Builder());
    }

    public function testBasicSelect(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['name', 'email'])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('users', $op['collection']);
        $this->assertSame('find', $op['operation']);
        $this->assertSame(['name' => 1, 'email' => 1, '_id' => 0], $op['projection']);
        $this->assertEmpty($result->bindings);
    }

    public function testSelectAll(): void
    {
        $result = (new Builder())
            ->from('users')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('find', $op['operation']);
        $this->assertArrayNotHasKey('projection', $op);
    }

    public function testFilterEqual(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('status', ['active'])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['status' => '?'], $op['filter']);
        $this->assertSame(['active'], $result->bindings);
    }

    public function testFilterEqualMultipleValues(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('status', ['active', 'pending'])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['status' => ['$in' => ['?', '?']]], $op['filter']);
        $this->assertSame(['active', 'pending'], $result->bindings);
    }

    public function testFilterNotEqual(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::notEqual('status', ['deleted'])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['status' => ['$ne' => '?']], $op['filter']);
        $this->assertSame(['deleted'], $result->bindings);
    }

    public function testFilterNotEqualMultipleValues(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::notEqual('status', ['deleted', 'banned'])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['status' => ['$nin' => ['?', '?']]], $op['filter']);
        $this->assertSame(['deleted', 'banned'], $result->bindings);
    }

    public function testFilterGreaterThan(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::greaterThan('age', 25)])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['age' => ['$gt' => '?']], $op['filter']);
        $this->assertSame([25], $result->bindings);
    }

    public function testFilterLessThan(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::lessThan('age', 30)])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['age' => ['$lt' => '?']], $op['filter']);
        $this->assertSame([30], $result->bindings);
    }

    public function testFilterGreaterThanEqual(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::greaterThanEqual('age', 18)])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['age' => ['$gte' => '?']], $op['filter']);
        $this->assertSame([18], $result->bindings);
    }

    public function testFilterLessThanEqual(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::lessThanEqual('age', 65)])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['age' => ['$lte' => '?']], $op['filter']);
        $this->assertSame([65], $result->bindings);
    }

    public function testFilterBetween(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::between('age', 18, 65)])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['age' => ['$gte' => '?', '$lte' => '?']], $op['filter']);
        $this->assertSame([18, 65], $result->bindings);
    }

    public function testFilterNotBetween(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::notBetween('age', 18, 65)])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$or' => [
            ['age' => ['$lt' => '?']],
            ['age' => ['$gt' => '?']],
        ]], $op['filter']);
        $this->assertSame([18, 65], $result->bindings);
    }

    public function testFilterStartsWith(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::startsWith('name', 'Al')])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['name' => ['$regex' => '?']], $op['filter']);
        $this->assertSame(['^Al'], $result->bindings);
    }

    public function testFilterEndsWith(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::endsWith('email', '.com')])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['email' => ['$regex' => '?']], $op['filter']);
        $this->assertSame(['\.com$'], $result->bindings);
    }

    public function testFilterContains(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::containsString('name', ['test'])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['name' => ['$regex' => '?']], $op['filter']);
        $this->assertSame(['test'], $result->bindings);
    }

    public function testFilterNotContains(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::notContains('name', ['test'])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['name' => ['$not' => ['$regex' => '?']]], $op['filter']);
        $this->assertSame(['test'], $result->bindings);
    }

    public function testFilterRegex(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::regex('email', '^[a-z]+@test\\.com$')])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['email' => ['$regex' => '?']], $op['filter']);
        $this->assertSame(['^[a-z]+@test\\.com$'], $result->bindings);
    }

    public function testFilterIsNull(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::isNull('deleted_at')])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['deleted_at' => null], $op['filter']);
        $this->assertEmpty($result->bindings);
    }

    public function testFilterIsNotNull(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::isNotNull('email')])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['email' => ['$ne' => null]], $op['filter']);
        $this->assertEmpty($result->bindings);
    }

    public function testFilterOr(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::or([
                Query::equal('status', ['active']),
                Query::greaterThan('age', 18),
            ])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$or' => [
            ['status' => '?'],
            ['age' => ['$gt' => '?']],
        ]], $op['filter']);
        $this->assertSame(['active', 18], $result->bindings);
    }

    public function testFilterAnd(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::and([
                Query::equal('status', ['active']),
                Query::greaterThan('age', 18),
            ])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$and' => [
            ['status' => '?'],
            ['age' => ['$gt' => '?']],
        ]], $op['filter']);
        $this->assertSame(['active', 18], $result->bindings);
    }

    public function testMultipleFiltersProduceAnd(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([
                Query::equal('status', ['active']),
                Query::greaterThan('age', 25),
            ])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$and' => [
            ['status' => '?'],
            ['age' => ['$gt' => '?']],
        ]], $op['filter']);
        $this->assertSame(['active', 25], $result->bindings);
    }

    public function testSortAscAndDesc(): void
    {
        $result = (new Builder())
            ->from('users')
            ->sortAsc('name')
            ->sortDesc('age')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['name' => 1, 'age' => -1], $op['sort']);
    }

    public function testLimitAndOffset(): void
    {
        $result = (new Builder())
            ->from('users')
            ->limit(10)
            ->offset(20)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(10, $op['limit']);
        $this->assertSame(20, $op['skip']);
    }

    public function testInsertSingleRow(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'Alice', 'email' => 'alice@test.com', 'age' => 30])
            ->insert();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('users', $op['collection']);
        $this->assertSame('insertMany', $op['operation']);
        /** @var list<array<string, mixed>> $documents */
        $documents = $op['documents'];
        $this->assertCount(1, $documents);
        $this->assertSame(['name' => '?', 'email' => '?', 'age' => '?'], $documents[0]);
        $this->assertSame(['Alice', 'alice@test.com', 30], $result->bindings);
    }

    public function testInsertMultipleRows(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'Alice', 'age' => 30])
            ->set(['name' => 'Bob', 'age' => 25])
            ->insert();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $documents */
        $documents = $op['documents'];
        $this->assertCount(2, $documents);
        $this->assertSame(['Alice', 30, 'Bob', 25], $result->bindings);
    }

    public function testUpdateWithSet(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['city' => 'New York'])
            ->filter([Query::equal('name', ['Alice'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('users', $op['collection']);
        $this->assertSame('updateMany', $op['operation']);
        $this->assertSame(['$set' => ['city' => '?']], $op['update']);
        $this->assertSame(['name' => '?'], $op['filter']);
        $this->assertSame(['Alice', 'New York'], $result->bindings);
    }

    public function testUpdateWithIncrement(): void
    {
        $result = (new Builder())
            ->from('users')
            ->increment('login_count', 1)
            ->filter([Query::equal('name', ['Alice'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$inc' => ['login_count' => 1]], $op['update']);
        $this->assertSame(['Alice'], $result->bindings);
    }

    public function testUpdateWithPush(): void
    {
        $result = (new Builder())
            ->from('users')
            ->push('tags', 'admin')
            ->filter([Query::equal('name', ['Alice'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$push' => ['tags' => '?']], $op['update']);
        $this->assertSame(['Alice', 'admin'], $result->bindings);
    }

    public function testUpdateWithPull(): void
    {
        $result = (new Builder())
            ->from('users')
            ->pull('tags', 'guest')
            ->filter([Query::equal('name', ['Alice'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$pull' => ['tags' => '?']], $op['update']);
        $this->assertSame(['Alice', 'guest'], $result->bindings);
    }

    public function testUpdateWithAddToSet(): void
    {
        $result = (new Builder())
            ->from('users')
            ->addToSet('roles', 'editor')
            ->filter([Query::equal('name', ['Alice'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$addToSet' => ['roles' => '?']], $op['update']);
        $this->assertSame(['Alice', 'editor'], $result->bindings);
    }

    public function testUpdateWithUnset(): void
    {
        $result = (new Builder())
            ->from('users')
            ->unsetFields('deprecated_field')
            ->filter([Query::equal('name', ['Alice'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$unset' => ['deprecated_field' => '']], $op['update']);
        $this->assertSame(['Alice'], $result->bindings);
    }

    public function testDelete(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('status', ['deleted'])])
            ->delete();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('users', $op['collection']);
        $this->assertSame('deleteMany', $op['operation']);
        $this->assertSame(['status' => '?'], $op['filter']);
        $this->assertSame(['deleted'], $result->bindings);
    }

    public function testDeleteWithoutFilter(): void
    {
        $result = (new Builder())
            ->from('users')
            ->delete();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('deleteMany', $op['operation']);
        $this->assertEmpty((array) $op['filter']);
    }

    public function testGroupByWithCount(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['country'])
            ->count('*', 'cnt')
            ->groupBy(['country'])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);

        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $groupStage = $this->findStage($pipeline, '$group');
        $this->assertNotNull($groupStage);
        /** @var array<string, mixed> $groupBody */
        $groupBody = $groupStage['$group'];
        $this->assertSame('$country', $groupBody['_id']);
        $this->assertSame(['$sum' => 1], $groupBody['cnt']);
    }

    public function testGroupByWithMultipleAggregates(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->select(['user_id'])
            ->sum('amount', 'total')
            ->avg('amount', 'average')
            ->groupBy(['user_id'])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $groupStage = $this->findStage($pipeline, '$group');
        $this->assertNotNull($groupStage);
        /** @var array<string, mixed> $groupBody */
        $groupBody = $groupStage['$group'];
        $this->assertSame(['$sum' => '$amount'], $groupBody['total']);
        $this->assertSame(['$avg' => '$amount'], $groupBody['average']);
    }

    public function testGroupByWithHaving(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'cnt')
            ->groupBy(['user_id'])
            ->having([Query::greaterThan('cnt', 5)])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $groupIdx = $this->findStageIndex($pipeline, '$group');
        $this->assertNotNull($groupIdx);

        // HAVING $match should come after $group
        $matchStages = [];
        for ($i = $groupIdx + 1; $i < \count($pipeline); $i++) {
            if (isset($pipeline[$i]['$match'])) {
                $matchStages[] = $pipeline[$i];
            }
        }
        $this->assertNotEmpty($matchStages);
    }

    public function testDistinct(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['country'])
            ->distinct()
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);

        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $groupStage = $this->findStage($pipeline, '$group');
        $this->assertNotNull($groupStage);
    }

    public function testJoin(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->select(['orders.id', 'users.name'])
            ->join('users', 'orders.user_id', 'users.id', '=', 'u')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);

        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $lookupStage = $this->findStage($pipeline, '$lookup');
        $this->assertNotNull($lookupStage);
        /** @var array<string, mixed> $lookupBody */
        $lookupBody = $lookupStage['$lookup'];
        $this->assertSame('users', $lookupBody['from']);
        $this->assertSame('user_id', $lookupBody['localField']);
        $this->assertSame('id', $lookupBody['foreignField']);
        $this->assertSame('u', $lookupBody['as']);
    }

    public function testLeftJoin(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->leftJoin('users', 'orders.user_id', 'users.id', '=', 'u')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $unwindStage = $this->findStage($pipeline, '$unwind');
        $this->assertNotNull($unwindStage);
        $this->assertIsArray($unwindStage['$unwind']);
        /** @var array<string, mixed> $unwindBody */
        $unwindBody = $unwindStage['$unwind'];
        $this->assertTrue($unwindBody['preserveNullAndEmptyArrays']);
    }

    public function testUnionAll(): void
    {
        $first = (new Builder())
            ->from('users')
            ->select(['name'])
            ->filter([Query::equal('country', ['US'])]);

        $second = (new Builder())
            ->from('users')
            ->select(['name'])
            ->filter([Query::equal('country', ['UK'])]);

        $result = $first->unionAll($second)->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);

        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $unionStage = $this->findStage($pipeline, '$unionWith');
        $this->assertNotNull($unionStage);
        /** @var array<string, mixed> $unionBody */
        $unionBody = $unionStage['$unionWith'];
        $this->assertSame('users', $unionBody['coll']);
    }

    public function testUpsert(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['email' => 'alice@test.com', 'name' => 'Alice Updated', 'age' => 31])
            ->onConflict(['email'], ['name', 'age'])
            ->upsert();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('updateOne', $op['operation']);
        $this->assertSame(['email' => '?'], $op['filter']);
        $this->assertSame(['$set' => ['name' => '?', 'age' => '?']], $op['update']);
        /** @var array<string, mixed> $options */
        $options = $op['options'];
        $this->assertTrue($options['upsert']);
        $this->assertSame(['alice@test.com', 'Alice Updated', 31], $result->bindings);
    }

    public function testInsertOrIgnore(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'Alice', 'email' => 'alice@test.com'])
            ->insertOrIgnore();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('insertMany', $op['operation']);
        /** @var array<string, mixed> $options */
        $options = $op['options'];
        $this->assertFalse($options['ordered']);
    }

    public function testWindowFunction(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->select(['user_id', 'amount'])
            ->selectWindow('ROW_NUMBER()', 'rn', ['user_id'], ['-amount'])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);

        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $windowStage = $this->findStage($pipeline, '$setWindowFields');
        $this->assertNotNull($windowStage);
        /** @var array<string, mixed> $windowBody */
        $windowBody = $windowStage['$setWindowFields'];
        /** @var array<string, mixed> $output */
        $output = $windowBody['output'];
        $this->assertArrayHasKey('rn', $output);
    }

    public function testFilterWhereInSubquery(): void
    {
        $subquery = (new Builder())
            ->from('orders')
            ->select(['user_id'])
            ->filter([Query::equal('status', ['completed'])]);

        $result = (new Builder())
            ->from('users')
            ->select(['name'])
            ->filterWhereIn('id', $subquery)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);

        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $lookupStage = $this->findStage($pipeline, '$lookup');
        $this->assertNotNull($lookupStage);
        /** @var array<string, mixed> $lookupBody */
        $lookupBody = $lookupStage['$lookup'];
        $this->assertSame('orders', $lookupBody['from']);
    }

    public function testSortRandom(): void
    {
        $result = (new Builder())
            ->from('users')
            ->sortRandom()
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);

        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $addFieldsStage = $this->findStage($pipeline, '$addFields');
        $this->assertNotNull($addFieldsStage);
        /** @var array<string, mixed> $addFieldsBody */
        $addFieldsBody = $addFieldsStage['$addFields'];
        $this->assertArrayHasKey('_rand', $addFieldsBody);
    }

    public function testTextSearch(): void
    {
        $result = (new Builder())
            ->from('articles')
            ->filterSearch('content', 'mongodb tutorial')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        /** @var array<string, mixed> $matchStage */
        $matchStage = $pipeline[0];
        /** @var array<string, mixed> $matchBody */
        $matchBody = $matchStage['$match'];
        $this->assertArrayHasKey('$text', $matchBody);
        /** @var array<string, mixed> $textBody */
        $textBody = $matchBody['$text'];
        $this->assertSame('?', $textBody['$search']);
        $this->assertSame(['mongodb tutorial'], $result->bindings);
    }

    public function testNoTableThrowsException(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())->select(['name'])->build();
    }

    public function testInsertWithoutRowsThrowsException(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())->into('users')->insert();
    }

    public function testUpdateWithoutOperationsThrowsException(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())->from('users')->update();
    }

    public function testReset(): void
    {
        $builder = (new Builder())
            ->from('users')
            ->select(['name'])
            ->filter([Query::equal('status', ['active'])])
            ->push('tags', 'test')
            ->increment('counter', 1);

        $builder->reset();

        $this->expectException(ValidationException::class);
        $builder->build();
    }

    public function testFindOperationForSimpleQuery(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['name'])
            ->filter([Query::equal('country', ['US'])])
            ->sortAsc('name')
            ->limit(10)
            ->offset(5)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('find', $op['operation']);
        $this->assertSame(['name' => 1, '_id' => 0], $op['projection']);
        $this->assertSame(['country' => '?'], $op['filter']);
        $this->assertSame(['name' => 1], $op['sort']);
        $this->assertSame(10, $op['limit']);
        $this->assertSame(5, $op['skip']);
    }

    public function testAggregateOperationForGroupBy(): void
    {
        $result = (new Builder())
            ->from('users')
            ->count('*', 'total')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);
    }

    public function testClone(): void
    {
        $original = (new Builder())
            ->from('users')
            ->select(['name'])
            ->filter([Query::equal('status', ['active'])]);

        $cloned = $original->clone();
        $cloned->filter([Query::greaterThan('age', 25)]);

        $originalResult = $original->build();
        $clonedResult = $cloned->build();

        $this->assertCount(1, $originalResult->bindings);
        $this->assertCount(2, $clonedResult->bindings);
    }

    public function testMultipleGroupByColumns(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'cnt')
            ->groupBy(['country', 'city'])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $groupStage = $this->findStage($pipeline, '$group');
        $this->assertNotNull($groupStage);
        /** @var array<string, mixed> $groupBody */
        $groupBody = $groupStage['$group'];
        $this->assertSame([
            'country' => '$country',
            'city' => '$city',
        ], $groupBody['_id']);
    }

    public function testMinMaxAggregates(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->min('amount', 'min_amount')
            ->max('amount', 'max_amount')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $groupStage = $this->findStage($pipeline, '$group');
        $this->assertNotNull($groupStage);
        /** @var array<string, mixed> $groupBody */
        $groupBody = $groupStage['$group'];
        $this->assertSame(['$min' => '$amount'], $groupBody['min_amount']);
        $this->assertSame(['$max' => '$amount'], $groupBody['max_amount']);
    }

    public function testFilterEqualWithNull(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('deleted_at', [null])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['deleted_at' => null], $op['filter']);
        $this->assertEmpty($result->bindings);
    }

    public function testFilterContainsMultipleValues(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::containsString('bio', ['php', 'java'])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$or' => [
            ['bio' => ['$regex' => '?']],
            ['bio' => ['$regex' => '?']],
        ]], $op['filter']);
        $this->assertSame(['php', 'java'], $result->bindings);
    }

    public function testFilterContainsAll(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::containsAll('bio', ['php', 'java'])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$and' => [
            ['bio' => ['$regex' => '?']],
            ['bio' => ['$regex' => '?']],
        ]], $op['filter']);
        $this->assertSame(['php', 'java'], $result->bindings);
    }

    public function testFilterNotStartsWith(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::notStartsWith('name', 'Test')])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['name' => ['$not' => ['$regex' => '?']]], $op['filter']);
        $this->assertSame(['^Test'], $result->bindings);
    }

    public function testUpdateWithMultipleOperators(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['name' => 'Alice Updated'])
            ->increment('login_count', 1)
            ->push('tags', 'updated')
            ->unsetFields('temp_field')
            ->filter([Query::equal('_id', ['abc123'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        $this->assertArrayHasKey('$set', $update);
        $this->assertArrayHasKey('$inc', $update);
        $this->assertArrayHasKey('$push', $update);
        $this->assertArrayHasKey('$unset', $update);
    }

    public function testPage(): void
    {
        $result = (new Builder())
            ->from('users')
            ->page(3, 10)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(10, $op['limit']);
        $this->assertSame(20, $op['skip']);
    }

    public function testTableSampling(): void
    {
        $result = (new Builder())
            ->from('users')
            ->tablesample(100)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $sampleStage = $this->findStage($pipeline, '$sample');
        $this->assertNotNull($sampleStage);
        /** @var array<string, mixed> $sampleBody */
        $sampleBody = $sampleStage['$sample'];
        $this->assertSame(100, $sampleBody['size']);
    }

    public function testDoesNotExposeNegatedFullTextSearch(): void
    {
        $builder = new Builder();

        $this->assertInstanceOf(FullTextSearch::class, $builder);
        $this->assertArrayNotHasKey(NegatedFullTextSearch::class, \class_implements($builder));
        $this->assertNotContains('filterNotSearch', \get_class_methods($builder));
    }

    public function testFilterExistsSubquery(): void
    {
        $subquery = (new Builder())
            ->from('orders')
            ->select(['user_id'])
            ->filter([Query::greaterThan('total', 100)]);

        $result = (new Builder())
            ->from('users')
            ->filterExists($subquery)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $lookupStage = $this->findStage($pipeline, '$lookup');
        $this->assertNotNull($lookupStage);
        /** @var array<string, mixed> $lookupBody */
        $lookupBody = $lookupStage['$lookup'];
        $this->assertSame('orders', $lookupBody['from']);
        /** @var string $lookupAs */
        $lookupAs = $lookupBody['as'];
        $this->assertStringStartsWith('_exists_', $lookupAs);

        $matchStages = [];
        foreach ($pipeline as $stage) {
            if (isset($stage['$match'])) {
                $matchStages[] = $stage;
            }
        }
        $this->assertNotEmpty($matchStages);

        $unsetStage = $this->findStage($pipeline, '$unset');
        $this->assertNotNull($unsetStage);
    }

    public function testFilterNotExistsSubquery(): void
    {
        $subquery = (new Builder())
            ->from('orders')
            ->select(['user_id']);

        $result = (new Builder())
            ->from('users')
            ->filterNotExists($subquery)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $hasExistsMatch = false;
        foreach ($pipeline as $stage) {
            if (isset($stage['$match'])) {
                /** @var array<string, mixed> $matchBody */
                $matchBody = $stage['$match'];
                foreach ($matchBody as $key => $val) {
                    if (\str_starts_with($key, '_exists_') && \is_array($val) && isset($val['$size'])) {
                        $hasExistsMatch = true;
                        $this->assertSame(0, $val['$size']);
                    }
                }
            }
        }
        $this->assertTrue($hasExistsMatch);
    }

    public function testFilterWhereNotInSubquery(): void
    {
        $subquery = (new Builder())
            ->from('banned_users')
            ->select(['user_id']);

        $result = (new Builder())
            ->from('users')
            ->filterWhereNotIn('id', $subquery)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $hasNotIn = false;
        foreach ($pipeline as $stage) {
            if (isset($stage['$match'])) {
                $json = \json_encode($stage['$match']);
                if ($json !== false && \str_contains($json, '$not')) {
                    $hasNotIn = true;
                }
            }
        }
        $this->assertTrue($hasNotIn);
    }

    public function testWindowFunctionWithSumAggregation(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->select(['user_id', 'amount'])
            ->selectWindow('SUM(amount)', 'running_total', ['user_id'], ['amount'])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $windowStage = $this->findStage($pipeline, '$setWindowFields');
        $this->assertNotNull($windowStage);
        /** @var array<string, mixed> $windowBody */
        $windowBody = $windowStage['$setWindowFields'];
        /** @var array<string, mixed> $output */
        $output = $windowBody['output'];
        $this->assertArrayHasKey('running_total', $output);
        /** @var array<string, mixed> $runningTotal */
        $runningTotal = $output['running_total'];
        $this->assertSame('$amount', $runningTotal['$sum']);
        $this->assertArrayHasKey('window', $runningTotal);
    }

    public function testWindowFunctionWithAvg(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->selectWindow('AVG(price)', 'avg_price', ['category'], ['-price'])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $windowStage = $this->findStage($pipeline, '$setWindowFields');
        $this->assertNotNull($windowStage);
        /** @var array<string, mixed> $windowBody */
        $windowBody = $windowStage['$setWindowFields'];
        /** @var array<string, mixed> $output */
        $output = $windowBody['output'];
        $this->assertArrayHasKey('avg_price', $output);
        /** @var array<string, mixed> $avgPrice */
        $avgPrice = $output['avg_price'];
        $this->assertSame('$price', $avgPrice['$avg']);
    }

    public function testWindowFunctionWithMin(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->selectWindow('MIN(amount)', 'min_amount', ['region'])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $windowStage = $this->findStage($pipeline, '$setWindowFields');
        $this->assertNotNull($windowStage);
        /** @var array<string, mixed> $windowBody */
        $windowBody = $windowStage['$setWindowFields'];
        /** @var array<string, mixed> $output */
        $output = $windowBody['output'];
        /** @var array<string, mixed> $minAmount */
        $minAmount = $output['min_amount'];
        $this->assertSame('$amount', $minAmount['$min']);
    }

    public function testWindowFunctionWithMax(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->selectWindow('MAX(amount)', 'max_amount', ['region'])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $windowStage = $this->findStage($pipeline, '$setWindowFields');
        $this->assertNotNull($windowStage);
        /** @var array<string, mixed> $windowBody */
        $windowBody = $windowStage['$setWindowFields'];
        /** @var array<string, mixed> $output */
        $output = $windowBody['output'];
        /** @var array<string, mixed> $maxAmount */
        $maxAmount = $output['max_amount'];
        $this->assertSame('$amount', $maxAmount['$max']);
    }

    public function testWindowFunctionWithCount(): void
    {
        $result = (new Builder())
            ->from('events')
            ->selectWindow('COUNT(id)', 'event_count', ['user_id'])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $windowStage = $this->findStage($pipeline, '$setWindowFields');
        $this->assertNotNull($windowStage);
        /** @var array<string, mixed> $windowBody */
        $windowBody = $windowStage['$setWindowFields'];
        /** @var array<string, mixed> $output */
        $output = $windowBody['output'];
        /** @var array<string, mixed> $eventCount */
        $eventCount = $output['event_count'];
        $this->assertSame(1, $eventCount['$sum']);
    }

    public function testWindowFunctionUnsupportedThrows(): void
    {
        $this->expectException(UnsupportedException::class);
        $this->expectExceptionMessage('Unsupported window function');

        (new Builder())
            ->from('orders')
            ->selectWindow('MEDIAN(amount)', 'med', ['user_id'])
            ->build();
    }

    public function testWindowFunctionUnsupportedNonParenthesizedThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid window function');

        (new Builder())
            ->from('orders')
            ->selectWindow('custom_func', 'cf', ['user_id']);
    }

    public function testWindowFunctionMultiplePartitionKeys(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->selectWindow('ROW_NUMBER()', 'rn', ['country', 'city'], ['amount'])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $windowStage = $this->findStage($pipeline, '$setWindowFields');
        $this->assertNotNull($windowStage);
        /** @var array<string, mixed> $windowBody */
        $windowBody = $windowStage['$setWindowFields'];
        $this->assertSame([
            'country' => '$country',
            'city' => '$city',
        ], $windowBody['partitionBy']);
    }

    public function testWindowFunctionRankAndDenseRank(): void
    {
        $result = (new Builder())
            ->from('scores')
            ->selectWindow('RANK()', 'rnk', ['category'], ['score'])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $windowStage = $this->findStage($pipeline, '$setWindowFields');
        $this->assertNotNull($windowStage);
        /** @var array<string, mixed> $windowBody */
        $windowBody = $windowStage['$setWindowFields'];
        /** @var array<string, mixed> $output */
        $output = $windowBody['output'];
        $this->assertArrayHasKey('rnk', $output);

        $resultDense = (new Builder())
            ->from('scores')
            ->selectWindow('DENSE_RANK()', 'dense_rnk', ['category'], ['score'])
            ->build();
        $this->assertBindingCount($resultDense);

        $opDense = $this->decode($resultDense->query);
        /** @var list<array<string, mixed>> $pipelineDense */
        $pipelineDense = $opDense['pipeline'];
        $windowStageDense = $this->findStage($pipelineDense, '$setWindowFields');
        $this->assertNotNull($windowStageDense);
        /** @var array<string, mixed> $windowBodyDense */
        $windowBodyDense = $windowStageDense['$setWindowFields'];
        /** @var array<string, mixed> $outputDense */
        $outputDense = $windowBodyDense['output'];
        $this->assertArrayHasKey('dense_rnk', $outputDense);
    }

    public function testWindowFunctionWithOrderByAsc(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->selectWindow('ROW_NUMBER()', 'rn', null, ['created_at'])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $windowStage = $this->findStage($pipeline, '$setWindowFields');
        $this->assertNotNull($windowStage);
        /** @var array<string, mixed> $windowBody */
        $windowBody = $windowStage['$setWindowFields'];
        /** @var array<string, int> $sortBy */
        $sortBy = $windowBody['sortBy'];
        $this->assertSame(1, $sortBy['created_at']);
    }

    public function testFilterEqualWithNullAndValues(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('status', ['active', null])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$or' => [
            ['status' => ['$in' => ['?']]],
            ['status' => null],
        ]], $op['filter']);
        $this->assertSame(['active'], $result->bindings);
    }

    public function testFilterNotEqualWithNullOnly(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::notEqual('status', [null])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['status' => ['$ne' => null]], $op['filter']);
        $this->assertEmpty($result->bindings);
    }

    public function testFilterNotEqualWithNullAndValues(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::notEqual('status', ['deleted', null])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$and' => [
            ['status' => ['$nin' => ['?']]],
            ['status' => ['$ne' => null]],
        ]], $op['filter']);
        $this->assertSame(['deleted'], $result->bindings);
    }

    public function testFilterNotContainsMultipleValues(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::notContains('bio', ['spam', 'junk'])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$and' => [
            ['bio' => ['$not' => ['$regex' => '?']]],
            ['bio' => ['$not' => ['$regex' => '?']]],
        ]], $op['filter']);
        $this->assertSame(['spam', 'junk'], $result->bindings);
    }

    public function testFilterFieldExists(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::exists(['email'])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['email' => ['$exists' => true, '$ne' => null]], $op['filter']);
    }

    public function testFilterFieldNotExists(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::notExists(['email'])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['email' => ['$type' => 10]], $op['filter']);
    }

    public function testFilterFieldExistsMultiple(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::exists(['email', 'phone'])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$and' => [
            ['email' => ['$exists' => true, '$ne' => null]],
            ['phone' => ['$exists' => true, '$ne' => null]],
        ]], $op['filter']);
    }

    public function testFilterFieldNotExistsMultiple(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::notExists(['email', 'phone'])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$and' => [
            ['email' => ['$type' => 10]],
            ['phone' => ['$type' => 10]],
        ]], $op['filter']);
    }

    public function testContainsAnyOnArray(): void
    {
        $query = Query::containsAny('tags', ['php', 'js']);
        $query->setOnArray(true);

        $result = (new Builder())
            ->from('users')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['tags' => ['$in' => ['?', '?']]], $op['filter']);
        $this->assertSame(['php', 'js'], $result->bindings);
    }

    public function testContainsAnyOnString(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::containsAny('bio', ['php', 'js'])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$or' => [
            ['bio' => ['$regex' => '?']],
            ['bio' => ['$regex' => '?']],
        ]], $op['filter']);
        $this->assertSame(['php', 'js'], $result->bindings);
    }

    public function testUpsertWithoutExplicitUpdateColumns(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['email' => 'alice@test.com', 'name' => 'Alice', 'age' => 30])
            ->onConflict(['email'], [])
            ->upsert();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('updateOne', $op['operation']);
        $this->assertSame(['email' => '?'], $op['filter']);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        /** @var array<string, mixed> $setDoc */
        $setDoc = $update['$set'];
        $this->assertArrayHasKey('name', $setDoc);
        $this->assertArrayHasKey('age', $setDoc);
        $this->assertArrayNotHasKey('email', $setDoc);
    }

    public function testUpsertMissingConflictKeyThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Conflict key 'email' not found in row data.");

        (new Builder())
            ->into('users')
            ->set(['name' => 'Alice'])
            ->onConflict(['email'], ['name'])
            ->upsert();
    }

    public function testAggregateWithLimitAndOffset(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'total')
            ->groupBy(['user_id'])
            ->limit(10)
            ->offset(20)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $skipStage = $this->findStage($pipeline, '$skip');
        $this->assertNotNull($skipStage);
        $this->assertSame(20, $skipStage['$skip']);

        $limitStage = $this->findStage($pipeline, '$limit');
        $this->assertNotNull($limitStage);
        $this->assertSame(10, $limitStage['$limit']);
    }

    public function testAggregateDefaultSortDoesNotThrow(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'cnt')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);
    }

    public function testAggregationWithNoAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $groupStage = $this->findStage($pipeline, '$group');
        $this->assertNotNull($groupStage);
        /** @var array<string, mixed> $groupBody */
        $groupBody = $groupStage['$group'];
        $this->assertArrayHasKey('count', $groupBody);
    }

    public function testUnsupportedAggregationThrows(): void
    {
        $this->expectException(UnsupportedException::class);
        $this->expectExceptionMessage('Unsupported aggregation for MongoDB');

        (new Builder())
            ->from('orders')
            ->queries([new Query(Method::CountDistinct, 'id', ['cd'])])
            ->build();
    }

    public function testBeforeBuildCallback(): void
    {
        $result = (new Builder())
            ->from('users')
            ->beforeBuild(function (Builder $builder) {
                $builder->filter([Query::equal('injected', ['yes'])]);
            })
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['injected' => '?'], $op['filter']);
        $this->assertSame(['yes'], $result->bindings);
    }

    public function testAfterBuildCallback(): void
    {
        $result = (new Builder())
            ->from('users')
            ->afterBuild(function ($result) {
                return $result;
            })
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('find', $op['operation']);
    }

    public function testFilterNotEndsWith(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::notEndsWith('email', '.com')])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['email' => ['$not' => ['$regex' => '?']]], $op['filter']);
        $this->assertSame(['\.com$'], $result->bindings);
    }

    public function testEmptyHavingReturnsEmpty(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'cnt')
            ->groupBy(['user_id'])
            ->having([Query::and([])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);
    }

    public function testHavingWithMultipleConditions(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'cnt')
            ->sum('amount', 'total')
            ->groupBy(['user_id'])
            ->having([
                Query::greaterThan('cnt', 5),
                Query::greaterThan('total', 100),
            ])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $groupIdx = $this->findStageIndex($pipeline, '$group');
        $this->assertNotNull($groupIdx);

        $havingMatches = [];
        for ($i = $groupIdx + 1; $i < \count($pipeline); $i++) {
            if (isset($pipeline[$i]['$match'])) {
                $havingMatches[] = $pipeline[$i];
            }
        }
        $this->assertNotEmpty($havingMatches);
    }

    public function testUnionWithFindOperation(): void
    {
        $first = (new Builder())
            ->from('users')
            ->select(['name'])
            ->filter([Query::equal('country', ['US'])])
            ->sortAsc('name')
            ->limit(10)
            ->offset(5);

        $second = (new Builder())
            ->from('users')
            ->select(['name'])
            ->filter([Query::equal('country', ['UK'])]);

        $result = $first->unionAll($second)->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $unionStage = $this->findStage($pipeline, '$unionWith');
        $this->assertNotNull($unionStage);
    }

    public function testEmptyOrLogical(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::or([])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertArrayHasKey('filter', $op);
    }

    public function testJoinWithNonEqualityOperatorThrows(): void
    {
        $this->expectException(UnsupportedException::class);
        $this->expectExceptionMessage('equality joins');

        (new Builder())
            ->from('orders')
            ->join('users', 'orders.user_id', 'users.id', '<>', 'u')
            ->build();
    }

    public function testJoinWithGreaterThanOperatorThrows(): void
    {
        $this->expectException(UnsupportedException::class);
        $this->expectExceptionMessage('equality joins');

        (new Builder())
            ->from('orders')
            ->join('orders', 'orders.amount', 'users.threshold', '>', 'o')
            ->build();
    }

    public function testDoesNotExposeRawSqlSetters(): void
    {
        $methods = \get_class_methods(new Builder());

        $this->assertNotContains('setRaw', $methods);
        $this->assertNotContains('setCase', $methods);
        $this->assertNotContains('conflictSetRaw', $methods);
    }

    public function testWindowFunctionMultiArgumentThrows(): void
    {
        $this->expectException(UnsupportedException::class);
        $this->expectExceptionMessage('Multi-argument window functions');

        (new Builder())
            ->from('metrics')
            ->selectWindow('COVAR(a, b)', 'cov', ['user_id'], ['created_at'])
            ->build();
    }

    public function testWindowFunctionRankWithoutOrderByThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('requires an ORDER BY');

        (new Builder())
            ->from('scores')
            ->selectWindow('RANK()', 'rnk', ['category'])
            ->build();
    }

    public function testWindowFunctionDenseRankWithoutOrderByThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('requires an ORDER BY');

        (new Builder())
            ->from('scores')
            ->selectWindow('DENSE_RANK()', 'dense', ['category'])
            ->build();
    }

    public function testWindowFunctionRowNumberWithoutOrderByThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('requires an ORDER BY');

        (new Builder())
            ->from('orders')
            ->selectWindow('ROW_NUMBER()', 'rn', ['user_id'])
            ->build();
    }

    public function testFilterFieldExistsUsesExplicitNonNullCheck(): void
    {
        // SQL-parity: IS NOT NULL is true only when the field is present AND non-null.
        $result = (new Builder())
            ->from('users')
            ->filter([Query::exists(['email'])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $filter */
        $filter = $op['filter'];
        /** @var array<string, mixed> $emailFilter */
        $emailFilter = $filter['email'];
        $this->assertTrue($emailFilter['$exists']);
        $this->assertNull($emailFilter['$ne']);
    }

    public function testFilterFieldNotExistsUsesTypeNull(): void
    {
        // SQL-parity: IS NULL is true only when the field is present AND explicitly null.
        // Missing fields do not match (BSON type 10 = null).
        $result = (new Builder())
            ->from('users')
            ->filter([Query::notExists(['email'])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $filter */
        $filter = $op['filter'];
        /** @var array<string, mixed> $emailFilter */
        $emailFilter = $filter['email'];
        $this->assertSame(10, $emailFilter['$type']);
    }

    public function testInsertOrIgnoreDoesNotRoundTripThroughJson(): void
    {
        // Regression: an earlier implementation did json_decode(insert()->query) which
        // would coerce any empty stdClass (used elsewhere to encode `{}`) to `[]`.
        // Verify the output is still a well-formed insertMany with ordered=false.
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'Alice', 'email' => 'alice@test.com'])
            ->insertOrIgnore();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('users', $op['collection']);
        $this->assertSame('insertMany', $op['operation']);
        /** @var array<string, mixed> $options */
        $options = $op['options'];
        $this->assertFalse($options['ordered']);
        /** @var list<array<string, mixed>> $documents */
        $documents = $op['documents'];
        $this->assertCount(1, $documents);
        $this->assertSame(['name' => '?', 'email' => '?'], $documents[0]);
        $this->assertSame(['Alice', 'alice@test.com'], $result->bindings);
    }

    public function testWindowFunctionAliasIsPreservedInProjection(): void
    {
        // Regression for commit 06ceaca: the $project stage after $setWindowFields
        // must include the window function alias so it survives projection.
        $result = (new Builder())
            ->from('orders')
            ->select(['user_id'])
            ->selectWindow('ROW_NUMBER()', 'rn', ['user_id'], ['-amount'])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);

        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $projectStage = $this->findStage($pipeline, '$project');
        $this->assertNotNull($projectStage);
        /** @var array<string, mixed> $projection */
        $projection = $projectStage['$project'];
        $this->assertArrayHasKey('rn', $projection);
        $this->assertSame(1, $projection['rn']);
        $this->assertArrayHasKey('user_id', $projection);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        /** @var array<string, mixed> */
        return \json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<array<string, mixed>> $pipeline
     * @return array<string, mixed>|null
     */
    private function findStage(array $pipeline, string $stageName): ?array
    {
        foreach ($pipeline as $stage) {
            if (isset($stage[$stageName])) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $pipeline
     */
    private function findStageIndex(array $pipeline, string $stageName): ?int
    {
        foreach ($pipeline as $idx => $stage) {
            if (isset($stage[$stageName])) {
                return $idx;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $pipeline
     * @return list<array<string, mixed>>
     */
    private function findAllStages(array $pipeline, string $stageName): array
    {
        $found = [];
        foreach ($pipeline as $stage) {
            if (isset($stage[$stageName])) {
                $found[] = $stage;
            }
        }

        return $found;
    }

    public function testJoinWithWhereGroupByHavingOrderLimitOffset(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->join('users', 'orders.user_id', 'users.id', '=', 'u')
            ->filter([Query::greaterThan('amount', 10)])
            ->count('*', 'cnt')
            ->sum('amount', 'total')
            ->groupBy(['u.country'])
            ->having([Query::greaterThan('cnt', 2)])
            ->sortDesc('total')
            ->limit(20)
            ->offset(5)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);

        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $lookupStage = $this->findStage($pipeline, '$lookup');
        $this->assertNotNull($lookupStage);

        $matchStage = $this->findStage($pipeline, '$match');
        $this->assertNotNull($matchStage);

        $groupStage = $this->findStage($pipeline, '$group');
        $this->assertNotNull($groupStage);

        $sortStage = $this->findStage($pipeline, '$sort');
        $this->assertNotNull($sortStage);

        $skipStage = $this->findStage($pipeline, '$skip');
        $this->assertNotNull($skipStage);
        $this->assertSame(5, $skipStage['$skip']);

        $limitStage = $this->findStage($pipeline, '$limit');
        $this->assertNotNull($limitStage);
        $this->assertSame(20, $limitStage['$limit']);

        $lookupIdx = $this->findStageIndex($pipeline, '$lookup');
        $matchIdx = $this->findStageIndex($pipeline, '$match');
        $groupIdx = $this->findStageIndex($pipeline, '$group');
        $sortIdx = $this->findStageIndex($pipeline, '$sort');
        $skipIdx = $this->findStageIndex($pipeline, '$skip');
        $limitIdx = $this->findStageIndex($pipeline, '$limit');

        $this->assertNotNull($lookupIdx);
        $this->assertNotNull($matchIdx);
        $this->assertNotNull($groupIdx);
        $this->assertNotNull($sortIdx);
        $this->assertNotNull($skipIdx);
        $this->assertNotNull($limitIdx);

        $this->assertLessThan($matchIdx, $lookupIdx);
        $this->assertLessThan($groupIdx, $matchIdx);
        $this->assertLessThan($sortIdx, $groupIdx);
        $this->assertLessThan($skipIdx, $sortIdx);
        $this->assertLessThan($limitIdx, $skipIdx);
    }

    public function testMultipleJoinsWithWhere(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->join('users', 'orders.user_id', 'users.id', '=', 'u')
            ->join('products', 'orders.product_id', 'products.id', '=', 'p')
            ->filter([Query::greaterThan('amount', 50)])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);

        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $lookupStages = $this->findAllStages($pipeline, '$lookup');
        $this->assertCount(2, $lookupStages);

        /** @var array<string, mixed> $lookup1 */
        $lookup1 = $lookupStages[0]['$lookup'];
        $this->assertSame('users', $lookup1['from']);
        $this->assertSame('u', $lookup1['as']);

        /** @var array<string, mixed> $lookup2 */
        $lookup2 = $lookupStages[1]['$lookup'];
        $this->assertSame('products', $lookup2['from']);
        $this->assertSame('p', $lookup2['as']);

        $this->assertSame([50], $result->bindings);
    }

    public function testLeftJoinAndInnerJoinCombined(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->leftJoin('users', 'orders.user_id', 'users.id', '=', 'u')
            ->join('categories', 'orders.category_id', 'categories.id', '=', 'c')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $unwindStages = $this->findAllStages($pipeline, '$unwind');
        $this->assertCount(2, $unwindStages);

        /** @var array<string, mixed>|string $firstUnwind */
        $firstUnwind = $unwindStages[0]['$unwind'];
        $this->assertIsArray($firstUnwind);
        $this->assertTrue($firstUnwind['preserveNullAndEmptyArrays']);

        /** @var string $secondUnwind */
        $secondUnwind = $unwindStages[1]['$unwind'];
        $this->assertSame('$c', $secondUnwind);
    }

    public function testJoinWithAggregateGroupByHaving(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->join('users', 'orders.user_id', 'users.id', '=', 'u')
            ->count('*', 'order_count')
            ->sum('orders.amount', 'total_amount')
            ->groupBy(['u.name'])
            ->having([Query::greaterThan('order_count', 3)])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $lookupStage = $this->findStage($pipeline, '$lookup');
        $this->assertNotNull($lookupStage);

        $groupStage = $this->findStage($pipeline, '$group');
        $this->assertNotNull($groupStage);
        /** @var array<string, mixed> $groupBody */
        $groupBody = $groupStage['$group'];
        $this->assertSame(['$sum' => 1], $groupBody['order_count']);
        $this->assertSame(['$sum' => '$orders.amount'], $groupBody['total_amount']);

        $groupIdx = $this->findStageIndex($pipeline, '$group');
        $this->assertNotNull($groupIdx);
        $havingMatches = [];
        for ($i = $groupIdx + 1; $i < \count($pipeline); $i++) {
            if (isset($pipeline[$i]['$match'])) {
                $havingMatches[] = $pipeline[$i];
            }
        }
        $this->assertNotEmpty($havingMatches);
    }

    public function testJoinWithWindowFunction(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->join('users', 'orders.user_id', 'users.id', '=', 'u')
            ->selectWindow('ROW_NUMBER()', 'rn', ['u.country'], ['-orders.amount'])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $lookupStage = $this->findStage($pipeline, '$lookup');
        $this->assertNotNull($lookupStage);

        $windowStage = $this->findStage($pipeline, '$setWindowFields');
        $this->assertNotNull($windowStage);

        $lookupIdx = $this->findStageIndex($pipeline, '$lookup');
        $windowIdx = $this->findStageIndex($pipeline, '$setWindowFields');
        $this->assertNotNull($lookupIdx);
        $this->assertNotNull($windowIdx);
        $this->assertLessThan($windowIdx, $lookupIdx);
    }

    public function testJoinWithDistinct(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->join('users', 'orders.user_id', 'users.id', '=', 'u')
            ->select(['u.country'])
            ->distinct()
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);

        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $lookupStage = $this->findStage($pipeline, '$lookup');
        $this->assertNotNull($lookupStage);

        $groupStage = $this->findStage($pipeline, '$group');
        $this->assertNotNull($groupStage);
    }

    public function testFilterWhereInSubqueryWithJoin(): void
    {
        $subquery = (new Builder())
            ->from('premium_users')
            ->select(['user_id'])
            ->filter([Query::equal('tier', ['gold'])]);

        $result = (new Builder())
            ->from('orders')
            ->join('products', 'orders.product_id', 'products.id', '=', 'p')
            ->filterWhereIn('user_id', $subquery)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $lookupStages = $this->findAllStages($pipeline, '$lookup');
        $this->assertGreaterThanOrEqual(2, \count($lookupStages));

        $this->assertSame(['gold'], $result->bindings);
    }

    public function testFilterWhereInSubqueryWithAggregate(): void
    {
        $subquery = (new Builder())
            ->from('active_users')
            ->select(['id'])
            ->filter([Query::equal('status', ['active'])]);

        $result = (new Builder())
            ->from('orders')
            ->filterWhereIn('user_id', $subquery)
            ->count('*', 'order_count')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);

        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $groupStage = $this->findStage($pipeline, '$group');
        $this->assertNotNull($groupStage);
        /** @var array<string, mixed> $groupBody */
        $groupBody = $groupStage['$group'];
        $this->assertSame(['$sum' => 1], $groupBody['order_count']);

        $this->assertSame(['active'], $result->bindings);
    }

    public function testExistsSubqueryWithRegularFilter(): void
    {
        $subquery = (new Builder())
            ->from('orders')
            ->select(['user_id'])
            ->filter([Query::greaterThan('total', 100)]);

        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('status', ['active'])])
            ->filterExists($subquery)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);

        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $lookupStage = $this->findStage($pipeline, '$lookup');
        $this->assertNotNull($lookupStage);

        $matchStages = $this->findAllStages($pipeline, '$match');
        $this->assertGreaterThanOrEqual(2, \count($matchStages));

        $this->assertSame([100, 'active'], $result->bindings);
    }

    public function testNotExistsSubqueryWithRegularFilter(): void
    {
        $subquery = (new Builder())
            ->from('banned_ips')
            ->select(['ip']);

        $result = (new Builder())
            ->from('logins')
            ->filter([Query::greaterThan('timestamp', '2024-01-01')])
            ->filterNotExists($subquery)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $hasExistsMatch = false;
        foreach ($pipeline as $stage) {
            if (isset($stage['$match'])) {
                /** @var array<string, mixed> $matchBody */
                $matchBody = $stage['$match'];
                foreach ($matchBody as $key => $val) {
                    if (\str_starts_with($key, '_exists_') && \is_array($val) && isset($val['$size'])) {
                        $hasExistsMatch = true;
                        $this->assertSame(0, $val['$size']);
                    }
                }
            }
        }
        $this->assertTrue($hasExistsMatch);

        $this->assertContains('2024-01-01', $result->bindings);
    }

    public function testUnionAllOfTwoComplexQueries(): void
    {
        $second = (new Builder())
            ->from('archived_orders')
            ->select(['id', 'amount'])
            ->filter([
                Query::greaterThan('amount', 200),
                Query::equal('status', ['archived']),
            ])
            ->sortDesc('amount')
            ->limit(5);

        $result = (new Builder())
            ->from('orders')
            ->select(['id', 'amount'])
            ->filter([
                Query::greaterThan('amount', 100),
                Query::equal('status', ['active']),
            ])
            ->sortDesc('amount')
            ->limit(10)
            ->unionAll($second)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);

        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $unionStage = $this->findStage($pipeline, '$unionWith');
        $this->assertNotNull($unionStage);
        /** @var array<string, mixed> $unionBody */
        $unionBody = $unionStage['$unionWith'];
        $this->assertSame('archived_orders', $unionBody['coll']);
        $this->assertArrayHasKey('pipeline', $unionBody);
    }

    public function testUnionAllOfThreeQueries(): void
    {
        $second = (new Builder())
            ->from('eu_users')
            ->select(['name'])
            ->filter([Query::equal('region', ['EU'])]);

        $third = (new Builder())
            ->from('asia_users')
            ->select(['name'])
            ->filter([Query::equal('region', ['ASIA'])]);

        $result = (new Builder())
            ->from('us_users')
            ->select(['name'])
            ->filter([Query::equal('region', ['US'])])
            ->unionAll($second)
            ->unionAll($third)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $unionStages = $this->findAllStages($pipeline, '$unionWith');
        $this->assertCount(2, $unionStages);

        /** @var array<string, mixed> $union1Body */
        $union1Body = $unionStages[0]['$unionWith'];
        $this->assertSame('eu_users', $union1Body['coll']);

        /** @var array<string, mixed> $union2Body */
        $union2Body = $unionStages[1]['$unionWith'];
        $this->assertSame('asia_users', $union2Body['coll']);

        $this->assertSame(['US', 'EU', 'ASIA'], $result->bindings);
    }

    public function testUnionWithOrderByAndLimit(): void
    {
        $second = (new Builder())
            ->from('archived_users')
            ->select(['name', 'score']);

        $result = (new Builder())
            ->from('users')
            ->select(['name', 'score'])
            ->unionAll($second)
            ->sortDesc('score')
            ->limit(50)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $unionIdx = $this->findStageIndex($pipeline, '$unionWith');
        $sortIdx = $this->findStageIndex($pipeline, '$sort');
        $limitIdx = $this->findStageIndex($pipeline, '$limit');

        $this->assertNotNull($unionIdx);
        $this->assertNotNull($sortIdx);
        $this->assertNotNull($limitIdx);

        $this->assertLessThan($sortIdx, $unionIdx);
        $this->assertLessThan($limitIdx, $sortIdx);
    }

    public function testWindowFunctionWithWhereAndOrder(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->filter([Query::greaterThan('amount', 0)])
            ->selectWindow('ROW_NUMBER()', 'rn', ['region'], ['-amount'])
            ->sortDesc('rn')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $matchIdx = $this->findStageIndex($pipeline, '$match');
        $windowIdx = $this->findStageIndex($pipeline, '$setWindowFields');
        $sortIdx = $this->findStageIndex($pipeline, '$sort');

        $this->assertNotNull($matchIdx);
        $this->assertNotNull($windowIdx);
        $this->assertNotNull($sortIdx);

        $this->assertLessThan($windowIdx, $matchIdx);
        $this->assertLessThan($sortIdx, $windowIdx);
    }

    public function testMultipleWindowFunctions(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->selectWindow('ROW_NUMBER()', 'rn', ['user_id'], ['-amount'])
            ->selectWindow('SUM(amount)', 'running_sum', ['user_id'], ['created_at'])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $windowStages = $this->findAllStages($pipeline, '$setWindowFields');
        $this->assertCount(2, $windowStages);

        /** @var array<string, mixed> $window1Body */
        $window1Body = $windowStages[0]['$setWindowFields'];
        /** @var array<string, mixed> $output1 */
        $output1 = $window1Body['output'];
        $this->assertArrayHasKey('rn', $output1);

        /** @var array<string, mixed> $window2Body */
        $window2Body = $windowStages[1]['$setWindowFields'];
        /** @var array<string, mixed> $output2 */
        $output2 = $window2Body['output'];
        $this->assertArrayHasKey('running_sum', $output2);
    }

    public function testWindowFunctionMultiplePartitionAndSortKeys(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->selectWindow('RANK()', 'rnk', ['region', 'department', 'team'], ['-revenue', 'name'])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $windowStage = $this->findStage($pipeline, '$setWindowFields');
        $this->assertNotNull($windowStage);

        /** @var array<string, mixed> $windowBody */
        $windowBody = $windowStage['$setWindowFields'];
        $this->assertSame([
            'region' => '$region',
            'department' => '$department',
            'team' => '$team',
        ], $windowBody['partitionBy']);

        /** @var array<string, int> $sortBy */
        $sortBy = $windowBody['sortBy'];
        $this->assertSame(-1, $sortBy['revenue']);
        $this->assertSame(1, $sortBy['name']);
    }

    public function testGroupByMultipleColumnsMultipleAggregates(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->count('*', 'cnt')
            ->sum('amount', 'total')
            ->avg('amount', 'average')
            ->groupBy(['region', 'year', 'quarter'])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $groupStage = $this->findStage($pipeline, '$group');
        $this->assertNotNull($groupStage);
        /** @var array<string, mixed> $groupBody */
        $groupBody = $groupStage['$group'];

        $this->assertSame([
            'region' => '$region',
            'year' => '$year',
            'quarter' => '$quarter',
        ], $groupBody['_id']);

        $this->assertSame(['$sum' => 1], $groupBody['cnt']);
        $this->assertSame(['$sum' => '$amount'], $groupBody['total']);
        $this->assertSame(['$avg' => '$amount'], $groupBody['average']);

        $projectStage = $this->findStage($pipeline, '$project');
        $this->assertNotNull($projectStage);
        /** @var array<string, mixed> $projectBody */
        $projectBody = $projectStage['$project'];
        $this->assertSame(0, $projectBody['_id']);
        $this->assertSame('$_id.region', $projectBody['region']);
        $this->assertSame('$_id.year', $projectBody['year']);
        $this->assertSame('$_id.quarter', $projectBody['quarter']);
        $this->assertSame(1, $projectBody['cnt']);
        $this->assertSame(1, $projectBody['total']);
        $this->assertSame(1, $projectBody['average']);
    }

    public function testMultipleAggregatesWithoutGroupBy(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'total_count')
            ->sum('amount', 'total_amount')
            ->avg('amount', 'avg_amount')
            ->min('amount', 'min_amount')
            ->max('amount', 'max_amount')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $groupStage = $this->findStage($pipeline, '$group');
        $this->assertNotNull($groupStage);
        /** @var array<string, mixed> $groupBody */
        $groupBody = $groupStage['$group'];

        $this->assertNull($groupBody['_id']);
        $this->assertSame(['$sum' => 1], $groupBody['total_count']);
        $this->assertSame(['$sum' => '$amount'], $groupBody['total_amount']);
        $this->assertSame(['$avg' => '$amount'], $groupBody['avg_amount']);
        $this->assertSame(['$min' => '$amount'], $groupBody['min_amount']);
        $this->assertSame(['$max' => '$amount'], $groupBody['max_amount']);
    }

    public function testBeforeBuildCallbackAddingFiltersWithMainFilters(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('role', ['admin'])])
            ->beforeBuild(function (Builder $builder) {
                $builder->filter([Query::equal('active', [true])]);
            })
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$and' => [
            ['role' => '?'],
            ['active' => '?'],
        ]], $op['filter']);
        $this->assertSame(['admin', true], $result->bindings);
    }

    public function testAfterBuildCallbackModifyingResult(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['name'])
            ->afterBuild(function (Statement $result) {
                /** @var array<string, mixed> $op */
                $op = \json_decode($result->query, true);
                $op['custom_flag'] = true;

                return new Statement(
                    \json_encode($op, JSON_THROW_ON_ERROR),
                    $result->bindings,
                    $result->readOnly
                );
            })
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertTrue($op['custom_flag']);
        $this->assertSame('find', $op['operation']);
    }

    public function testInsertMultipleRowsDocumentStructure(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'Alice', 'age' => 30, 'city' => 'NYC'])
            ->set(['name' => 'Bob', 'age' => 25, 'city' => 'LA'])
            ->set(['name' => 'Charlie', 'age' => 35, 'city' => 'SF'])
            ->insert();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $documents */
        $documents = $op['documents'];
        $this->assertCount(3, $documents);

        foreach ($documents as $doc) {
            $this->assertSame(['name' => '?', 'age' => '?', 'city' => '?'], $doc);
        }

        $this->assertSame(['Alice', 30, 'NYC', 'Bob', 25, 'LA', 'Charlie', 35, 'SF'], $result->bindings);
    }

    public function testUpdateWithComplexMultiConditionFilter(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['status' => 'suspended'])
            ->filter([Query::or([
                Query::and([
                    Query::equal('role', ['admin']),
                    Query::lessThan('last_login', '2023-01-01'),
                ]),
                Query::and([
                    Query::equal('role', ['user']),
                    Query::equal('verified', [false]),
                ]),
            ])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('updateMany', $op['operation']);
        $this->assertSame(['$set' => ['status' => '?']], $op['update']);

        /** @var array<string, mixed> $filter */
        $filter = $op['filter'];
        $this->assertArrayHasKey('$or', $filter);
        /** @var list<array<string, mixed>> $orConditions */
        $orConditions = $filter['$or'];
        $this->assertCount(2, $orConditions);
        $this->assertArrayHasKey('$and', $orConditions[0]);
        $this->assertArrayHasKey('$and', $orConditions[1]);
    }

    public function testDeleteWithComplexOrAndFilter(): void
    {
        $result = (new Builder())
            ->from('events')
            ->filter([Query::or([
                Query::and([
                    Query::lessThan('timestamp', '2022-01-01'),
                    Query::equal('type', ['error']),
                ]),
                Query::equal('status', ['expired']),
            ])])
            ->delete();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('deleteMany', $op['operation']);

        /** @var array<string, mixed> $filter */
        $filter = $op['filter'];
        $this->assertArrayHasKey('$or', $filter);
        /** @var list<array<string, mixed>> $orConditions */
        $orConditions = $filter['$or'];
        $this->assertCount(2, $orConditions);
        $this->assertArrayHasKey('$and', $orConditions[0]);
    }

    public function testFilterOrWithEqualAndGreaterThanStructure(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::or([
                Query::equal('a', [1]),
                Query::greaterThan('b', 5),
            ])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$or' => [
            ['a' => '?'],
            ['b' => ['$gt' => '?']],
        ]], $op['filter']);
        $this->assertSame([1, 5], $result->bindings);
    }

    public function testFilterAndWithEqualAndLessThanStructure(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::and([
                Query::equal('a', [1]),
                Query::lessThan('b', 10),
            ])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$and' => [
            ['a' => '?'],
            ['b' => ['$lt' => '?']],
        ]], $op['filter']);
        $this->assertSame([1, 10], $result->bindings);
    }

    public function testNestedOrInsideAndInsideOr(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::or([
                Query::and([
                    Query::equal('status', ['active']),
                    Query::greaterThan('age', 18),
                ]),
                Query::and([
                    Query::lessThan('score', 50),
                    Query::notEqual('role', 'guest'),
                ]),
            ])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $filter */
        $filter = $op['filter'];
        $this->assertArrayHasKey('$or', $filter);
        /** @var list<array<string, mixed>> $orConditions */
        $orConditions = $filter['$or'];
        $this->assertCount(2, $orConditions);
        $this->assertArrayHasKey('$and', $orConditions[0]);
        $this->assertArrayHasKey('$and', $orConditions[1]);

        /** @var list<array<string, mixed>> $and1 */
        $and1 = $orConditions[0]['$and'];
        $this->assertCount(2, $and1);
        $this->assertSame(['status' => '?'], $and1[0]);
        $this->assertSame(['age' => ['$gt' => '?']], $and1[1]);

        /** @var list<array<string, mixed>> $and2 */
        $and2 = $orConditions[1]['$and'];
        $this->assertCount(2, $and2);
        $this->assertSame(['score' => ['$lt' => '?']], $and2[0]);
        $this->assertSame(['role' => ['$ne' => '?']], $and2[1]);
    }

    public function testTripleNestingAndOfOrFilters(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::and([
                Query::or([
                    Query::equal('status', ['active']),
                    Query::greaterThan('score', 100),
                ]),
                Query::or([
                    Query::lessThan('age', 30),
                    Query::between('balance', 0, 1000),
                ]),
            ])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $filter */
        $filter = $op['filter'];
        $this->assertArrayHasKey('$and', $filter);
        /** @var list<array<string, mixed>> $andConditions */
        $andConditions = $filter['$and'];
        $this->assertCount(2, $andConditions);

        $this->assertArrayHasKey('$or', $andConditions[0]);
        $this->assertArrayHasKey('$or', $andConditions[1]);

        /** @var list<array<string, mixed>> $or1 */
        $or1 = $andConditions[0]['$or'];
        $this->assertSame(['status' => '?'], $or1[0]);
        $this->assertSame(['score' => ['$gt' => '?']], $or1[1]);

        /** @var list<array<string, mixed>> $or2 */
        $or2 = $andConditions[1]['$or'];
        $this->assertSame(['age' => ['$lt' => '?']], $or2[0]);
        $this->assertSame(['balance' => ['$gte' => '?', '$lte' => '?']], $or2[1]);
    }

    public function testIsNullWithEqualCombined(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([
                Query::isNull('deleted_at'),
                Query::equal('status', ['active']),
            ])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$and' => [
            ['deleted_at' => null],
            ['status' => '?'],
        ]], $op['filter']);
        $this->assertSame(['active'], $result->bindings);
    }

    public function testIsNotNullWithGreaterThanCombined(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([
                Query::isNotNull('email'),
                Query::greaterThan('login_count', 0),
            ])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$and' => [
            ['email' => ['$ne' => null]],
            ['login_count' => ['$gt' => '?']],
        ]], $op['filter']);
        $this->assertSame([0], $result->bindings);
    }

    public function testBetweenWithNotEqualCombined(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([
                Query::between('age', 18, 65),
                Query::notEqual('status', 'banned'),
            ])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$and' => [
            ['age' => ['$gte' => '?', '$lte' => '?']],
            ['status' => ['$ne' => '?']],
        ]], $op['filter']);
        $this->assertSame([18, 65, 'banned'], $result->bindings);
    }

    public function testContainsWithStartsWithCombined(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([
                Query::containsString('name', ['test']),
                Query::startsWith('email', 'admin'),
            ])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$and' => [
            ['name' => ['$regex' => '?']],
            ['email' => ['$regex' => '?']],
        ]], $op['filter']);
        $this->assertSame(['test', '^admin'], $result->bindings);
    }

    public function testNotContainsWithContainsCombined(): void
    {
        $result = (new Builder())
            ->from('posts')
            ->filter([
                Query::notContains('body', ['spam']),
                Query::containsString('body', ['valuable']),
            ])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$and' => [
            ['body' => ['$not' => ['$regex' => '?']]],
            ['body' => ['$regex' => '?']],
        ]], $op['filter']);
        $this->assertSame(['spam', 'valuable'], $result->bindings);
    }

    public function testMultipleEqualOnDifferentFields(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([
                Query::equal('name', ['Alice']),
                Query::equal('city', ['NYC']),
                Query::equal('role', ['admin']),
            ])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$and' => [
            ['name' => '?'],
            ['city' => '?'],
            ['role' => '?'],
        ]], $op['filter']);
        $this->assertSame(['Alice', 'NYC', 'admin'], $result->bindings);
    }

    public function testEqualMultiValueInEquivalent(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('x', [1, 2, 3])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['x' => ['$in' => ['?', '?', '?']]], $op['filter']);
        $this->assertSame([1, 2, 3], $result->bindings);
    }

    public function testNotEqualMultiValueNinEquivalent(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::notEqual('x', [1, 2, 3])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['x' => ['$nin' => ['?', '?', '?']]], $op['filter']);
        $this->assertSame([1, 2, 3], $result->bindings);
    }

    public function testEqualBooleanValue(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('active', [true])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['active' => '?'], $op['filter']);
        $this->assertSame([true], $result->bindings);
    }

    public function testEqualEmptyStringValue(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('name', [''])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['name' => '?'], $op['filter']);
        $this->assertSame([''], $result->bindings);
    }

    public function testRegexWithOtherFilters(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([
                Query::regex('name', '^[A-Z]'),
                Query::greaterThan('age', 18),
                Query::equal('status', ['active']),
            ])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$and' => [
            ['name' => ['$regex' => '?']],
            ['age' => ['$gt' => '?']],
            ['status' => '?'],
        ]], $op['filter']);
        $this->assertSame(['^[A-Z]', 18, 'active'], $result->bindings);
    }

    public function testContainsAllWithMultipleValues(): void
    {
        $result = (new Builder())
            ->from('posts')
            ->filter([Query::containsAll('tags', ['php', 'mongodb', 'testing'])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$and' => [
            ['tags' => ['$regex' => '?']],
            ['tags' => ['$regex' => '?']],
            ['tags' => ['$regex' => '?']],
        ]], $op['filter']);
        $this->assertSame(['php', 'mongodb', 'testing'], $result->bindings);
    }

    public function testFilterOnDottedNestedField(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('address.city', ['NYC'])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['address.city' => '?'], $op['filter']);
        $this->assertSame(['NYC'], $result->bindings);
    }

    public function testComplexQueryBindingOrder(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->filter([
                Query::equal('status', ['active']),
                Query::greaterThan('amount', 100),
                Query::between('created_at', '2024-01-01', '2024-12-31'),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame([
            'active',
            100,
            '2024-01-01',
            '2024-12-31',
        ], $result->bindings);
    }

    public function testJoinFilterHavingBindingPositions(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->join('users', 'orders.user_id', 'users.id', '=', 'u')
            ->filter([Query::greaterThan('amount', 50)])
            ->count('*', 'cnt')
            ->groupBy(['u.name'])
            ->having([Query::greaterThan('cnt', 10)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame([50, 10], $result->bindings);
    }

    public function testUnionBindingsInBothBranches(): void
    {
        $second = (new Builder())
            ->from('orders')
            ->select(['id'])
            ->filter([Query::equal('status', ['cancelled'])]);

        $result = (new Builder())
            ->from('orders')
            ->select(['id'])
            ->filter([Query::equal('status', ['active'])])
            ->unionAll($second)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['active', 'cancelled'], $result->bindings);
    }

    public function testSubqueryBindingsWithOuterQueryBindings(): void
    {
        $subquery = (new Builder())
            ->from('vip_users')
            ->select(['id'])
            ->filter([Query::equal('level', ['platinum'])]);

        $result = (new Builder())
            ->from('orders')
            ->filter([Query::greaterThan('total', 500)])
            ->filterWhereIn('user_id', $subquery)
            ->build();
        $this->assertBindingCount($result);

        $this->assertContains('platinum', $result->bindings);
        $this->assertContains(500, $result->bindings);
    }

    public function testUpdateWithFilterBindingsAndSetValueBindings(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['status' => 'banned', 'reason' => 'violation'])
            ->filter([
                Query::equal('role', ['user']),
                Query::lessThan('karma', -10),
            ])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame(['user', -10, 'banned', 'violation'], $result->bindings);
    }

    public function testInsertMultipleRowsBindingPositions(): void
    {
        $result = (new Builder())
            ->into('items')
            ->set(['a' => 1, 'b' => 'x'])
            ->set(['a' => 2, 'b' => 'y'])
            ->set(['a' => 3, 'b' => 'z'])
            ->insert();
        $this->assertBindingCount($result);

        $this->assertSame([1, 'x', 2, 'y', 3, 'z'], $result->bindings);
    }

    public function testSelectEmptyArray(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select([])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('find', $op['operation']);
        // Empty select still creates a projection with only _id suppressed
        $this->assertSame(['_id' => 0], $op['projection']);
    }

    public function testSelectStar(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['*'])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('find', $op['operation']);
        $this->assertArrayHasKey('projection', $op);
        /** @var array<string, int> $projection */
        $projection = $op['projection'];
        $this->assertSame(1, $projection['*']);
    }

    public function testSelectManyColumns(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['a', 'b', 'c', 'd', 'e'])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, int> $projection */
        $projection = $op['projection'];
        $this->assertSame(1, $projection['a']);
        $this->assertSame(1, $projection['b']);
        $this->assertSame(1, $projection['c']);
        $this->assertSame(1, $projection['d']);
        $this->assertSame(1, $projection['e']);
        $this->assertSame(0, $projection['_id']);
    }

    public function testCompoundSort(): void
    {
        $result = (new Builder())
            ->from('users')
            ->sortAsc('a')
            ->sortDesc('b')
            ->sortAsc('c')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['a' => 1, 'b' => -1, 'c' => 1], $op['sort']);
    }

    public function testLimitOne(): void
    {
        $result = (new Builder())
            ->from('users')
            ->limit(1)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(1, $op['limit']);
    }

    public function testOffsetZero(): void
    {
        $result = (new Builder())
            ->from('users')
            ->offset(0)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(0, $op['skip']);
    }

    public function testLargeLimit(): void
    {
        $result = (new Builder())
            ->from('users')
            ->limit(1000000)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(1000000, $op['limit']);
    }

    public function testGroupByThreeColumns(): void
    {
        $result = (new Builder())
            ->from('data')
            ->count('*', 'cnt')
            ->groupBy(['a', 'b', 'c'])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $groupStage = $this->findStage($pipeline, '$group');
        $this->assertNotNull($groupStage);
        /** @var array<string, mixed> $groupBody */
        $groupBody = $groupStage['$group'];
        $this->assertSame([
            'a' => '$a',
            'b' => '$b',
            'c' => '$c',
        ], $groupBody['_id']);
    }

    public function testDistinctWithoutExplicitSelect(): void
    {
        $result = (new Builder())
            ->from('users')
            ->distinct()
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);
    }

    public function testDistinctWithSelectAndSort(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['country', 'city'])
            ->distinct()
            ->sortAsc('country')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);

        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $groupStage = $this->findStage($pipeline, '$group');
        $this->assertNotNull($groupStage);

        $sortStage = $this->findStage($pipeline, '$sort');
        $this->assertNotNull($sortStage);
        /** @var array<string, int> $sortBody */
        $sortBody = $sortStage['$sort'];
        $this->assertSame(1, $sortBody['country']);
    }

    public function testCountStarWithoutGroupByWholeCollection(): void
    {
        $result = (new Builder())
            ->from('users')
            ->count('*', 'total')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);

        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $groupStage = $this->findStage($pipeline, '$group');
        $this->assertNotNull($groupStage);
        /** @var array<string, mixed> $groupBody */
        $groupBody = $groupStage['$group'];
        $this->assertNull($groupBody['_id']);
        $this->assertSame(['$sum' => 1], $groupBody['total']);
    }

    public function testReadOnlyFlagOnBuild(): void
    {
        $buildResult = (new Builder())
            ->from('users')
            ->select(['name'])
            ->build();
        $this->assertBindingCount($buildResult);
        $this->assertTrue($buildResult->readOnly);
    }

    public function testReadOnlyFlagOnInsert(): void
    {
        $insertResult = (new Builder())
            ->into('users')
            ->set(['name' => 'Alice'])
            ->insert();
        $this->assertBindingCount($insertResult);
        $this->assertFalse($insertResult->readOnly);
    }

    public function testReadOnlyFlagOnUpdate(): void
    {
        $updateResult = (new Builder())
            ->from('users')
            ->set(['name' => 'Alice'])
            ->update();
        $this->assertBindingCount($updateResult);
        $this->assertFalse($updateResult->readOnly);
    }

    public function testReadOnlyFlagOnDelete(): void
    {
        $deleteResult = (new Builder())
            ->from('users')
            ->delete();
        $this->assertBindingCount($deleteResult);
        $this->assertFalse($deleteResult->readOnly);
    }

    public function testCloneThenModifyOriginalUnchanged(): void
    {
        $original = (new Builder())
            ->from('users')
            ->select(['name'])
            ->filter([Query::equal('status', ['active'])]);

        $cloned = $original->clone();
        $cloned->filter([Query::greaterThan('age', 25)]);
        $cloned->sortDesc('age');
        $cloned->limit(5);

        $originalResult = $original->build();
        $clonedResult = $cloned->build();

        $this->assertCount(1, $originalResult->bindings);
        $this->assertCount(2, $clonedResult->bindings);

        $originalOp = $this->decode($originalResult->query);
        $this->assertSame('find', $originalOp['operation']);
        $this->assertArrayNotHasKey('sort', $originalOp);
        $this->assertArrayNotHasKey('limit', $originalOp);
    }

    public function testResetThenRebuild(): void
    {
        $builder = (new Builder())
            ->from('users')
            ->select(['name'])
            ->filter([Query::equal('status', ['active'])])
            ->sortAsc('name')
            ->limit(10);

        $builder->reset();

        $builder->from('orders')
            ->select(['id'])
            ->filter([Query::greaterThan('total', 100)])
            ->build();

        $result = $builder->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('orders', $op['collection']);
        $this->assertSame(['total' => ['$gt' => '?']], $op['filter']);
        $this->assertSame([100], $result->bindings);
    }

    public function testMultipleSetCallsForUpdate(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['name' => 'First'])
            ->set(['name' => 'Second', 'email' => 'test@test.com'])
            ->filter([Query::equal('id', ['abc'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        $this->assertArrayHasKey('$set', $update);
        /** @var array<string, string> $setDoc */
        $setDoc = $update['$set'];
        $this->assertSame('?', $setDoc['name']);
    }

    public function testEmptyOrLogicalProducesExprFalse(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::or([])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $filter */
        $filter = $op['filter'];
        $this->assertArrayHasKey('$expr', $filter);
        $this->assertFalse($filter['$expr']);
    }

    public function testEmptyAndLogicalProducesNoFilter(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::and([])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        // Empty AND returns [], buildFilter returns [], buildFind skips it
        $this->assertArrayNotHasKey('filter', $op);
    }

    public function testTextSearchIsFirstPipelineStage(): void
    {
        $result = (new Builder())
            ->from('articles')
            ->filterSearch('content', 'mongodb')
            ->filter([Query::equal('status', ['published'])])
            ->sortDesc('score')
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        /** @var array<string, mixed> $firstStage */
        $firstStage = $pipeline[0];
        $this->assertArrayHasKey('$match', $firstStage);
        /** @var array<string, mixed> $matchBody */
        $matchBody = $firstStage['$match'];
        $this->assertArrayHasKey('$text', $matchBody);

        $this->assertSame(['mongodb', 'published'], $result->bindings);
    }

    public function testTableSamplingBeforeFilters(): void
    {
        $result = (new Builder())
            ->from('logs')
            ->tablesample(500)
            ->filter([Query::equal('level', ['error'])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $sampleIdx = $this->findStageIndex($pipeline, '$sample');
        $matchIdx = $this->findStageIndex($pipeline, '$match');

        $this->assertNotNull($sampleIdx);
        $this->assertNotNull($matchIdx);
        $this->assertLessThan($matchIdx, $sampleIdx);
    }

    public function testSortRandomWithFilter(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('active', [true])])
            ->sortRandom()
            ->limit(5)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);

        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $addFieldsStage = $this->findStage($pipeline, '$addFields');
        $this->assertNotNull($addFieldsStage);

        $sortStage = $this->findStage($pipeline, '$sort');
        $this->assertNotNull($sortStage);
        /** @var array<string, int> $sortBody */
        $sortBody = $sortStage['$sort'];
        $this->assertSame(1, $sortBody['_rand']);

        $unsetStage = $this->findStage($pipeline, '$unset');
        $this->assertNotNull($unsetStage);
        $this->assertSame('_rand', $unsetStage['$unset']);
    }

    public function testUpdateWithSetAndPushAndIncrement(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['last_login' => '2024-06-15'])
            ->push('activity_log', 'logged_in')
            ->increment('login_count', 1)
            ->addToSet('badges', 'frequent_user')
            ->pull('temp_flags', 'old_flag')
            ->unsetFields('deprecated', 'legacy')
            ->filter([Query::equal('_id', ['user123'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        $this->assertArrayHasKey('$set', $update);
        $this->assertArrayHasKey('$push', $update);
        $this->assertArrayHasKey('$inc', $update);
        $this->assertArrayHasKey('$addToSet', $update);
        $this->assertArrayHasKey('$pull', $update);
        $this->assertArrayHasKey('$unset', $update);

        /** @var array<string, string> $unsetDoc */
        $unsetDoc = $update['$unset'];
        $this->assertArrayHasKey('deprecated', $unsetDoc);
        $this->assertArrayHasKey('legacy', $unsetDoc);
    }

    public function testUpsertWithMultipleConflictKeys(): void
    {
        $result = (new Builder())
            ->into('metrics')
            ->set(['date' => '2024-06-15', 'metric' => 'pageviews', 'value' => 1500])
            ->onConflict(['date', 'metric'], ['value'])
            ->upsert();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('updateOne', $op['operation']);
        $this->assertSame(['date' => '?', 'metric' => '?'], $op['filter']);
        $this->assertSame(['$set' => ['value' => '?']], $op['update']);
        $this->assertSame(['2024-06-15', 'pageviews', 1500], $result->bindings);
    }

    public function testDeleteWithMultipleFilters(): void
    {
        $result = (new Builder())
            ->from('sessions')
            ->filter([
                Query::lessThan('expires_at', '2024-01-01'),
                Query::notEqual('persistent', true),
                Query::isNull('user_id'),
            ])
            ->delete();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('deleteMany', $op['operation']);

        /** @var array<string, mixed> $filter */
        $filter = $op['filter'];
        $this->assertArrayHasKey('$and', $filter);
        /** @var list<array<string, mixed>> $andConditions */
        $andConditions = $filter['$and'];
        $this->assertCount(3, $andConditions);

        $this->assertSame(['expires_at' => ['$lt' => '?']], $andConditions[0]);
        $this->assertSame(['persistent' => ['$ne' => '?']], $andConditions[1]);
        $this->assertSame(['user_id' => null], $andConditions[2]);

        $this->assertSame(['2024-01-01', true], $result->bindings);
    }

    public function testUpdateWithNoFilterProducesEmptyStdclass(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['updated_at' => '2024-06-15'])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('updateMany', $op['operation']);
        $this->assertEmpty((array) $op['filter']);
    }

    public function testInsertOrIgnorePreservesOrderedFalse(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'A', 'email' => 'a@b.com'])
            ->set(['name' => 'B', 'email' => 'b@b.com'])
            ->insertOrIgnore();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('insertMany', $op['operation']);
        /** @var array<string, mixed> $options */
        $options = $op['options'];
        $this->assertFalse($options['ordered']);
        /** @var list<array<string, mixed>> $documents */
        $documents = $op['documents'];
        $this->assertCount(2, $documents);
    }

    public function testPipelineStageOrderWithAllFeatures(): void
    {
        $subquery = (new Builder())
            ->from('vips')
            ->select(['user_id']);

        $unionBranch = (new Builder())
            ->from('archived')
            ->select(['name']);

        $result = (new Builder())
            ->from('users')
            ->filterSearch('bio', 'developer')
            ->join('profiles', 'users.id', 'profiles.user_id', '=', 'p')
            ->filterWhereIn('id', $subquery)
            ->filter([Query::equal('active', [true])])
            ->count('*', 'cnt')
            ->groupBy(['region'])
            ->having([Query::greaterThan('cnt', 5)])
            ->unionAll($unionBranch)
            ->sortDesc('cnt')
            ->limit(20)
            ->offset(10)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);

        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $stageTypes = [];
        foreach ($pipeline as $stage) {
            $stageTypes[] = \array_key_first($stage);
        }

        $textMatchPos = \array_search('$match', $stageTypes);
        $this->assertNotFalse($textMatchPos);
        $this->assertSame(0, $textMatchPos);
    }

    public function testWindowFunctionWithNullPartition(): void
    {
        $result = (new Builder())
            ->from('events')
            ->selectWindow('ROW_NUMBER()', 'global_rn', null, ['timestamp'])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $windowStage = $this->findStage($pipeline, '$setWindowFields');
        $this->assertNotNull($windowStage);

        /** @var array<string, mixed> $windowBody */
        $windowBody = $windowStage['$setWindowFields'];
        $this->assertArrayNotHasKey('partitionBy', $windowBody);
        $this->assertArrayHasKey('sortBy', $windowBody);
    }

    public function testWindowFunctionWithEmptyPartition(): void
    {
        $result = (new Builder())
            ->from('events')
            ->selectWindow('DENSE_RANK()', 'rnk', [], ['score'])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $windowStage = $this->findStage($pipeline, '$setWindowFields');
        $this->assertNotNull($windowStage);

        /** @var array<string, mixed> $windowBody */
        $windowBody = $windowStage['$setWindowFields'];
        $this->assertArrayNotHasKey('partitionBy', $windowBody);
    }

    public function testWindowFunctionWithNullOrderBy(): void
    {
        $result = (new Builder())
            ->from('events')
            ->selectWindow('SUM(amount)', 'total', ['category'], null)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $windowStage = $this->findStage($pipeline, '$setWindowFields');
        $this->assertNotNull($windowStage);

        /** @var array<string, mixed> $windowBody */
        $windowBody = $windowStage['$setWindowFields'];
        $this->assertArrayNotHasKey('sortBy', $windowBody);
    }

    public function testGroupByProjectReshape(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->sum('amount', 'total_sales')
            ->groupBy(['region'])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $projectStage = $this->findStage($pipeline, '$project');
        $this->assertNotNull($projectStage);
        /** @var array<string, mixed> $projectBody */
        $projectBody = $projectStage['$project'];
        $this->assertSame(0, $projectBody['_id']);
        $this->assertSame('$_id', $projectBody['region']);
        $this->assertSame(1, $projectBody['total_sales']);
    }

    public function testDoesNotExposeCrossOrNaturalJoins(): void
    {
        $builder = new Builder();
        $methods = \get_class_methods($builder);

        $this->assertArrayNotHasKey(CrossJoins::class, \class_implements($builder));
        $this->assertNotContains('crossJoin', $methods);
        $this->assertNotContains('naturalJoin', $methods);
    }

    public function testCrossJoinViaQueryStillReports(): void
    {
        $this->expectException(UnsupportedException::class);
        $this->expectExceptionMessage('cannot be expressed as a MongoDB $lookup');

        (new Builder())
            ->from('users')
            ->queries([Query::crossJoin('roles')])
            ->build();
    }

    public function testNaturalJoinViaQueryStillReports(): void
    {
        $this->expectException(UnsupportedException::class);
        $this->expectExceptionMessage('cannot be expressed as a MongoDB $lookup');

        (new Builder())
            ->from('users')
            ->queries([Query::naturalJoin('roles')])
            ->build();
    }

    public function testFilterEndsWithSpecialChars(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::endsWith('email', '.co.uk')])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['email' => ['$regex' => '?']], $op['filter']);
        $this->assertSame(['\.co\.uk$'], $result->bindings);
    }

    public function testFilterStartsWithSpecialChars(): void
    {
        $result = (new Builder())
            ->from('files')
            ->filter([Query::startsWith('path', '/var/log.')])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['path' => ['$regex' => '?']], $op['filter']);
        $this->assertSame(['^\/var\/log\.'], $result->bindings);
    }

    public function testFilterContainsWithSpecialCharsEscaped(): void
    {
        $result = (new Builder())
            ->from('logs')
            ->filter([Query::containsString('message', ['file.txt'])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['message' => ['$regex' => '?']], $op['filter']);
        $this->assertSame(['file\.txt'], $result->bindings);
    }

    public function testFilterGreaterThanEqualWithFloat(): void
    {
        $result = (new Builder())
            ->from('products')
            ->filter([Query::greaterThanEqual('price', 9.99)])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['price' => ['$gte' => '?']], $op['filter']);
        $this->assertSame([9.99], $result->bindings);
    }

    public function testFilterLessThanEqualWithZero(): void
    {
        $result = (new Builder())
            ->from('products')
            ->filter([Query::lessThanEqual('stock', 0)])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['stock' => ['$lte' => '?']], $op['filter']);
        $this->assertSame([0], $result->bindings);
    }

    public function testInsertSingleRowBindingStructure(): void
    {
        $result = (new Builder())
            ->into('logs')
            ->set(['level' => 'info', 'message' => 'test', 'timestamp' => 12345])
            ->insert();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $documents */
        $documents = $op['documents'];
        $this->assertCount(1, $documents);
        $this->assertSame(['level' => '?', 'message' => '?', 'timestamp' => '?'], $documents[0]);
        $this->assertSame(['info', 'test', 12345], $result->bindings);
    }

    public function testFindOperationHasNoProjectionWhenNoneSelected(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('active', [true])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('find', $op['operation']);
        $this->assertArrayNotHasKey('projection', $op);
    }

    public function testFindOperationHasNoSortWhenNoneSorted(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('active', [true])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertArrayNotHasKey('sort', $op);
    }

    public function testFindOperationHasNoSkipWhenNoOffset(): void
    {
        $result = (new Builder())
            ->from('users')
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertArrayNotHasKey('skip', $op);
    }

    public function testFindOperationHasNoLimitWhenNoLimit(): void
    {
        $result = (new Builder())
            ->from('users')
            ->offset(10)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertArrayNotHasKey('limit', $op);
    }

    public function testSelectIdFieldSuppressesIdExclusion(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['_id', 'name'])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, int> $projection */
        $projection = $op['projection'];
        $this->assertSame(1, $projection['_id']);
        $this->assertSame(1, $projection['name']);
    }

    public function testIncrementWithFloat(): void
    {
        $result = (new Builder())
            ->from('accounts')
            ->increment('balance', 99.50)
            ->filter([Query::equal('id', ['acc1'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        /** @var array<string, float> $incDoc */
        $incDoc = $update['$inc'];
        $this->assertSame(99.50, $incDoc['balance']);
    }

    public function testIncrementWithNegativeValue(): void
    {
        $result = (new Builder())
            ->from('counters')
            ->increment('value', -5)
            ->filter([Query::equal('name', ['test'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        /** @var array<string, int> $incDoc */
        $incDoc = $update['$inc'];
        $this->assertSame(-5, $incDoc['value']);
    }

    public function testUnsetMultipleFields(): void
    {
        $result = (new Builder())
            ->from('users')
            ->unsetFields('field_a', 'field_b', 'field_c')
            ->filter([Query::equal('id', ['x'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        /** @var array<string, string> $unsetDoc */
        $unsetDoc = $update['$unset'];
        $this->assertCount(3, $unsetDoc);
        $this->assertSame('', $unsetDoc['field_a']);
        $this->assertSame('', $unsetDoc['field_b']);
        $this->assertSame('', $unsetDoc['field_c']);
    }

    public function testResetClearsMongoSpecificState(): void
    {
        $builder = (new Builder())
            ->from('users')
            ->push('tags', 'a')
            ->pull('tags', 'b')
            ->addToSet('roles', 'admin')
            ->increment('counter', 1)
            ->unsetFields('temp')
            ->filterSearch('bio', 'test')
            ->tablesample(50);

        $builder->reset();
        $builder->from('items')->set(['name' => 'item1']);

        $result = $builder->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('items', $op['collection']);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        $this->assertArrayHasKey('$set', $update);
        $this->assertArrayNotHasKey('$push', $update);
        $this->assertArrayNotHasKey('$pull', $update);
        $this->assertArrayNotHasKey('$addToSet', $update);
        $this->assertArrayNotHasKey('$inc', $update);
        $this->assertArrayNotHasKey('$unset', $update);
    }

    public function testSingleFilterDoesNotWrapInAnd(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('name', ['Alice'])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['name' => '?'], $op['filter']);
        $this->assertArrayNotHasKey('$and', $op['filter']);
    }

    public function testPageCalculation(): void
    {
        $result = (new Builder())
            ->from('users')
            ->page(5, 20)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(20, $op['limit']);
        $this->assertSame(80, $op['skip']);
    }

    public function testTextSearchAndTableSamplingCombined(): void
    {
        $result = (new Builder())
            ->from('articles')
            ->filterSearch('content', 'tutorial')
            ->tablesample(200)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $this->assertArrayHasKey('$match', $pipeline[0]);
        /** @var array<string, mixed> $firstMatch */
        $firstMatch = $pipeline[0]['$match'];
        $this->assertArrayHasKey('$text', $firstMatch);

        $this->assertArrayHasKey('$sample', $pipeline[1]);
        /** @var array<string, mixed> $sampleBody */
        $sampleBody = $pipeline[1]['$sample'];
        $this->assertSame(200, $sampleBody['size']);
    }

    public function testNotBetweenStructure(): void
    {
        $result = (new Builder())
            ->from('products')
            ->filter([Query::notBetween('price', 10.0, 50.0)])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['$or' => [
            ['price' => ['$lt' => '?']],
            ['price' => ['$gt' => '?']],
        ]], $op['filter']);
        $this->assertSame([10.0, 50.0], $result->bindings);
    }

    public function testContainsAnyOnArrayUsesIn(): void
    {
        $query = Query::containsAny('tags', ['a', 'b', 'c']);
        $query->setOnArray(true);

        $result = (new Builder())
            ->from('posts')
            ->filter([$query])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame(['tags' => ['$in' => ['?', '?', '?']]], $op['filter']);
        $this->assertSame(['a', 'b', 'c'], $result->bindings);
    }

    public function testFilterWhereNotInSubqueryStructure(): void
    {
        $subquery = (new Builder())
            ->from('blacklist')
            ->select(['user_id']);

        $result = (new Builder())
            ->from('users')
            ->filterWhereNotIn('id', $subquery)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $lookupStage = $this->findStage($pipeline, '$lookup');
        $this->assertNotNull($lookupStage);
        /** @var array<string, mixed> $lookupBody */
        $lookupBody = $lookupStage['$lookup'];
        $this->assertSame('blacklist', $lookupBody['from']);
        $this->assertSame('_sub_0', $lookupBody['as']);

        $unsetStage = $this->findStage($pipeline, '$unset');
        $this->assertNotNull($unsetStage);
    }

    public function testBuildIdempotent(): void
    {
        $builder = (new Builder())
            ->from('users')
            ->select(['name'])
            ->filter([Query::equal('status', ['active'])])
            ->sortAsc('name')
            ->limit(10);

        $result1 = $builder->build();
        $result2 = $builder->build();

        $this->assertSame($result1->query, $result2->query);
        $this->assertSame($result1->bindings, $result2->bindings);
    }

    public function testExistsSubqueryAddsLimitOnePipeline(): void
    {
        $subquery = (new Builder())
            ->from('orders')
            ->select(['user_id']);

        $result = (new Builder())
            ->from('users')
            ->filterExists($subquery)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $lookupStage = $this->findStage($pipeline, '$lookup');
        $this->assertNotNull($lookupStage);
        /** @var array<string, mixed> $lookupBody */
        $lookupBody = $lookupStage['$lookup'];
        /** @var list<array<string, mixed>> $subPipeline */
        $subPipeline = $lookupBody['pipeline'];

        $hasLimit = false;
        foreach ($subPipeline as $stage) {
            if (isset($stage['$limit'])) {
                $hasLimit = true;
                $this->assertSame(1, $stage['$limit']);
            }
        }
        $this->assertTrue($hasLimit);
    }

    public function testJoinStripTablePrefix(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->join('users', 'orders.user_id', 'users._id', '=', 'u')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $lookupStage = $this->findStage($pipeline, '$lookup');
        $this->assertNotNull($lookupStage);
        /** @var array<string, mixed> $lookupBody */
        $lookupBody = $lookupStage['$lookup'];
        $this->assertSame('user_id', $lookupBody['localField']);
        $this->assertSame('_id', $lookupBody['foreignField']);
    }

    public function testJoinDefaultAliasUsesTableName(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->join('users', 'orders.user_id', 'users.id')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $lookupStage = $this->findStage($pipeline, '$lookup');
        $this->assertNotNull($lookupStage);
        /** @var array<string, mixed> $lookupBody */
        $lookupBody = $lookupStage['$lookup'];
        $this->assertSame('users', $lookupBody['as']);
    }

    public function testSortRandomWithSortAscCombined(): void
    {
        $result = (new Builder())
            ->from('users')
            ->sortAsc('name')
            ->sortRandom()
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);

        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $sortStage = $this->findStage($pipeline, '$sort');
        $this->assertNotNull($sortStage);
        /** @var array<string, int> $sortBody */
        $sortBody = $sortStage['$sort'];
        $this->assertSame(1, $sortBody['name']);
        $this->assertSame(1, $sortBody['_rand']);
    }

    public function testImplementsFieldUpdates(): void
    {
        $this->assertInstanceOf(FieldUpdates::class, new Builder());
    }

    public function testImplementsArrayPushModifiers(): void
    {
        $this->assertInstanceOf(ArrayPushModifiers::class, new Builder());
    }

    public function testImplementsConditionalArrayUpdates(): void
    {
        $this->assertInstanceOf(ConditionalArrayUpdates::class, new Builder());
    }

    public function testImplementsPipelineStages(): void
    {
        $this->assertInstanceOf(PipelineStages::class, new Builder());
    }

    public function testImplementsAtlasSearch(): void
    {
        $this->assertInstanceOf(AtlasSearch::class, new Builder());
    }

    public function testRenameField(): void
    {
        $result = (new Builder())
            ->from('users')
            ->rename('old_name', 'new_name')
            ->filter([Query::equal('_id', ['abc'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('updateMany', $op['operation']);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        $this->assertArrayHasKey('$rename', $update);
        $this->assertSame(['old_name' => 'new_name'], $update['$rename']);
    }

    public function testMultiply(): void
    {
        $result = (new Builder())
            ->from('products')
            ->multiply('price', 1.1)
            ->filter([Query::equal('category', ['sale'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        $this->assertArrayHasKey('$mul', $update);
        $this->assertSame(['price' => 1.1], $update['$mul']);
    }

    public function testPopFirst(): void
    {
        $result = (new Builder())
            ->from('users')
            ->popFirst('tags')
            ->filter([Query::equal('_id', ['abc'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        $this->assertArrayHasKey('$pop', $update);
        $this->assertSame(['tags' => -1], $update['$pop']);
    }

    public function testPopLast(): void
    {
        $result = (new Builder())
            ->from('users')
            ->popLast('tags')
            ->filter([Query::equal('_id', ['abc'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        $this->assertArrayHasKey('$pop', $update);
        $this->assertSame(['tags' => 1], $update['$pop']);
    }

    public function testPullAll(): void
    {
        $result = (new Builder())
            ->from('users')
            ->pullAll('scores', [0, 5])
            ->filter([Query::equal('_id', ['abc'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        $this->assertArrayHasKey('$pullAll', $update);
        /** @var array<string, array<string>> $pullAll */
        $pullAll = $update['$pullAll'];
        $this->assertSame(['?', '?'], $pullAll['scores']);
        $this->assertContains(0, $result->bindings);
        $this->assertContains(5, $result->bindings);
    }

    public function testUpdateMin(): void
    {
        $result = (new Builder())
            ->from('users')
            ->updateMin('low_score', 50)
            ->filter([Query::equal('_id', ['abc'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        $this->assertArrayHasKey('$min', $update);
        $this->assertSame(['low_score' => '?'], $update['$min']);
        $this->assertContains(50, $result->bindings);
    }

    public function testUpdateMax(): void
    {
        $result = (new Builder())
            ->from('users')
            ->updateMax('high_score', 100)
            ->filter([Query::equal('_id', ['abc'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        $this->assertArrayHasKey('$max', $update);
        $this->assertSame(['high_score' => '?'], $update['$max']);
        $this->assertContains(100, $result->bindings);
    }

    public function testCurrentDate(): void
    {
        $result = (new Builder())
            ->from('users')
            ->currentDate('lastModified', 'date')
            ->filter([Query::equal('_id', ['abc'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        $this->assertArrayHasKey('$currentDate', $update);
        $this->assertSame(['lastModified' => ['$type' => 'date']], $update['$currentDate']);
    }

    public function testCurrentDateTimestamp(): void
    {
        $result = (new Builder())
            ->from('users')
            ->currentDate('lastModified', 'timestamp')
            ->filter([Query::equal('_id', ['abc'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        $this->assertSame(['lastModified' => ['$type' => 'timestamp']], $update['$currentDate']);
    }

    public function testMultipleUpdateOperators(): void
    {
        $result = (new Builder())
            ->from('users')
            ->rename('old_field', 'new_field')
            ->multiply('score', 2)
            ->popLast('queue')
            ->updateMin('min_val', 10)
            ->updateMax('max_val', 100)
            ->currentDate('updated_at')
            ->filter([Query::equal('_id', ['abc'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        $this->assertArrayHasKey('$rename', $update);
        $this->assertArrayHasKey('$mul', $update);
        $this->assertArrayHasKey('$pop', $update);
        $this->assertArrayHasKey('$min', $update);
        $this->assertArrayHasKey('$max', $update);
        $this->assertArrayHasKey('$currentDate', $update);
    }

    public function testPushEachBasic(): void
    {
        $result = (new Builder())
            ->from('users')
            ->pushEach('tags', ['a', 'b', 'c'])
            ->filter([Query::equal('_id', ['abc'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        $this->assertArrayHasKey('$push', $update);
        /** @var array<string, mixed> $pushDoc */
        $pushDoc = $update['$push'];
        /** @var array<string, mixed> $tagsModifier */
        $tagsModifier = $pushDoc['tags'];
        $this->assertSame(['?', '?', '?'], $tagsModifier['$each']);
        $this->assertArrayNotHasKey('$position', $tagsModifier);
        $this->assertArrayNotHasKey('$slice', $tagsModifier);
        $this->assertArrayNotHasKey('$sort', $tagsModifier);
    }

    public function testPushEachWithAllModifiers(): void
    {
        $result = (new Builder())
            ->from('users')
            ->pushEach('scores', [85, 92], 0, 5, ['score' => -1])
            ->filter([Query::equal('_id', ['abc'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        /** @var array<string, mixed> $pushDoc */
        $pushDoc = $update['$push'];
        /** @var array<string, mixed> $scoresModifier */
        $scoresModifier = $pushDoc['scores'];
        $this->assertSame(['?', '?'], $scoresModifier['$each']);
        $this->assertSame(0, $scoresModifier['$position']);
        $this->assertSame(5, $scoresModifier['$slice']);
        $this->assertSame(['score' => -1], $scoresModifier['$sort']);
    }

    public function testPushEachWithPosition(): void
    {
        $result = (new Builder())
            ->from('users')
            ->pushEach('items', ['x'], 2)
            ->filter([Query::equal('_id', ['abc'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        /** @var array<string, mixed> $pushDoc */
        $pushDoc = $update['$push'];
        /** @var array<string, mixed> $itemsModifier */
        $itemsModifier = $pushDoc['items'];
        $this->assertSame(2, $itemsModifier['$position']);
        $this->assertArrayNotHasKey('$slice', $itemsModifier);
    }

    public function testPushEachWithSlice(): void
    {
        $result = (new Builder())
            ->from('users')
            ->pushEach('items', ['a', 'b'], null, 10)
            ->filter([Query::equal('_id', ['abc'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        /** @var array<string, mixed> $pushDoc */
        $pushDoc = $update['$push'];
        /** @var array<string, mixed> $itemsModifier */
        $itemsModifier = $pushDoc['items'];
        $this->assertSame(10, $itemsModifier['$slice']);
        $this->assertArrayNotHasKey('$position', $itemsModifier);
    }

    public function testPushAndPushEachCombined(): void
    {
        $result = (new Builder())
            ->from('users')
            ->push('simple_field', 'value')
            ->pushEach('array_field', ['a', 'b'])
            ->filter([Query::equal('_id', ['abc'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        /** @var array<string, mixed> $pushDoc */
        $pushDoc = $update['$push'];
        $this->assertSame('?', $pushDoc['simple_field']);
        $this->assertIsArray($pushDoc['array_field']);
    }

    public function testArrayFilter(): void
    {
        $result = (new Builder())
            ->from('students')
            ->set(['grades.$[elem].mean' => 0])
            ->arrayFilter('elem', ['elem.grade' => ['$gte' => 85]])
            ->filter([Query::equal('_id', ['abc'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('updateMany', $op['operation']);
        $this->assertArrayHasKey('options', $op);
        /** @var array<string, mixed> $options */
        $options = $op['options'];
        $this->assertArrayHasKey('arrayFilters', $options);
        /** @var list<array<string, mixed>> $filters */
        $filters = $options['arrayFilters'];
        $this->assertCount(1, $filters);
        $this->assertArrayHasKey('elem.grade', $filters[0]);
        $this->assertSame(['$gte' => 85], $filters[0]['elem.grade']);
    }

    public function testMultipleArrayFilters(): void
    {
        $result = (new Builder())
            ->from('students')
            ->set(['grades.$[elem].adjusted' => true])
            ->arrayFilter('elem', ['elem.grade' => ['$gte' => 85]])
            ->arrayFilter('other', ['other.type' => 'test'])
            ->filter([Query::equal('_id', ['abc'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $options */
        $options = $op['options'];
        /** @var list<array<string, mixed>> $filters */
        $filters = $options['arrayFilters'];
        $this->assertCount(2, $filters);
        $this->assertArrayHasKey('elem.grade', $filters[0]);
        $this->assertArrayHasKey('other.type', $filters[1]);
    }

    public function testBucket(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->bucket('price', [0, 100, 200, 300], 'Other', ['count' => ['$sum' => 1]])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);

        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $bucketStage = $this->findStage($pipeline, '$bucket');
        $this->assertNotNull($bucketStage);
        /** @var array<string, mixed> $bucketBody */
        $bucketBody = $bucketStage['$bucket'];
        $this->assertSame('$price', $bucketBody['groupBy']);
        $this->assertSame([0, 100, 200, 300], $bucketBody['boundaries']);
        $this->assertSame('Other', $bucketBody['default']);
        $this->assertSame(['count' => ['$sum' => 1]], $bucketBody['output']);
    }

    public function testBucketWithoutDefault(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->bucket('amount', [0, 50, 100])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $bucketStage = $this->findStage($pipeline, '$bucket');
        $this->assertNotNull($bucketStage);
        /** @var array<string, mixed> $bucketBody */
        $bucketBody = $bucketStage['$bucket'];
        $this->assertArrayNotHasKey('default', $bucketBody);
        $this->assertArrayNotHasKey('output', $bucketBody);
    }

    public function testBucketAuto(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->bucketAuto('price', 5, ['count' => ['$sum' => 1]])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $bucketAutoStage = $this->findStage($pipeline, '$bucketAuto');
        $this->assertNotNull($bucketAutoStage);
        /** @var array<string, mixed> $bucketAutoBody */
        $bucketAutoBody = $bucketAutoStage['$bucketAuto'];
        $this->assertSame('$price', $bucketAutoBody['groupBy']);
        $this->assertSame(5, $bucketAutoBody['buckets']);
        $this->assertSame(['count' => ['$sum' => 1]], $bucketAutoBody['output']);
    }

    public function testBucketAutoWithoutOutput(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->bucketAuto('amount', 10)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $bucketAutoStage = $this->findStage($pipeline, '$bucketAuto');
        $this->assertNotNull($bucketAutoStage);
        /** @var array<string, mixed> $bucketAutoBody */
        $bucketAutoBody = $bucketAutoStage['$bucketAuto'];
        $this->assertArrayNotHasKey('output', $bucketAutoBody);
    }

    public function testFacet(): void
    {
        $priceFacet = (new Builder())
            ->from('products')
            ->bucket('price', [0, 100, 200]);

        $categoryFacet = (new Builder())
            ->from('products')
            ->count('*', 'total')
            ->groupBy(['category']);

        $result = (new Builder())
            ->from('products')
            ->facet([
                'priceFacet' => $priceFacet,
                'categoryFacet' => $categoryFacet,
            ])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);

        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $facetStage = $this->findStage($pipeline, '$facet');
        $this->assertNotNull($facetStage);
        /** @var array<string, mixed> $facetBody */
        $facetBody = $facetStage['$facet'];
        $this->assertArrayHasKey('priceFacet', $facetBody);
        $this->assertArrayHasKey('categoryFacet', $facetBody);
        $this->assertIsArray($facetBody['priceFacet']);
        $this->assertIsArray($facetBody['categoryFacet']);
    }

    public function testGraphLookup(): void
    {
        $result = (new Builder())
            ->from('employees')
            ->graphLookup('employees', 'managerId', 'managerId', '_id', 'reportingHierarchy', 5, 'depth')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);

        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $graphLookupStage = $this->findStage($pipeline, '$graphLookup');
        $this->assertNotNull($graphLookupStage);
        /** @var array<string, mixed> $graphLookupBody */
        $graphLookupBody = $graphLookupStage['$graphLookup'];
        $this->assertSame('employees', $graphLookupBody['from']);
        $this->assertSame('$managerId', $graphLookupBody['startWith']);
        $this->assertSame('managerId', $graphLookupBody['connectFromField']);
        $this->assertSame('_id', $graphLookupBody['connectToField']);
        $this->assertSame('reportingHierarchy', $graphLookupBody['as']);
        $this->assertSame(5, $graphLookupBody['maxDepth']);
        $this->assertSame('depth', $graphLookupBody['depthField']);
    }

    public function testGraphLookupWithoutOptionalFields(): void
    {
        $result = (new Builder())
            ->from('categories')
            ->graphLookup('categories', 'parentId', 'parentId', '_id', 'ancestors')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $graphLookupStage = $this->findStage($pipeline, '$graphLookup');
        $this->assertNotNull($graphLookupStage);
        /** @var array<string, mixed> $graphLookupBody */
        $graphLookupBody = $graphLookupStage['$graphLookup'];
        $this->assertArrayNotHasKey('maxDepth', $graphLookupBody);
        $this->assertArrayNotHasKey('depthField', $graphLookupBody);
    }

    public function testMergeIntoCollection(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'total')
            ->groupBy(['region'])
            ->mergeIntoCollection('order_summary', ['_id'], ['replace'], ['insert'])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $mergeStage = $this->findStage($pipeline, '$merge');
        $this->assertNotNull($mergeStage);
        /** @var array<string, mixed> $mergeBody */
        $mergeBody = $mergeStage['$merge'];
        $this->assertSame('order_summary', $mergeBody['into']);
        $this->assertSame(['_id'], $mergeBody['on']);
        $this->assertSame(['replace'], $mergeBody['whenMatched']);
        $this->assertSame(['insert'], $mergeBody['whenNotMatched']);
    }

    public function testMergeIntoCollectionMinimal(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'total')
            ->mergeIntoCollection('summary')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $mergeStage = $this->findStage($pipeline, '$merge');
        $this->assertNotNull($mergeStage);
        /** @var array<string, mixed> $mergeBody */
        $mergeBody = $mergeStage['$merge'];
        $this->assertSame('summary', $mergeBody['into']);
        $this->assertArrayNotHasKey('on', $mergeBody);
        $this->assertArrayNotHasKey('whenMatched', $mergeBody);
    }

    public function testMergeIsLastPipelineStage(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'total')
            ->sortDesc('total')
            ->limit(10)
            ->mergeIntoCollection('output')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $lastStage = $pipeline[\count($pipeline) - 1];
        $this->assertArrayHasKey('$merge', $lastStage);
    }

    public function testOutputToCollection(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'total')
            ->outputToCollection('order_results')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $outStage = $this->findStage($pipeline, '$out');
        $this->assertNotNull($outStage);
        $this->assertSame('order_results', $outStage['$out']);
    }

    public function testOutputToCollectionWithDatabase(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'total')
            ->outputToCollection('results', 'analytics_db')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $outStage = $this->findStage($pipeline, '$out');
        $this->assertNotNull($outStage);
        /** @var array<string, string> $outBody */
        $outBody = $outStage['$out'];
        $this->assertSame('analytics_db', $outBody['db']);
        $this->assertSame('results', $outBody['coll']);
    }

    public function testOutputIsLastPipelineStage(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'total')
            ->limit(5)
            ->outputToCollection('output')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $lastStage = $pipeline[\count($pipeline) - 1];
        $this->assertArrayHasKey('$out', $lastStage);
    }

    public function testMergeTakesPrecedenceOverOut(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'total')
            ->mergeIntoCollection('merge_target')
            ->outputToCollection('out_target')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $mergeStage = $this->findStage($pipeline, '$merge');
        $this->assertNotNull($mergeStage);
        $outStage = $this->findStage($pipeline, '$out');
        $this->assertNull($outStage);
    }

    public function testReplaceRoot(): void
    {
        $result = (new Builder())
            ->from('users')
            ->replaceRoot('$profile')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);

        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $replaceRootStage = $this->findStage($pipeline, '$replaceRoot');
        $this->assertNotNull($replaceRootStage);
        /** @var array<string, mixed> $replaceRootBody */
        $replaceRootBody = $replaceRootStage['$replaceRoot'];
        $this->assertSame('$profile', $replaceRootBody['newRoot']);
    }

    public function testAtlasSearch(): void
    {
        $result = (new Builder())
            ->from('articles')
            ->search(['text' => ['query' => 'mongodb', 'path' => 'content']], 'default')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);

        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $this->assertArrayHasKey('$search', $pipeline[0]);
        /** @var array<string, mixed> $searchBody */
        $searchBody = $pipeline[0]['$search'];
        $this->assertSame('default', $searchBody['index']);
        $this->assertSame(['query' => 'mongodb', 'path' => 'content'], $searchBody['text']);
    }

    public function testAtlasSearchWithoutIndex(): void
    {
        $result = (new Builder())
            ->from('articles')
            ->search(['text' => ['query' => 'test', 'path' => 'title']])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        /** @var array<string, mixed> $searchBody */
        $searchBody = $pipeline[0]['$search'];
        $this->assertArrayNotHasKey('index', $searchBody);
    }

    public function testAtlasSearchIsFirstStage(): void
    {
        $result = (new Builder())
            ->from('articles')
            ->filter([Query::equal('status', ['published'])])
            ->search(['text' => ['query' => 'test', 'path' => 'content']], 'default')
            ->sortDesc('score')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $this->assertArrayHasKey('$search', $pipeline[0]);
    }

    public function testSearchMeta(): void
    {
        $result = (new Builder())
            ->from('articles')
            ->searchMeta(['facet' => ['operator' => ['text' => ['query' => 'test', 'path' => 'content']], 'facets' => ['categories' => ['type' => 'string', 'path' => 'category']]]], 'default')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);

        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $this->assertCount(1, $pipeline);
        $this->assertArrayHasKey('$searchMeta', $pipeline[0]);
    }

    public function testVectorSearch(): void
    {
        $result = (new Builder())
            ->from('products')
            ->vectorSearch('embedding', [0.1, 0.2, 0.3], 100, 10, 'vector_index', ['category' => 'electronics'])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);

        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $this->assertArrayHasKey('$vectorSearch', $pipeline[0]);
        /** @var array<string, mixed> $vectorSearchBody */
        $vectorSearchBody = $pipeline[0]['$vectorSearch'];
        $this->assertSame('embedding', $vectorSearchBody['path']);
        $this->assertSame([0.1, 0.2, 0.3], $vectorSearchBody['queryVector']);
        $this->assertSame(100, $vectorSearchBody['numCandidates']);
        $this->assertSame(10, $vectorSearchBody['limit']);
        $this->assertSame('vector_index', $vectorSearchBody['index']);
        $this->assertSame(['category' => 'electronics'], $vectorSearchBody['filter']);
    }

    public function testVectorSearchWithoutOptionalFields(): void
    {
        $result = (new Builder())
            ->from('products')
            ->vectorSearch('embedding', [0.5, 0.5], 50, 5)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        /** @var array<string, mixed> $vectorSearchBody */
        $vectorSearchBody = $pipeline[0]['$vectorSearch'];
        $this->assertArrayNotHasKey('index', $vectorSearchBody);
        $this->assertArrayNotHasKey('filter', $vectorSearchBody);
    }

    public function testVectorSearchIsFirstStage(): void
    {
        $result = (new Builder())
            ->from('products')
            ->filter([Query::equal('active', [true])])
            ->vectorSearch('embedding', [0.1, 0.2], 100, 10)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $this->assertArrayHasKey('$vectorSearch', $pipeline[0]);
    }

    public function testHintStringOnFind(): void
    {
        $result = (new Builder())
            ->from('users')
            ->hint('idx_name')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('find', $op['operation']);
        $this->assertSame('idx_name', $op['hint']);
    }

    public function testHintArrayOnFind(): void
    {
        $result = (new Builder())
            ->from('users')
            ->hint(['name' => 1, 'age' => -1])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('find', $op['operation']);
        $this->assertSame(['name' => 1, 'age' => -1], $op['hint']);
    }

    public function testHintOnAggregate(): void
    {
        $result = (new Builder())
            ->from('users')
            ->count('*', 'total')
            ->hint('idx_name')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);
        $this->assertSame('idx_name', $op['hint']);
    }

    public function testResetClearsNewProperties(): void
    {
        $builder = (new Builder())
            ->from('users')
            ->rename('a', 'b')
            ->multiply('c', 2)
            ->popFirst('d')
            ->pullAll('e', [1])
            ->updateMin('f', 0)
            ->updateMax('g', 100)
            ->currentDate('h')
            ->pushEach('i', [1, 2])
            ->arrayFilter('elem', ['elem.x' => 1])
            ->hint('idx');

        $builder->reset();
        $builder->from('items')->set(['name' => 'item1']);

        $result = $builder->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        $this->assertArrayHasKey('$set', $update);
        $this->assertArrayNotHasKey('$rename', $update);
        $this->assertArrayNotHasKey('$mul', $update);
        $this->assertArrayNotHasKey('$pop', $update);
        $this->assertArrayNotHasKey('$pullAll', $update);
        $this->assertArrayNotHasKey('$min', $update);
        $this->assertArrayNotHasKey('$max', $update);
        $this->assertArrayNotHasKey('$currentDate', $update);
        $this->assertArrayNotHasKey('options', $op);
        $this->assertArrayNotHasKey('hint', $op);
    }

    public function testResetClearsPipelineStages(): void
    {
        $builder = (new Builder())
            ->from('products')
            ->bucket('price', [0, 100])
            ->replaceRoot('$data')
            ->mergeIntoCollection('output');

        $builder->reset();
        $builder->from('items');

        $result = $builder->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('find', $op['operation']);
    }

    public function testResetClearsSearchStages(): void
    {
        $builder = (new Builder())
            ->from('articles')
            ->search(['text' => ['query' => 'test', 'path' => 'content']]);

        $builder->reset();
        $builder->from('articles');

        $result = $builder->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('find', $op['operation']);
    }

    public function testBucketReplacesGroupBy(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->bucket('price', [0, 50, 100, 200])
            ->filter([Query::greaterThan('quantity', 0)])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $matchIdx = $this->findStageIndex($pipeline, '$match');
        $bucketIdx = $this->findStageIndex($pipeline, '$bucket');
        $this->assertNotNull($matchIdx);
        $this->assertNotNull($bucketIdx);
        $this->assertLessThan($bucketIdx, $matchIdx);

        $groupStage = $this->findStage($pipeline, '$group');
        $this->assertNull($groupStage);
    }

    public function testGraphLookupWithFilter(): void
    {
        $result = (new Builder())
            ->from('employees')
            ->graphLookup('employees', 'reportsTo', 'reportsTo', '_id', 'hierarchy', 3)
            ->filter([Query::equal('department', ['engineering'])])
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $graphLookupIdx = $this->findStageIndex($pipeline, '$graphLookup');
        $matchIdx = $this->findStageIndex($pipeline, '$match');
        $this->assertNotNull($graphLookupIdx);
        $this->assertNotNull($matchIdx);
        $this->assertLessThan($matchIdx, $graphLookupIdx);
    }

    public function testSearchWithFilterAndSort(): void
    {
        $result = (new Builder())
            ->from('articles')
            ->search(['text' => ['query' => 'mongodb', 'path' => 'content']], 'default')
            ->filter([Query::equal('status', ['published'])])
            ->sortDesc('score')
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $searchIdx = $this->findStageIndex($pipeline, '$search');
        $matchIdx = $this->findStageIndex($pipeline, '$match');
        $sortIdx = $this->findStageIndex($pipeline, '$sort');
        $limitIdx = $this->findStageIndex($pipeline, '$limit');

        $this->assertNotNull($searchIdx);
        $this->assertNotNull($matchIdx);
        $this->assertNotNull($sortIdx);
        $this->assertNotNull($limitIdx);

        $this->assertSame(0, $searchIdx);
        $this->assertLessThan($matchIdx, $searchIdx);
        $this->assertLessThan($sortIdx, $matchIdx);
        $this->assertLessThan($limitIdx, $sortIdx);
    }

    public function testAllUpdateOperatorsCombined(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['name' => 'Updated'])
            ->increment('counter', 1)
            ->push('log', 'entry')
            ->pull('obsolete', 'old')
            ->addToSet('roles', 'editor')
            ->unsetFields('temp')
            ->rename('oldField', 'newField')
            ->multiply('score', 1.5)
            ->popFirst('queue')
            ->pullAll('tags', ['a', 'b'])
            ->updateMin('min_val', 0)
            ->updateMax('max_val', 999)
            ->currentDate('modified_at', 'timestamp')
            ->pushEach('items', ['x', 'y'], 0, 10)
            ->filter([Query::equal('_id', ['test'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        $this->assertArrayHasKey('$set', $update);
        $this->assertArrayHasKey('$inc', $update);
        $this->assertArrayHasKey('$push', $update);
        $this->assertArrayHasKey('$pull', $update);
        $this->assertArrayHasKey('$addToSet', $update);
        $this->assertArrayHasKey('$unset', $update);
        $this->assertArrayHasKey('$rename', $update);
        $this->assertArrayHasKey('$mul', $update);
        $this->assertArrayHasKey('$pop', $update);
        $this->assertArrayHasKey('$pullAll', $update);
        $this->assertArrayHasKey('$min', $update);
        $this->assertArrayHasKey('$max', $update);
        $this->assertArrayHasKey('$currentDate', $update);
    }

    public function testBucketWithFilterAndSort(): void
    {
        $result = (new Builder())
            ->from('products')
            ->filter([Query::equal('active', [true])])
            ->bucket('price', [0, 25, 50, 100, 200], 'expensive', ['count' => ['$sum' => 1], 'avgPrice' => ['$avg' => '$price']])
            ->sortDesc('count')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $matchIdx = $this->findStageIndex($pipeline, '$match');
        $bucketIdx = $this->findStageIndex($pipeline, '$bucket');
        $sortIdx = $this->findStageIndex($pipeline, '$sort');

        $this->assertNotNull($matchIdx);
        $this->assertNotNull($bucketIdx);
        $this->assertNotNull($sortIdx);

        $this->assertLessThan($bucketIdx, $matchIdx);
        $this->assertLessThan($sortIdx, $bucketIdx);
    }

    public function testHintOnSearchMeta(): void
    {
        $result = (new Builder())
            ->from('articles')
            ->searchMeta(['count' => ['type' => 'total']], 'default')
            ->hint('search_idx')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertSame('aggregate', $op['operation']);
        $this->assertSame('search_idx', $op['hint']);
    }

    public function testReplaceRootAfterGroupBy(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->count('*', 'total')
            ->groupBy(['region'])
            ->replaceRoot('$stats')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $groupIdx = $this->findStageIndex($pipeline, '$group');
        $replaceRootIdx = $this->findStageIndex($pipeline, '$replaceRoot');

        $this->assertNotNull($groupIdx);
        $this->assertNotNull($replaceRootIdx);
        $this->assertLessThan($replaceRootIdx, $groupIdx);
    }

    public function testUpdateWithNoArrayFiltersHasNoOptions(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['name' => 'test'])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertArrayNotHasKey('options', $op);
    }

    public function testMultiplyWithInteger(): void
    {
        $result = (new Builder())
            ->from('products')
            ->multiply('quantity', 2)
            ->filter([Query::equal('_id', ['abc'])])
            ->update();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var array<string, mixed> $update */
        $update = $op['update'];
        $this->assertSame(['quantity' => 2], $update['$mul']);
    }

    public function testBucketAutoWithFilter(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->filter([Query::greaterThan('amount', 0)])
            ->bucketAuto('amount', 4)
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];

        $matchIdx = $this->findStageIndex($pipeline, '$match');
        $bucketAutoIdx = $this->findStageIndex($pipeline, '$bucketAuto');

        $this->assertNotNull($matchIdx);
        $this->assertNotNull($bucketAutoIdx);
        $this->assertLessThan($bucketAutoIdx, $matchIdx);
    }

    public function testNoHintOnFindByDefault(): void
    {
        $result = (new Builder())
            ->from('users')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertArrayNotHasKey('hint', $op);
    }

    public function testNoHintOnAggregateByDefault(): void
    {
        $result = (new Builder())
            ->from('users')
            ->count('*', 'total')
            ->build();
        $this->assertBindingCount($result);

        $op = $this->decode($result->query);
        $this->assertArrayNotHasKey('hint', $op);
    }

    /**
     * @return list<array{0: string, 1: callable(Builder, string): mixed}>
     */
    public static function fieldNameRejectingSetters(): array
    {
        return [
            ['set',          fn (Builder $b, string $f) => $b->set([$f => 1])],
            ['push',         fn (Builder $b, string $f) => $b->push($f, 1)],
            ['pull',         fn (Builder $b, string $f) => $b->pull($f, 1)],
            ['pullAll',      fn (Builder $b, string $f) => $b->pullAll($f, [1])],
            ['addToSet',     fn (Builder $b, string $f) => $b->addToSet($f, 1)],
            ['increment',    fn (Builder $b, string $f) => $b->increment($f, 1)],
            ['multiply',     fn (Builder $b, string $f) => $b->multiply($f, 2)],
            ['rename',       fn (Builder $b, string $f) => $b->rename($f, 'ok')],
            ['renameTarget', fn (Builder $b, string $f) => $b->rename('ok', $f)],
            ['unsetFields',  fn (Builder $b, string $f) => $b->unsetFields($f)],
            ['currentDate',  fn (Builder $b, string $f) => $b->currentDate($f)],
            ['popFirst',     fn (Builder $b, string $f) => $b->popFirst($f)],
            ['popLast',      fn (Builder $b, string $f) => $b->popLast($f)],
            ['updateMin',    fn (Builder $b, string $f) => $b->updateMin($f, 1)],
            ['updateMax',    fn (Builder $b, string $f) => $b->updateMax($f, 1)],
            ['pushEach',     fn (Builder $b, string $f) => $b->pushEach($f, [1])],
        ];
    }

    /**
     * @param callable(Builder, string): mixed $action
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('fieldNameRejectingSetters')]
    public function testSetterRejectsDollarPrefixedFieldName(string $label, callable $action): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid MongoDB field name');

        $action(new Builder(), '$where');
    }

    /**
     * @param callable(Builder, string): mixed $action
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('fieldNameRejectingSetters')]
    public function testSetterRejectsEmptyFieldName(string $label, callable $action): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid MongoDB field name');

        $action(new Builder(), '');
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

        $op = $this->decode($result->query);
        $this->assertArrayHasKey('projection', $op);
        $this->assertIsArray($op['projection']);
        $this->assertArrayHasKey($word, $op['projection']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reservedWordsProvider')]
    public function testReservedWordInFrom(string $word): void
    {
        $result = (new Builder())
            ->from($word)
            ->build();

        $op = $this->decode($result->query);
        $this->assertSame($word, $op['collection']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reservedWordsProvider')]
    public function testReservedWordInFilter(string $word): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal($word, ['x'])])
            ->build();

        $op = $this->decode($result->query);
        $this->assertArrayHasKey('filter', $op);
        $this->assertIsArray($op['filter']);
        $this->assertArrayHasKey($word, $op['filter']);
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

        $op = $this->decode($result->query);
        $this->assertArrayHasKey('projection', $op);
        $this->assertIsArray($op['projection']);
        $this->assertArrayHasKey($identifier, $op['projection']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unicodeIdentifiersProvider')]
    public function testUnicodeIdentifierInFrom(string $identifier): void
    {
        $result = (new Builder())
            ->from($identifier)
            ->build();

        $op = $this->decode($result->query);
        $this->assertSame($identifier, $op['collection']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unicodeIdentifiersProvider')]
    public function testUnicodeIdentifierInFilter(string $identifier): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal($identifier, ['x'])])
            ->build();

        $op = $this->decode($result->query);
        $this->assertArrayHasKey('filter', $op);
        $this->assertIsArray($op['filter']);
        $this->assertArrayHasKey($identifier, $op['filter']);
        $this->assertSame(['x'], $result->bindings);
    }

    public function testUpdateUsesOperatorEnumsForMixedOperations(): void
    {
        $builder = (new Builder())
            ->from('users')
            ->set(['status' => 'active'])
            ->push('tags', 'vip')
            ->increment('loginCount', 1)
            ->unsetFields('deprecatedField');

        $plan = $builder->update();
        /** @var array<string, mixed> $operation */
        $operation = \json_decode($plan->query, true);

        $this->assertSame(Operation::UpdateMany->value, $operation['operation']);

        /** @var array<string, mixed> $update */
        $update = $operation['update'];

        $this->assertArrayHasKey(UpdateOperator::Set->value, $update);
        $this->assertArrayHasKey(UpdateOperator::Push->value, $update);
        $this->assertArrayHasKey(UpdateOperator::Increment->value, $update);
        $this->assertArrayHasKey(UpdateOperator::Unset->value, $update);

        $this->assertSame(['status' => '?'], $update[UpdateOperator::Set->value]);
        $this->assertSame(['tags' => '?'], $update[UpdateOperator::Push->value]);
        $this->assertSame(['loginCount' => 1], $update[UpdateOperator::Increment->value]);
        $this->assertSame(['deprecatedField' => ''], $update[UpdateOperator::Unset->value]);
    }

    public function testUpdateOperatorEnumValuesMatchMongoStrings(): void
    {
        $this->assertSame('$set', UpdateOperator::Set->value);
        $this->assertSame('$push', UpdateOperator::Push->value);
        $this->assertSame('$pull', UpdateOperator::Pull->value);
        $this->assertSame('$pullAll', UpdateOperator::PullAll->value);
        $this->assertSame('$addToSet', UpdateOperator::AddToSet->value);
        $this->assertSame('$inc', UpdateOperator::Increment->value);
        $this->assertSame('$mul', UpdateOperator::Multiply->value);
        $this->assertSame('$unset', UpdateOperator::Unset->value);
        $this->assertSame('$rename', UpdateOperator::Rename->value);
        $this->assertSame('$pop', UpdateOperator::Pop->value);
        $this->assertSame('$min', UpdateOperator::Min->value);
        $this->assertSame('$max', UpdateOperator::Max->value);
        $this->assertSame('$currentDate', UpdateOperator::CurrentDate->value);
    }

    public function testDoesNotImplementRawSql(): void
    {
        $builder = new Builder();

        $this->assertArrayNotHasKey(RawSql::class, \class_implements($builder));

        $methods = \get_class_methods($builder);

        foreach ([
            'selectRaw', 'selectCast', 'orderByRaw', 'groupByRaw', 'havingRaw',
            'whereRaw', 'whereColumn', 'selectCase', 'setCase', 'setRaw',
            'conflictSetRaw', 'insertColumnExpression',
        ] as $method) {
            $this->assertNotContains(
                $method,
                $methods,
                "MongoDB builder must not expose {$method}()"
            );
        }
    }

}
