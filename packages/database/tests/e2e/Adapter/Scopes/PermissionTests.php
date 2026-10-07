<?php

namespace Tests\E2E\Adapter\Scopes;

use Exception;
use Utopia\Database\Adapter\SQL;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

trait PermissionTests
{
    public function testUpdatingASharedDefinitionKeepsItsPermissionRowsTenantless(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        // Only the SQL adapters keep permissions in a side table that carries
        // its own tenant column; Mongo stores them on the document itself.
        if (!$database->getSharedTables() || !$database->getAdapter() instanceof SQL) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $tenant = $database->getTenant();
        $collection = 'sharedDefinitionPerms';

        try {
            // A shared pool's system collections are created once with no
            // tenant, so every tenant on the pool reads the one definition.
            $database->setTenant(null);
            $database->createCollection($collection, [], [], [Permission::read(Role::any())], false);

            // A per-project pass rewrites that definition while it holds one
            // project's tenant. The rows it writes belong to the document, not
            // to whoever happened to be selected.
            $database->setTenant(989);
            $database->updateDocument(Database::METADATA, $collection, new Document([
                '$id' => $collection,
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            ]));

            // Permission filtering reads the permission rows, so tenanting them
            // to 989 hides the shared definition from every other tenant.
            $database->setTenant(990);
            $found = $database->find(Database::METADATA, [Query::equal('$id', [$collection])]);

            $this->assertCount(
                1,
                $found,
                'A shared definition updated under one tenant must stay visible to the rest of the pool.',
            );
        } finally {
            $database->setTenant($tenant);
        }
    }

    public function testUnsetPermissions(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection(__FUNCTION__);
        $this->assertTrue($database->createAttribute(
            collection: __FUNCTION__,
            id: 'president',
            type: Database::VAR_STRING,
            size: 255,
            required: false
        ));

        $permissions = [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ];

        $documents = [];

        for ($i = 0; $i < 3; $i++) {
            $documents[] = new Document([
                '$permissions' => $permissions,
                'president' => 'Donald Trump'
            ]);
        }

        $results = [];
        $count = $database->createDocuments(__FUNCTION__, $documents, onNext: function ($doc) use (&$results) {
            $results[] = $doc;
        });

        $this->assertEquals(3, $count);

        foreach ($results as $result) {
            $this->assertEquals('Donald Trump', $result->getAttribute('president'));
            $this->assertEquals($permissions, $result->getPermissions());
        }

        /**
         * No permissions passed, Check old is preserved
         */
        $updates = new Document([
            'president' => 'George Washington'
        ]);

        $results = [];
        $modified = $database->updateDocuments(
            __FUNCTION__,
            $updates,
            onNext: function ($doc) use (&$results) {
                $results[] = $doc;
            }
        );

        $this->assertEquals(3, $modified);

        foreach ($results as $result) {
            $this->assertEquals('George Washington', $result->getAttribute('president'));
            $this->assertEquals($permissions, $result->getPermissions());
        }

        $documents = $database->find(__FUNCTION__);

        $this->assertEquals(3, count($documents));

        foreach ($documents as $document) {
            $this->assertEquals('George Washington', $document->getAttribute('president'));
            $this->assertEquals($permissions, $document->getPermissions());
        }

        /**
         * Change permissions remove delete
         */
        $permissions = [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
        ];

        $updates = new Document([
            '$permissions' => $permissions,
            'president' => 'Joe biden'
        ]);

        $results = [];
        $modified = $database->updateDocuments(
            __FUNCTION__,
            $updates,
            onNext: function ($doc) use (&$results) {
                $results[] = $doc;
            }
        );

        $this->assertEquals(3, $modified);

        foreach ($results as $result) {
            $this->assertEquals('Joe biden', $result->getAttribute('president'));
            $this->assertEquals($permissions, $result->getPermissions());
            $this->assertArrayNotHasKey('$skipPermissionsUpdate', $result);
        }

        $documents = $database->find(__FUNCTION__);

        $this->assertEquals(3, count($documents));

        foreach ($documents as $document) {
            $this->assertEquals('Joe biden', $document->getAttribute('president'));
            $this->assertEquals($permissions, $document->getPermissions());
        }

        /**
         * Unset permissions
         */
        $updates = new Document([
            '$permissions' => [],
            'president' => 'Richard Nixon'
        ]);

        $results = [];
        $modified = $database->updateDocuments(
            __FUNCTION__,
            $updates,
            onNext: function ($doc) use (&$results) {
                $results[] = $doc;
            }
        );

        $this->assertEquals(3, $modified);

        foreach ($results as $result) {
            $this->assertEquals('Richard Nixon', $result->getAttribute('president'));
            $this->assertEquals([], $result->getPermissions());
        }

        $documents = $database->find(__FUNCTION__);
        $this->assertEquals(0, count($documents));

        $this->getDatabase()->getAuthorization()->disable();
        $documents = $database->find(__FUNCTION__);
        $this->getDatabase()->getAuthorization()->reset();

        $this->assertEquals(3, count($documents));

        foreach ($documents as $document) {
            $this->assertEquals('Richard Nixon', $document->getAttribute('president'));
            $this->assertEquals([], $document->getPermissions());
            $this->assertArrayNotHasKey('$skipPermissionsUpdate', $document);
        }
    }

    public function testCreateDocumentsEmptyPermission(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection(__FUNCTION__);

        /**
         * Validate the decode function does not add $permissions null entry when no permissions are provided
         */

        $document = $database->createDocument(__FUNCTION__, new Document());

        $this->assertArrayHasKey('$permissions', $document);
        $this->assertEquals([], $document->getAttribute('$permissions'));

        $documents = [];

        for ($i = 0; $i < 2; $i++) {
            $documents[] = new Document();
        }

        $results = [];
        $count = $database->createDocuments(__FUNCTION__, $documents, onNext: function ($doc) use (&$results) {
            $results[] = $doc;
        });

        $this->assertEquals(2, $count);
        foreach ($results as $result) {
            $this->assertArrayHasKey('$permissions', $result);
            $this->assertEquals([], $result->getAttribute('$permissions'));
        }
    }

    public function testReadPermissionsFailure(): Document
    {
        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::any()->toString());

        /** @var Database $database */
        $database = $this->getDatabase();

        $document = $database->createDocument('documents', new Document([
            '$permissions' => [
                Permission::read(Role::user('1')),
                Permission::create(Role::user('1')),
                Permission::update(Role::user('1')),
                Permission::delete(Role::user('1')),
            ],
            'string' => 'text📝',
            'integer_signed' => -Database::MAX_INT,
            'integer_unsigned' => Database::MAX_INT,
            'bigint_signed' => -Database::MAX_BIG_INT,
            'bigint_unsigned' => Database::MAX_BIG_INT,
            'float_signed' => -5.55,
            'float_unsigned' => 5.55,
            'boolean' => true,
            'colors' => ['pink', 'green', 'blue'],
        ]));

        $this->getDatabase()->getAuthorization()->cleanRoles();

        $document = $database->getDocument($document->getCollection(), $document->getId());

        $this->assertEquals(true, $document->isEmpty());

        $this->getDatabase()->getAuthorization()->addRole(Role::any()->toString());

        return $document;
    }

    public function testNoChangeUpdateDocumentWithoutPermission(): Document
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $document = $database->createDocument('documents', new Document([
            '$id' => ID::unique(),
            '$permissions' => [
                Permission::read(Role::any())
            ],
            'string' => 'text📝',
            'integer_signed' => -Database::MAX_INT,
            'integer_unsigned' => Database::MAX_INT,
            'bigint_signed' => -Database::MAX_BIG_INT,
            'bigint_unsigned' => Database::MAX_BIG_INT,
            'float_signed' => -123456789.12346,
            'float_unsigned' => 123456789.12346,
            'boolean' => true,
            'colors' => ['pink', 'green', 'blue'],
        ]));

        $updatedDocument = $database->updateDocument(
            'documents',
            $document->getId(),
            $document
        );

        // Document should not be updated as there is no change.
        // It should also not throw any authorization exception without any permission because of no change.
        $this->assertEquals($updatedDocument->getUpdatedAt(), $document->getUpdatedAt());

        $document = $database->createDocument('documents', new Document([
            '$id' => ID::unique(),
            '$permissions' => [],
            'string' => 'text📝',
            'integer_signed' => -Database::MAX_INT,
            'integer_unsigned' => Database::MAX_INT,
            'bigint_signed' => -Database::MAX_BIG_INT,
            'bigint_unsigned' => Database::MAX_BIG_INT,
            'float_signed' => -123456789.12346,
            'float_unsigned' => 123456789.12346,
            'boolean' => true,
            'colors' => ['pink', 'green', 'blue'],
        ]));

        // Should throw exception, because nothing was updated, but there was no read permission
        try {
            $database->updateDocument(
                'documents',
                $document->getId(),
                $document
            );
        } catch (Exception $e) {
            $this->assertInstanceOf(AuthorizationException::class, $e);
        }

        return $document;
    }

    public function testUpdateDocumentsPermissions(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForBatchOperations()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collection = 'testUpdateDocumentsPerms';

        $database->createCollection($collection, attributes: [
            new Document([
                '$id' => ID::custom('string'),
                'type' => Database::VAR_STRING,
                'size' => 767,
                'required' => true,
            ])
        ], permissions: [], documentSecurity: true);

        // Test we can bulk update permissions we have access to
        $this->getDatabase()->getAuthorization()->skip(function () use ($collection, $database) {
            for ($i = 0; $i < 10; $i++) {
                $database->createDocument($collection, new Document([
                    '$id' => 'doc' . $i,
                    'string' => 'text📝 ' . $i,
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::create(Role::any()),
                        Permission::update(Role::any()),
                        Permission::delete(Role::any())
                    ],
                ]));
            }

            $database->createDocument($collection, new Document([
                '$id' => 'doc' . $i,
                'string' => 'text📝 ' . $i,
                '$permissions' => [
                    Permission::read(Role::user('user1')),
                    Permission::create(Role::user('user1')),
                    Permission::update(Role::user('user1')),
                    Permission::delete(Role::user('user1'))
                ],
            ]));
        });

        $modified = $database->updateDocuments($collection, new Document([
            '$permissions' => [
                Permission::read(Role::user('user2')),
                Permission::create(Role::user('user2')),
                Permission::update(Role::user('user2')),
                Permission::delete(Role::user('user2'))
            ],
        ]));

        /** @var Database $database */
        $database = $this->getDatabase();

        $documents = $this->getDatabase()->getAuthorization()->skip(function () use ($collection, $database) {
            return $database->find($collection);
        });

        $this->assertEquals(10, $modified);
        $this->assertEquals(11, \count($documents));

        $modifiedDocuments = array_filter($documents, function (Document $document) {
            return $document->getAttribute('$permissions') == [
                Permission::read(Role::user('user2')),
                Permission::create(Role::user('user2')),
                Permission::update(Role::user('user2')),
                Permission::delete(Role::user('user2'))
            ];
        });

        $this->assertCount(10, $modifiedDocuments);

        $unmodifiedDocuments = array_filter($documents, function (Document $document) {
            return $document->getAttribute('$permissions') == [
                Permission::read(Role::user('user1')),
                Permission::create(Role::user('user1')),
                Permission::update(Role::user('user1')),
                Permission::delete(Role::user('user1'))
            ];
        });

        $this->assertCount(1, $unmodifiedDocuments);

        $this->getDatabase()->getAuthorization()->addRole(Role::user('user2')->toString());

        // Test Bulk permission update with data
        $modified = $database->updateDocuments($collection, new Document([
            '$permissions' => [
                Permission::read(Role::user('user3')),
                Permission::create(Role::user('user3')),
                Permission::update(Role::user('user3')),
                Permission::delete(Role::user('user3'))
            ],
            'string' => 'text📝 updated',
        ]));

        $this->assertEquals(10, $modified);

        $documents = $this->getDatabase()->getAuthorization()->skip(function () use ($collection) {
            return $this->getDatabase()->find($collection);
        });

        $this->assertCount(11, $documents);

        $modifiedDocuments = array_filter($documents, function (Document $document) {
            return $document->getAttribute('$permissions') == [
                Permission::read(Role::user('user3')),
                Permission::create(Role::user('user3')),
                Permission::update(Role::user('user3')),
                Permission::delete(Role::user('user3'))
            ];
        });

        foreach ($modifiedDocuments as $document) {
            $this->assertEquals('text📝 updated', $document->getAttribute('string'));
        }
    }

    public function testCollectionPermissions(): Document
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $collection = $database->createCollection('collectionSecurity', permissions: [
            Permission::create(Role::users()),
            Permission::read(Role::users()),
            Permission::update(Role::users()),
            Permission::delete(Role::users())
        ], documentSecurity: false);

        $this->assertInstanceOf(Document::class, $collection);

        $this->assertTrue($database->createAttribute(
            collection: $collection->getId(),
            id: 'test',
            type: Database::VAR_STRING,
            size: 255,
            required: false
        ));

        return $collection;
    }

    /**
     * @param array<Document> $data
     * @depends testCollectionPermissionsCreateWorks
     */
    public function testCollectionPermissionsCountThrowsException(array $data): void
    {
        [$collection, $document] = $data;

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::any()->toString());

        /** @var Database $database */
        $database = $this->getDatabase();

        try {
            $database->count($collection->getId());
            $this->fail('Failed to throw exception');
        } catch (\Throwable $th) {
            $this->assertInstanceOf(AuthorizationException::class, $th);
        }
    }

    /**
     * @depends testCollectionPermissionsCreateWorks
     * @param array<Document> $data
     * @return array<Document>
     */
    public function testCollectionPermissionsCountWorks(array $data): array
    {
        [$collection, $document] = $data;

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::users()->toString());

        /** @var Database $database */
        $database = $this->getDatabase();

        $count = $database->count(
            $collection->getId()
        );

        $this->assertNotEmpty($count);

        return $data;
    }

    /**
     * @depends testCollectionPermissions
     */
    public function testCollectionPermissionsCreateThrowsException(Document $collection): void
    {
        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::any()->toString());
        $this->expectException(AuthorizationException::class);

        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createDocument($collection->getId(), new Document([
            '$id' => ID::unique(),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any())
            ],
            'test' => 'lorem ipsum'
        ]));
    }

    /**
     * @depends testCollectionPermissions
     * @return array<Document>
     */
    public function testCollectionPermissionsCreateWorks(Document $collection): array
    {
        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::users()->toString());

        /** @var Database $database */
        $database = $this->getDatabase();

        $document = $database->createDocument($collection->getId(), new Document([
            '$id' => ID::unique(),
            '$permissions' => [
                Permission::read(Role::user('random')),
                Permission::update(Role::user('random')),
                Permission::delete(Role::user('random'))
            ],
            'test' => 'lorem'
        ]));
        $this->assertInstanceOf(Document::class, $document);

        return [$collection, $document];
    }

    /**
     * @param array<Document> $data
     * @depends testCollectionPermissionsUpdateWorks
     */
    public function testCollectionPermissionsDeleteThrowsException(array $data): void
    {
        [$collection, $document] = $data;

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::any()->toString());

        $this->expectException(AuthorizationException::class);

        /** @var Database $database */
        $database = $this->getDatabase();

        $database->deleteDocument(
            $collection->getId(),
            $document->getId()
        );
    }

    /**
    * @param array<Document> $data
    * @depends testCollectionPermissionsUpdateWorks
    */
    public function testCollectionPermissionsDeleteWorks(array $data): void
    {
        [$collection, $document] = $data;

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::users()->toString());

        /** @var Database $database */
        $database = $this->getDatabase();

        $this->assertTrue($database->deleteDocument(
            $collection->getId(),
            $document->getId()
        ));
    }

    public function testCollectionPermissionsExceptions(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $this->expectException(DatabaseException::class);
        $database->createCollection('collectionSecurity', permissions: [
            'i dont work'
        ]);
    }

    /**
     * @param array<Document> $data
     * @depends testCollectionPermissionsCreateWorks
     */
    public function testCollectionPermissionsFindThrowsException(array $data): void
    {
        [$collection, $document] = $data;

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::any()->toString());

        $this->expectException(AuthorizationException::class);

        /** @var Database $database */
        $database = $this->getDatabase();

        $database->find($collection->getId());
    }

    /**
     * @depends testCollectionPermissionsCreateWorks
     * @param array<Document> $data
     * @return array<Document>
     */
    public function testCollectionPermissionsFindWorks(array $data): array
    {
        [$collection, $document] = $data;

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::users()->toString());

        /** @var Database $database */
        $database = $this->getDatabase();

        $documents = $database->find($collection->getId());
        $this->assertNotEmpty($documents);

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::user('random')->toString());

        try {
            $database->find($collection->getId());
            $this->fail('Failed to throw exception');
        } catch (AuthorizationException) {
        }

        return $data;
    }

    /**
     * @depends testCollectionPermissionsCreateWorks
     * @param array<Document> $data
     */
    public function testCollectionPermissionsGetThrowsException(array $data): void
    {
        [$collection, $document] = $data;

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::any()->toString());

        /** @var Database $database */
        $database = $this->getDatabase();

        $document = $database->getDocument(
            $collection->getId(),
            $document->getId(),
        );
        $this->assertInstanceOf(Document::class, $document);
        $this->assertTrue($document->isEmpty());
    }

    /**
     * @depends testCollectionPermissionsCreateWorks
     * @param array<Document> $data
     * @return array<Document>
     */
    public function testCollectionPermissionsGetWorks(array $data): array
    {
        [$collection, $document] = $data;

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::users()->toString());

        /** @var Database $database */
        $database = $this->getDatabase();

        $document = $database->getDocument(
            $collection->getId(),
            $document->getId()
        );
        $this->assertInstanceOf(Document::class, $document);
        $this->assertFalse($document->isEmpty());

        return $data;
    }

    /**
     * @return array<Document>
     */
    public function testCollectionPermissionsRelationships(): array
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $collection = $database->createCollection('collectionSecurity.Parent', permissions: [
            Permission::create(Role::users()),
            Permission::read(Role::users()),
            Permission::update(Role::users()),
            Permission::delete(Role::users())
        ], documentSecurity: true);

        $this->assertInstanceOf(Document::class, $collection);

        $this->assertTrue($database->createAttribute(
            collection: $collection->getId(),
            id: 'test',
            type: Database::VAR_STRING,
            size: 255,
            required: false
        ));

        $collectionOneToOne = $database->createCollection('collectionSecurity.OneToOne', permissions: [
            Permission::create(Role::users()),
            Permission::read(Role::users()),
            Permission::update(Role::users()),
            Permission::delete(Role::users())
        ], documentSecurity: true);

        $this->assertInstanceOf(Document::class, $collectionOneToOne);

        $this->assertTrue($database->createAttribute(
            collection: $collectionOneToOne->getId(),
            id: 'test',
            type: Database::VAR_STRING,
            size: 255,
            required: false
        ));

        $this->assertTrue($database->createRelationship(
            collection: $collection->getId(),
            relatedCollection: $collectionOneToOne->getId(),
            type: Database::RELATION_ONE_TO_ONE,
            id: Database::RELATION_ONE_TO_ONE,
            onDelete: Database::RELATION_MUTATE_CASCADE
        ));

        $collectionOneToMany = $database->createCollection('collectionSecurity.OneToMany', permissions: [
            Permission::create(Role::users()),
            Permission::read(Role::users()),
            Permission::update(Role::users()),
            Permission::delete(Role::users())
        ], documentSecurity: true);

        $this->assertInstanceOf(Document::class, $collectionOneToMany);

        $this->assertTrue($database->createAttribute(
            collection: $collectionOneToMany->getId(),
            id: 'test',
            type: Database::VAR_STRING,
            size: 255,
            required: false
        ));

        $this->assertTrue($database->createRelationship(
            collection: $collection->getId(),
            relatedCollection: $collectionOneToMany->getId(),
            type: Database::RELATION_ONE_TO_MANY,
            id: Database::RELATION_ONE_TO_MANY,
            onDelete: Database::RELATION_MUTATE_CASCADE
        ));

        return [$collection, $collectionOneToOne, $collectionOneToMany];
    }

    /**
     * @depends testCollectionPermissionsRelationshipsCreateWorks
     * @param array<Document> $data
     */
    public function testCollectionPermissionsRelationshipsCountWorks(array $data): void
    {
        [$collection, $collectionOneToOne, $collectionOneToMany, $document] = $data;

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::users()->toString());

        /** @var Database $database */
        $database = $this->getDatabase();

        $documents = $database->count(
            $collection->getId()
        );

        $this->assertEquals(1, $documents);

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::user('random')->toString());

        $documents = $database->count(
            $collection->getId()
        );

        $this->assertEquals(1, $documents);

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::user('unknown')->toString());

        $documents = $database->count(
            $collection->getId()
        );

        $this->assertEquals(0, $documents);
    }

    /**
     * @depends testCollectionPermissionsRelationships
     * @param array<Document> $data
     */
    public function testCollectionPermissionsRelationshipsCreateThrowsException(array $data): void
    {
        [$collection, $collectionOneToOne, $collectionOneToMany] = $data;

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::any()->toString());
        $this->expectException(AuthorizationException::class);

        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createDocument($collection->getId(), new Document([
            '$id' => ID::unique(),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any())
            ],
            'test' => 'lorem ipsum'
        ]));
    }

    /**
    * @param array<Document> $data
    * @depends testCollectionPermissionsRelationshipsUpdateWorks
    */
    public function testCollectionPermissionsRelationshipsDeleteThrowsException(array $data): void
    {
        [$collection, $collectionOneToOne, $collectionOneToMany, $document] = $data;

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::any()->toString());

        $this->expectException(AuthorizationException::class);

        /** @var Database $database */
        $database = $this->getDatabase();

        $document = $database->deleteDocument(
            $collection->getId(),
            $document->getId()
        );
    }

    /**
     * @depends testCollectionPermissionsRelationships
     * @param array<Document> $data
     * @return array<Document>
     */
    public function testCollectionPermissionsRelationshipsCreateWorks(array $data): array
    {
        [$collection, $collectionOneToOne, $collectionOneToMany] = $data;
        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::users()->toString());

        /** @var Database $database */
        $database = $this->getDatabase();

        $document = $database->createDocument($collection->getId(), new Document([
            '$id' => ID::unique(),
            '$permissions' => [
                Permission::read(Role::user('random')),
                Permission::update(Role::user('random')),
                Permission::delete(Role::user('random'))
            ],
            'test' => 'lorem',
            Database::RELATION_ONE_TO_ONE => [
                '$id' => ID::unique(),
                '$permissions' => [
                    Permission::read(Role::user('random')),
                    Permission::update(Role::user('random')),
                    Permission::delete(Role::user('random'))
                ],
                'test' => 'lorem ipsum'
            ],
            Database::RELATION_ONE_TO_MANY => [
                [
                    '$id' => ID::unique(),
                    '$permissions' => [
                        Permission::read(Role::user('random')),
                        Permission::update(Role::user('random')),
                        Permission::delete(Role::user('random'))
                    ],
                    'test' => 'lorem ipsum'
                ], [
                    '$id' => ID::unique(),
                    '$permissions' => [
                        Permission::read(Role::user('torsten')),
                        Permission::update(Role::user('random')),
                        Permission::delete(Role::user('random'))
                    ],
                    'test' => 'dolor'
                ]
            ],
        ]));
        $this->assertInstanceOf(Document::class, $document);

        return [...$data, $document];
    }

    /**
     * @param array<Document> $data
     * @depends testCollectionPermissionsRelationshipsUpdateWorks
     */
    public function testCollectionPermissionsRelationshipsDeleteWorks(array $data): void
    {
        [$collection, $collectionOneToOne, $collectionOneToMany, $document] = $data;

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::users()->toString());

        /** @var Database $database */
        $database = $this->getDatabase();

        $this->assertTrue($database->deleteDocument(
            $collection->getId(),
            $document->getId()
        ));
    }

    /**
     * @depends testCollectionPermissionsRelationshipsCreateWorks
     * @param array<Document> $data
     */
    public function testCollectionPermissionsRelationshipsFindWorks(array $data): void
    {
        [$collection, $collectionOneToOne, $collectionOneToMany, $document] = $data;

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::users()->toString());

        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $documents = $database->find(
            $collection->getId()
        );

        $this->assertIsArray($documents);
        $this->assertCount(1, $documents);
        $document = $documents[0];
        $this->assertInstanceOf(Document::class, $document);
        $this->assertInstanceOf(Document::class, $document->getAttribute(Database::RELATION_ONE_TO_ONE));
        $this->assertIsArray($document->getAttribute(Database::RELATION_ONE_TO_MANY));
        $this->assertCount(2, $document->getAttribute(Database::RELATION_ONE_TO_MANY));
        $this->assertFalse($document->isEmpty());

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::user('random')->toString());

        $documents = $database->find(
            $collection->getId()
        );

        $this->assertIsArray($documents);
        $this->assertCount(1, $documents);
        $document = $documents[0];
        $this->assertInstanceOf(Document::class, $document);
        $this->assertInstanceOf(Document::class, $document->getAttribute(Database::RELATION_ONE_TO_ONE));
        $this->assertIsArray($document->getAttribute(Database::RELATION_ONE_TO_MANY));
        $this->assertCount(1, $document->getAttribute(Database::RELATION_ONE_TO_MANY));
        $this->assertFalse($document->isEmpty());

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::user('unknown')->toString());

        $documents = $database->find(
            $collection->getId()
        );

        $this->assertIsArray($documents);
        $this->assertCount(0, $documents);
    }

    /**
     * @param array<Document> $data
     * @depends testCollectionPermissionsRelationshipsCreateWorks
     */
    public function testCollectionPermissionsRelationshipsGetThrowsException(array $data): void
    {
        [$collection, $collectionOneToOne, $collectionOneToMany, $document] = $data;

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::any()->toString());

        /** @var Database $database */
        $database = $this->getDatabase();

        $document = $database->getDocument(
            $collection->getId(),
            $document->getId(),
        );
        $this->assertInstanceOf(Document::class, $document);
        $this->assertTrue($document->isEmpty());
    }

    /**
     * @depends testCollectionPermissionsRelationshipsCreateWorks
     * @param array<Document> $data
     * @return array<Document>
     */
    public function testCollectionPermissionsRelationshipsGetWorks(array $data): array
    {
        [$collection, $collectionOneToOne, $collectionOneToMany, $document] = $data;

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::users()->toString());

        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return [];
        }

        $document = $database->getDocument(
            $collection->getId(),
            $document->getId()
        );

        $this->assertInstanceOf(Document::class, $document);
        $this->assertInstanceOf(Document::class, $document->getAttribute(Database::RELATION_ONE_TO_ONE));
        $this->assertIsArray($document->getAttribute(Database::RELATION_ONE_TO_MANY));
        $this->assertCount(2, $document->getAttribute(Database::RELATION_ONE_TO_MANY));
        $this->assertFalse($document->isEmpty());

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::user('random')->toString());

        $document = $database->getDocument(
            $collection->getId(),
            $document->getId()
        );

        $this->assertInstanceOf(Document::class, $document);
        $this->assertInstanceOf(Document::class, $document->getAttribute(Database::RELATION_ONE_TO_ONE));
        $this->assertIsArray($document->getAttribute(Database::RELATION_ONE_TO_MANY));
        $this->assertCount(1, $document->getAttribute(Database::RELATION_ONE_TO_MANY));
        $this->assertFalse($document->isEmpty());

        return $data;
    }

    /**
     * @param array<Document> $data
     * @depends testCollectionPermissionsRelationshipsCreateWorks
     */
    public function testCollectionPermissionsRelationshipsUpdateThrowsException(array $data): void
    {
        [$collection, $collectionOneToOne, $collectionOneToMany, $document] = $data;

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::any()->toString());

        $this->expectException(AuthorizationException::class);

        /** @var Database $database */
        $database = $this->getDatabase();

        $document = $database->updateDocument(
            $collection->getId(),
            $document->getId(),
            $document->setAttribute('test', $document->getAttribute('test').'new_value')
        );
    }

    /**
     * @depends testCollectionPermissionsRelationshipsCreateWorks
     * @param array<Document> $data
     * @return array<Document>
     */
    public function testCollectionPermissionsRelationshipsUpdateWorks(array $data): array
    {
        [$collection, $collectionOneToOne, $collectionOneToMany, $document] = $data;

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::users()->toString());

        /** @var Database $database */
        $database = $this->getDatabase();

        $database->updateDocument(
            $collection->getId(),
            $document->getId(),
            $document
        );

        $this->assertTrue(true);

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::user('random')->toString());

        $database->updateDocument(
            $collection->getId(),
            $document->getId(),
            $document->setAttribute('test', 'ipsum')
        );

        $this->assertTrue(true);

        return $data;
    }

    /**
     * @param array<Document> $data
     * @depends testCollectionPermissionsCreateWorks
     */
    public function testCollectionPermissionsUpdateThrowsException(array $data): void
    {
        [$collection, $document] = $data;

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::any()->toString());
        $this->expectException(AuthorizationException::class);

        /** @var Database $database */
        $database = $this->getDatabase();

        $document = $database->updateDocument(
            $collection->getId(),
            $document->getId(),
            $document->setAttribute('test', 'lorem')
        );
    }

    /**
     * @depends testCollectionPermissionsCreateWorks
     * @param array<Document> $data
     * @return array<Document>
     */
    public function testCollectionPermissionsUpdateWorks(array $data): array
    {
        [$collection, $document] = $data;

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::users()->toString());

        /** @var Database $database */
        $database = $this->getDatabase();

        $this->assertInstanceOf(Document::class, $database->updateDocument(
            $collection->getId(),
            $document->getId(),
            $document->setAttribute('test', 'ipsum')
        ));

        return $data;
    }

    /**
     * @depends testCollectionUpdate
     */
    public function testCollectionUpdatePermissionsThrowException(Document $collection): void
    {
        $this->expectException(DatabaseException::class);

        /** @var Database $database */
        $database = $this->getDatabase();

        $database->updateCollection($collection->getId(), permissions: [
            'i dont work'
        ], documentSecurity: false);
    }

    public function testWritePermissions(): void
    {
        $this->getDatabase()->getAuthorization()->addRole(Role::any()->toString());
        $database = $this->getDatabase();

        $database->createCollection('animals', permissions: [
            Permission::create(Role::any()),
        ], documentSecurity: true);

        $database->createAttribute('animals', 'type', Database::VAR_STRING, 128, true);

        $dog = $database->createDocument('animals', new Document([
            '$id' => 'dog',
            '$permissions' => [
                Permission::delete(Role::any()),
            ],
            'type' => 'Dog'
        ]));

        $cat = $database->createDocument('animals', new Document([
            '$id' => 'cat',
            '$permissions' => [
                Permission::update(Role::any()),
            ],
            'type' => 'Cat'
        ]));

        // No read permissions:

        $docs = $database->find('animals');
        $this->assertCount(0, $docs);

        $doc = $database->getDocument('animals', 'dog');
        $this->assertTrue($doc->isEmpty());

        $doc = $database->getDocument('animals', 'cat');
        $this->assertTrue($doc->isEmpty());

        // Cannot delete with update permission:
        $didFail = false;

        try {
            $database->deleteDocument('animals', 'cat');
        } catch (AuthorizationException) {
            $didFail = true;
        }

        $this->assertTrue($didFail);

        // Cannot update with delete permission:
        $didFail = false;

        try {
            $newDog = $dog->setAttribute('type', 'newDog');
            $database->updateDocument('animals', 'dog', $newDog);
        } catch (AuthorizationException) {
            $didFail = true;
        }

        $this->assertTrue($didFail);

        // Can delete:
        $database->deleteDocument('animals', 'dog');

        // Can update:
        $newCat = $cat->setAttribute('type', 'newCat');
        $database->updateDocument('animals', 'cat', $newCat);

        $docs = $this->getDatabase()->getAuthorization()->skip(fn () => $database->find('animals'));
        $this->assertCount(1, $docs);
        $this->assertEquals('cat', $docs[0]['$id']);
        $this->assertEquals('newCat', $docs[0]['type']);
    }

    public function testCreateRelationDocumentWithoutUpdatePermission(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::user('a')->toString());

        $database->createCollection('parentRelationTest', [], [], [
            Permission::read(Role::user('a')),
            Permission::create(Role::user('a')),
            Permission::update(Role::user('a')),
            Permission::delete(Role::user('a'))
        ]);
        $database->createCollection('childRelationTest', [], [], [
            Permission::create(Role::user('a')),
            Permission::read(Role::user('a')),
        ]);
        $database->createAttribute('parentRelationTest', 'name', Database::VAR_STRING, 255, false);
        $database->createAttribute('childRelationTest', 'name', Database::VAR_STRING, 255, false);

        $database->createRelationship(
            collection: 'parentRelationTest',
            relatedCollection: 'childRelationTest',
            type: Database::RELATION_ONE_TO_MANY,
            id: 'children'
        );

        // Create document with relationship with nested data
        $parent = $database->createDocument('parentRelationTest', new Document([
            '$id' => 'parent1',
            'name' => 'Parent 1',
            'children' => [
                [
                    '$id' => 'child1',
                    'name' => 'Child 1',
                ],
            ],
        ]));
        $this->assertEquals('child1', $parent->getAttribute('children')[0]->getId());
        $parent->setAttribute('children', [
            [
                '$id' => 'child2',
            ],
        ]);
        $updatedParent = $database->updateDocument('parentRelationTest', 'parent1', $parent);

        $this->assertEquals('child2', $updatedParent->getAttribute('children')[0]->getId());

        $database->deleteCollection('parentRelationTest');
        $database->deleteCollection('childRelationTest');
    }

    public function testDocumentPermissionRolesAreMatchedExactly(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        $authorization = $database->getAuthorization();
        $collection = 'perm_exact_' . uniqid();

        $database->createCollection($collection, permissions: [
            Permission::create(Role::any()),
        ], documentSecurity: true);
        $database->createAttribute($collection, 'amount', Database::VAR_INTEGER, 0, true);

        $authorization->skip(function () use ($database, $collection): void {
            $database->createDocument($collection, new Document([
                '$id' => 'alice0',
                '$permissions' => [Permission::read(Role::user('alice0'))],
                'amount' => 10,
            ]));
            $database->createDocument($collection, new Document([
                '$id' => 'alice9',
                '$permissions' => [Permission::read(Role::user('alice9'))],
                'amount' => 20,
            ]));
            $database->createDocument($collection, new Document([
                '$id' => 'mass',
                '$permissions' => [Permission::read(Role::user('a3f9c1e0b2d4a6f8c1e0'))],
                'amount' => 30,
            ]));
            $database->createDocument($collection, new Document([
                '$id' => 'literal',
                '$permissions' => [Permission::read(Role::user('alice.'))],
                'amount' => 40,
            ]));
        });

        $authorization->cleanRoles();
        $authorization->addRole(Role::user('alice.')->toString());

        $this->assertSame(['literal'], $this->documentIds($database->find($collection)));
        $this->assertSame(1, $database->count($collection));
        $this->assertSame(40, (int) $database->sum($collection, 'amount'));
        $this->assertTrue($database->getDocument($collection, 'alice0')->isEmpty());
        $this->assertSame('literal', $database->getDocument($collection, 'literal')->getId());

        $authorization->cleanRoles();
        $authorization->addRole(Role::user('a' . \str_repeat('.', 19))->toString());

        $this->assertSame([], $this->documentIds($database->find($collection)));
        $this->assertSame(0, $database->count($collection));
        $this->assertSame(0, (int) $database->sum($collection, 'amount'));
        $this->assertTrue($database->getDocument($collection, 'mass')->isEmpty());

        $authorization->cleanRoles();
        $authorization->addRole(Role::user('alice0')->toString());

        $this->assertSame(['alice0'], $this->documentIds($database->find($collection)));
        $this->assertSame(1, $database->count($collection));
        $this->assertSame(10, (int) $database->sum($collection, 'amount'));
        $this->assertSame('alice0', $database->getDocument($collection, 'alice0')->getId());

        $database->deleteCollection($collection);
    }

    /**
     * @param array<Document> $documents
     * @return list<string>
     */
    private function documentIds(array $documents): array
    {
        return \array_values(\array_map(
            static fn (Document $document): string => $document->getId(),
            $documents,
        ));
    }

}
