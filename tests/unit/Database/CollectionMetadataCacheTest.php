<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Appwrite\Utopia\Database\Database;
use PDO;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;

final class CollectionMetadataCacheTest extends TestCase
{
    private Database $database;

    private Cache $cache;

    protected function setUp(): void
    {
        Database::resetCollectionMemo();
        Database::setRevalidateTtl(2.0);

        $connection = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $adapter = new SQLite($connection);
        $this->cache = new Cache(new MemoryCache());

        $this->database = new Database($adapter, $this->cache);
        $this->database->setDatabase('test_db');
        $this->database->setNamespace('ns');
        $this->database->create();
    }

    protected function tearDown(): void
    {
        Database::resetCollectionMemo();
        Database::setRevalidateTtl(2.0);
    }

    public function testFindCollectionMemoizesMetadataAndReturnsClone(): void
    {
        $this->database->createCollection(
            Collection::create(
                id: 'articles',
                attributes: [Attribute::string('title', size: 128)],
            )
        );

        $this->assertSame(0, Database::getCollectionMemoCount());

        $first = $this->database->findCollection('articles');
        $this->assertNotNull($first);
        $this->assertSame('articles', $first->getId());
        $this->assertSame(1, Database::getCollectionMemoCount());

        // Ensure returned instance is isolated so caller changes do not corrupt the memoized copy
        $first->setAttribute('corrupted', true);

        $second = $this->database->findCollection('articles');
        $this->assertNotNull($second);
        $this->assertNull($second->getAttribute('corrupted'), 'Cached collection must be insulated against external mutations');
        $this->assertNotSame($first, $second, 'Subsequent lookups should return a cloned instance');
        $this->assertSame(1, Database::getCollectionMemoCount());
    }

    public function testPurgeCachedCollectionInvalidatesSpecificCollection(): void
    {
        $this->database->createCollection(
            Collection::create(
                id: 'posts',
                attributes: [Attribute::string('title', size: 100)],
            )
        );

        $this->database->createCollection(
            Collection::create(
                id: 'comments',
                attributes: [Attribute::string('body', size: 255)],
            )
        );

        $this->assertNotNull($this->database->findCollection('posts'));
        $this->assertNotNull($this->database->findCollection('comments'));
        $this->assertSame(2, Database::getCollectionMemoCount());

        $this->database->purgeCachedCollection('posts');

        $this->assertSame(1, Database::getCollectionMemoCount());
        $this->assertNotNull($this->database->findCollection('comments'));
    }

    public function testPurgeMetadataCollectionClearsAllMemoEntries(): void
    {
        $this->database->createCollection(
            Collection::create(
                id: 'coll_a',
                attributes: [Attribute::string('name', size: 50)],
            )
        );
        $this->database->createCollection(
            Collection::create(
                id: 'coll_b',
                attributes: [Attribute::string('name', size: 50)],
            )
        );

        $this->database->findCollection('coll_a');
        $this->database->findCollection('coll_b');
        $this->assertSame(2, Database::getCollectionMemoCount());

        $this->database->purgeCachedCollection(Database::METADATA);

        $this->assertSame(0, Database::getCollectionMemoCount());
    }

    public function testActiveTransactionBypassesInProcessMemo(): void
    {
        $this->database->createCollection(
            Collection::create(
                id: 'orders',
                attributes: [Attribute::string('reference', size: 64)],
            )
        );

        // Pre-warm memo
        $this->database->findCollection('orders');
        $this->assertSame(1, Database::getCollectionMemoCount());

        // Clear memo to verify in-transaction behavior
        Database::resetCollectionMemo();
        $this->assertSame(0, Database::getCollectionMemoCount());

        $this->database->getAdapter()->startTransaction();
        try {
            $collection = $this->database->findCollection('orders');
            $this->assertNotNull($collection);
            $this->assertSame(0, Database::getCollectionMemoCount(), 'In transaction, metadata lookups must bypass memo');
        } finally {
            $this->database->getAdapter()->rollbackTransaction();
        }
    }

    public function testRevalidationDetectsCachePurge(): void
    {
        $this->database->createCollection(
            Collection::create(
                id: 'tickets',
                attributes: [Attribute::string('subject', size: 100)],
            )
        );

        // Force TTL window to 0 to trigger generation check on every call
        Database::setRevalidateTtl(0.0);

        $first = $this->database->findCollection('tickets');
        $this->assertNotNull($first);
        $this->assertSame(1, Database::getCollectionMemoCount());

        // Purge document cache simulates invalidation by another worker / node
        $this->database->purgeCachedCollection('tickets');

        // Next read revalidates and repopulates memo
        $second = $this->database->findCollection('tickets');
        $this->assertNotNull($second);
        $this->assertSame(1, Database::getCollectionMemoCount());
    }

    public function testFactoryProvisionedDatabaseEndToEndFlow(): void
    {
        $hostname = 'db_main';
        $namespace = 'shared';

        \putenv('_APP_DATABASE_SHARED_NAMESPACE=' . $namespace);
        \putenv('_APP_DATABASE_SHARED_TABLES=' . $hostname);

        try {
            $connection = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $adapter = new class ($connection) extends SQLite {
                #[\Override]
                public function hostname(): string
                {
                    return 'mariadb';
                }
            };

            $pools = new \Utopia\Pools\Group();
            $pools->add(new \Utopia\Pools\Pool(new \Utopia\Pools\Adapter\Stack(), $hostname, 1, static fn (): SQLite => $adapter, 1.0));

            $factory = new \Appwrite\Database\Factory($pools, new Cache(new MemoryCache()), new \Utopia\Database\Validator\Authorization());

            $setup = $factory->setup($hostname);
            $setup->create();

            $projectDoc = new \Utopia\Database\Document([
                '$id' => 'project-test',
                '$sequence' => '42',
                'database' => 'mysql://' . $hostname . '?namespace=' . $namespace,
            ]);

            $projectDb = $factory->project($projectDoc);

            // Verify Factory produces our Database implementation
            $this->assertInstanceOf(Database::class, $projectDb);

            // Create a collection
            $setup->createCollection(Collection::create(
                id: 'tasks',
                attributes: [
                    Attribute::string('title', size: 255),
                    Attribute::string('status', size: 32),
                ],
                permissions: [
                    \Utopia\Database\Permission::create(\Utopia\Database\Role::any()),
                    \Utopia\Database\Permission::read(\Utopia\Database\Role::any()),
                    \Utopia\Database\Permission::update(\Utopia\Database\Role::any()),
                    \Utopia\Database\Permission::delete(\Utopia\Database\Role::any()),
                ],
            ));

            $this->assertSame(0, Database::getCollectionMemoCount());

            // Perform document operations (which internally call findCollection)
            $created = $projectDb->createDocument('tasks', new \Utopia\Database\Document([
                '$permissions' => [\Utopia\Database\Permission::read(\Utopia\Database\Role::any()), \Utopia\Database\Permission::write(\Utopia\Database\Role::any())],
                'title' => 'Optimize metadata resolution',
                'status' => 'completed',
            ]));

            $this->assertSame(1, Database::getCollectionMemoCount(), 'Collection metadata should be memoized during document creation');

            $retrieved = $projectDb->getDocument('tasks', $created->getId());
            $this->assertNotNull($retrieved);
            $this->assertSame('Optimize metadata resolution', $retrieved->getAttribute('title'));
            $this->assertSame(1, Database::getCollectionMemoCount(), 'Subsequent document fetch reuses memoized metadata');
        } finally {
            \putenv('_APP_DATABASE_SHARED_NAMESPACE');
            \putenv('_APP_DATABASE_SHARED_TABLES');
        }
    }

    public function testHostIsolationForIdenticalDatabaseAndCollection(): void
    {
        $conn1 = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $adapter1 = new class ($conn1) extends SQLite {
            #[\Override]
            public function hostname(): string
            {
                return 'host_alpha';
            }
        };

        $conn2 = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $adapter2 = new class ($conn2) extends SQLite {
            #[\Override]
            public function hostname(): string
            {
                return 'host_beta';
            }
        };

        $db1 = new Database($adapter1, $this->cache);
        $db1->setDatabase('shared_db')->setNamespace('ns')->create();
        $db1->createCollection(Collection::create(id: 'users', attributes: [Attribute::string('name', size: 50)]));

        $db2 = new Database($adapter2, $this->cache);
        $db2->setDatabase('shared_db')->setNamespace('ns')->create();
        $db2->createCollection(Collection::create(id: 'users', attributes: [Attribute::string('email', size: 100)]));

        $col1 = $db1->findCollection('users');
        $col2 = $db2->findCollection('users');

        $this->assertNotNull($col1);
        $this->assertNotNull($col2);
        $this->assertSame(2, Database::getCollectionMemoCount(), 'Separate hosts must have isolated memo entries');
        $this->assertCount(1, $col1->attributes());
        $this->assertSame('name', $col1->attributes()[0]->key);
        $this->assertCount(1, $col2->attributes());
        $this->assertSame('email', $col2->attributes()[0]->key);
    }

    public function testGenerationChangeDuringReadPreventsStaleMemoization(): void
    {
        $this->database->createCollection(
            Collection::create(
                id: 'config',
                attributes: [Attribute::string('setting', size: 64)],
            )
        );

        $this->assertSame(0, Database::getCollectionMemoCount());

        // Create an instrumented subclass where metadata lookup triggers a generation increment
        $racyDb = new class ($this->database->getAdapter(), $this->cache) extends Database {
            public bool $triggerRace = true;

            #[\Override]
            public function getDocument(string $collection, string $id, array $queries = [], bool $forUpdate = false): \Utopia\Database\Document
            {
                $doc = parent::getDocument($collection, $id, $queries, $forUpdate);
                if ($this->triggerRace && $collection === self::METADATA) {
                    // Simulate a cross-node update purging the cache during the metadata fetch
                    $this->triggerRace = false;
                    $this->purgeCachedCollection('config');
                }
                return $doc;
            }
        };
        $racyDb->setDatabase('test_db')->setNamespace('ns');

        Database::resetCollectionMemo();

        $collection = $racyDb->findCollection('config');
        $this->assertNotNull($collection);
        $this->assertSame(0, Database::getCollectionMemoCount(), 'Must not memoize when generation changes during read');

        // Subsequent lookup with stable generation is safely memoized
        $stable = $racyDb->findCollection('config');
        $this->assertNotNull($stable);
        $this->assertSame(1, Database::getCollectionMemoCount(), 'Subsequent stable read memoizes definition');
    }
}
