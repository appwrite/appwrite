<?php

namespace Tests\Integration\Builder;

use Tests\Integration\IntegrationTestCase;
use Utopia\Query\Builder\MongoDB as Builder;
use Utopia\Query\Query;

class MongoDBIntegrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->connectMongoDB();

        $this->trackMongoCollection('mg_users');
        $this->trackMongoCollection('mg_orders');

        $client = $this->mongoClient;
        $this->assertNotNull($client);

        $client->dropCollection('mg_users');
        $client->dropCollection('mg_orders');

        $client->insertMany('mg_users', [
            ['id' => 1, 'name' => 'Alice', 'email' => 'alice@test.com', 'age' => 30, 'country' => 'US', 'active' => true],
            ['id' => 2, 'name' => 'Bob', 'email' => 'bob@test.com', 'age' => 25, 'country' => 'UK', 'active' => true],
            ['id' => 3, 'name' => 'Charlie', 'email' => 'charlie@test.com', 'age' => 35, 'country' => 'US', 'active' => false],
            ['id' => 4, 'name' => 'Diana', 'email' => 'diana@test.com', 'age' => 28, 'country' => 'DE', 'active' => true],
            ['id' => 5, 'name' => 'Eve', 'email' => 'eve@test.com', 'age' => 22, 'country' => 'UK', 'active' => true],
        ]);

        $client->insertMany('mg_orders', [
            ['id' => 1, 'user_id' => 1, 'product' => 'Widget', 'amount' => 29.99, 'status' => 'completed'],
            ['id' => 2, 'user_id' => 1, 'product' => 'Gadget', 'amount' => 49.99, 'status' => 'completed'],
            ['id' => 3, 'user_id' => 2, 'product' => 'Widget', 'amount' => 29.99, 'status' => 'pending'],
            ['id' => 4, 'user_id' => 3, 'product' => 'Gizmo', 'amount' => 99.99, 'status' => 'completed'],
            ['id' => 5, 'user_id' => 4, 'product' => 'Widget', 'amount' => 29.99, 'status' => 'cancelled'],
            ['id' => 6, 'user_id' => 4, 'product' => 'Gadget', 'amount' => 49.99, 'status' => 'pending'],
            ['id' => 7, 'user_id' => 5, 'product' => 'Gizmo', 'amount' => 99.99, 'status' => 'completed'],
        ]);
    }

    public function testSelectWithWhere(): void
    {
        $result = (new Builder())
            ->from('mg_users')
            ->select(['id', 'name', 'country'])
            ->filter([Query::equal('country', ['US'])])
            ->build();

        $rows = $this->executeOnMongoDB($result);

        $this->assertCount(2, $rows);
        $names = \array_column($rows, 'name');
        $this->assertContains('Alice', $names);
        $this->assertContains('Charlie', $names);
    }

    public function testSelectWithOrderByAndLimit(): void
    {
        $result = (new Builder())
            ->from('mg_users')
            ->select(['id', 'name', 'age'])
            ->sortDesc('age')
            ->limit(3)
            ->build();

        $rows = $this->executeOnMongoDB($result);

        $this->assertCount(3, $rows);
        $this->assertSame('Charlie', $rows[0]['name']);
        $this->assertSame('Alice', $rows[1]['name']);
        $this->assertSame('Diana', $rows[2]['name']);
    }

    public function testSelectWithJoin(): void
    {
        $result = (new Builder())
            ->from('mg_orders')
            ->select(['id', 'product', 'u.name'])
            ->join('mg_users', 'mg_orders.user_id', 'mg_users.id', '=', 'u')
            ->filter([Query::equal('status', ['completed'])])
            ->sortAsc('id')
            ->build();

        $rows = $this->executeOnMongoDB($result);

        $this->assertCount(4, $rows);
        /** @var array<string, mixed> $joined */
        $joined = $rows[0]['u'];
        $this->assertSame('Alice', $joined['name']);
    }

    public function testSelectWithLeftJoin(): void
    {
        $result = (new Builder())
            ->from('mg_users')
            ->select(['name'])
            ->leftJoin('mg_orders', 'mg_users.id', 'mg_orders.user_id', '=', 'o')
            ->filter([Query::equal('o.status', ['cancelled'])])
            ->sortAsc('name')
            ->build();

        $rows = $this->executeOnMongoDB($result);

        $this->assertCount(1, $rows);
        $this->assertSame('Diana', $rows[0]['name']);
    }

    public function testInsertSingleRow(): void
    {
        $insert = (new Builder())
            ->into('mg_users')
            ->set(['id' => 10, 'name' => 'Frank', 'email' => 'frank@test.com', 'age' => 40, 'country' => 'FR', 'active' => true])
            ->insert();

        $this->executeOnMongoDB($insert);

        $select = (new Builder())
            ->from('mg_users')
            ->select(['id', 'name'])
            ->filter([Query::equal('id', [10])])
            ->build();

        $rows = $this->executeOnMongoDB($select);

        $this->assertCount(1, $rows);
        $this->assertSame('Frank', $rows[0]['name']);
    }

    public function testInsertMultipleRows(): void
    {
        $insert = (new Builder())
            ->into('mg_users')
            ->set(['id' => 10, 'name' => 'Frank', 'email' => 'frank@test.com', 'age' => 40, 'country' => 'FR', 'active' => true])
            ->set(['id' => 11, 'name' => 'Grace', 'email' => 'grace@test.com', 'age' => 33, 'country' => 'FR', 'active' => true])
            ->insert();

        $this->executeOnMongoDB($insert);

        $select = (new Builder())
            ->from('mg_users')
            ->select(['id', 'name'])
            ->filter([Query::equal('country', ['FR'])])
            ->sortAsc('id')
            ->build();

        $rows = $this->executeOnMongoDB($select);

        $this->assertCount(2, $rows);
        $this->assertSame('Frank', $rows[0]['name']);
        $this->assertSame('Grace', $rows[1]['name']);
    }

    public function testUpdateWithWhere(): void
    {
        $update = (new Builder())
            ->from('mg_users')
            ->set(['country' => 'CA'])
            ->filter([Query::equal('name', ['Alice'])])
            ->update();

        $this->executeOnMongoDB($update);

        $select = (new Builder())
            ->from('mg_users')
            ->select(['country'])
            ->filter([Query::equal('name', ['Alice'])])
            ->build();

        $rows = $this->executeOnMongoDB($select);

        $this->assertCount(1, $rows);
        $this->assertSame('CA', $rows[0]['country']);
    }

    public function testDeleteWithWhere(): void
    {
        $delete = (new Builder())
            ->from('mg_users')
            ->filter([Query::equal('name', ['Eve'])])
            ->delete();

        $this->executeOnMongoDB($delete);

        $select = (new Builder())
            ->from('mg_users')
            ->filter([Query::equal('name', ['Eve'])])
            ->build();

        $rows = $this->executeOnMongoDB($select);

        $this->assertCount(0, $rows);
    }

    public function testSelectWithGroupByAndHaving(): void
    {
        $result = (new Builder())
            ->from('mg_orders')
            ->select(['status'])
            ->count('*', 'cnt')
            ->groupBy(['status'])
            ->having([Query::greaterThan('cnt', 1)])
            ->build();

        $rows = $this->executeOnMongoDB($result);

        $statuses = \array_column($rows, 'status');
        $this->assertContains('completed', $statuses);
        foreach ($rows as $row) {
            /** @var int $cnt */
            $cnt = $row['cnt'];
            $this->assertGreaterThan(1, $cnt);
        }
    }

    public function testSelectWithUnionAll(): void
    {
        $first = (new Builder())
            ->from('mg_users')
            ->select(['name'])
            ->filter([Query::equal('country', ['US'])]);

        $second = (new Builder())
            ->from('mg_users')
            ->select(['name'])
            ->filter([Query::equal('country', ['UK'])]);

        $result = $first->unionAll($second)->build();

        $rows = $this->executeOnMongoDB($result);

        $this->assertCount(4, $rows);
        $names = \array_column($rows, 'name');
        $this->assertContains('Alice', $names);
        $this->assertContains('Charlie', $names);
        $this->assertContains('Bob', $names);
        $this->assertContains('Eve', $names);
    }

    public function testSelectWithDistinct(): void
    {
        $result = (new Builder())
            ->from('mg_users')
            ->select(['country'])
            ->distinct()
            ->sortAsc('country')
            ->build();

        $rows = $this->executeOnMongoDB($result);

        $this->assertCount(3, $rows);
        $countries = \array_column($rows, 'country');
        $this->assertSame(['DE', 'UK', 'US'], $countries);
    }

    public function testSelectWithSubqueryInWhere(): void
    {
        $subquery = (new Builder())
            ->from('mg_orders')
            ->select(['user_id'])
            ->filter([Query::equal('status', ['completed'])]);

        $result = (new Builder())
            ->from('mg_users')
            ->select(['id', 'name'])
            ->filterWhereIn('id', $subquery)
            ->sortAsc('id')
            ->build();

        $rows = $this->executeOnMongoDB($result);

        $this->assertCount(3, $rows);
        $names = \array_column($rows, 'name');
        $this->assertContains('Alice', $names);
        $this->assertContains('Charlie', $names);
        $this->assertContains('Eve', $names);
    }

    public function testUpsertOnConflict(): void
    {
        $result = (new Builder())
            ->into('mg_users')
            ->set(['email' => 'alice@test.com', 'name' => 'Alice Updated', 'age' => 31, 'country' => 'US', 'active' => true])
            ->onConflict(['email'], ['name', 'age'])
            ->upsert();

        $this->executeOnMongoDB($result);

        $check = (new Builder())
            ->from('mg_users')
            ->select(['name', 'age'])
            ->filter([Query::equal('email', ['alice@test.com'])])
            ->build();

        $rows = $this->executeOnMongoDB($check);

        $this->assertCount(1, $rows);
        $this->assertSame('Alice Updated', $rows[0]['name']);
        $this->assertSame(31, $rows[0]['age']);
    }

    public function testSelectWithWindowFunction(): void
    {
        $result = (new Builder())
            ->from('mg_orders')
            ->select(['user_id', 'product', 'amount'])
            ->selectWindow('ROW_NUMBER()', 'rn', ['user_id'], ['-amount'])
            ->sortAsc('user_id')
            ->sortDesc('amount')
            ->build();

        $rows = $this->executeOnMongoDB($result);

        $this->assertGreaterThan(0, \count($rows));
        $this->assertArrayHasKey('rn', $rows[0]);

        // Check first user's rows are numbered
        $user1Rows = \array_values(\array_filter($rows, fn ($r) => $r['user_id'] === 1));
        $this->assertSame(1, $user1Rows[0]['rn']);
        $this->assertSame(2, $user1Rows[1]['rn']);
    }

    public function testFilterStartsWith(): void
    {
        $result = (new Builder())
            ->from('mg_users')
            ->select(['name'])
            ->filter([Query::startsWith('name', 'Al')])
            ->build();

        $rows = $this->executeOnMongoDB($result);

        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
    }

    public function testFilterContains(): void
    {
        $result = (new Builder())
            ->from('mg_users')
            ->select(['name'])
            ->filter([Query::containsString('email', ['test.com'])])
            ->build();

        $rows = $this->executeOnMongoDB($result);

        $this->assertCount(5, $rows);
    }

    public function testFilterBetween(): void
    {
        $result = (new Builder())
            ->from('mg_users')
            ->select(['name', 'age'])
            ->filter([Query::between('age', 25, 30)])
            ->sortAsc('age')
            ->build();

        $rows = $this->executeOnMongoDB($result);

        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertGreaterThanOrEqual(25, $row['age']);
            $this->assertLessThanOrEqual(30, $row['age']);
        }
    }

    public function testSelectWithOffset(): void
    {
        $result = (new Builder())
            ->from('mg_users')
            ->select(['name'])
            ->sortAsc('name')
            ->limit(2)
            ->offset(1)
            ->build();

        $rows = $this->executeOnMongoDB($result);

        $this->assertCount(2, $rows);
        $this->assertSame('Bob', $rows[0]['name']);
        $this->assertSame('Charlie', $rows[1]['name']);
    }

    public function testFilterRegex(): void
    {
        $result = (new Builder())
            ->from('mg_users')
            ->select(['name'])
            ->filter([Query::regex('name', '^[A-C]')])
            ->sortAsc('name')
            ->build();

        $rows = $this->executeOnMongoDB($result);

        $this->assertCount(3, $rows);
        $names = \array_column($rows, 'name');
        $this->assertSame(['Alice', 'Bob', 'Charlie'], $names);
    }

    public function testAggregateSum(): void
    {
        $result = (new Builder())
            ->from('mg_orders')
            ->sum('amount', 'total')
            ->groupBy(['user_id'])
            ->sortAsc('user_id')
            ->build();

        $rows = $this->executeOnMongoDB($result);

        $this->assertGreaterThan(0, \count($rows));
        // User 1 has orders of 29.99 + 49.99 = 79.98
        $user1 = \array_values(\array_filter($rows, fn ($r) => $r['user_id'] === 1))[0];
        $this->assertEqualsWithDelta(79.98, $user1['total'], 0.01);
    }

    public function testFieldUpdateSet(): void
    {
        $this->trackMongoCollection('mg_field_updates');
        $this->mongoClient?->dropCollection('mg_field_updates');

        $this->executeOnMongoDB(
            (new Builder())
                ->into('mg_field_updates')
                ->set(['id' => 1, 'name' => 'Alice', 'age' => 30, 'email' => 'alice@example.com'])
                ->insert()
        );

        $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_field_updates')
                ->set(['nickname' => 'Ally'])
                ->filter([Query::equal('id', [1])])
                ->update()
        );

        $rows = $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_field_updates')
                ->select(['id', 'name', 'nickname'])
                ->filter([Query::equal('id', [1])])
                ->build()
        );

        $this->assertCount(1, $rows);
        $this->assertSame('Ally', $rows[0]['nickname']);
        $this->assertSame('Alice', $rows[0]['name']);
    }

    public function testFieldUpdateInc(): void
    {
        $this->trackMongoCollection('mg_field_updates');
        $this->mongoClient?->dropCollection('mg_field_updates');

        $this->executeOnMongoDB(
            (new Builder())
                ->into('mg_field_updates')
                ->set(['id' => 2, 'name' => 'Bob', 'age' => 25, 'email' => 'bob@example.com'])
                ->insert()
        );

        $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_field_updates')
                ->increment('age', 1)
                ->filter([Query::equal('id', [2])])
                ->update()
        );

        $rows = $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_field_updates')
                ->select(['id', 'age'])
                ->filter([Query::equal('id', [2])])
                ->build()
        );

        $this->assertCount(1, $rows);
        $this->assertSame(26, $rows[0]['age']);
    }

    public function testFieldUpdateRename(): void
    {
        $this->trackMongoCollection('mg_field_updates');
        $this->mongoClient?->dropCollection('mg_field_updates');

        $this->executeOnMongoDB(
            (new Builder())
                ->into('mg_field_updates')
                ->set(['id' => 3, 'name' => 'Carol', 'age' => 40, 'email' => 'carol@example.com'])
                ->insert()
        );

        $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_field_updates')
                ->rename('email', 'contact')
                ->filter([Query::equal('id', [3])])
                ->update()
        );

        $rows = $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_field_updates')
                ->select(['id', 'email', 'contact'])
                ->filter([Query::equal('id', [3])])
                ->build()
        );

        $this->assertCount(1, $rows);
        $this->assertSame('carol@example.com', $rows[0]['contact']);
        $this->assertArrayNotHasKey('email', $rows[0]);
    }

    public function testFieldUpdateUnset(): void
    {
        $this->trackMongoCollection('mg_field_updates');
        $this->mongoClient?->dropCollection('mg_field_updates');

        $this->executeOnMongoDB(
            (new Builder())
                ->into('mg_field_updates')
                ->set(['id' => 4, 'name' => 'Dave', 'age' => 35, 'email' => 'dave@example.com'])
                ->insert()
        );

        $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_field_updates')
                ->unsetFields('email')
                ->filter([Query::equal('id', [4])])
                ->update()
        );

        $rows = $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_field_updates')
                ->select(['id', 'name', 'email'])
                ->filter([Query::equal('id', [4])])
                ->build()
        );

        $this->assertCount(1, $rows);
        $this->assertSame('Dave', $rows[0]['name']);
        $this->assertArrayNotHasKey('email', $rows[0]);
    }

    public function testArrayPush(): void
    {
        $this->trackMongoCollection('mg_array_push');
        $this->mongoClient?->dropCollection('mg_array_push');

        $this->executeOnMongoDB(
            (new Builder())
                ->into('mg_array_push')
                ->set(['id' => 1, 'tags' => []])
                ->insert()
        );

        $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_array_push')
                ->push('tags', 'alpha')
                ->filter([Query::equal('id', [1])])
                ->update()
        );

        $rows = $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_array_push')
                ->select(['id', 'tags'])
                ->filter([Query::equal('id', [1])])
                ->build()
        );

        $this->assertCount(1, $rows);
        /** @var array<int, string> $tags */
        $tags = (array) $rows[0]['tags'];
        $this->assertSame(['alpha'], \array_values($tags));
    }

    public function testArrayAddToSet(): void
    {
        $this->trackMongoCollection('mg_array_push');
        $this->mongoClient?->dropCollection('mg_array_push');

        $this->executeOnMongoDB(
            (new Builder())
                ->into('mg_array_push')
                ->set(['id' => 2, 'tags' => ['alpha']])
                ->insert()
        );

        $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_array_push')
                ->addToSet('tags', 'alpha')
                ->filter([Query::equal('id', [2])])
                ->update()
        );

        $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_array_push')
                ->addToSet('tags', 'beta')
                ->filter([Query::equal('id', [2])])
                ->update()
        );

        $rows = $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_array_push')
                ->select(['id', 'tags'])
                ->filter([Query::equal('id', [2])])
                ->build()
        );

        $this->assertCount(1, $rows);
        /** @var array<int, string> $tags */
        $tags = (array) $rows[0]['tags'];
        $values = \array_values($tags);
        $this->assertSame(['alpha', 'beta'], $values);
    }

    public function testArrayPushEach(): void
    {
        $this->trackMongoCollection('mg_array_push');
        $this->mongoClient?->dropCollection('mg_array_push');

        $this->executeOnMongoDB(
            (new Builder())
                ->into('mg_array_push')
                ->set(['id' => 3, 'tags' => []])
                ->insert()
        );

        $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_array_push')
                ->pushEach('tags', ['x', 'y', 'z'])
                ->filter([Query::equal('id', [3])])
                ->update()
        );

        $rows = $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_array_push')
                ->select(['id', 'tags'])
                ->filter([Query::equal('id', [3])])
                ->build()
        );

        $this->assertCount(1, $rows);
        /** @var array<int, string> $tags */
        $tags = (array) $rows[0]['tags'];
        $this->assertSame(['x', 'y', 'z'], \array_values($tags));
    }

    public function testArrayPushEachWithSlice(): void
    {
        $this->trackMongoCollection('mg_array_push');
        $this->mongoClient?->dropCollection('mg_array_push');

        $this->executeOnMongoDB(
            (new Builder())
                ->into('mg_array_push')
                ->set(['id' => 4, 'tags' => ['a', 'b']])
                ->insert()
        );

        $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_array_push')
                ->pushEach('tags', ['c', 'd', 'e'], slice: 3)
                ->filter([Query::equal('id', [4])])
                ->update()
        );

        $rows = $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_array_push')
                ->select(['id', 'tags'])
                ->filter([Query::equal('id', [4])])
                ->build()
        );

        $this->assertCount(1, $rows);
        /** @var array<int, string> $tags */
        $tags = (array) $rows[0]['tags'];
        $values = \array_values($tags);
        $this->assertCount(3, $values);
        $this->assertSame(['a', 'b', 'c'], $values);
    }

    public function testPipelineFacet(): void
    {
        $this->trackMongoCollection('mg_scores');
        $this->mongoClient?->dropCollection('mg_scores');

        $documents = [];
        for ($i = 1; $i <= 10; $i++) {
            $documents[] = ['id' => $i, 'score' => $i * 10];
        }
        $this->mongoClient?->insertMany('mg_scores', $documents);

        $high = (new Builder())
            ->from('mg_scores')
            ->count('*', 'cnt')
            ->filter([Query::greaterThan('score', 50)]);

        $low = (new Builder())
            ->from('mg_scores')
            ->count('*', 'cnt')
            ->filter([Query::lessThanEqual('score', 50)]);

        $result = (new Builder())
            ->from('mg_scores')
            ->facet(['highScores' => $high, 'lowScores' => $low])
            ->build();

        $rows = $this->executeOnMongoDB($result);

        $this->assertCount(1, $rows);
        /** @var array<int, array<string, mixed>> $highBucket */
        $highBucket = (array) $rows[0]['highScores'];
        /** @var array<int, array<string, mixed>> $lowBucket */
        $lowBucket = (array) $rows[0]['lowScores'];

        $highFirst = (array) \array_values($highBucket)[0];
        $lowFirst = (array) \array_values($lowBucket)[0];

        $this->assertSame(5, $highFirst['cnt']);
        $this->assertSame(5, $lowFirst['cnt']);
    }

    public function testPipelineBucket(): void
    {
        $this->trackMongoCollection('mg_scores');
        $this->mongoClient?->dropCollection('mg_scores');

        $documents = [
            ['id' => 1, 'score' => 10],
            ['id' => 2, 'score' => 20],
            ['id' => 3, 'score' => 40],
            ['id' => 4, 'score' => 50],
            ['id' => 5, 'score' => 60],
            ['id' => 6, 'score' => 80],
            ['id' => 7, 'score' => 95],
        ];
        $this->mongoClient?->insertMany('mg_scores', $documents);

        $result = (new Builder())
            ->from('mg_scores')
            ->bucket('score', [0, 50, 100], 'other', ['count' => ['$sum' => 1]])
            ->build();

        $rows = $this->executeOnMongoDB($result);

        $counts = [];
        foreach ($rows as $row) {
            /** @var int $count */
            $count = $row['count'];
            $counts[] = $count;
        }
        \sort($counts);

        $this->assertSame([3, 4], $counts);
    }

    /**
     * Atlas $search requires a `mongot` sidecar process and an Atlas Search index.
     * The vanilla `mongo:7` image shipped by our docker-compose does NOT include
     * mongot, so `db.runCommand({aggregate, pipeline:[{$search:...}]})` is rejected
     * with "$search is not allowed in this atlas tier". This is as close as we can
     * get to "real" integration coverage without standing up Atlas Local
     * (`mongodb/mongodb-atlas-local`) — we assert on the shape of the Statement the
     * Builder produces and skip the actual round-trip.
     */
    public function testAtlasSearchQueryStructure(): void
    {
        $result = (new Builder())
            ->from('mg_articles')
            ->search(['text' => ['query' => 'hello world', 'path' => 'body']], 'default')
            ->build();

        /** @var array<string, mixed> $op */
        $op = \json_decode($result->query, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('mg_articles', $op['collection']);
        $this->assertSame('aggregate', $op['operation']);

        /** @var list<array<string, mixed>> $pipeline */
        $pipeline = $op['pipeline'];
        $this->assertNotEmpty($pipeline);
        $this->assertArrayHasKey('$search', $pipeline[0]);

        /** @var array<string, mixed> $searchStage */
        $searchStage = $pipeline[0]['$search'];
        $this->assertSame('default', $searchStage['index']);
        $this->assertSame(['query' => 'hello world', 'path' => 'body'], $searchStage['text']);
    }

    public function testArrayFilterUpdate(): void
    {
        $this->trackMongoCollection('mg_students');
        $this->mongoClient?->dropCollection('mg_students');

        $this->mongoClient?->insertMany('mg_students', [
            [
                'id' => 1,
                'name' => 'Alice',
                'grades' => [
                    ['subject' => 'math', 'grade' => 90, 'mean' => 0],
                    ['subject' => 'history', 'grade' => 70, 'mean' => 0],
                    ['subject' => 'science', 'grade' => 85, 'mean' => 0],
                ],
            ],
        ]);

        $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_students')
                ->set(['grades.$[elem].mean' => 100])
                ->arrayFilter('elem', ['elem.grade' => ['$gte' => 85]])
                ->filter([Query::equal('id', [1])])
                ->update()
        );

        $rows = $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_students')
                ->select(['id', 'grades'])
                ->filter([Query::equal('id', [1])])
                ->build()
        );

        $this->assertCount(1, $rows);
        /** @var list<array<string, mixed>> $grades */
        $grades = (array) $rows[0]['grades'];
        $bySubject = [];
        foreach ($grades as $entry) {
            $row = (array) $entry;
            /** @var string $subject */
            $subject = $row['subject'];
            $bySubject[$subject] = $row;
        }

        $this->assertSame(100, $bySubject['math']['mean']);
        $this->assertSame(100, $bySubject['science']['mean']);
        $this->assertSame(0, $bySubject['history']['mean']);
    }

    public function testFieldUpdateMultiply(): void
    {
        $this->trackMongoCollection('mg_products');
        $this->mongoClient?->dropCollection('mg_products');

        $this->executeOnMongoDB(
            (new Builder())
                ->into('mg_products')
                ->set(['id' => 1, 'name' => 'Widget', 'price' => 10.0])
                ->insert()
        );

        $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_products')
                ->multiply('price', 1.5)
                ->filter([Query::equal('id', [1])])
                ->update()
        );

        $rows = $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_products')
                ->select(['id', 'price'])
                ->filter([Query::equal('id', [1])])
                ->build()
        );

        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(15.0, $rows[0]['price'], 0.0001);
    }

    public function testFieldUpdatePopFirst(): void
    {
        $this->trackMongoCollection('mg_pop');
        $this->mongoClient?->dropCollection('mg_pop');

        $this->executeOnMongoDB(
            (new Builder())
                ->into('mg_pop')
                ->set(['id' => 1, 'tags' => ['a', 'b', 'c']])
                ->insert()
        );

        $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_pop')
                ->popFirst('tags')
                ->filter([Query::equal('id', [1])])
                ->update()
        );

        $rows = $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_pop')
                ->select(['id', 'tags'])
                ->filter([Query::equal('id', [1])])
                ->build()
        );

        /** @var array<int, string> $tags */
        $tags = (array) $rows[0]['tags'];
        $this->assertSame(['b', 'c'], \array_values($tags));
    }

    public function testFieldUpdatePopLast(): void
    {
        $this->trackMongoCollection('mg_pop');
        $this->mongoClient?->dropCollection('mg_pop');

        $this->executeOnMongoDB(
            (new Builder())
                ->into('mg_pop')
                ->set(['id' => 2, 'tags' => ['a', 'b', 'c']])
                ->insert()
        );

        $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_pop')
                ->popLast('tags')
                ->filter([Query::equal('id', [2])])
                ->update()
        );

        $rows = $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_pop')
                ->select(['id', 'tags'])
                ->filter([Query::equal('id', [2])])
                ->build()
        );

        /** @var array<int, string> $tags */
        $tags = (array) $rows[0]['tags'];
        $this->assertSame(['a', 'b'], \array_values($tags));
    }

    public function testFieldUpdatePullAll(): void
    {
        $this->trackMongoCollection('mg_pull');
        $this->mongoClient?->dropCollection('mg_pull');

        $this->executeOnMongoDB(
            (new Builder())
                ->into('mg_pull')
                ->set(['id' => 1, 'scores' => [10, 20, 30, 20, 40]])
                ->insert()
        );

        $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_pull')
                ->pullAll('scores', [20, 40])
                ->filter([Query::equal('id', [1])])
                ->update()
        );

        $rows = $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_pull')
                ->select(['id', 'scores'])
                ->filter([Query::equal('id', [1])])
                ->build()
        );

        /** @var array<int, int> $scores */
        $scores = (array) $rows[0]['scores'];
        $this->assertSame([10, 30], \array_values($scores));
    }

    public function testFieldUpdateMin(): void
    {
        $this->trackMongoCollection('mg_minmax');
        $this->mongoClient?->dropCollection('mg_minmax');

        $this->executeOnMongoDB(
            (new Builder())
                ->into('mg_minmax')
                ->set(['id' => 1, 'low_score' => 50])
                ->insert()
        );

        // $min with 30 (smaller) should update to 30.
        $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_minmax')
                ->updateMin('low_score', 30)
                ->filter([Query::equal('id', [1])])
                ->update()
        );

        // $min with 80 (larger) should NOT update.
        $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_minmax')
                ->updateMin('low_score', 80)
                ->filter([Query::equal('id', [1])])
                ->update()
        );

        $rows = $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_minmax')
                ->select(['id', 'low_score'])
                ->filter([Query::equal('id', [1])])
                ->build()
        );

        $this->assertCount(1, $rows);
        $this->assertSame(30, $rows[0]['low_score']);
    }

    public function testFieldUpdateMax(): void
    {
        $this->trackMongoCollection('mg_minmax');
        $this->mongoClient?->dropCollection('mg_minmax');

        $this->executeOnMongoDB(
            (new Builder())
                ->into('mg_minmax')
                ->set(['id' => 2, 'high_score' => 50])
                ->insert()
        );

        // $max with 80 (larger) should update to 80.
        $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_minmax')
                ->updateMax('high_score', 80)
                ->filter([Query::equal('id', [2])])
                ->update()
        );

        // $max with 20 (smaller) should NOT update.
        $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_minmax')
                ->updateMax('high_score', 20)
                ->filter([Query::equal('id', [2])])
                ->update()
        );

        $rows = $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_minmax')
                ->select(['id', 'high_score'])
                ->filter([Query::equal('id', [2])])
                ->build()
        );

        $this->assertCount(1, $rows);
        $this->assertSame(80, $rows[0]['high_score']);
    }

    public function testFieldUpdateCurrentDate(): void
    {
        $this->trackMongoCollection('mg_dates');
        $this->mongoClient?->dropCollection('mg_dates');

        $this->executeOnMongoDB(
            (new Builder())
                ->into('mg_dates')
                ->set(['id' => 1, 'name' => 'Alice'])
                ->insert()
        );

        $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_dates')
                ->currentDate('modified', 'date')
                ->filter([Query::equal('id', [1])])
                ->update()
        );

        $rows = $this->executeOnMongoDB(
            (new Builder())
                ->from('mg_dates')
                ->select(['id', 'modified'])
                ->filter([Query::equal('id', [1])])
                ->build()
        );

        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('modified', $rows[0]);
        // Executed with MongoDB driver, $currentDate produces a BSON date/UTCDateTime.
        $this->assertNotNull($rows[0]['modified']);
    }

    public function testPipelineGraphLookup(): void
    {
        $this->trackMongoCollection('mg_employees');
        $this->mongoClient?->dropCollection('mg_employees');

        // CEO (null manager) -> VP -> Dir -> Eng (self-referencing by manager field).
        $this->mongoClient?->insertMany('mg_employees', [
            ['id' => 1, 'name' => 'CEO', 'manager' => null],
            ['id' => 2, 'name' => 'VP', 'manager' => 1],
            ['id' => 3, 'name' => 'Director', 'manager' => 2],
            ['id' => 4, 'name' => 'Engineer', 'manager' => 3],
        ]);

        $result = (new Builder())
            ->from('mg_employees')
            ->graphLookup('mg_employees', 'manager', 'manager', 'id', 'reporting_chain')
            ->filter([Query::equal('id', [4])])
            ->build();

        $rows = $this->executeOnMongoDB($result);

        $this->assertCount(1, $rows);
        /** @var list<array<string, mixed>> $chain */
        $chain = (array) $rows[0]['reporting_chain'];
        $names = [];
        foreach ($chain as $entry) {
            $row = (array) $entry;
            /** @var string $name */
            $name = $row['name'];
            $names[] = $name;
        }
        \sort($names);

        // Engineer's reporting chain traverses upward through Director, VP, CEO.
        $this->assertSame(['CEO', 'Director', 'VP'], $names);
    }

    public function testPipelineReplaceRoot(): void
    {
        $this->trackMongoCollection('mg_orders_nested');
        $this->mongoClient?->dropCollection('mg_orders_nested');

        $this->mongoClient?->insertMany('mg_orders_nested', [
            ['id' => 1, 'customer' => ['name' => 'Alice', 'city' => 'NY']],
            ['id' => 2, 'customer' => ['name' => 'Bob', 'city' => 'LA']],
        ]);

        $result = (new Builder())
            ->from('mg_orders_nested')
            ->replaceRoot('$customer')
            ->sortAsc('name')
            ->build();

        $rows = $this->executeOnMongoDB($result);

        $this->assertCount(2, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame('NY', $rows[0]['city']);
        $this->assertSame('Bob', $rows[1]['name']);
        $this->assertSame('LA', $rows[1]['city']);
        // Original top-level `id` is gone because root was replaced.
        $this->assertArrayNotHasKey('id', $rows[0]);
    }
}
