<?php

namespace Utopia\Tests;

use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\TestCase;
use Utopia\Mongo\Client;
use Utopia\Mongo\Exception;

class MongoTest extends TestCase
{
    public static ?Client $db = null;

    /**
     * @throws Exception
     * @throws \Exception
     */
    public static function getDatabase(): Client
    {
        if (!is_null(self::$db)) {
            return self::$db;
        }

        $client = new Client(
            database: 'testing',
            host: 'mongo',
            port: 27017,
            user: 'root',
            password: 'example',
            useCoroutine: false
        );

        $client->connect();

        self::$db = $client;
        return self::$db;
    }

    public function testDeleteDatabase()
    {
        self::assertTrue($this->getDatabase()->dropDatabase());
    }

    public function testGetHost()
    {
        self::assertSame('mongo', $this->getDatabase()->getHost());
    }

    /**
     * @throws Exception
     */
    public function testCreateCollection()
    {
        self::assertTrue($this->getDatabase()->createCollection('movies'));
        self::assertTrue($this->getDatabase()->dropCollection('movies'));
        self::assertTrue($this->getDatabase()->createCollection('movies'));
        self::expectException(Exception::class);
        self::assertTrue($this->getDatabase()->createCollection('movies'));
    }

    public function testListDatabases()
    {
        self::assertCount(4, $this->getDatabase()->listDatabaseNames()->databases);
    }

    public function testCreateDocument()
    {
        $doc = $this->getDatabase()->insert(
            'movies',
            [
            'name' => 'Armageddon',
            'country' => 'USA',
            'language' => 'English'
            ]
        );

        $id = (string)$doc['_id'];
        self::assertEquals(36, strlen($id));

        $doc = $this->getDatabase()->find('movies', ['name' => 'Armageddon'])->cursor->firstBatch ?? [];
        self::assertCount(1, $doc);

        $doc = $this->getDatabase()->find('movies', ['_id' => $id])->cursor->firstBatch ?? [];
        self::assertCount(1, $doc);

        $doc = $this->getDatabase()->insert('movies', ['9 Monkeys']);
        $id = (string)$doc['_id'];
        self::assertEquals(36, strlen($id));

        $doc = $this->getDatabase()->insert(
            'movies',
            [
                'name' => 300,
                'country' => 'USA',
                'language' => 'English'
            ]
        );

        $doc = $this->getDatabase()->insert(
            'movies',
            [
                '_id' => 999,
                'array' => ['USA', 'UK', 'India'],
                'language' => 'English',
                'float' => 9.9,
                'integer' => 9,
                'is_open' => true,
                'date_string' => (new \DateTime())->format('Y-m-d\TH:i:s.vP'),
                'date_object' => new \DateTime()
            ]
        );

        $doc = $this->getDatabase()->find('movies', ['_id' => 999])->cursor->firstBatch ?? [];
        $doc = $doc[0];

        self::assertTrue($doc->is_open);
        self::assertIsFloat($doc->float);
        self::assertIsInt($doc->integer);
        self::assertIsArray($doc->array);
        self::assertIsString($doc->date_string);
        self::assertIsObject($doc->date_object); // Todo: This is not working can't retrieve the object back
    }

    public function testCreateDocuments(): array
    {
        $docs = $this->getDatabase()->insertMany(
            'movies',
            [
                [
                    'name' => 'Armageddon',
                    'country' => 'USA',
                    'language' => 'English'
                ],
                [
                    'name' => '9 Monkeys',
                    'country' => 'USA',
                    'language' => 'English'
                ],
                [
                    'name' => 300,
                    'country' => 'USA',
                    'language' => 'English'
                ]
            ]
        );

        self::assertCount(3, $docs);

        return $docs;
    }

    public function testUpdateDocument(): void
    {
        $this->getDatabase()->insert(
            'movies',
            [
                'name' => 'Armageddon',
                'country' => 'AUS',
                'language' => 'English'
            ]
        );

        $this->getDatabase()->update(
            'movies',
            ['name' => 'Armageddon'],
            ['$set' => ['name' => 'Armageddon 2']]
        );

        $doc = $this->getDatabase()->find(
            'movies',
            ['name' => 'Armageddon 2']
        )->cursor->firstBatch ?? [];

        self::assertCount(1, $doc);
    }

    /**
     * @depends testCreateDocuments
     * @return void
     * @throws Exception
     */
    public function testUpdateDocuments(array $documents): void
    {
        $this->getDatabase()->update(
            'movies',
            updates: ['$set' => ['name' => 'Armageddon 2']],
            multi: true
        );

        $docs = $this->getDatabase()->find(
            'movies',
            filters: ['name' => 'Armageddon 2']
        )->cursor->firstBatch ?? [];

        self::assertCount(8, $docs);
    }

    public function testUpdateMultipleDocuments(): void
    {
        $this->getDatabase()->update(
            'movies',
            ['name' => 'Armageddon 2'],
            ['$rename' => ['name' => 'title']],
            multi: true
        );

        $docs = $this->getDatabase()->find(
            'movies',
            ['title' => 'Armageddon 2']
        )->cursor->firstBatch ?? [];

        self::assertCount(8, $docs);
    }

    public function testDriverException()
    {
        self::expectException('MongoDB\Driver\Exception\InvalidArgumentException');
        self::expectExceptionCode(0);
        new ObjectId('triggerAnError');
    }

    public function testDuplicationException()
    {
        self::expectException(Exception::class);
        self::expectExceptionCode(11000);
        $this->getDatabase()->insert('movies', ['_id' => 999]);
    }

    public function testExceedTimeException()
    {
        self::expectException(Exception::class);
        self::expectExceptionCode(50);

        $this->getDatabase()->find(
            'movies',
            ['$where' => 'sleep(1000) || true'],
            ['maxTimeMS' => 1]
        )->cursor->firstBatch ?? [];
    }


    public function testUpsert()
    {
        $this->getDatabase()->insert(
            'movies_upsert',
            [
                'name' => 'Gone with the wind',
                'language' => 'English',
                'country' => 'UK',
                'counter' => 1
            ]
        );

        $this->getDatabase()->upsert('movies_upsert', [
            [
                'filter' => ['name' => 'Gone with the wind'],
                'update' => [
                    '$set' => ['country' => 'USA'],
                    '$inc' => ['counter' => 3]
                ]
            ],
            [
                'filter' => ['name' => 'The godfather'],
                'update' => [
                    '$set' => ['name' => 'The godfather 2', 'country' => 'USA', 'language' => 'English']
                ]
            ],
        ]);

        $documents = $this->getDatabase()->find('movies_upsert')->cursor->firstBatch ?? [];
        self::assertCount(2, $documents);
        self::assertEquals(4, $documents[0]->counter);
        self::assertEquals('The godfather 2', $documents[1]->name);
        self::assertEquals('USA', $documents[1]->country);
        self::assertEquals('English', $documents[1]->language);
    }

    public function testToArrayWithNestedDocumentFromMongo()
    {
        $client = $this->getDatabase();

        // Insert a document with nested object data
        $client->insert('movies_nested', [
            '_id' => 'nested-test-1',
            'title' => 'Inception',
            'director' => [
                'name' => 'Christopher Nolan',
                'born' => 1970,
            ],
            'cast' => [
                ['name' => 'Leonardo DiCaprio', 'role' => 'Cobb'],
                ['name' => 'Tom Hardy', 'role' => 'Eames'],
            ],
        ]);

        // Read back from MongoDB — cursor returns stdClass with nested stdClass
        $result = $client->find('movies_nested', ['_id' => 'nested-test-1'])->cursor->firstBatch[0] ?? null;
        self::assertNotNull($result);
        self::assertInstanceOf(\stdClass::class, $result);
        self::assertInstanceOf(\stdClass::class, $result->director);

        // Convert via toArray — nested stdClass must become arrays
        $array = $client->toArray($result);
        self::assertIsArray($array);
        self::assertIsArray($array['director']);
        self::assertEquals('Christopher Nolan', $array['director']['name']);
        self::assertEquals(1970, $array['director']['born']);
        self::assertIsArray($array['cast']);
        self::assertIsArray($array['cast'][0]);
        self::assertEquals('Leonardo DiCaprio', $array['cast'][0]['name']);
        self::assertIsArray($array['cast'][1]);
        self::assertEquals('Tom Hardy', $array['cast'][1]['name']);

        // Also test via lastDocument
        $last = $client->lastDocument('movies_nested');
        self::assertIsArray($last);
        self::assertIsArray($last['director']);
        self::assertEquals('Christopher Nolan', $last['director']['name']);

        $client->dropCollection('movies_nested');
    }

    public function testInt64ValuesDecodeToNativeIntegers()
    {
        if (\PHP_INT_SIZE < 8) {
            // normalizeInt64() deliberately keeps the wrapper on 32-bit builds,
            // where it is the only lossless representation, and the literals
            // below would already be floats before reaching the driver.
            self::markTestSkipped('Native int64 round-trip requires a 64-bit PHP build.');
        }

        $client = $this->getDatabase();

        // Beyond the int32 range, so MongoDB stores these as BSON int64 and
        // Document::toPHP() hands them back as MongoDB\BSON\Int64 wrappers.
        $negative = -3408048000;
        $positive = 3408048000;
        $extreme = \PHP_INT_MAX;

        try {
            $client->insert('movies_int64', [
                '_id' => 'int64-test-1',
                'small' => -42,
                'big' => $negative,
                'list' => [$negative, -42, $positive, $extreme],
                'nested' => ['deep' => ['value' => $negative]],
            ]);

            $result = $client->find('movies_int64', ['_id' => 'int64-test-1'])->cursor->firstBatch[0] ?? null;
            self::assertNotNull($result);

            self::assertIsInt($result->small);
            self::assertSame(-42, $result->small);

            self::assertIsInt($result->big);
            self::assertSame($negative, $result->big);

            self::assertIsInt($result->list[0]);
            self::assertSame($negative, $result->list[0]);
            self::assertSame(-42, $result->list[1]);
            self::assertSame($positive, $result->list[2]);
            self::assertSame($extreme, $result->list[3]);

            self::assertIsInt($result->nested->deep->value);
            self::assertSame($negative, $result->nested->deep->value);

            // toArray() must carry the native integers through untouched.
            $array = $client->toArray($result);
            self::assertSame($negative, $array['big']);
            self::assertSame([$negative, -42, $positive, $extreme], $array['list']);
            self::assertSame($negative, $array['nested']['deep']['value']);
        } finally {
            $client->dropCollection('movies_int64');
        }
    }

    public function testToArrayNestedConversion()
    {
        $client = $this->getDatabase();

        // Nested stdClass (simulates MongoDB BSON result)
        $nested = new \stdClass();
        $nested->name = 'John';
        $nested->age = 30;

        $root = new \stdClass();
        $root->id = 'doc-1';
        $root->author = $nested;
        $root->tags = ['php', 'mongo'];

        $result = $client->toArray($root);

        self::assertIsArray($result);
        self::assertEquals('doc-1', $result['id']);
        self::assertIsArray($result['author']);
        self::assertEquals('John', $result['author']['name']);
        self::assertEquals(30, $result['author']['age']);
        self::assertIsArray($result['tags']);

        // Deeply nested stdClass
        $deep = new \stdClass();
        $deep->value = 'deep';

        $mid = new \stdClass();
        $mid->child = $deep;

        $top = new \stdClass();
        $top->mid = $mid;

        $result = $client->toArray($top);

        self::assertIsArray($result);
        self::assertIsArray($result['mid']);
        self::assertIsArray($result['mid']['child']);
        self::assertEquals('deep', $result['mid']['child']['value']);

        // Non-stdClass objects should NOT be converted
        $root = new \stdClass();
        $root->created = new \DateTime('2024-01-01');
        $root->name = 'test';

        $result = $client->toArray($root);

        self::assertIsArray($result);
        self::assertInstanceOf(\DateTime::class, $result['created']);

        // stdClass inside an array (simulates nested documents in a list)
        $item = new \stdClass();
        $item->id = 'item-1';
        $item->label = 'Tag';

        $root = new \stdClass();
        $root->id = 'doc-5';
        $root->items = [$item];

        $result = $client->toArray($root);

        self::assertIsArray($result);
        self::assertIsArray($result['items']);
        self::assertIsArray($result['items'][0]);
        self::assertEquals('item-1', $result['items'][0]['id']);

        // Null handling
        self::assertNull($client->toArray(null));

        // Scalar wrapping
        self::assertEquals([42], $client->toArray(42));
    }

    public function testEmptyObjectSurvivesToArray()
    {
        $client = $this->getDatabase();

        $created = $client->insert('movies_empty_object', [
            '_id' => 'empty-object-1',
            'empty' => new \stdClass(),
            'inner' => (object)['nested' => new \stdClass()],
            'list' => [new \stdClass(), (object)['x' => 1]],
            'filled' => (object)['a' => 1],
            'emptyList' => [],
        ]);

        self::assertSame('{}', json_encode($created['empty']), 'insert() response flattened an empty object');
        self::assertSame('{"nested":{}}', json_encode($created['inner']), 'insert() response flattened a nested empty object');
        self::assertSame('[{},{"x":1}]', json_encode($created['list']), 'insert() response flattened an empty object inside a list');
        self::assertSame('{"a":1}', json_encode($created['filled']));
        self::assertSame('[]', json_encode($created['emptyList']), 'insert() response turned an empty array into an object');

        self::assertIsArray($created['inner'], 'non-empty objects must still be associative arrays');
        self::assertSame(['a' => 1], $created['filled'], 'non-empty objects must still be associative arrays');

        $batch = $client->insertMany('movies_empty_object', [[
            '_id' => 'empty-object-2',
            'empty' => new \stdClass(),
            'inner' => (object)['nested' => new \stdClass()],
            'list' => [new \stdClass(), (object)['x' => 1]],
        ]]);

        self::assertSame('{}', json_encode($batch[0]['empty']), 'insertMany() response flattened an empty object');
        self::assertSame('{"nested":{}}', json_encode($batch[0]['inner']), 'insertMany() response flattened a nested empty object');
        self::assertSame('[{},{"x":1}]', json_encode($batch[0]['list']), 'insertMany() response flattened an empty object inside a list');

        $read = $client->toArray($client->find('movies_empty_object', ['_id' => 'empty-object-1'])->cursor->firstBatch[0]);

        self::assertSame('{}', json_encode($read['empty']), 'read path flattened an empty object');
        self::assertSame('{"nested":{}}', json_encode($read['inner']), 'read path flattened a nested empty object');
        self::assertSame('[{},{"x":1}]', json_encode($read['list']), 'read path flattened an empty object inside a list');
        self::assertSame('{"a":1}', json_encode($read['filled']));
        self::assertSame('[]', json_encode($read['emptyList']), 'read path turned an empty array into an object');

        $last = $client->lastDocument('movies_empty_object');

        self::assertSame('{}', json_encode($last['empty']), 'lastDocument() flattened an empty object');
        self::assertSame('{"nested":{}}', json_encode($last['inner']), 'lastDocument() flattened a nested empty object');

        self::assertSame([], $client->toArray(new \stdClass()), 'toArray() returns ?array, so only nested values change shape');

        $client->dropCollection('movies_empty_object');
    }

    public function testCountMethod()
    {
        $collectionName = 'count_test';
        $this->getDatabase()->createCollection($collectionName);
        try {
            $documents = [];
            for ($i = 1; $i <= 30; $i++) {
                $documents[] = [
                    'name' => "Document {$i}",
                    'number' => $i,
                    'category' => 'test',
                    'created_at' => new \DateTime()
                ];
            }

            $this->getDatabase()->insertMany($collectionName, $documents);

            $total = $this->getDatabase()->count($collectionName, [], []);
            self::assertEquals(30, $total);

            // Test count with filter (should be 1 for each specific number)
            $total = $this->getDatabase()->count($collectionName, ['number' => 15], []);
            self::assertEquals(1, $total);

            // Test count with range filter (should be 10 for numbers 1-10)
            $total = $this->getDatabase()->count($collectionName, ['number' => ['$lte' => 10]], []);
            self::assertEquals(10, $total);

            // Test count with limit (should be 5 for first 5 documents)
            $total = $this->getDatabase()->count($collectionName, [], ['limit' => 5]);
            self::assertEquals(5, $total);

            // Test count with filter and limit (should be 3 for first 3 documents with number <= 10)
            $total = $this->getDatabase()->count($collectionName, ['number' => ['$lte' => 10]], ['limit' => 3]);
            self::assertEquals(3, $total);


            // Test count with $or operator and comparison (should be 2 documents with number <= 2 OR number >= 29)
            $total = $this->getDatabase()->count($collectionName, ['$or' => [['number' => ['$lte' => 2]], ['number' => ['$gte' => 29]]]], []);
            self::assertEquals(4, $total);

            // Test aggregation count - total documents
            $aggregationResult = $this->getDatabase()->aggregate($collectionName, [
                ['$count' => 'total']
            ]);
            self::assertEquals(30, $aggregationResult->cursor->firstBatch[0]->total);

            // Test aggregation count with filter
            $filteredAggregationResult = $this->getDatabase()->aggregate($collectionName, [
                ['$match' => ['number' => ['$lte' => 10]]],
                ['$count' => 'total']
            ]);
            self::assertEquals(10, $filteredAggregationResult->cursor->firstBatch[0]->total);

            // Test aggregation count with limit
            $limitedAggregationResult = $this->getDatabase()->aggregate($collectionName, [
                ['$limit' => 7],
                ['$count' => 'total']
            ]);
            self::assertEquals(7, $limitedAggregationResult->cursor->firstBatch[0]->total);

            // Test aggregation count with group by
            $groupedAggregationResult = $this->getDatabase()->aggregate($collectionName, [
                ['$group' => [
                    '_id' => '$category', // Group by category
                    'count' => ['$sum' => 1] // Count of documents in the group
                ]]
            ]);
            self::assertEquals(30, $groupedAggregationResult->cursor->firstBatch[0]->count);
            self::assertEquals('test', $groupedAggregationResult->cursor->firstBatch[0]->_id);

            // Test aggregation count with $or operator
            $orAggregationResult = $this->getDatabase()->aggregate($collectionName, [
                ['$match' => ['$or' => [['number' => 5], ['number' => 15], ['number' => 25]]]],
                ['$count' => 'total']
            ]);
            self::assertEquals(3, $orAggregationResult->cursor->firstBatch[0]->total);

            // Test aggregation count with complex $or and range
            $complexOrAggregationResult = $this->getDatabase()->aggregate($collectionName, [
                ['$match' => ['$or' => [['number' => ['$lte' => 3]], ['number' => ['$gte' => 28]]]]],
                ['$count' => 'total']
            ]);
            self::assertEquals(6, $complexOrAggregationResult->cursor->firstBatch[0]->total);
        } finally {
            $this->getDatabase()->dropCollection($collectionName);
        }
    }
}
