<?php

declare(strict_types=1);

namespace Tests\Unit\Migration\Version;

use Appwrite\Migration\Version\V20;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Index;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Schema\ColumnType;

final class V20Test extends TestCase
{
    public function testDropsIndexesOfConfiguredArrayAttributes(): void
    {
        $authorization = new Authorization();
        $authorization->disable();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationTests')
            ->setNamespace('_console');
        $database->create();

        $labels = Attribute::string(key: 'labels', size: 128, array: true);
        $name = Attribute::string(key: 'name', size: 128);
        $labelsIndex = Index::key(key: '_key_labels', attributes: ['labels']);
        $nameIndex = Index::key(key: '_key_name', attributes: ['name']);
        $database->createCollection(Collection::create(
            id: 'tags',
            attributes: [$labels, $name],
            indexes: [$labelsIndex, $nameIndex],
        ));

        $migration = new class ($database, [
            '$collection' => Database::METADATA,
            '$id' => 'tags',
            'attributes' => [$labels, $name],
            'indexes' => [$labelsIndex, $nameIndex],
        ]) extends V20 {
            /**
             * @param array<string, mixed> $tags
             */
            public function __construct(Database $database, array $tags)
            {
                $this->dbForProject = $database;
                $this->project = new Document(['$id' => 'console', '$sequence' => 'console']);
                $this->collections = ['console' => ['tags' => $tags]];
            }
        };

        \ob_start();
        try {
            $migration->execute();
        } finally {
            \ob_end_clean();
        }

        $tags = $database->getCollection('tags');
        $this->assertSame(['_key_name'], \array_map(fn (Index $index) => $index->key, $tags->indexes()));
        $attributes = $tags->attributes();
        $this->assertSame(['labels', 'name'], \array_map(fn (Attribute $attribute) => $attribute->key, $attributes));
        $this->assertSame(ColumnType::String, $attributes[0]->type);
        $this->assertTrue($attributes[0]->array);
    }

    public function testRetypesStoredArrayAttributesOfUserCollections(): void
    {
        $authorization = new Authorization();
        $authorization->disable();
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization($authorization)
            ->setDatabase('migrationTests')
            ->setNamespace('_1');
        $database->create();

        $database->createCollection(Collection::create(
            id: 'attributes',
            attributes: [
                Attribute::string(key: 'databaseInternalId'),
                Attribute::string(key: 'collectionInternalId'),
                Attribute::string(key: 'key'),
                Attribute::string(key: 'type'),
                Attribute::boolean(key: 'array'),
            ],
        ));
        $database->createCollection(Collection::create(
            id: 'indexes',
            attributes: [
                Attribute::string(key: 'databaseInternalId'),
                Attribute::string(key: 'collectionInternalId'),
                Attribute::string(key: 'key'),
                Attribute::string(key: 'attributes', array: true),
            ],
        ));
        $database->createCollection(Collection::create(id: 'functions'));
        $database->createCollection(Collection::create(id: 'databases'));
        $database->createCollection(Collection::create(
            id: 'database_1_collection_1',
            attributes: [Attribute::string(key: 'labels', size: 128, array: true)],
            indexes: [Index::key(key: '_key_labels', attributes: ['labels'])],
        ));
        $database->createDocument('attributes', new Document([
            'databaseInternalId' => '1',
            'collectionInternalId' => '1',
            'key' => 'labels',
            'type' => ColumnType::String->value,
            'array' => true,
        ]));
        $database->createDocument('indexes', new Document([
            'databaseInternalId' => '1',
            'collectionInternalId' => '1',
            'key' => '_key_labels',
            'attributes' => ['labels'],
        ]));

        $migration = new class ($database) extends V20 {
            public function __construct(Database $database)
            {
                $this->dbForProject = $database;
                $this->project = new Document(['$id' => 'project', '$sequence' => '1']);
                $this->collections = ['projects' => []];
            }

            #[\Override]
            protected function migrateUsageMetrics(string $from, string $to): void
            {
            }

            #[\Override]
            protected function migrateSessionsMetric(): void
            {
            }

            #[\Override]
            protected function migrateBuckets(): void
            {
            }
        };

        \ob_start();
        try {
            $migration->execute();
        } finally {
            \ob_end_clean();
        }

        $collection = $database->getCollection('database_1_collection_1');
        $this->assertSame([], $collection->indexes());
        $this->assertSame([], $database->find('indexes'));
        $labels = $collection->attributes()[0];
        $this->assertSame('labels', $labels->key);
        $this->assertSame(ColumnType::String, $labels->type);
        $this->assertTrue($labels->array);
    }
}
