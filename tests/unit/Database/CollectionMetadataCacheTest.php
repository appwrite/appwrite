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
}
