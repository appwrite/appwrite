<?php

namespace Tests\Integration\Schema;

use MongoDB\Driver\Exception\BulkWriteException;
use Tests\Integration\IntegrationTestCase;
use Utopia\Query\Schema\MongoDB;

class MongoDBIntegrationTest extends IntegrationTestCase
{
    private MongoDB $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectMongoDB();
        $this->schema = new MongoDB();
    }

    public function testCreateCollection(): void
    {
        $collection = 'schema_create_' . \uniqid();
        $this->trackMongoCollection($collection);

        $plan = $this->schema->table($collection)
            ->integer('id')
            ->string('name', 100)
            ->integer('age')->nullable()
            ->create();

        $mongo = $this->mongoClient;
        $this->assertNotNull($mongo);

        $mongo->command($plan->query);

        // Collection exists after create.
        $this->assertContains($collection, $mongo->listCollectionNames());

        // Validator is enforced: a valid insert succeeds.
        $mongo->insertOne($collection, ['id' => 1, 'name' => 'Alice']);

        // Missing required `name` + wrong type for `id` must be rejected by the validator.
        $rejected = false;
        try {
            $mongo->insertOne($collection, ['id' => 'not-an-int']);
        } catch (BulkWriteException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'MongoDB validator should reject invalid document');
    }

    public function testCreateIndexSingleField(): void
    {
        $collection = 'schema_idx_single_' . \uniqid();
        $this->trackMongoCollection($collection);

        $mongo = $this->mongoClient;
        $this->assertNotNull($mongo);

        $mongo->command($this->schema->table($collection)
            ->integer('id')
            ->string('email', 255)
            ->create()->query);

        $mongo->command($this->schema->createIndex($collection, 'idx_email', ['email'])->query);

        $this->assertContains('idx_email', $mongo->listIndexNames($collection));
        $this->assertSame(['email' => 1], $mongo->getIndexKey($collection, 'idx_email'));
    }

    public function testCreateIndexCompound(): void
    {
        $collection = 'schema_idx_compound_' . \uniqid();
        $this->trackMongoCollection($collection);

        $mongo = $this->mongoClient;
        $this->assertNotNull($mongo);

        $mongo->command($this->schema->table($collection)
            ->integer('id')
            ->string('country', 32)
            ->string('city', 64)
            ->create()->query);

        $indexStatement = $this->schema->createIndex(
            $collection,
            'idx_country_city',
            ['country', 'city'],
            orders: ['country' => 'asc', 'city' => 'desc'],
        );
        $mongo->command($indexStatement->query);

        $this->assertSame(
            ['country' => 1, 'city' => -1],
            $mongo->getIndexKey($collection, 'idx_country_city'),
        );
    }

    public function testCreateIndexUnique(): void
    {
        $collection = 'schema_idx_unique_' . \uniqid();
        $this->trackMongoCollection($collection);

        $mongo = $this->mongoClient;
        $this->assertNotNull($mongo);

        $mongo->command($this->schema->table($collection)
            ->integer('id')
            ->string('email', 255)
            ->create()->query);

        $mongo->command($this->schema->createIndex($collection, 'idx_email_unique', ['email'], unique: true)->query);

        $mongo->insertOne($collection, ['id' => 1, 'email' => 'a@test.com']);

        $rejected = false;
        try {
            $mongo->insertOne($collection, ['id' => 2, 'email' => 'a@test.com']);
        } catch (BulkWriteException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'Unique index should reject duplicate value');
    }

    public function testDropCollection(): void
    {
        $collection = 'schema_drop_' . \uniqid();
        $this->trackMongoCollection($collection);

        $mongo = $this->mongoClient;
        $this->assertNotNull($mongo);

        $mongo->command($this->schema->table($collection)
            ->integer('id')
            ->create()->query);

        $this->assertContains($collection, $mongo->listCollectionNames());

        $mongo->command($this->schema->table($collection)->drop()->query);

        $this->assertNotContains($collection, $mongo->listCollectionNames());
    }
}
