<?php

namespace Tests\E2E\Adapter\Scopes;

use Exception;
use Throwable;
use Utopia\Cache\Adapter\Redis as RedisAdapter;
use Utopia\Cache\Cache;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Exception\Timeout as TimeoutException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Mirror;
use Utopia\Database\Query;

trait GeneralTests
{
    public function testPing(): void
    {
        $this->assertEquals(true, $this->getDatabase()->ping());
    }

    /**
     * @throws AuthorizationException
     * @throws DuplicateException
     * @throws ConflictException
     * @throws LimitException
     * @throws StructureException
     * @throws DatabaseException
     */
    public function testQueryTimeout(): void
    {
        if (!$this->getDatabase()->getAdapter()->getSupportForTimeouts()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection('global-timeouts');

        $this->assertEquals(
            true,
            $database->createAttribute(
                collection: 'global-timeouts',
                id: 'longtext',
                type: Database::VAR_STRING,
                size: 100000000,
                required: true
            )
        );

        for ($i = 0; $i < 20; $i++) {
            $database->createDocument('global-timeouts', new Document([
                'longtext' => file_get_contents(__DIR__ . '/../../../resources/longtext.txt'),
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any())
                ]
            ]));
        }

        $database->setTimeout(1);

        try {
            $database->find('global-timeouts', [
                Query::notEqual('longtext', 'appwrite'),
            ]);
            $this->fail('Failed to throw exception');
        } catch (\Exception $e) {
            $database->clearTimeout();
            $database->deleteCollection('global-timeouts');
            $this->assertInstanceOf(TimeoutException::class, $e);
        }
    }

    public function testCountTimeout(): void
    {
        if (!$this->getDatabase()->getAdapter()->getSupportForTimeouts()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection('count-timeouts');

        $this->assertEquals(
            true,
            $database->createAttribute(
                collection: 'count-timeouts',
                id: 'longtext',
                type: Database::VAR_STRING,
                size: 100000000,
                required: true
            )
        );

        $longtext = file_get_contents(__DIR__ . '/../../../resources/longtext.txt');
        for ($i = 0; $i < 20; $i++) {
            $database->createDocument('count-timeouts', new Document([
                'longtext' => $longtext,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any())
                ]
            ]));
        }

        try {
            $database->setTimeout(1);

            $thrown = null;
            try {
                // A substring scan forces the engine to walk every huge value; a
                // cheap filter (e.g. notEqual) lets COUNT finish inside the timeout.
                $database->count('count-timeouts', [
                    Query::contains('longtext', ['needle-that-does-not-exist']),
                ]);
            } catch (\Exception $e) {
                $thrown = $e;
            }

            $this->assertInstanceOf(TimeoutException::class, $thrown, 'count() must throw a timeout exception');
        } finally {
            $database->clearTimeout();
            $database->deleteCollection('count-timeouts');
        }
    }

    public function testPreserveDatesUpdate(): void
    {
        $this->getDatabase()->getAuthorization()->disable();

        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->setPreserveDates(true);

        $database->createCollection('preserve_update_dates');

        $database->createAttribute('preserve_update_dates', 'attr1', Database::VAR_STRING, 10, false);

        $doc1 = $database->createDocument('preserve_update_dates', new Document([
            '$id' => 'doc1',
            '$permissions' => [],
            'attr1' => 'value1',
        ]));

        $doc2 = $database->createDocument('preserve_update_dates', new Document([
            '$id' => 'doc2',
            '$permissions' => [],
            'attr1' => 'value2',
        ]));

        $doc3 = $database->createDocument('preserve_update_dates', new Document([
            '$id' => 'doc3',
            '$permissions' => [],
            'attr1' => 'value3',
        ]));
        // updating with empty dates
        try {
            $doc1->setAttribute('$updatedAt', '');
            $doc1 = $database->updateDocument('preserve_update_dates', 'doc1', $doc1);
            $this->fail('Failed to throw structure exception');

        } catch (Exception $e) {
            $this->assertInstanceOf(StructureException::class, $e);
            $this->assertEquals('Invalid document structure: Missing required attribute "$updatedAt"', $e->getMessage());
        }

        try {
            $this->getDatabase()->updateDocuments(
                'preserve_update_dates',
                new Document([
                    '$updatedAt' => ''
                ]),
                [
                    Query::equal('$id', [
                        $doc2->getId(),
                        $doc3->getId()
                    ])
                ]
            );
            $this->fail('Failed to throw structure exception');

        } catch (Exception $e) {
            $this->assertInstanceOf(StructureException::class, $e);
            $this->assertEquals('Invalid document structure: Missing required attribute "$updatedAt"', $e->getMessage());
        }

        // non empty dates
        $newDate = '2000-01-01T10:00:00.000+00:00';

        $doc1->setAttribute('$updatedAt', $newDate);
        $doc1 = $database->updateDocument('preserve_update_dates', 'doc1', $doc1);
        $this->assertEquals($newDate, $doc1->getAttribute('$updatedAt'));
        $doc1 = $database->getDocument('preserve_update_dates', 'doc1');
        $this->assertEquals($newDate, $doc1->getAttribute('$updatedAt'));

        $this->getDatabase()->updateDocuments(
            'preserve_update_dates',
            new Document([
                '$updatedAt' => $newDate
            ]),
            [
                Query::equal('$id', [
                    $doc2->getId(),
                    $doc3->getId()
                ])
            ]
        );

        $doc2 = $database->getDocument('preserve_update_dates', 'doc2');
        $doc3 = $database->getDocument('preserve_update_dates', 'doc3');
        $this->assertEquals($newDate, $doc2->getAttribute('$updatedAt'));
        $this->assertEquals($newDate, $doc3->getAttribute('$updatedAt'));

        $database->deleteCollection('preserve_update_dates');

        $database->setPreserveDates(false);

        $this->getDatabase()->getAuthorization()->reset();
    }

    public function testPreserveDatesCreate(): void
    {
        $this->getDatabase()->getAuthorization()->disable();

        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->setPreserveDates(true);

        $database->createCollection('preserve_create_dates');

        $database->createAttribute('preserve_create_dates', 'attr1', Database::VAR_STRING, 10, false);

        // empty string for $createdAt should throw Structure exception
        try {
            $date = '';
            $database->createDocument('preserve_create_dates', new Document([
                '$id' => 'doc1',
                '$permissions' => [],
                'attr1' => 'value1',
                '$createdAt' => $date
            ]));
            $this->fail('Failed to throw structure exception');
        } catch (Exception $e) {
            $this->assertInstanceOf(StructureException::class, $e);
            $this->assertEquals('Invalid document structure: Missing required attribute "$createdAt"', $e->getMessage());
        }

        try {
            $database->createDocuments('preserve_create_dates', [
                new Document([
                    '$id' => 'doc2',
                    '$permissions' => [],
                    'attr1' => 'value2',
                    '$createdAt' => $date
                ]),
                new Document([
                    '$id' => 'doc3',
                    '$permissions' => [],
                    'attr1' => 'value3',
                    '$createdAt' => $date
                ]),
            ], batchSize: 2);
            $this->fail('Failed to throw structure exception');
        } catch (Exception $e) {
            $this->assertInstanceOf(StructureException::class, $e);
            $this->assertEquals('Invalid document structure: Missing required attribute "$createdAt"', $e->getMessage());
        }

        // non empty date
        $date = '2000-01-01T10:00:00.000+00:00';

        $database->createDocument('preserve_create_dates', new Document([
            '$id' => 'doc1',
            '$permissions' => [],
            'attr1' => 'value1',
            '$createdAt' => $date
        ]));

        $database->createDocuments('preserve_create_dates', [
            new Document([
                '$id' => 'doc2',
                '$permissions' => [],
                'attr1' => 'value2',
                '$createdAt' => $date
            ]),
            new Document([
                '$id' => 'doc3',
                '$permissions' => [],
                'attr1' => 'value3',
                '$createdAt' => $date,
            ]),
            new Document([
                '$id' => 'doc4',
                '$permissions' => [],
                'attr1' => 'value3',
                '$createdAt' => null,
            ]),
            new Document([
                '$id' => 'doc5',
                '$permissions' => [],
                'attr1' => 'value3',
            ]),
        ], batchSize: 2);

        $doc1 = $database->getDocument('preserve_create_dates', 'doc1');
        $doc2 = $database->getDocument('preserve_create_dates', 'doc2');
        $doc3 = $database->getDocument('preserve_create_dates', 'doc3');
        $doc4 = $database->getDocument('preserve_create_dates', 'doc4');
        $doc5 = $database->getDocument('preserve_create_dates', 'doc5');
        $this->assertEquals($date, $doc1->getAttribute('$createdAt'));
        $this->assertEquals($date, $doc2->getAttribute('$createdAt'));
        $this->assertEquals($date, $doc3->getAttribute('$createdAt'));
        $this->assertNotEmpty($date, $doc4->getAttribute('$createdAt'));
        $this->assertNotEquals($date, $doc4->getAttribute('$createdAt'));
        $this->assertNotEmpty($date, $doc5->getAttribute('$createdAt'));
        $this->assertNotEquals($date, $doc5->getAttribute('$createdAt'));

        $database->deleteCollection('preserve_create_dates');

        $database->setPreserveDates(false);

        $this->getDatabase()->getAuthorization()->reset();
    }

    public function testGetAttributeLimit(): void
    {
        $this->assertIsInt($this->getDatabase()->getLimitForAttributes());
    }
    public function testGetIndexLimit(): void
    {
        $this->assertEquals(58, $this->getDatabase()->getLimitForIndexes());
    }

    public function testGetId(): void
    {
        $this->assertEquals(20, strlen(ID::unique()));
        $this->assertEquals(13, strlen(ID::unique(0)));
        $this->assertEquals(13, strlen(ID::unique(-1)));
        $this->assertEquals(23, strlen(ID::unique(10)));

        // ensure two sequential calls to getId do not give the same result
        $this->assertNotEquals(ID::unique(10), ID::unique(10));
    }

    public function testSharedTablesUpdateTenant(): void
    {
        $database = $this->getDatabase();
        $sharedTables = $database->getSharedTables();
        $namespace = $database->getNamespace();
        $schema = $database->getDatabase();

        if (!$database->getAdapter()->getSupportForSchemas()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        if ($database->exists('sharedTables')) {
            $database->setDatabase('sharedTables')->delete();
        }

        $database
            ->setDatabase('sharedTables')
            ->setNamespace('')
            ->setSharedTables(true)
            ->setTenant(null)
            ->create();

        // Create collection
        $database->createCollection(__FUNCTION__, documentSecurity: false);

        $database
            ->setTenant(1)
            ->updateDocument(Database::METADATA, __FUNCTION__, new Document([
                '$id' => __FUNCTION__,
                'name' => 'Scooby Doo',
            ]));

        // Ensure tenant was not swapped
        $doc = $database
            ->setTenant(null)
            ->getDocument(Database::METADATA, __FUNCTION__);

        $this->assertEquals('Scooby Doo', $doc['name']);

        // Reset state
        $database
            ->setSharedTables($sharedTables)
            ->setNamespace($namespace)
            ->setDatabase($schema);
    }


    public function testFindOrderByAfterException(): void
    {
        /**
         * ORDER BY - After Exception
         * Must be last assertion in test
         */
        $document = new Document([
            '$collection' => 'other collection'
        ]);

        $this->expectException(Exception::class);

        /** @var Database $database */
        $database = $this->getDatabase();

        $database->find('movies', [
            Query::limit(2),
            Query::offset(0),
            Query::cursorAfter($document)
        ]);
    }


    public function testNestedQueryValidation(): void
    {
        $this->getDatabase()->createCollection(__FUNCTION__, [
            new Document([
                '$id' => ID::custom('name'),
                'type' => Database::VAR_STRING,
                'size' => 255,
                'required' => true,
            ])
        ], permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ]);

        $this->getDatabase()->createDocuments(__FUNCTION__, [
            new Document([
                '$id' => ID::unique(),
                'name' => 'test1',
            ]),
            new Document([
                '$id' => ID::unique(),
                'name' => 'doc2',
            ]),
        ]);

        try {
            $this->getDatabase()->find(__FUNCTION__, [
                Query::or([
                    Query::equal('name', ['test1']),
                    Query::search('name', 'doc'),
                ])
            ]);
            $this->fail('Failed to throw exception');
        } catch (Throwable $e) {
            $this->assertInstanceOf(QueryException::class, $e);
            $this->assertEquals('Searching by attribute "name" requires a fulltext index.', $e->getMessage());
        }
    }


    public function testSharedTablesTenantPerDocument(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $sharedTables = $database->getSharedTables();
        $tenantPerDocument = $database->getTenantPerDocument();
        $namespace = $database->getNamespace();
        $schema = $database->getDatabase();

        if (!$database->getAdapter()->getSupportForSchemas()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        if ($database->exists('sharedTablesTenantPerDocument')) {
            $database->delete('sharedTablesTenantPerDocument');
        }

        $database
            ->setDatabase('sharedTablesTenantPerDocument')
            ->setNamespace('')
            ->setSharedTables(true)
            ->setTenant(null)
            ->create();

        // Create collection
        $database->createCollection(__FUNCTION__, permissions: [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
            Permission::update(Role::any()),
        ], documentSecurity: false);

        $database->createAttribute(__FUNCTION__, 'name', Database::VAR_STRING, 100, false);
        $database->createIndex(__FUNCTION__, 'nameIndex', Database::INDEX_KEY, ['name']);

        $doc1Id = ID::unique();

        // Create doc for tenant 1
        $database
            ->setTenant(null)
            ->setTenantPerDocument(true)
            ->createDocument(__FUNCTION__, new Document([
                '$id' => $doc1Id,
                '$tenant' => 1,
                'name' => 'Spiderman',
            ]));

        // Set to tenant 1 and read
        $doc = $database
            ->setTenantPerDocument(false)
            ->setTenant(1)
            ->getDocument(__FUNCTION__, $doc1Id);

        $this->assertEquals('Spiderman', $doc['name']);

        $doc2Id = ID::unique();

        // Create doc for tenant 2
        $database
            ->setTenant(null)
            ->setTenantPerDocument(true)
            ->createDocument(__FUNCTION__, new Document([
                '$id' => $doc2Id,
                '$tenant' => 2,
                'name' => 'Batman',
            ]));

        // Set to tenant 2 and read
        $doc = $database
            ->setTenantPerDocument(false)
            ->setTenant(2)
            ->getDocument(__FUNCTION__, $doc2Id);

        $this->assertEquals('Batman', $doc['name']);
        $this->assertEquals(2, $doc->getTenant());

        // Ensure no read cross-tenant
        $docs = $database
            ->setTenantPerDocument(false)
            ->setTenant(1)
            ->find(__FUNCTION__);

        $this->assertEquals(1, \count($docs));
        $this->assertEquals($doc1Id, $docs[0]->getId());

        if ($database->getAdapter()->getSupportForUpserts()) {
            // Test upsert with tenant per doc
            $doc3Id = ID::unique();
            $database
                ->setTenant(null)
                ->setTenantPerDocument(true)
                ->upsertDocuments(__FUNCTION__, [new Document([
                    '$id' => $doc3Id,
                    '$tenant' => 3,
                    'name' => 'Superman3',
                ])]);

            // Set to tenant 3 and read
            $doc = $database
                ->setTenantPerDocument(false)
                ->setTenant(3)
                ->getDocument(__FUNCTION__, $doc3Id);

            $this->assertEquals('Superman3', $doc['name']);
            $this->assertEquals(3, $doc->getTenant());
            $this->assertEquals($doc3Id, $doc->getId());

            // Test no read from other tenants
            $docs = $database
                ->setTenantPerDocument(false)
                ->setTenant(1)
                ->find(__FUNCTION__);

            $this->assertEquals(1, \count($docs));

            // Ensure no cross-tenant read from upsert
            $doc = $database
                ->setTenant(1)
                ->setTenantPerDocument(false)
                ->getDocument(__FUNCTION__, $doc3Id);

            $this->assertEquals(true, $doc->isEmpty());

            // Upsert new documents with different tenants. The sequence lookup binds one
            // placeholder per distinct tenant, so a cross-tenant batch has to keep each
            // tenant's value at the position its placeholder was named for -- collected here
            // because $onNext is the only way these documents reach the caller.
            $doc4Id = ID::unique();
            $doc5Id = ID::unique();
            $sequences = [];
            $database
                ->setTenant(null)
                ->setTenantPerDocument(true)
                ->upsertDocuments(
                    __FUNCTION__,
                    [new Document([
                        '$id' => $doc4Id,
                        '$tenant' => 4,
                        'name' => 'Superman4',
                    ]), new Document([
                        '$id' => $doc5Id,
                        '$tenant' => 5,
                        'name' => 'Superman5',
                    ])],
                    onNext: function (Document $document) use (&$sequences) {
                        $sequences[$document->getId()] = $document->getSequence();
                    }
                );

            $this->assertCount(2, $sequences);
            $this->assertNotEmpty($sequences[$doc4Id]);
            $this->assertNotEmpty($sequences[$doc5Id]);

            // Set to tenant 4 and read
            $doc = $database
                ->setTenantPerDocument(false)
                ->setTenant(4)
                ->getDocument(__FUNCTION__, $doc4Id);

            $this->assertEquals('Superman4', $doc['name']);
            $this->assertEquals(4, $doc->getTenant());
            $this->assertEquals($doc->getSequence(), $sequences[$doc4Id]);

            // Set to tenant 5 and read
            $doc = $database
                ->setTenantPerDocument(false)
                ->setTenant(5)
                ->getDocument(__FUNCTION__, $doc5Id);

            $this->assertEquals('Superman5', $doc['name']);
            $this->assertEquals(5, $doc->getTenant());
            $this->assertEquals($doc->getSequence(), $sequences[$doc5Id]);

            // Update names via upsert
            $database
                ->setTenant(null)
                ->setTenantPerDocument(true)
                ->upsertDocuments(__FUNCTION__, [new Document([
                    '$id' => $doc4Id,
                    '$tenant' => 4,
                    'name' => 'Superman4 updated',
                ]), new Document([
                    '$id' => $doc5Id,
                    '$tenant' => 5,
                    'name' => 'Superman5 updated',
                ])]);

            // Set to tenant 4 and read
            $doc = $database
                ->setTenantPerDocument(false)
                ->setTenant(4)
                ->getDocument(__FUNCTION__, $doc4Id);

            $this->assertEquals('Superman4 updated', $doc['name']);
            $this->assertEquals(4, $doc->getTenant());

            // Set to tenant 5 and read
            $doc = $database
                ->setTenantPerDocument(false)
                ->setTenant(5)
                ->getDocument(__FUNCTION__, $doc5Id);

            $this->assertEquals('Superman5 updated', $doc['name']);
            $this->assertEquals(5, $doc->getTenant());
        }

        // Reset instance
        $database
            ->setSharedTables($sharedTables)
            ->setTenantPerDocument($tenantPerDocument)
            ->setNamespace($namespace)
            ->setDatabase($schema);
    }


    public function testCacheFallback(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForCacheSkipOnFailure()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::any()->toString());

        // Write mock data
        $database->createCollection('testRedisFallback', attributes: [
            new Document([
                '$id' => ID::custom('string'),
                'type' => Database::VAR_STRING,
                'size' => 767,
                'required' => true,
            ])
        ], permissions: [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ]);

        $database->createDocument('testRedisFallback', new Document([
            '$id' => 'doc1',
            'string' => 'text📝',
        ]));

        $database->createIndex('testRedisFallback', 'index1', Database::INDEX_KEY, ['string']);
        $this->assertCount(1, $database->find('testRedisFallback', [Query::equal('string', ['text📝'])]));

        // Bring down Redis
        $stdout = '';
        $stderr = '';
        Console::execute('docker ps -a --filter "name=utopia-redis" --format "{{.Names}}" | xargs -r docker stop', "", $stdout, $stderr);

        // Check we can read data still
        $this->assertCount(1, $database->find('testRedisFallback', [Query::equal('string', ['text📝'])]));
        $this->assertFalse(($database->getDocument('testRedisFallback', 'doc1'))->isEmpty());

        // Check we cannot modify data
        try {
            $database->updateDocument('testRedisFallback', 'doc1', new Document([
                'string' => 'text📝 updated',
            ]));
            $this->fail('Failed to throw exception');
        } catch (\Throwable $e) {
            $this->assertEquals('Redis server redis:6379 went away', $e->getMessage());
        }

        try {
            $database->deleteDocument('testRedisFallback', 'doc1');
            $this->fail('Failed to throw exception');
        } catch (\Throwable $e) {
            $this->assertEquals('Redis server redis:6379 went away', $e->getMessage());
        }

        // Bring backup Redis
        Console::execute('docker ps -a --filter "name=utopia-redis" --format "{{.Names}}" | xargs -r docker start', "", $stdout, $stderr);
        sleep(5);

        $this->assertCount(1, $database->find('testRedisFallback', [Query::equal('string', ['text📝'])]));
    }

    public function testCacheReconnect(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForCacheSkipOnFailure()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Wait for Redis to be fully healthy after previous test
        $this->waitForRedis();

        // Create new cache with reconnection enabled
        $redis = new \Redis();
        $redis->connect('redis', 6379);
        $cache = new Cache((new RedisAdapter($redis))->setMaxRetries(3));

        // For Mirror, we need to set cache on both source and destination
        if ($database instanceof Mirror) {
            $database->getSource()->setCache($cache);

            $mirrorRedis = new \Redis();
            $mirrorRedis->connect('redis-mirror', 6379);
            $mirrorCache = new Cache((new RedisAdapter($mirrorRedis))->setMaxRetries(3));
            $database->getDestination()->setCache($mirrorCache);
        }

        $database->setCache($cache);

        $database->getAuthorization()->cleanRoles();
        $database->getAuthorization()->addRole(Role::any()->toString());

        try {
            $database->createCollection('testCacheReconnect', attributes: [
                new Document([
                    '$id' => ID::custom('title'),
                    'type' => Database::VAR_STRING,
                    'size' => 255,
                    'required' => true,
                ])
            ], permissions: [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any())
            ]);

            $database->createDocument('testCacheReconnect', new Document([
                '$id' => 'reconnect_doc',
                'title' => 'Test Document',
            ]));

            // Cache the document
            $doc = $database->getDocument('testCacheReconnect', 'reconnect_doc');
            $this->assertEquals('Test Document', $doc->getAttribute('title'));

            // Bring down Redis
            $stdout = '';
            $stderr = '';
            Console::execute('docker ps -a --filter "name=utopia-redis" --format "{{.Names}}" | xargs -r docker stop', "", $stdout, $stderr);
            sleep(1);

            // Bring back Redis
            Console::execute('docker ps -a --filter "name=utopia-redis" --format "{{.Names}}" | xargs -r docker start', "", $stdout, $stderr);
            $this->waitForRedis();

            // Cache should reconnect - read should work
            $doc = $database->getDocument('testCacheReconnect', 'reconnect_doc');
            $this->assertEquals('Test Document', $doc->getAttribute('title'));

            // Update should work after reconnect
            $database->updateDocument('testCacheReconnect', 'reconnect_doc', new Document([
                '$id' => 'reconnect_doc',
                'title' => 'Updated Title',
            ]));

            $doc = $database->getDocument('testCacheReconnect', 'reconnect_doc');
            $this->assertEquals('Updated Title', $doc->getAttribute('title'));
        } finally {
            // Ensure Redis is running
            $stdout = '';
            $stderr = '';
            Console::execute('docker ps -a --filter "name=utopia-redis" --format "{{.Names}}" | xargs -r docker start', "", $stdout, $stderr);
            $this->waitForRedis();

            // Cleanup collection if it exists
            if ($database->exists() && !$database->getCollection('testCacheReconnect')->isEmpty()) {
                $database->deleteCollection('testCacheReconnect');
            }
        }
    }

    /**
     * Test that withTransaction properly rolls back on failure.
     * With the Pool adapter, this verifies that the entire transaction
     * (start, callback, commit/rollback) runs on a single pinned connection.
     */
    public function testTransactionAtomicity(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection('transactionAtomicity');
        $database->createAttribute('transactionAtomicity', 'title', Database::VAR_STRING, 128, true);

        // Verify a successful transaction commits
        $doc = $database->withTransaction(function () use ($database) {
            return $database->createDocument('transactionAtomicity', new Document([
                '$id' => 'tx_success',
                '$permissions' => [
                    Permission::read(Role::any()),
                ],
                'title' => 'Committed',
            ]));
        });
        $this->assertEquals('tx_success', $doc->getId());
        $found = $database->getDocument('transactionAtomicity', 'tx_success');
        $this->assertFalse($found->isEmpty());
        $this->assertEquals('Committed', $found->getAttribute('title'));

        // Verify a failed transaction rolls back completely
        try {
            $database->withTransaction(function () use ($database) {
                $database->createDocument('transactionAtomicity', new Document([
                    '$id' => 'tx_fail',
                    '$permissions' => [
                        Permission::read(Role::any()),
                    ],
                    'title' => 'Should be rolled back',
                ]));

                throw new \Exception('Intentional failure to trigger rollback');
            });
        } catch (\Exception $e) {
            $this->assertEquals('Intentional failure to trigger rollback', $e->getMessage());
        }

        // Document should NOT exist since the transaction was rolled back
        $notFound = $database->getDocument('transactionAtomicity', 'tx_fail');
        $this->assertTrue($notFound->isEmpty(), 'Document should not exist after transaction rollback');

        $database->deleteCollection('transactionAtomicity');
    }

    /**
     * Test that withTransaction correctly resets inTransaction state
     * when a known exception (DuplicateException) is thrown after successful rollback.
     */
    public function testTransactionStateAfterKnownException(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection('txKnownException');
        $database->createAttribute('txKnownException', 'title', Database::VAR_STRING, 128, true);

        $database->createDocument('txKnownException', new Document([
            '$id' => 'existing_doc',
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'title' => 'Original',
        ]));

        // Trigger a DuplicateException inside withTransaction by inserting a duplicate ID
        try {
            $database->withTransaction(function () use ($database) {
                $database->createDocument('txKnownException', new Document([
                    '$id' => 'existing_doc',
                    '$permissions' => [
                        Permission::read(Role::any()),
                    ],
                    'title' => 'Duplicate',
                ]));
            });
            $this->fail('Expected DuplicateException was not thrown');
        } catch (DuplicateException $e) {
            // Expected
        }

        // inTransaction must be false after the exception
        $this->assertFalse(
            $database->getAdapter()->inTransaction(),
            'Adapter should not be in transaction after DuplicateException'
        );

        // Database should still be functional
        $doc = $database->getDocument('txKnownException', 'existing_doc');
        $this->assertEquals('Original', $doc->getAttribute('title'));

        $database->deleteCollection('txKnownException');
    }

    /**
     * Test that withTransaction correctly resets inTransaction state
     * when retries are exhausted for a generic exception.
     *
     * MongoDB's withTransaction has no retry logic, so this test
     * only applies to SQL-based adapters.
     */
    public function testTransactionStateAfterRetriesExhausted(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForTransactionRetries()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $attempts = 0;

        try {
            $database->withTransaction(function () use (&$attempts) {
                $attempts++;
                throw new \RuntimeException('Persistent failure');
            });
        } catch (\RuntimeException $e) {
            $this->assertEquals('Persistent failure', $e->getMessage());
        }

        // Should have attempted 3 times (initial + 2 retries)
        $this->assertEquals(3, $attempts, 'Should have exhausted all retry attempts');

        // inTransaction must be false after retries exhausted
        $this->assertFalse(
            $database->getAdapter()->inTransaction(),
            'Adapter should not be in transaction after retries exhausted'
        );
    }

    /**
     * Test that nested withTransaction calls maintain correct inTransaction state
     * when the inner transaction throws a known exception.
     *
     * MongoDB does not support nested transactions or savepoints, so a duplicate
     * key error inside an inner transaction aborts the entire transaction.
     */
    public function testNestedTransactionState(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForNestedTransactions()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('txNested');
        $database->createAttribute('txNested', 'title', Database::VAR_STRING, 128, true);

        $database->createDocument('txNested', new Document([
            '$id' => 'nested_existing',
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'title' => 'Original',
        ]));

        // Outer transaction should succeed even if inner transaction throws
        $result = $database->withTransaction(function () use ($database) {
            $database->createDocument('txNested', new Document([
                '$id' => 'outer_doc',
                '$permissions' => [
                    Permission::read(Role::any()),
                ],
                'title' => 'Outer',
            ]));

            // Inner transaction throws a DuplicateException
            try {
                $database->withTransaction(function () use ($database) {
                    $database->createDocument('txNested', new Document([
                        '$id' => 'nested_existing',
                        '$permissions' => [
                            Permission::read(Role::any()),
                        ],
                        'title' => 'Duplicate',
                    ]));
                });
            } catch (DuplicateException $e) {
                // Caught and handled — outer transaction should continue
            }

            return true;
        });

        $this->assertTrue($result);

        // inTransaction must be false after everything completes
        $this->assertFalse(
            $database->getAdapter()->inTransaction(),
            'Adapter should not be in transaction after nested transactions complete'
        );

        // Outer document should have been committed
        $outerDoc = $database->getDocument('txNested', 'outer_doc');
        $this->assertFalse($outerDoc->isEmpty(), 'Outer transaction document should exist');
        $this->assertEquals('Outer', $outerDoc->getAttribute('title'));

        // Original document should be unchanged
        $existingDoc = $database->getDocument('txNested', 'nested_existing');
        $this->assertEquals('Original', $existingDoc->getAttribute('title'));

        $database->deleteCollection('txNested');
    }

    /**
     * Wait for Redis to be ready with a readiness probe
     */
    private function waitForRedis(int $maxRetries = 10, int $delayMs = 500): void
    {
        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                $redis = new \Redis();
                $redis->connect('redis', 6379);
                $redis->ping();
                return;
            } catch (\RedisException $e) {
                usleep($delayMs * 1000);
            }
        }
    }
}
