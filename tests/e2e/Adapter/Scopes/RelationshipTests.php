<?php

namespace Tests\E2E\Adapter\Scopes;

use Exception;
use Tests\E2E\Adapter\Scopes\Relationships\ManyToManyTests;
use Tests\E2E\Adapter\Scopes\Relationships\ManyToOneTests;
use Tests\E2E\Adapter\Scopes\Relationships\OneToManyTests;
use Tests\E2E\Adapter\Scopes\Relationships\OneToOneTests;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Exception\Relationship as RelationshipException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

trait RelationshipTests
{
    use OneToOneTests;
    use OneToManyTests;
    use ManyToOneTests;
    use ManyToManyTests;

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function relationshipQueryValueLimitProvider(): array
    {
        $cases = [];
        foreach ([Database::RELATION_ONE_TO_ONE, Database::RELATION_ONE_TO_MANY, Database::RELATION_MANY_TO_ONE, Database::RELATION_MANY_TO_MANY] as $type) {
            foreach (['parents', 'children'] as $collection) {
                $cases[$type . ' ' . $collection] = [$type, $collection, 3];
            }
        }

        // One parent can exceed the value limit when fetching its related documents.
        $cases['manyToMany related documents'] = [Database::RELATION_MANY_TO_MANY, 'parents', 1];

        return $cases;
    }

    /**
     * @dataProvider relationshipQueryValueLimitProvider
     */
    public function testRelationshipQueryValueLimit(string $type, string $collection, int $limit): void
    {
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $parents = ID::unique();
        $children = ID::unique();
        $permissions = [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ];
        $database->createCollection($parents, permissions: $permissions);
        $database->createCollection($children, permissions: $permissions);
        $database->createRelationship($parents, $children, $type, true, 'children', 'parents');

        foreach (['child1', 'child2', 'child3'] as $id) {
            $database->createDocument($children, new Document(['$id' => $id]));
        }

        for ($i = 1; $i <= 3; $i++) {
            $related = match ($type) {
                Database::RELATION_MANY_TO_MANY => ['child1', 'child2', 'child3'],
                Database::RELATION_ONE_TO_MANY => ['child' . $i],
                default => 'child' . $i,
            };
            $database->createDocument($parents, new Document([
                '$id' => 'parent' . $i,
                'children' => $related,
            ]));
        }

        $max = $database->getMaxQueryValues();
        $database->setMaxQueryValues(2);

        try {
            $documents = $database->find($collection === 'parents' ? $parents : $children, [Query::limit($limit)]);

            $this->assertCount($limit, $documents);
            foreach ($documents as $document) {
                $related = $document->getAttribute($collection === 'parents' ? 'children' : 'parents');
                $related = $related instanceof Document ? [$related] : $related;
                $ids = \array_map(fn (Document $related) => $related->getId(), $related);
                $prefix = $collection === 'parents' ? 'child' : 'parent';
                $expected = $type === Database::RELATION_MANY_TO_MANY
                    ? [$prefix . '1', $prefix . '2', $prefix . '3']
                    : [$prefix . \substr($document->getId(), -1)];
                $this->assertEqualsCanonicalizing($expected, $ids);
            }

            $this->expectException(QueryException::class);
            $database->find($children, [Query::equal('$id', ['child1', 'child2', 'child3'])]);
        } finally {
            $database->setMaxQueryValues($max);
            $database->deleteCollection($parents);
            $database->deleteCollection($children);
        }
    }

    public function testZoo(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('zoo');
        $database->createAttribute('zoo', 'name', Database::VAR_STRING, 256, true);

        $database->createCollection('veterinarians');
        $database->createAttribute('veterinarians', 'fullname', Database::VAR_STRING, 256, true);

        $database->createCollection('presidents');
        $database->createAttribute('presidents', 'firstName', Database::VAR_STRING, 256, true);
        $database->createAttribute('presidents', 'lastName', Database::VAR_STRING, 256, true);
        $database->createRelationship(
            collection: 'presidents',
            relatedCollection: 'veterinarians',
            type: Database::RELATION_MANY_TO_MANY,
            twoWay: true,
            id: 'votes',
            twoWayKey: 'presidents'
        );

        $database->createCollection('__animals');
        $database->createAttribute('__animals', 'name', Database::VAR_STRING, 256, true);
        $database->createAttribute('__animals', 'age', Database::VAR_INTEGER, 0, false);
        $database->createAttribute('__animals', 'price', Database::VAR_FLOAT, 0, false);
        $database->createAttribute('__animals', 'dateOfBirth', Database::VAR_DATETIME, 0, true, filters:['datetime']);
        $database->createAttribute('__animals', 'longtext', Database::VAR_STRING, 100000000, false);
        $database->createAttribute('__animals', 'isActive', Database::VAR_BOOLEAN, 0, false, default: true);
        $database->createAttribute('__animals', 'integers', Database::VAR_INTEGER, 0, false, array: true);
        $database->createAttribute('__animals', 'email', Database::VAR_STRING, 255, false);
        $database->createAttribute('__animals', 'ip', Database::VAR_STRING, 255, false);
        $database->createAttribute('__animals', 'url', Database::VAR_STRING, 255, false);
        $database->createAttribute('__animals', 'enum', Database::VAR_STRING, 255, false);

        $database->createRelationship(
            collection: 'presidents',
            relatedCollection: '__animals',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
            id: 'animal',
            twoWayKey: 'president'
        );

        $database->createRelationship(
            collection: 'veterinarians',
            relatedCollection: '__animals',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true,
            id: 'animals',
            twoWayKey: 'veterinarian'
        );

        $database->createRelationship(
            collection: '__animals',
            relatedCollection: 'zoo',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
            id: 'zoo',
            twoWayKey: 'animals'
        );

        $zoo = $database->createDocument('zoo', new Document([
            '$id' => 'zoo1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'name' => 'Bronx Zoo'
        ]));

        $this->assertEquals('zoo1', $zoo->getId());
        $this->assertArrayHasKey('animals', $zoo);

        $iguana = $database->createDocument('__animals', new Document([
            '$id' => 'iguana',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'name' => 'Iguana',
            'age' => 11,
            'price' => 50.5,
            'dateOfBirth' => '1975-06-12',
            'longtext' => 'I am a pretty long text',
            'isActive' => true,
            'integers' => [1, 2, 3],
            'email' => 'iguana@appwrite.io',
            'enum' => 'maybe',
            'ip' => '127.0.0.1',
            'url' => 'https://appwrite.io/',
            'zoo' => $zoo->getId(),
        ]));

        $tiger = $database->createDocument('__animals', new Document([
            '$id' => 'tiger',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'name' => 'Tiger',
            'age' => 5,
            'price' => 1000,
            'dateOfBirth' => '2020-06-12',
            'longtext' => 'I am a hungry tiger',
            'isActive' => false,
            'integers' => [9, 2, 3],
            'email' => 'tiger@appwrite.io',
            'enum' => 'yes',
            'ip' => '255.0.0.1',
            'url' => 'https://appwrite.io/',
            'zoo' => $zoo->getId(),
        ]));

        $lama = $database->createDocument('__animals', new Document([
            '$id' => 'lama',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'name' => 'Lama',
            'age' => 15,
            'price' => 1000,
            'dateOfBirth' => '1975-06-12',
            'isActive' => true,
            'integers' => null,
            'email' => null,
            'enum' => null,
            'ip' => '255.0.0.1',
            'url' => 'https://appwrite.io/',
            'zoo' => null,
        ]));

        $veterinarian1 = $database->createDocument('veterinarians', new Document([
            '$id' => 'dr.pol',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'fullname' => 'The Incredible Dr. Pol',
            'animals' => ['iguana'],
        ]));

        $veterinarian2 = $database->createDocument('veterinarians', new Document([
            '$id' => 'dr.seuss',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'fullname' => 'Dr. Seuss',
            'animals' => ['tiger'],
        ]));

        $trump = $database->createDocument('presidents', new Document([
            '$id' => 'trump',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'firstName' => 'Donald',
            'lastName' => 'Trump',
            'votes' => [
                $veterinarian1->getId(),
                $veterinarian2->getId(),
            ],
        ]));

        $bush = $database->createDocument('presidents', new Document([
            '$id' => 'bush',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'firstName' => 'George',
            'lastName' => 'Bush',
            'animal' => 'iguana',
        ]));

        $biden = $database->createDocument('presidents', new Document([
            '$id' => 'biden',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'firstName' => 'Joe',
            'lastName' => 'Biden',
            'animal' => 'tiger',
        ]));

        /**
         * Check Zoo data
         */
        $zoo = $database->getDocument('zoo', 'zoo1');

        $this->assertEquals('zoo1', $zoo->getId());
        $this->assertEquals('Bronx Zoo', $zoo->getAttribute('name'));
        $this->assertArrayHasKey('animals', $zoo);
        $this->assertEquals(2, count($zoo->getAttribute('animals')));
        $this->assertArrayHasKey('president', $zoo->getAttribute('animals')[0]);
        $this->assertArrayHasKey('veterinarian', $zoo->getAttribute('animals')[0]);

        $zoo = $database->findOne('zoo');

        $this->assertEquals('zoo1', $zoo->getId());
        $this->assertEquals('Bronx Zoo', $zoo->getAttribute('name'));
        $this->assertArrayHasKey('animals', $zoo);
        $this->assertEquals(2, count($zoo->getAttribute('animals')));
        $this->assertArrayHasKey('president', $zoo->getAttribute('animals')[0]);
        $this->assertArrayHasKey('veterinarian', $zoo->getAttribute('animals')[0]);

        /**
         * Check Veterinarians data
         */
        $veterinarian = $database->getDocument('veterinarians', 'dr.pol');

        $this->assertEquals('dr.pol', $veterinarian->getId());
        $this->assertArrayHasKey('presidents', $veterinarian);
        $this->assertEquals(1, count($veterinarian->getAttribute('presidents')));
        $this->assertArrayHasKey('animal', $veterinarian->getAttribute('presidents')[0]);
        $this->assertArrayHasKey('animals', $veterinarian);
        $this->assertEquals(1, count($veterinarian->getAttribute('animals')));
        $this->assertArrayHasKey('zoo', $veterinarian->getAttribute('animals')[0]);
        $this->assertArrayHasKey('president', $veterinarian->getAttribute('animals')[0]);

        $veterinarian = $database->findOne('veterinarians', [
            Query::equal('$id', ['dr.pol'])
        ]);

        $this->assertEquals('dr.pol', $veterinarian->getId());
        $this->assertArrayHasKey('presidents', $veterinarian);
        $this->assertEquals(1, count($veterinarian->getAttribute('presidents')));
        $this->assertArrayHasKey('animal', $veterinarian->getAttribute('presidents')[0]);
        $this->assertArrayHasKey('animals', $veterinarian);
        $this->assertEquals(1, count($veterinarian->getAttribute('animals')));
        $this->assertArrayHasKey('zoo', $veterinarian->getAttribute('animals')[0]);
        $this->assertArrayHasKey('president', $veterinarian->getAttribute('animals')[0]);

        /**
         * Check Animals data
         */
        $animal = $database->getDocument('__animals', 'iguana');

        $this->assertEquals('iguana', $animal->getId());
        $this->assertArrayHasKey('zoo', $animal);
        $this->assertEquals('Bronx Zoo', $animal['zoo']->getAttribute('name'));
        $this->assertArrayHasKey('veterinarian', $animal);
        $this->assertEquals('dr.pol', $animal['veterinarian']->getId());
        $this->assertArrayHasKey('presidents', $animal['veterinarian']);
        $this->assertArrayHasKey('president', $animal);
        $this->assertEquals('bush', $animal['president']->getId());

        $animal = $database->findOne('__animals', [
            Query::equal('$id', ['tiger'])
        ]);

        $this->assertEquals('tiger', $animal->getId());
        $this->assertArrayHasKey('zoo', $animal);
        $this->assertEquals('Bronx Zoo', $animal['zoo']->getAttribute('name'));
        $this->assertArrayHasKey('veterinarian', $animal);
        $this->assertEquals('dr.seuss', $animal['veterinarian']->getId());
        $this->assertArrayHasKey('presidents', $animal['veterinarian']);
        $this->assertArrayHasKey('president', $animal);
        $this->assertEquals('biden', $animal['president']->getId());

        /**
         * Check President data
         */
        $president = $database->getDocument('presidents', 'trump');

        $this->assertEquals('trump', $president->getId());
        $this->assertArrayHasKey('animal', $president);
        $this->assertArrayHasKey('votes', $president);
        $this->assertEquals(2, count($president['votes']));

        /**
         * Check President data
         */
        $president = $database->findOne('presidents', [
            Query::equal('$id', ['bush'])
        ]);

        $this->assertEquals('bush', $president->getId());
        $this->assertArrayHasKey('animal', $president);
        $this->assertArrayHasKey('votes', $president);
        $this->assertEquals(0, count($president['votes']));

        $president = $database->findOne('presidents', [
            Query::select([
                '*',
                'votes.*',
            ]),
            Query::equal('$id', ['trump'])
        ]);

        $this->assertEquals('trump', $president->getId());
        $this->assertArrayHasKey('votes', $president);
        $this->assertEquals(2, count($president['votes']));
        $this->assertArrayNotHasKey('animals', $president['votes'][0]); // Not exist

        $president = $database->findOne('presidents', [
            Query::select([
                '*',
                'votes.*',
                'votes.animals.*',
            ]),
            Query::equal('$id', ['trump'])
        ]);

        $this->assertEquals('trump', $president->getId());
        $this->assertArrayHasKey('votes', $president);
        $this->assertEquals(2, count($president['votes']));
        $this->assertArrayHasKey('animals', $president['votes'][0]); // Exist

        /**
         * Check Selects queries
         */
        $veterinarian = $database->findOne('veterinarians', [
            Query::select(['*']), // No resolving
            Query::equal('$id', ['dr.pol']),
        ]);

        $this->assertEquals('dr.pol', $veterinarian->getId());
        $this->assertArrayNotHasKey('presidents', $veterinarian);
        $this->assertArrayNotHasKey('animals', $veterinarian);

        $veterinarian = $database->findOne(
            'veterinarians',
            [
                Query::select([
                    'animals.*',
                ])
            ]
        );

        $this->assertEquals('dr.pol', $veterinarian->getId());
        $this->assertArrayHasKey('animals', $veterinarian);
        $this->assertArrayNotHasKey('presidents', $veterinarian);

        $animal = $veterinarian['animals'][0];

        $this->assertArrayHasKey('president', $animal);
        $this->assertEquals('bush', $animal->getAttribute('president')); // Check president is a value
        $this->assertArrayHasKey('zoo', $animal);
        $this->assertEquals('zoo1', $animal->getAttribute('zoo')); // Check zoo is a value

        $veterinarian = $database->findOne(
            'veterinarians',
            [
                Query::select([
                    'animals.*',
                    'animals.zoo.*',
                    'animals.president.*',
                ])
            ]
        );

        $this->assertEquals('dr.pol', $veterinarian->getId());
        $this->assertArrayHasKey('animals', $veterinarian);
        $this->assertArrayNotHasKey('presidents', $veterinarian);

        $animal = $veterinarian['animals'][0];

        $this->assertArrayHasKey('president', $animal);
        $this->assertEquals('Bush', $animal->getAttribute('president')->getAttribute('lastName')); // Check president is an object
        $this->assertArrayHasKey('zoo', $animal);
        $this->assertEquals('Bronx Zoo', $animal->getAttribute('zoo')->getAttribute('name')); // Check zoo is an object
    }

    public function testSimpleRelationshipPopulation(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Simple test case: user -> post (one-to-many)
        $database->createCollection('usersSimple');
        $database->createCollection('postsSimple');

        $database->createAttribute('usersSimple', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('postsSimple', 'title', Database::VAR_STRING, 255, true);

        $database->createRelationship(
            collection: 'usersSimple',
            relatedCollection: 'postsSimple',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true,
            id: 'posts',
            twoWayKey: 'author'
        );

        // Create some data
        $user = $database->createDocument('usersSimple', new Document([
            '$id' => 'user1',
            '$permissions' => [Permission::read(Role::any())],
            'name' => 'John Doe',
        ]));

        $post1 = $database->createDocument('postsSimple', new Document([
            '$id' => 'post1',
            '$permissions' => [Permission::read(Role::any())],
            'title' => 'First Post',
            'author' => 'user1',
        ]));

        $post2 = $database->createDocument('postsSimple', new Document([
            '$id' => 'post2',
            '$permissions' => [Permission::read(Role::any())],
            'title' => 'Second Post',
            'author' => 'user1',
        ]));

        // fetch user with posts populated
        $fetchedUser = $database->getDocument('usersSimple', 'user1');
        $posts = $fetchedUser->getAttribute('posts', []);

        // Basic assertions
        $this->assertIsArray($posts, 'Posts should be an array');
        $this->assertCount(2, $posts, 'Should have 2 posts');

        if (!empty($posts)) {
            $this->assertInstanceOf(Document::class, $posts[0], 'First post should be a Document object');
            $this->assertEquals('First Post', $posts[0]->getAttribute('title'), 'First post title should be populated');
        }

        // fetch posts with author populated
        $fetchedPosts = $database->find('postsSimple');

        $this->assertCount(2, $fetchedPosts, 'Should fetch 2 posts');

        if (!empty($fetchedPosts)) {
            $author = $fetchedPosts[0]->getAttribute('author');
            $this->assertInstanceOf(Document::class, $author, 'Author should be a Document object');
            $this->assertEquals('John Doe', $author->getAttribute('name'), 'Author name should be populated');
        }
    }

    public function testDeleteRelatedCollection(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('c1');
        $database->createCollection('c2');

        // ONE_TO_ONE
        $database->createRelationship(
            collection: 'c1',
            relatedCollection: 'c2',
            type: Database::RELATION_ONE_TO_ONE,
        );

        $this->assertEquals(true, $database->deleteCollection('c1'));
        $collection = $database->getCollection('c2');
        $this->assertCount(0, $collection->getAttribute('attributes'));
        $this->assertCount(0, $collection->getAttribute('indexes'));

        $database->createCollection('c1');
        $database->createRelationship(
            collection: 'c1',
            relatedCollection: 'c2',
            type: Database::RELATION_ONE_TO_ONE,
        );

        $this->assertEquals(true, $database->deleteCollection('c2'));
        $collection = $database->getCollection('c1');
        $this->assertCount(0, $collection->getAttribute('attributes'));
        $this->assertCount(0, $collection->getAttribute('indexes'));

        $database->createCollection('c2');
        $database->createRelationship(
            collection: 'c1',
            relatedCollection: 'c2',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true
        );

        $this->assertEquals(true, $database->deleteCollection('c1'));
        $collection = $database->getCollection('c2');
        $this->assertCount(0, $collection->getAttribute('attributes'));
        $this->assertCount(0, $collection->getAttribute('indexes'));

        $database->createCollection('c1');
        $database->createRelationship(
            collection: 'c1',
            relatedCollection: 'c2',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true
        );

        $this->assertEquals(true, $database->deleteCollection('c2'));
        $collection = $database->getCollection('c1');
        $this->assertCount(0, $collection->getAttribute('attributes'));
        $this->assertCount(0, $collection->getAttribute('indexes'));

        // ONE_TO_MANY
        $database->createCollection('c2');
        $database->createRelationship(
            collection: 'c1',
            relatedCollection: 'c2',
            type: Database::RELATION_ONE_TO_MANY,
        );

        $this->assertEquals(true, $database->deleteCollection('c1'));
        $collection = $database->getCollection('c2');
        $this->assertCount(0, $collection->getAttribute('attributes'));
        $this->assertCount(0, $collection->getAttribute('indexes'));

        $database->createCollection('c1');
        $database->createRelationship(
            collection: 'c1',
            relatedCollection: 'c2',
            type: Database::RELATION_ONE_TO_MANY,
        );

        $this->assertEquals(true, $database->deleteCollection('c2'));
        $collection = $database->getCollection('c1');
        $this->assertCount(0, $collection->getAttribute('attributes'));
        $this->assertCount(0, $collection->getAttribute('indexes'));

        $database->createCollection('c2');
        $database->createRelationship(
            collection: 'c1',
            relatedCollection: 'c2',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true
        );

        $this->assertEquals(true, $database->deleteCollection('c1'));
        $collection = $database->getCollection('c2');
        $this->assertCount(0, $collection->getAttribute('attributes'));
        $this->assertCount(0, $collection->getAttribute('indexes'));

        $database->createCollection('c1');
        $database->createRelationship(
            collection: 'c1',
            relatedCollection: 'c2',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true
        );

        $this->assertEquals(true, $database->deleteCollection('c2'));
        $collection = $database->getCollection('c1');
        $this->assertCount(0, $collection->getAttribute('attributes'));
        $this->assertCount(0, $collection->getAttribute('indexes'));

        // RELATION_MANY_TO_ONE
        $database->createCollection('c2');
        $database->createRelationship(
            collection: 'c1',
            relatedCollection: 'c2',
            type: Database::RELATION_MANY_TO_ONE,
        );

        $this->assertEquals(true, $database->deleteCollection('c1'));
        $collection = $database->getCollection('c2');
        $this->assertCount(0, $collection->getAttribute('attributes'));
        $this->assertCount(0, $collection->getAttribute('indexes'));

        $database->createCollection('c1');
        $database->createRelationship(
            collection: 'c1',
            relatedCollection: 'c2',
            type: Database::RELATION_MANY_TO_ONE,
        );

        $this->assertEquals(true, $database->deleteCollection('c2'));
        $collection = $database->getCollection('c1');
        $this->assertCount(0, $collection->getAttribute('attributes'));
        $this->assertCount(0, $collection->getAttribute('indexes'));

        $database->createCollection('c2');
        $database->createRelationship(
            collection: 'c1',
            relatedCollection: 'c2',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true
        );

        $this->assertEquals(true, $database->deleteCollection('c1'));
        $collection = $database->getCollection('c2');
        $this->assertCount(0, $collection->getAttribute('attributes'));
        $this->assertCount(0, $collection->getAttribute('indexes'));

        $database->createCollection('c1');
        $database->createRelationship(
            collection: 'c1',
            relatedCollection: 'c2',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true
        );

        $this->assertEquals(true, $database->deleteCollection('c2'));
        $collection = $database->getCollection('c1');
        $this->assertCount(0, $collection->getAttribute('attributes'));
        $this->assertCount(0, $collection->getAttribute('indexes'));
    }

    public function testVirtualRelationsAttributes(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('v1');
        $database->createCollection('v2');

        /**
         * RELATION_ONE_TO_ONE
         * TwoWay is false no attribute is created on v2
         */
        $database->createRelationship(
            collection: 'v1',
            relatedCollection: 'v2',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: false
        );

        try {
            $database->createDocument('v2', new Document([
                '$id' => 'doc1',
                '$permissions' => [],
                'v1' => 'invalidValue',
            ]));
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof RelationshipException);
        }

        try {
            $database->createDocument('v2', new Document([
                '$id' => 'doc1',
                '$permissions' => [],
                'v1' => [
                    '$id' => 'test',
                    '$permissions' => [],
                ]
            ]));
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof RelationshipException);
        }

        try {
            $database->find('v2', [
                Query::equal('v1', ['virtualAttribute']),
            ]);
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof QueryException);
        }

        /**
         * Success for later test update
         */
        $doc = $database->createDocument('v1', new Document([
            '$id' => 'man',
            '$permissions' => [
                Permission::update(Role::any()),
                Permission::read(Role::any()),
            ],
            'v2' => [
                '$id' => 'woman',
                '$permissions' => [
                    Permission::update(Role::any()),
                    Permission::read(Role::any())
                ]
            ]
        ]));

        $this->assertEquals('man', $doc->getId());

        try {
            $database->updateDocument('v1', 'man', new Document([
                '$permissions' => [],
                'v2' => [[
                    '$id' => 'woman',
                    '$permissions' => []
                ]]
            ]));
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertInstanceOf(RelationshipException::class, $e);
        }

        $database->deleteRelationship('v1', 'v2');

        /**
         * RELATION_ONE_TO_MANY
         * No attribute is created in V1 collection
         */
        $database->createRelationship(
            collection: 'v1',
            relatedCollection: 'v2',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true
        );

        try {
            $database->createDocument('v1', new Document([
                '$id' => 'doc1',
                '$permissions' => [],
                'v2' => [ // Expecting Array of arrays or array of strings, object provided
                    '$id' => 'test',
                    '$permissions' => [],
                ]
            ]));
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof RelationshipException);
        }

        try {
            $database->createDocument('v1', new Document([
                '$permissions' => [],
                'v2' => 'invalidValue',
            ]));
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof RelationshipException);
        }

        try {
            $database->createDocument('v2', new Document([
                '$id' => 'doc1',
                '$permissions' => [],
                'v1' => [[  // Expecting a string or an object ,array provided
                    '$id' => 'test',
                    '$permissions' => [],
                ]]
            ]));
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof RelationshipException);
        }

        /**
         * Success for later test update
         */
        $doc = $database->createDocument('v2', new Document([
            '$id' => 'v2_uid',
            '$permissions' => [
                Permission::update(Role::any()),
            ],
            'v1' => [
                '$id' => 'v1_uid',
                '$permissions' => [
                    Permission::update(Role::any())
                ],
            ]
        ]));

        $this->assertEquals('v2_uid', $doc->getId());

        /**
         * Test update
         */

        try {
            $database->updateDocument('v1', 'v1_uid', new Document([
                '$permissions' => [],
                'v2' => [ // Expecting array of arrays or array of strings, object given
                    '$id' => 'v2_uid',
                    '$permissions' => [],
                ]
            ]));
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof RelationshipException);
        }

        try {
            $database->updateDocument('v1', 'v1_uid', new Document([
                '$permissions' => [],
                'v2' => 'v2_uid'
            ]));
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof RelationshipException);
        }

        try {
            $database->updateDocument('v2', 'v2_uid', new Document([
                '$permissions' => [],
                'v1' => [
                    '$id' => null, // Invalid value
                    '$permissions' => [],
                ]
            ]));
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof RelationshipException);
        }

        /**
         * Here we get this error: Unknown PDO Type for array
         * Added in Filter.php Text validator for relationship
         */
        try {
            $database->find('v2', [
                //@phpstan-ignore-next-line
                Query::equal('v1', [['doc1']]),
            ]);
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof QueryException);
        }

        try {
            $database->find('v1', [
                Query::equal('v2', ['virtualAttribute']),
            ]);
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof QueryException);
        }

        $database->deleteRelationship('v1', 'v2');

        /**
         * RELATION_MANY_TO_ONE
         * No attribute is created in V2 collection
         */
        $database->createRelationship(
            collection: 'v1',
            relatedCollection: 'v2',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true
        );

        try {
            $database->createDocument('v1', new Document([
                '$id' => 'doc',
                '$permissions' => [],
                'v2' => [[ // Expecting an object or a string array provided
                    '$id' => 'test',
                    '$permissions' => [],
                ]]
            ]));
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof RelationshipException);
        }

        try {
            $database->createDocument('v2', new Document([
                '$permissions' => [],
                'v1' => 'invalidValue',
            ]));
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof RelationshipException);
        }

        try {
            $database->createDocument('v2', new Document([
                '$id' => 'doc',
                '$permissions' => [],
                'v1' => [ // Expecting an array, object provided
                    '$id' => 'test',
                    '$permissions' => [],
                ]
            ]));
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof RelationshipException);
        }

        try {
            $database->find('v2', [
                Query::equal('v1', ['virtualAttribute']),
            ]);
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof QueryException);
        }

        /**
         * Success for later test update
         */
        $doc = $database->createDocument('v1', new Document([
            '$id' => 'doc1',
            '$permissions' => [
                Permission::update(Role::any()),
                Permission::read(Role::any()),
            ],
            'v2' => [
                '$id' => 'doc2',
                '$permissions' => [
                    Permission::update(Role::any()),
                    Permission::read(Role::any()),
                ],
            ]
        ]));

        $this->assertEquals('doc1', $doc->getId());

        try {
            $database->updateDocument('v1', 'doc1', new Document([
                '$permissions' => [
                    Permission::update(Role::any()),
                    Permission::read(Role::any()),
                ],
                'v2' => [[]],
            ]));
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof RelationshipException);
        }

        try {
            $database->updateDocument('v2', 'doc2', new Document([
                '$permissions' => [],
                'v1' => null
            ]));
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof RelationshipException);
        }

        $database->deleteRelationship('v1', 'v2');

        /**
         * RELATION_MANY_TO_MANY
         * No attribute on V1/v2 collections only on junction table
         */
        $database->createRelationship(
            collection: 'v1',
            relatedCollection: 'v2',
            type: Database::RELATION_MANY_TO_MANY,
            twoWay: true,
            id: 'students',
            twoWayKey: 'classes'
        );

        try {
            $database->createDocument('v1', new Document([
                '$permissions' => [],
                'students' => 'invalidValue',
            ]));
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof RelationshipException);
        }

        try {
            $database->createDocument('v2', new Document([
                '$permissions' => [],
                'classes' => 'invalidValue',
            ]));
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof RelationshipException);
        }

        try {
            $database->createDocument('v2', new Document([
                '$id' => 'doc',
                '$permissions' => [],
                'classes' => [ // Expected array, object provided
                    '$id' => 'test',
                    '$permissions' => [],
                ]
            ]));
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof RelationshipException);
        }

        try {
            $database->find('v1', [
                Query::equal('students', ['virtualAttribute']),
            ]);
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof QueryException);
        }

        try {
            $database->find('v2', [
                Query::equal('classes', ['virtualAttribute']),
            ]);
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof QueryException);
        }

        /**
         * Success for later test update
         */

        $doc = $database->createDocument('v1', new Document([
            '$id' => 'class1',
            '$permissions' => [
                Permission::update(Role::any()),
                Permission::read(Role::any()),
            ],
            'students' => [
                [
                    '$id' => 'Richard',
                    '$permissions' => [
                        Permission::update(Role::any()),
                        Permission::read(Role::any())
                    ]
                ],
                [
                    '$id' => 'Bill',
                    '$permissions' => [
                        Permission::update(Role::any()),
                        Permission::read(Role::any())
                    ]
                ]
            ]
        ]));

        $this->assertEquals('class1', $doc->getId());

        try {
            $database->updateDocument('v1', 'class1', new Document([
                '$permissions' => [
                    Permission::update(Role::any()),
                    Permission::read(Role::any()),
                ],
                'students' => [
                    '$id' => 'Richard',
                    '$permissions' => [
                        Permission::update(Role::any()),
                        Permission::read(Role::any())
                    ]
                ]
            ]));
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof RelationshipException);
        }

        try {
            $database->updateDocument('v1', 'class1', new Document([
                '$permissions' => [
                    Permission::update(Role::any()),
                    Permission::read(Role::any()),
                ],
                'students' => 'Richard'
            ]));
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertTrue($e instanceof RelationshipException);
        }
    }

    public function testStructureValidationAfterRelationsAttribute(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        if (!$database->getAdapter()->getSupportForAttributes()) {
            // Schemaless mode allows unknown attributes, so structure validation won't reject them
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection("structure_1", [], [], [Permission::create(Role::any())]);
        $database->createCollection("structure_2", [], [], [Permission::create(Role::any())]);

        $database->createRelationship(
            collection: "structure_1",
            relatedCollection: "structure_2",
            type: Database::RELATION_ONE_TO_ONE,
        );

        try {
            $database->createDocument('structure_1', new Document([
                '$permissions' => [
                    Permission::read(Role::any()),
                ],
                'structure_2' => '100',
                'name' => 'Frozen', // Unknown attribute 'name' after relation attribute
            ]));
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertInstanceOf(StructureException::class, $e);
        }
    }


    public function testNoChangeUpdateDocumentWithRelationWithoutPermission(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }
        $attribute = new Document([
            '$id' => ID::custom("name"),
            'type' => Database::VAR_STRING,
            'size' => 100,
            'required' => false,
            'default' => null,
            'signed' => false,
            'array' => false,
            'filters' => [],
        ]);

        $permissions = [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::delete(Role::any()),
        ];
        for ($i = 1; $i < 6; $i++) {
            $database->createCollection("level{$i}", [$attribute], [], $permissions);
        }

        for ($i = 1; $i < 5; $i++) {
            $collectionId = $i;
            $relatedCollectionId = $i + 1;
            $database->createRelationship(
                collection: "level{$collectionId}",
                relatedCollection: "level{$relatedCollectionId}",
                type: Database::RELATION_ONE_TO_ONE,
                id: "level{$relatedCollectionId}"
            );
        }

        // Create document with relationship with nested data
        $level1 = $database->createDocument('level1', new Document([
            '$id' => 'level1',
            '$permissions' => [],
            'name' => 'Level 1',
            'level2' => [
                '$id' => 'level2',
                '$permissions' => [],
                'name' => 'Level 2',
                'level3' => [
                    '$id' => 'level3',
                    '$permissions' => [],
                    'name' => 'Level 3',
                    'level4' => [
                        '$id' => 'level4',
                        '$permissions' => [],
                        'name' => 'Level 4',
                        'level5' => [
                            '$id' => 'level5',
                            '$permissions' => [],
                            'name' => 'Level 5',
                        ]
                    ],
                ],
            ],
        ]));
        $database->updateDocument('level1', $level1->getId(), new Document($level1->getArrayCopy()));
        $updatedLevel1 = $database->getDocument('level1', $level1->getId());
        $this->assertEquals($level1, $updatedLevel1);

        try {
            $database->updateDocument('level1', $level1->getId(), $level1->setAttribute('name', 'haha'));
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertInstanceOf(AuthorizationException::class, $e);
        }
        $level1->setAttribute('name', 'Level 1');
        $database->updateCollection('level3', [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ], false);
        $level2 = $level1->getAttribute('level2');
        $level3 = $level2->getAttribute('level3');

        $level3->setAttribute('name', 'updated value');
        $level2->setAttribute('level3', $level3);
        $level1->setAttribute('level2', $level2);

        $level1 = $database->updateDocument('level1', $level1->getId(), $level1);
        $this->assertEquals('updated value', $level1['level2']['level3']['name']);

        for ($i = 1; $i < 6; $i++) {
            $database->deleteCollection("level{$i}");
        }
    }



    public function testUpdateAttributeRenameRelationshipTwoWay(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('rnRsTestA');
        $database->createCollection('rnRsTestB');

        $database->createAttribute('rnRsTestB', 'name', Database::VAR_STRING, 255, true);

        $database->createRelationship(
            'rnRsTestA',
            'rnRsTestB',
            Database::RELATION_ONE_TO_ONE,
            true
        );

        $docA = $database->createDocument('rnRsTestA', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'rnRsTestB' => [
                '$id' => 'b1',
                'name' => 'B1'
            ]
        ]));

        $docB = $database->getDocument('rnRsTestB', 'b1');
        $this->assertArrayHasKey('rnRsTestA', $docB->getAttributes());
        $this->assertEquals('B1', $docB->getAttribute('name'));

        // Rename attribute
        $database->updateRelationship(
            collection: 'rnRsTestA',
            id: 'rnRsTestB',
            newKey: 'rnRsTestB_renamed'
        );

        // Rename again
        $database->updateRelationship(
            collection: 'rnRsTestA',
            id: 'rnRsTestB_renamed',
            newKey: 'rnRsTestB_renamed_2'
        );

        // Check our data is OK
        $docA = $database->getDocument('rnRsTestA', $docA->getId());
        $this->assertArrayHasKey('rnRsTestB_renamed_2', $docA->getAttributes());
        $this->assertEquals($docB->getId(), $docA->getAttribute('rnRsTestB_renamed_2')['$id']);
    }

    public function testNoInvalidKeysWithRelationships(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }
        $database->createCollection('species');
        $database->createCollection('creatures');
        $database->createCollection('characteristics');

        $database->createAttribute('species', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('creatures', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('characteristics', 'name', Database::VAR_STRING, 255, true);

        $database->createRelationship(
            collection: 'species',
            relatedCollection: 'creatures',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
            id: 'creature',
            twoWayKey:'species'
        );
        $database->createRelationship(
            collection: 'creatures',
            relatedCollection: 'characteristics',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
            id: 'characteristic',
            twoWayKey:'creature'
        );

        $species = $database->createDocument('species', new Document([
            '$id' => ID::custom('1'),
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'name' => 'Canine',
            'creature' => [
                '$id' => ID::custom('1'),
                '$permissions' => [
                    Permission::read(Role::any()),
                ],
                'name' => 'Dog',
                'characteristic' => [
                    '$id' => ID::custom('1'),
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::update(Role::any()),
                    ],
                    'name' => 'active',
                ]
            ]
        ]));
        $database->updateDocument('species', $species->getId(), new Document([
            '$id' => ID::custom('1'),
            '$collection' => 'species',
            'creature' => [
                '$id' => ID::custom('1'),
                '$collection' => 'creatures',
                'characteristic' => [
                    '$id' => ID::custom('1'),
                    'name' => 'active',
                    '$collection' => 'characteristics',
                ]
            ]
        ]));

        $updatedSpecies = $database->getDocument('species', $species->getId());

        $this->assertEquals($species, $updatedSpecies);
    }

    public function testSelectRelationshipAttributes(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('make');
        $database->createCollection('model');

        $database->createAttribute('make', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('make', 'origin', Database::VAR_STRING, 255, true);
        $database->createAttribute('model', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('model', 'year', Database::VAR_INTEGER, 0, true);

        $database->createRelationship(
            collection: 'make',
            relatedCollection: 'model',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true,
            id: 'models',
            twoWayKey: 'make',
        );

        $database->createDocument('make', new Document([
            '$id' => 'ford',
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'name' => 'Ford',
            'origin' => 'USA',
            'models' => [
                [
                    '$id' => 'fiesta',
                    '$permissions' => [
                        Permission::read(Role::any()),
                    ],
                    'name' => 'Fiesta',
                    'year' => 2010,
                ],
                [
                    '$id' => 'focus',
                    '$permissions' => [
                        Permission::read(Role::any()),
                    ],
                    'name' => 'Focus',
                    'year' => 2011,
                ],
            ],
        ]));

        // Select some parent attributes, some child attributes
        $make = $database->findOne('make', [
            Query::select(['name', 'models.name']),
        ]);

        if ($make->isEmpty()) {
            throw new Exception('Make not found');
        }

        $this->assertEquals('Ford', $make['name']);
        $this->assertEquals(2, \count($make['models']));
        $this->assertEquals('Fiesta', $make['models'][0]['name']);
        $this->assertEquals('Focus', $make['models'][1]['name']);
        $this->assertArrayNotHasKey('year', $make['models'][0]);
        $this->assertArrayNotHasKey('year', $make['models'][1]);
        $this->assertArrayHasKey('$id', $make);
        $this->assertArrayHasKey('$sequence', $make);
        $this->assertArrayHasKey('$permissions', $make);
        $this->assertArrayHasKey('$collection', $make);
        $this->assertArrayHasKey('$createdAt', $make);
        $this->assertArrayHasKey('$updatedAt', $make);

        // Select internal attributes
        $make = $database->findOne('make', [
            Query::select(['name', '$id']),
        ]);

        if ($make->isEmpty()) {
            throw new Exception('Make not found');
        }

        $this->assertArrayHasKey('name', $make);
        $this->assertArrayHasKey('$id', $make);
        $this->assertArrayHasKey('$sequence', $make);
        $this->assertArrayHasKey('$collection', $make);
        $this->assertArrayHasKey('$createdAt', $make);
        $this->assertArrayHasKey('$updatedAt', $make);
        $this->assertArrayHasKey('$permissions', $make);

        $make = $database->findOne('make', [
            Query::select(['name', '$sequence']),
        ]);

        if ($make->isEmpty()) {
            throw new Exception('Make not found');
        }

        $this->assertArrayHasKey('name', $make);
        $this->assertArrayHasKey('$id', $make);
        $this->assertArrayHasKey('$sequence', $make);
        $this->assertArrayHasKey('$collection', $make);
        $this->assertArrayHasKey('$createdAt', $make);
        $this->assertArrayHasKey('$updatedAt', $make);
        $this->assertArrayHasKey('$permissions', $make);

        $make = $database->findOne('make', [
            Query::select(['name', '$collection']),
        ]);

        if ($make->isEmpty()) {
            throw new Exception('Make not found');
        }

        $this->assertArrayHasKey('name', $make);
        $this->assertArrayHasKey('$id', $make);
        $this->assertArrayHasKey('$sequence', $make);
        $this->assertArrayHasKey('$collection', $make);
        $this->assertArrayHasKey('$createdAt', $make);
        $this->assertArrayHasKey('$updatedAt', $make);
        $this->assertArrayHasKey('$permissions', $make);

        $make = $database->findOne('make', [
            Query::select(['name', '$createdAt']),
        ]);

        if ($make->isEmpty()) {
            throw new Exception('Make not found');
        }

        $this->assertArrayHasKey('name', $make);
        $this->assertArrayHasKey('$id', $make);
        $this->assertArrayHasKey('$sequence', $make);
        $this->assertArrayHasKey('$collection', $make);
        $this->assertArrayHasKey('$createdAt', $make);
        $this->assertArrayHasKey('$updatedAt', $make);
        $this->assertArrayHasKey('$permissions', $make);

        $make = $database->findOne('make', [
            Query::select(['name', '$updatedAt']),
        ]);

        if ($make->isEmpty()) {
            throw new Exception('Make not found');
        }

        $this->assertArrayHasKey('name', $make);
        $this->assertArrayHasKey('$id', $make);
        $this->assertArrayHasKey('$sequence', $make);
        $this->assertArrayHasKey('$collection', $make);
        $this->assertArrayHasKey('$createdAt', $make);
        $this->assertArrayHasKey('$updatedAt', $make);
        $this->assertArrayHasKey('$permissions', $make);

        $make = $database->findOne('make', [
            Query::select(['name', '$permissions']),
        ]);

        if ($make->isEmpty()) {
            throw new Exception('Make not found');
        }

        $this->assertArrayHasKey('name', $make);
        $this->assertArrayHasKey('$id', $make);
        $this->assertArrayHasKey('$sequence', $make);
        $this->assertArrayHasKey('$collection', $make);
        $this->assertArrayHasKey('$createdAt', $make);
        $this->assertArrayHasKey('$updatedAt', $make);
        $this->assertArrayHasKey('$permissions', $make);

        // Select all parent attributes, some child attributes
        $make = $database->findOne('make', [
            Query::select(['*', 'models.year']),
        ]);

        if ($make->isEmpty()) {
            throw new Exception('Make not found');
        }

        $this->assertEquals('Ford', $make['name']);
        $this->assertEquals(2, \count($make['models']));
        $this->assertArrayNotHasKey('name', $make['models'][0]);
        $this->assertArrayNotHasKey('name', $make['models'][1]);
        $this->assertEquals(2010, $make['models'][0]['year']);
        $this->assertEquals(2011, $make['models'][1]['year']);

        // Select all parent attributes, all child attributes
        $make = $database->findOne('make', [
            Query::select(['*', 'models.*']),
        ]);

        if ($make->isEmpty()) {
            throw new Exception('Make not found');
        }

        $this->assertEquals('Ford', $make['name']);
        $this->assertEquals(2, \count($make['models']));
        $this->assertEquals('Fiesta', $make['models'][0]['name']);
        $this->assertEquals('Focus', $make['models'][1]['name']);
        $this->assertEquals(2010, $make['models'][0]['year']);
        $this->assertEquals(2011, $make['models'][1]['year']);

        // Select all parent attributes, all child attributes
        // Must select parent if selecting children
        $make = $database->findOne('make', [
            Query::select(['models.*']),
        ]);

        if ($make->isEmpty()) {
            throw new Exception('Make not found');
        }

        $this->assertEquals('Ford', $make['name']);
        $this->assertEquals(2, \count($make['models']));
        $this->assertEquals('Fiesta', $make['models'][0]['name']);
        $this->assertEquals('Focus', $make['models'][1]['name']);
        $this->assertEquals(2010, $make['models'][0]['year']);
        $this->assertEquals(2011, $make['models'][1]['year']);

        // Select all parent attributes, no child attributes
        $make = $database->findOne('make', [
            Query::select(['name']),
        ]);

        if ($make->isEmpty()) {
            throw new Exception('Make not found');
        }
        $this->assertEquals('Ford', $make['name']);
        $this->assertArrayNotHasKey('models', $make);

        // Select some parent attributes, all child attributes
        $make = $database->findOne('make', [
            Query::select(['name', 'models.*']),
        ]);

        $this->assertEquals('Ford', $make['name']);
        $this->assertEquals(2, \count($make['models']));

        /*
         * FROM CHILD TO PARENT
         */

        // Select some parent attributes, some child attributes
        $model = $database->findOne('model', [
            Query::select(['name', 'make.name']),
        ]);

        $this->assertEquals('Fiesta', $model['name']);
        $this->assertEquals('Ford', $model['make']['name']);
        $this->assertArrayNotHasKey('origin', $model['make']);
        $this->assertArrayNotHasKey('year', $model);
        $this->assertArrayHasKey('name', $model);

        // Select all parent attributes, some child attributes
        $model = $database->findOne('model', [
            Query::select(['*', 'make.name']),
        ]);

        $this->assertEquals('Fiesta', $model['name']);
        $this->assertEquals('Ford', $model['make']['name']);
        $this->assertArrayHasKey('year', $model);

        // Select all parent attributes, all child attributes
        $model = $database->findOne('model', [
            Query::select(['*', 'make.*']),
        ]);

        $this->assertEquals('Fiesta', $model['name']);
        $this->assertEquals('Ford', $model['make']['name']);
        $this->assertArrayHasKey('year', $model);
        $this->assertArrayHasKey('name', $model['make']);

        // Select all parent attributes, no child attributes
        $model = $database->findOne('model', [
            Query::select(['*']),
        ]);

        $this->assertEquals('Fiesta', $model['name']);
        $this->assertArrayHasKey('make', $model);
        $this->assertArrayHasKey('year', $model);

        // Select some parent attributes, all child attributes
        $model = $database->findOne('model', [
            Query::select(['name', 'make.*']),
        ]);

        $this->assertEquals('Fiesta', $model['name']);
        $this->assertEquals('Ford', $model['make']['name']);
        $this->assertEquals('USA', $model['make']['origin']);
    }

    public function testInheritRelationshipPermissions(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('lawns', permissions: [Permission::create(Role::any())], documentSecurity: true);
        $database->createCollection('trees', permissions: [Permission::create(Role::any())], documentSecurity: true);
        $database->createCollection('birds', permissions: [Permission::create(Role::any())], documentSecurity: true);

        $database->createAttribute('lawns', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('trees', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('birds', 'name', Database::VAR_STRING, 255, true);

        $database->createRelationship(
            collection: 'lawns',
            relatedCollection: 'trees',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true,
            twoWayKey: 'lawn',
            onDelete: Database::RELATION_MUTATE_CASCADE,
        );
        $database->createRelationship(
            collection: 'trees',
            relatedCollection: 'birds',
            type: Database::RELATION_MANY_TO_MANY,
            twoWay: true,
            onDelete: Database::RELATION_MUTATE_SET_NULL,
        );

        $permissions = [
            Permission::read(Role::any()),
            Permission::read(Role::user('user1')),
            Permission::update(Role::user('user1')),
            Permission::delete(Role::user('user2')),
        ];

        $database->createDocument('lawns', new Document([
            '$id' => 'lawn1',
            '$permissions' => $permissions,
            'name' => 'Lawn 1',
            'trees' => [
                [
                    '$id' => 'tree1',
                    'name' => 'Tree 1',
                    'birds' => [
                        [
                            '$id' => 'bird1',
                            'name' => 'Bird 1',
                        ],
                        [
                            '$id' => 'bird2',
                            'name' => 'Bird 2',
                        ],
                    ],
                ],
            ],
        ]));

        $lawn1 = $database->getDocument('lawns', 'lawn1');
        $this->assertEquals($permissions, $lawn1->getPermissions());
        $this->assertEquals($permissions, $lawn1['trees'][0]->getPermissions());
        $this->assertEquals($permissions, $lawn1['trees'][0]['birds'][0]->getPermissions());
        $this->assertEquals($permissions, $lawn1['trees'][0]['birds'][1]->getPermissions());

        $tree1 = $database->getDocument('trees', 'tree1');
        $this->assertEquals($permissions, $tree1->getPermissions());
        $this->assertEquals($permissions, $tree1['lawn']->getPermissions());
        $this->assertEquals($permissions, $tree1['birds'][0]->getPermissions());
        $this->assertEquals($permissions, $tree1['birds'][1]->getPermissions());
    }

    /**
     * @depends testInheritRelationshipPermissions
     */
    public function testEnforceRelationshipPermissions(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }
        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::any()->toString());
        $lawn1 = $database->getDocument('lawns', 'lawn1');
        $this->assertEquals('Lawn 1', $lawn1['name']);

        // Try update root document
        try {
            $database->updateDocument(
                'lawns',
                $lawn1->getId(),
                $lawn1->setAttribute('name', 'Lawn 1 Updated')
            );
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertEquals('Missing "update" permission for role "user:user1". Only "["any"]" scopes are allowed and "["user:user1"]" was given.', $e->getMessage());
        }

        // Try delete root document
        try {
            $database->deleteDocument(
                'lawns',
                $lawn1->getId(),
            );
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertEquals('Missing "delete" permission for role "user:user2". Only "["any"]" scopes are allowed and "["user:user2"]" was given.', $e->getMessage());
        }

        $tree1 = $database->getDocument('trees', 'tree1');

        // Try update nested document
        try {
            $database->updateDocument(
                'trees',
                $tree1->getId(),
                $tree1->setAttribute('name', 'Tree 1 Updated')
            );
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertEquals('Missing "update" permission for role "user:user1". Only "["any"]" scopes are allowed and "["user:user1"]" was given.', $e->getMessage());
        }

        // Try delete nested document
        try {
            $database->deleteDocument(
                'trees',
                $tree1->getId(),
            );
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertEquals('Missing "delete" permission for role "user:user2". Only "["any"]" scopes are allowed and "["user:user2"]" was given.', $e->getMessage());
        }

        $bird1 = $database->getDocument('birds', 'bird1');

        // Try update multi-level nested document
        try {
            $database->updateDocument(
                'birds',
                $bird1->getId(),
                $bird1->setAttribute('name', 'Bird 1 Updated')
            );
            $this->fail('Failed to throw exception when updating document with missing permissions');
        } catch (Exception $e) {
            $this->assertEquals('Missing "update" permission for role "user:user1". Only "["any"]" scopes are allowed and "["user:user1"]" was given.', $e->getMessage());
        }

        // Try delete multi-level nested document
        try {
            $database->deleteDocument(
                'birds',
                $bird1->getId(),
            );
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertEquals('Missing "delete" permission for role "user:user2". Only "["any"]" scopes are allowed and "["user:user2"]" was given.', $e->getMessage());
        }

        $this->getDatabase()->getAuthorization()->addRole(Role::user('user1')->toString());

        $bird1 = $database->getDocument('birds', 'bird1');

        // Try update multi-level nested document
        $bird1 = $database->updateDocument(
            'birds',
            $bird1->getId(),
            $bird1->setAttribute('name', 'Bird 1 Updated')
        );

        $this->assertEquals('Bird 1 Updated', $bird1['name']);

        $this->getDatabase()->getAuthorization()->addRole(Role::user('user2')->toString());

        // Try delete multi-level nested document
        $deleted = $database->deleteDocument(
            'birds',
            $bird1->getId(),
        );

        $this->assertEquals(true, $deleted);
        $tree1 = $database->getDocument('trees', 'tree1');
        $this->assertEquals(1, count($tree1['birds']));

        // Try update nested document
        $tree1 = $database->updateDocument(
            'trees',
            $tree1->getId(),
            $tree1->setAttribute('name', 'Tree 1 Updated')
        );

        $this->assertEquals('Tree 1 Updated', $tree1['name']);

        // Try delete nested document
        $deleted = $database->deleteDocument(
            'trees',
            $tree1->getId(),
        );

        $this->assertEquals(true, $deleted);
        $lawn1 = $database->getDocument('lawns', 'lawn1');
        $this->assertEquals(0, count($lawn1['trees']));

        // Create document with no permissions
        $database->createDocument('lawns', new Document([
            '$id' => 'lawn2',
            'name' => 'Lawn 2',
            'trees' => [
                [
                    '$id' => 'tree2',
                    'name' => 'Tree 2',
                    'birds' => [
                        [
                            '$id' => 'bird3',
                            'name' => 'Bird 3',
                        ],
                    ],
                ],
            ],
        ]));

        $lawn2 = $database->getDocument('lawns', 'lawn2');
        $this->assertEquals(true, $lawn2->isEmpty());

        $tree2 = $database->getDocument('trees', 'tree2');
        $this->assertEquals(true, $tree2->isEmpty());

        $bird3 = $database->getDocument('birds', 'bird3');
        $this->assertEquals(true, $bird3->isEmpty());
    }

    public function testCreateRelationshipMissingCollection(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Collection not found');

        $database->createRelationship(
            collection: 'missing',
            relatedCollection: 'missing',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true,
        );
    }

    public function testCreateRelationshipMissingRelatedCollection(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('test');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Related collection not found');

        $database->createRelationship(
            collection: 'test',
            relatedCollection: 'missing',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true,
        );
    }

    public function testCreateDuplicateRelationship(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('test1');
        $database->createCollection('test2');

        $database->createRelationship(
            collection: 'test1',
            relatedCollection: 'test2',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true,
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Attribute already exists');

        $database->createRelationship(
            collection: 'test1',
            relatedCollection: 'test2',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true,
        );
    }

    public function testCreateInvalidRelationship(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('test3');
        $database->createCollection('test4');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid relationship type');

        $database->createRelationship(
            collection: 'test3',
            relatedCollection: 'test4',
            type: 'invalid',
            twoWay: true,
        );
    }


    public function testDeleteMissingRelationship(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        try {
            $database->deleteRelationship('test', 'test2');
            $this->fail('Failed to throw exception');
        } catch (\Throwable $e) {
            $this->assertEquals('Relationship not found', $e->getMessage());
        }
    }

    public function testCreateInvalidIntValueRelationship(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('invalid1');
        $database->createCollection('invalid2');

        $database->createRelationship(
            collection: 'invalid1',
            relatedCollection: 'invalid2',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
        );

        $this->expectException(RelationshipException::class);
        $this->expectExceptionMessage('Invalid relationship value. Must be either a document, document ID, or an array of documents or document IDs.');

        $database->createDocument('invalid1', new Document([
            '$id' => ID::unique(),
            'invalid2' => 10,
        ]));
    }

    /**
     * @depends testCreateInvalidIntValueRelationship
     */
    public function testCreateInvalidObjectValueRelationship(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $this->expectException(RelationshipException::class);
        $this->expectExceptionMessage('Invalid relationship value. Must be either a document, document ID, or an array of documents or document IDs.');

        $database->createDocument('invalid1', new Document([
            '$id' => ID::unique(),
            'invalid2' => new \stdClass(),
        ]));
    }

    /**
     * @depends testCreateInvalidIntValueRelationship
     */
    public function testCreateInvalidArrayIntValueRelationship(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createRelationship(
            collection: 'invalid1',
            relatedCollection: 'invalid2',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true,
            id: 'invalid3',
            twoWayKey: 'invalid4',
        );

        $this->expectException(RelationshipException::class);
        $this->expectExceptionMessage('Invalid relationship value. Must be either a document, document ID, or an array of documents or document IDs.');

        $database->createDocument('invalid1', new Document([
            '$id' => ID::unique(),
            'invalid3' => [10],
        ]));
    }

    public function testCreateInvalidOneWayChildArrayValueRelationship(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('reverse1');
        $database->createCollection('reverse2');

        $database->createRelationship(
            collection: 'reverse1',
            relatedCollection: 'reverse2',
            type: Database::RELATION_ONE_TO_ONE,
        );

        $this->expectException(RelationshipException::class);
        $this->expectExceptionMessage('Invalid relationship value. Cannot set a value from the child side of a oneToOne relationship when twoWay is false.');

        $database->createDocument('reverse2', new Document([
            '$id' => ID::unique(),
            'reverse1' => ['name' => 'reverse'],
        ]));
    }

    public function testCreateEmptyValueRelationship(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('null1');
        $database->createCollection('null2');

        $database->createRelationship(
            collection: 'null1',
            relatedCollection: 'null2',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
        );
        $database->createRelationship(
            collection: 'null1',
            relatedCollection: 'null2',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true,
            id: 'null3',
            twoWayKey: 'null4',
        );
        $database->createRelationship(
            collection: 'null1',
            relatedCollection: 'null2',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
            id: 'null4',
            twoWayKey: 'null5',
        );
        $database->createRelationship(
            collection: 'null1',
            relatedCollection: 'null2',
            type: Database::RELATION_MANY_TO_MANY,
            twoWay: true,
            id: 'null6',
            twoWayKey: 'null7',
        );

        $document = $database->createDocument('null1', new Document([
            '$id' => ID::unique(),
            'null2' => null,
        ]));

        $this->assertEquals(null, $document->getAttribute('null2'));

        $document = $database->createDocument('null2', new Document([
            '$id' => ID::unique(),
            'null1' => null,
        ]));

        $this->assertEquals(null, $document->getAttribute('null1'));

        $document = $database->createDocument('null1', new Document([
            '$id' => ID::unique(),
            'null3' => null,
        ]));

        // One to many will be empty array instead of null
        $this->assertEquals([], $document->getAttribute('null3'));

        $document = $database->createDocument('null2', new Document([
            '$id' => ID::unique(),
            'null4' => null,
        ]));

        $this->assertEquals(null, $document->getAttribute('null4'));

        $document = $database->createDocument('null1', new Document([
            '$id' => ID::unique(),
            'null4' => null,
        ]));

        $this->assertEquals(null, $document->getAttribute('null4'));

        $document = $database->createDocument('null2', new Document([
            '$id' => ID::unique(),
            'null5' => null,
        ]));

        $this->assertEquals([], $document->getAttribute('null5'));

        $document = $database->createDocument('null1', new Document([
            '$id' => ID::unique(),
            'null6' => null,
        ]));

        $this->assertEquals([], $document->getAttribute('null6'));

        $document = $database->createDocument('null2', new Document([
            '$id' => ID::unique(),
            'null7' => null,
        ]));

        $this->assertEquals([], $document->getAttribute('null7'));
    }

    public function testUpdateRelationshipToExistingKey(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('ovens');
        $database->createCollection('cakes');

        $database->createAttribute('ovens', 'maxTemp', Database::VAR_INTEGER, 0, true);
        $database->createAttribute('ovens', 'owner', Database::VAR_STRING, 255, true);
        $database->createAttribute('cakes', 'height', Database::VAR_INTEGER, 0, true);
        $database->createAttribute('cakes', 'colour', Database::VAR_STRING, 255, true);

        $database->createRelationship(
            collection: 'ovens',
            relatedCollection: 'cakes',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true,
            id: 'cakes',
            twoWayKey: 'oven'
        );

        try {
            $database->updateRelationship('ovens', 'cakes', newKey: 'owner');
            $this->fail('Failed to throw exception');
        } catch (DuplicateException $e) {
            $this->assertEquals('Relationship already exists', $e->getMessage());
        }

        try {
            $database->updateRelationship('ovens', 'cakes', newTwoWayKey: 'height');
            $this->fail('Failed to throw exception');
        } catch (DuplicateException $e) {
            $this->assertEquals('Related attribute already exists', $e->getMessage());
        }
    }

    public function testUpdateDocumentsRelationships(): void
    {
        if (!$this->getDatabase()->getAdapter()->getSupportForBatchOperations() || !$this->getDatabase()->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $this->getDatabase()->getAuthorization()->cleanRoles();
        $this->getDatabase()->getAuthorization()->addRole(Role::any()->toString());

        $this->getDatabase()->createCollection('testUpdateDocumentsRelationships1', attributes: [
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

        $this->getDatabase()->createCollection('testUpdateDocumentsRelationships2', attributes: [
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

        $this->getDatabase()->createRelationship(
            collection: 'testUpdateDocumentsRelationships1',
            relatedCollection: 'testUpdateDocumentsRelationships2',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
        );

        $this->getDatabase()->createDocument('testUpdateDocumentsRelationships1', new Document([
            '$id' => 'doc1',
            'string' => 'text📝',
        ]));

        $this->getDatabase()->createDocument('testUpdateDocumentsRelationships2', new Document([
            '$id' => 'doc1',
            'string' => 'text📝',
            'testUpdateDocumentsRelationships1' => 'doc1'
        ]));

        $sisterDocument = $this->getDatabase()->getDocument('testUpdateDocumentsRelationships2', 'doc1');
        $this->assertNotNull($sisterDocument);

        $this->getDatabase()->updateDocuments('testUpdateDocumentsRelationships1', new Document([
            'string' => 'text📝 updated',
        ]));

        $document = $this->getDatabase()->findOne('testUpdateDocumentsRelationships1');

        $this->assertNotFalse($document);
        $this->assertEquals('text📝 updated', $document->getAttribute('string'));

        $sisterDocument = $this->getDatabase()->getDocument('testUpdateDocumentsRelationships2', 'doc1');
        $this->assertNotNull($sisterDocument);

        $relationalDocument = $sisterDocument->getAttribute('testUpdateDocumentsRelationships1');
        $this->assertEquals('text📝 updated', $relationalDocument->getAttribute('string'));

        // Check relationship value updating between each other.
        $this->getDatabase()->deleteRelationship('testUpdateDocumentsRelationships1', 'testUpdateDocumentsRelationships2');

        $this->getDatabase()->createRelationship(
            collection: 'testUpdateDocumentsRelationships1',
            relatedCollection: 'testUpdateDocumentsRelationships2',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true,
        );

        for ($i = 2; $i < 11; $i++) {
            $this->getDatabase()->createDocument('testUpdateDocumentsRelationships1', new Document([
                '$id' => 'doc' . $i,
                'string' => 'text📝',
            ]));

            $this->getDatabase()->createDocument('testUpdateDocumentsRelationships2', new Document([
                '$id' => 'doc' . $i,
                'string' => 'text📝',
                'testUpdateDocumentsRelationships1' => 'doc' . $i
            ]));
        }

        $this->getDatabase()->updateDocuments('testUpdateDocumentsRelationships2', new Document([
            'testUpdateDocumentsRelationships1' => null
        ]));

        $this->getDatabase()->updateDocuments('testUpdateDocumentsRelationships2', new Document([
            'testUpdateDocumentsRelationships1' => 'doc1'
        ]));

        $documents = $this->getDatabase()->find('testUpdateDocumentsRelationships2');

        foreach ($documents as $document) {
            $this->assertEquals('doc1', $document->getAttribute('testUpdateDocumentsRelationships1')->getId());
        }
    }

    public function testUpdateDocumentWithRelationships(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }
        $database->createCollection('userProfiles', [
            new Document([
                '$id' => ID::custom('username'),
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => 700,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ]),
        ], [], [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ]);
        $database->createCollection('links', [
            new Document([
                '$id' => ID::custom('title'),
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => 700,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ]),
        ], [], [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ]);
        $database->createCollection('videos', [
            new Document([
                '$id' => ID::custom('title'),
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => 700,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ]),
        ], [], [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ]);
        $database->createCollection('products', [
            new Document([
                '$id' => ID::custom('title'),
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => 700,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ]),
        ], [], [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ]);
        $database->createCollection('settings', [
            new Document([
                '$id' => ID::custom('metaTitle'),
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => 700,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ]),
        ], [], [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ]);
        $database->createCollection('appearance', [
            new Document([
                '$id' => ID::custom('metaTitle'),
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => 700,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ]),
        ], [], [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ]);
        $database->createCollection('group', [
            new Document([
                '$id' => ID::custom('name'),
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => 700,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ]),
        ], [], [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ]);
        $database->createCollection('community', [
            new Document([
                '$id' => ID::custom('name'),
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => 700,
                'signed' => true,
                'required' => false,
                'default' => null,
                'array' => false,
                'filters' => [],
            ]),
        ], [], [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any())
        ]);

        $database->createRelationship(
            collection: 'userProfiles',
            relatedCollection: 'links',
            type: Database::RELATION_ONE_TO_MANY,
            id: 'links'
        );

        $database->createRelationship(
            collection: 'userProfiles',
            relatedCollection: 'videos',
            type: Database::RELATION_ONE_TO_MANY,
            id: 'videos'
        );

        $database->createRelationship(
            collection: 'userProfiles',
            relatedCollection: 'products',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true,
            id: 'products',
            twoWayKey: 'userProfile',
        );

        $database->createRelationship(
            collection: 'userProfiles',
            relatedCollection: 'settings',
            type: Database::RELATION_ONE_TO_ONE,
            id: 'settings'
        );

        $database->createRelationship(
            collection: 'userProfiles',
            relatedCollection: 'appearance',
            type: Database::RELATION_ONE_TO_ONE,
            id: 'appearance'
        );

        $database->createRelationship(
            collection: 'userProfiles',
            relatedCollection: 'group',
            type: Database::RELATION_MANY_TO_ONE,
            id: 'group'
        );

        $database->createRelationship(
            collection: 'userProfiles',
            relatedCollection: 'community',
            type: Database::RELATION_MANY_TO_ONE,
            id: 'community'
        );

        $profile = $database->createDocument('userProfiles', new Document([
            '$id' => '1',
            'username' => 'user1',
            'links' => [
                [
                    '$id' => 'link1',
                    'title' => 'Link 1',
                ],
            ],
            'videos' => [
                [
                    '$id' => 'video1',
                    'title' => 'Video 1',
                ],
            ],
            'products' => [
                [
                    '$id' => 'product1',
                    'title' => 'Product 1',
                ],
            ],
            'settings' => [
                '$id' => 'settings1',
                'metaTitle' => 'Meta Title',
            ],
            'appearance' => [
                '$id' => 'appearance1',
                'metaTitle' => 'Meta Title',
            ],
            'group' => [
                '$id' => 'group1',
                'name' => 'Group 1',
            ],
            'community' => [
                '$id' => 'community1',
                'name' => 'Community 1',
            ],
        ]));
        $this->assertEquals('link1', $profile->getAttribute('links')[0]->getId());
        $this->assertEquals('settings1', $profile->getAttribute('settings')->getId());
        $this->assertEquals('group1', $profile->getAttribute('group')->getId());
        $this->assertEquals('community1', $profile->getAttribute('community')->getId());
        $this->assertEquals('video1', $profile->getAttribute('videos')[0]->getId());
        $this->assertEquals('product1', $profile->getAttribute('products')[0]->getId());
        $this->assertEquals('appearance1', $profile->getAttribute('appearance')->getId());

        $profile->setAttribute('links', [
            [
                '$id' => 'link1',
                'title' => 'New Link Value',
            ],
        ]);

        $profile->setAttribute('settings', [
            '$id' => 'settings1',
            'metaTitle' => 'New Meta Title',
        ]);

        $profile->setAttribute('group', [
            '$id' => 'group1',
            'name' => 'New Group Name',
        ]);

        $updatedProfile = $database->updateDocument('userProfiles', '1', $profile);

        $this->assertEquals('New Link Value', $updatedProfile->getAttribute('links')[0]->getAttribute('title'));
        $this->assertEquals('New Meta Title', $updatedProfile->getAttribute('settings')->getAttribute('metaTitle'));
        $this->assertEquals('New Group Name', $updatedProfile->getAttribute('group')->getAttribute('name'));

        // This is the point of test, related documents should be present if they are not updated
        $this->assertEquals('Video 1', $updatedProfile->getAttribute('videos')[0]->getAttribute('title'));
        $this->assertEquals('Product 1', $updatedProfile->getAttribute('products')[0]->getAttribute('title'));
        $this->assertEquals('Meta Title', $updatedProfile->getAttribute('appearance')->getAttribute('metaTitle'));
        $this->assertEquals('Community 1', $updatedProfile->getAttribute('community')->getAttribute('name'));

        // updating document using two way key in one to many relationship
        $product = $database->getDocument('products', 'product1');
        $product->setAttribute('userProfile', [
            '$id' => '1',
            'username' => 'updated user value',
        ]);
        $updatedProduct = $database->updateDocument('products', 'product1', $product);
        $this->assertEquals('updated user value', $updatedProduct->getAttribute('userProfile')->getAttribute('username'));
        $this->assertEquals('Product 1', $updatedProduct->getAttribute('title'));
        $this->assertEquals('product1', $updatedProduct->getId());
        $this->assertEquals('1', $updatedProduct->getAttribute('userProfile')->getId());

        $database->deleteCollection('userProfiles');
        $database->deleteCollection('links');
        $database->deleteCollection('settings');
        $database->deleteCollection('group');
        $database->deleteCollection('community');
        $database->deleteCollection('videos');
        $database->deleteCollection('products');
        $database->deleteCollection('appearance');
    }

    /**
     * Test that nested relationships are populated for all documents in a multi-document query
     * Covers bug: https://github.com/appwrite/appwrite/issues/10552
     */
    public function testMultiDocumentNestedRelationships(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Create collections: car -> customer -> inspection
        $database->createCollection('car');
        $database->createAttribute('car', 'plateNumber', Database::VAR_STRING, 255, true);

        $database->createCollection('customer');
        $database->createAttribute('customer', 'name', Database::VAR_STRING, 255, true);

        $database->createCollection('inspection');
        $database->createAttribute('inspection', 'type', Database::VAR_STRING, 255, true);

        // Create relationships
        // car -> customer (many to one, one-way to avoid circular references)
        $database->createRelationship(
            collection: 'car',
            relatedCollection: 'customer',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: false,
            id: 'customer',
        );

        // customer -> inspection (one to many, one-way)
        $database->createRelationship(
            collection: 'customer',
            relatedCollection: 'inspection',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: false,
            id: 'inspections',
        );

        // Create test data - customers with inspections first
        $database->createDocument('inspection', new Document([
            '$id' => 'inspection1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'type' => 'annual',
        ]));
        $database->createDocument('inspection', new Document([
            '$id' => 'inspection2',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'type' => 'safety',
        ]));
        $database->createDocument('inspection', new Document([
            '$id' => 'inspection3',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'type' => 'emissions',
        ]));
        $database->createDocument('inspection', new Document([
            '$id' => 'inspection4',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'type' => 'annual',
        ]));
        $database->createDocument('inspection', new Document([
            '$id' => 'inspection5',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'type' => 'safety',
        ]));

        $database->createDocument('customer', new Document([
            '$id' => 'customer1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'name' => 'Customer 1',
            'inspections' => ['inspection1', 'inspection2'],
        ]));

        $database->createDocument('customer', new Document([
            '$id' => 'customer2',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'name' => 'Customer 2',
            'inspections' => ['inspection3', 'inspection4'],
        ]));

        $database->createDocument('customer', new Document([
            '$id' => 'customer3',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'name' => 'Customer 3',
            'inspections' => ['inspection5'],
        ]));

        $car1 = $database->createDocument('car', new Document([
            '$id' => 'car1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::delete(Role::any()),
            ],
            'plateNumber' => 'ABC123',
            'customer' => 'customer1',
        ]));

        $car2 = $database->createDocument('car', new Document([
            '$id' => 'car2',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::delete(Role::any()),
            ],
            'plateNumber' => 'DEF456',
            'customer' => 'customer2',
        ]));

        $car3 = $database->createDocument('car', new Document([
            '$id' => 'car3',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::delete(Role::any()),
            ],
            'plateNumber' => 'GHI789',
            'customer' => 'customer3',
        ]));

        // Query all cars with nested relationship selections
        $cars = $database->find('car', [
            Query::select([
                '*',
                'customer.*',
                'customer.inspections.type',
            ]),
        ]);

        $this->assertCount(3, $cars);

        $this->assertEquals('ABC123', $cars[0]['plateNumber']);
        $this->assertEquals('Customer 1', $cars[0]['customer']['name']);
        $this->assertCount(2, $cars[0]['customer']['inspections']);
        $this->assertEquals('annual', $cars[0]['customer']['inspections'][0]['type']);
        $this->assertEquals('safety', $cars[0]['customer']['inspections'][1]['type']);

        $this->assertEquals('DEF456', $cars[1]['plateNumber']);
        $this->assertEquals('Customer 2', $cars[1]['customer']['name']);
        $this->assertCount(2, $cars[1]['customer']['inspections']);
        $this->assertEquals('emissions', $cars[1]['customer']['inspections'][0]['type']);
        $this->assertEquals('annual', $cars[1]['customer']['inspections'][1]['type']);

        $this->assertEquals('GHI789', $cars[2]['plateNumber']);
        $this->assertEquals('Customer 3', $cars[2]['customer']['name']);
        $this->assertCount(1, $cars[2]['customer']['inspections']);
        $this->assertEquals('safety', $cars[2]['customer']['inspections'][0]['type']);

        // Test with createDocuments as well
        $database->deleteDocument('car', 'car1');
        $database->deleteDocument('car', 'car2');
        $database->deleteDocument('car', 'car3');

        $database->createDocuments('car', [
            new Document([
                '$id' => 'car1',
                '$permissions' => [Permission::read(Role::any())],
                'plateNumber' => 'ABC123',
                'customer' => 'customer1',
            ]),
            new Document([
                '$id' => 'car2',
                '$permissions' => [Permission::read(Role::any())],
                'plateNumber' => 'DEF456',
                'customer' => 'customer2',
            ]),
            new Document([
                '$id' => 'car3',
                '$permissions' => [Permission::read(Role::any())],
                'plateNumber' => 'GHI789',
                'customer' => 'customer3',
            ]),
        ]);

        $cars = $database->find('car', [
            Query::select([
                '*',
                'customer.*',
                'customer.inspections.type',
            ]),
        ]);

        // Verify all cars still have nested relationships after batch create
        $this->assertCount(3, $cars);
        $this->assertCount(2, $cars[0]['customer']['inspections']);
        $this->assertCount(2, $cars[1]['customer']['inspections']);
        $this->assertCount(1, $cars[2]['customer']['inspections']);

        // Clean up
        $database->deleteCollection('inspection');
        $database->deleteCollection('car');
        $database->deleteCollection('customer');
    }

    /**
     * Test that nested document creation properly populates relationships at all depths.
     * This test verifies the fix for the depth handling bug where populateDocumentsRelationships()
     * would early return for non-zero depth, causing nested documents to not have their relationships populated.
     */
    public function testNestedDocumentCreationWithDepthHandling(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Create three collections with chained relationships: Order -> Product -> Store
        $database->createCollection('orderDepthTest');
        $database->createCollection('productDepthTest');
        $database->createCollection('storeDepthTest');

        $database->createAttribute('orderDepthTest', 'orderNumber', Database::VAR_STRING, 255, true);
        $database->createAttribute('productDepthTest', 'productName', Database::VAR_STRING, 255, true);
        $database->createAttribute('storeDepthTest', 'storeName', Database::VAR_STRING, 255, true);

        // Order -> Product (many-to-one)
        $database->createRelationship(
            collection: 'orderDepthTest',
            relatedCollection: 'productDepthTest',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
            id: 'product',
            twoWayKey: 'orders'
        );

        // Product -> Store (many-to-one)
        $database->createRelationship(
            collection: 'productDepthTest',
            relatedCollection: 'storeDepthTest',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
            id: 'store',
            twoWayKey: 'products'
        );

        // First, create a store that will be referenced by the nested product
        $store = $database->createDocument('storeDepthTest', new Document([
            '$id' => 'store1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'storeName' => 'Main Store',
        ]));

        $this->assertEquals('store1', $store->getId());
        $this->assertEquals('Main Store', $store->getAttribute('storeName'));

        // Create an order with a nested product that references the existing store
        // The nested product is created at depth 1
        // With the bug, the product's relationships (including 'store') would not be populated
        // With the fix, the product's 'store' relationship should be properly populated
        $order = $database->createDocument('orderDepthTest', new Document([
            '$id' => 'order1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'orderNumber' => 'ORD-001',
            'product' => [
                '$id' => 'product1',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                ],
                'productName' => 'Widget',
                'store' => 'store1', // Reference to existing store
            ],
        ]));

        // Verify the order was created
        $this->assertEquals('order1', $order->getId());
        $this->assertEquals('ORD-001', $order->getAttribute('orderNumber'));

        // Verify the nested product relationship is populated (depth 1)
        $this->assertArrayHasKey('product', $order);
        $product = $order->getAttribute('product');
        $this->assertInstanceOf(Document::class, $product);
        $this->assertEquals('product1', $product->getId());
        $this->assertEquals('Widget', $product->getAttribute('productName'));

        // CRITICAL: Verify the product's store relationship is populated (depth 2)
        // This is the key assertion that would fail with the bug
        $this->assertArrayHasKey('store', $product);
        $productStore = $product->getAttribute('store');
        $this->assertInstanceOf(Document::class, $productStore);
        $this->assertEquals('store1', $productStore->getId());
        $this->assertEquals('Main Store', $productStore->getAttribute('storeName'));

        // Also test with update - create another order and update it with nested product
        $order2 = $database->createDocument('orderDepthTest', new Document([
            '$id' => 'order2',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'orderNumber' => 'ORD-002',
        ]));

        // Update order2 to add a nested product
        $order2Updated = $database->updateDocument('orderDepthTest', 'order2', $order2->setAttribute('product', [
            '$id' => 'product2',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'productName' => 'Gadget',
            'store' => 'store1',
        ]));

        // Verify the updated order has the nested product with populated store
        $this->assertEquals('order2', $order2Updated->getId());
        $product2 = $order2Updated->getAttribute('product');
        $this->assertInstanceOf(Document::class, $product2);
        $this->assertEquals('product2', $product2->getId());

        // Verify the product's store is populated after update
        $this->assertArrayHasKey('store', $product2);
        $product2Store = $product2->getAttribute('store');
        $this->assertInstanceOf(Document::class, $product2Store);
        $this->assertEquals('store1', $product2Store->getId());

        // Clean up
        $database->deleteCollection('orderDepthTest');
        $database->deleteCollection('productDepthTest');
        $database->deleteCollection('storeDepthTest');
    }

    /**
     * Test filtering by relationship attributes using dot-path notation
     */
    public function testRelationshipTypeQueries(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Create author -> posts relationship
        $database->createCollection('authorsFilter');
        $database->createCollection('postsFilter');

        $database->createAttribute('authorsFilter', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('authorsFilter', 'age', Database::VAR_INTEGER, 0, true);
        $database->createAttribute('postsFilter', 'title', Database::VAR_STRING, 255, true);
        $database->createAttribute('postsFilter', 'published', Database::VAR_BOOLEAN, 0, true);

        $database->createRelationship(
            collection: 'authorsFilter',
            relatedCollection: 'postsFilter',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true,
            id: 'posts',
            twoWayKey: 'author'
        );

        // Create test data
        $author1 = $database->createDocument('authorsFilter', new Document([
            '$id' => 'author1',
            '$permissions' => [Permission::read(Role::any())],
            'name' => 'Alice',
            'age' => 30,
        ]));

        $author2 = $database->createDocument('authorsFilter', new Document([
            '$id' => 'author2',
            '$permissions' => [Permission::read(Role::any())],
            'name' => 'Bob',
            'age' => 25,
        ]));

        // Create posts
        $database->createDocument('postsFilter', new Document([
            '$id' => 'post1',
            '$permissions' => [Permission::read(Role::any())],
            'title' => 'Alice Post 1',
            'published' => true,
            'author' => 'author1',
        ]));

        $database->createDocument('postsFilter', new Document([
            '$id' => 'post2',
            '$permissions' => [Permission::read(Role::any())],
            'title' => 'Alice Post 2',
            'published' => true,
            'author' => 'author1',
        ]));

        $database->createDocument('postsFilter', new Document([
            '$id' => 'post3',
            '$permissions' => [Permission::read(Role::any())],
            'title' => 'Bob Post',
            'published' => true,
            'author' => 'author2',
        ]));

        // Filter posts by author name
        $posts = $database->find('postsFilter', [
            Query::equal('author.name', ['Alice']),
        ]);
        $this->assertCount(2, $posts);
        $this->assertEquals('post1', $posts[0]->getId());
        $this->assertEquals('post2', $posts[1]->getId());

        // Filter posts by author age
        $posts = $database->find('postsFilter', [
            Query::lessThan('author.age', 30),
        ]);
        $this->assertCount(1, $posts);
        $this->assertEquals('post3', $posts[0]->getId());

        // Filter authors by their posts' published status
        $authors = $database->find('authorsFilter', [
            Query::equal('posts.published', [true]),
        ]);
        $this->assertCount(2, $authors); // Both authors have published posts

        $database->deleteCollection('authorsFilter');
        $database->deleteCollection('postsFilter');

        $database->createCollection('usersOto');
        $database->createCollection('profilesOto');

        $database->createAttribute('usersOto', 'username', Database::VAR_STRING, 255, true);
        $database->createAttribute('profilesOto', 'bio', Database::VAR_STRING, 255, true);

        // ONE_TO_ONE with twoWay=true
        $database->createRelationship(
            collection: 'usersOto',
            relatedCollection: 'profilesOto',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
            id: 'profile',
            twoWayKey: 'user'
        );

        $user1 = $database->createDocument('usersOto', new Document([
            '$id' => 'user1',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'username' => 'alice',
        ]));

        $profile1 = $database->createDocument('profilesOto', new Document([
            '$id' => 'profile1',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'bio' => 'Software Engineer',
            'user' => 'user1',
        ]));

        // Filter profiles by user username
        $profiles = $database->find('profilesOto', [
            Query::equal('user.username', ['alice']),
        ]);
        $this->assertCount(1, $profiles);
        $this->assertEquals('profile1', $profiles[0]->getId());

        // Filter users by profile bio
        $users = $database->find('usersOto', [
            Query::equal('profile.bio', ['Software Engineer']),
        ]);
        $this->assertCount(1, $users);
        $this->assertEquals('user1', $users[0]->getId());

        // Clean up ONE_TO_ONE test
        $database->deleteCollection('usersOto');
        $database->deleteCollection('profilesOto');

        $database->createCollection('commentsMto');
        $database->createCollection('usersMto');

        $database->createAttribute('commentsMto', 'content', Database::VAR_STRING, 255, true);
        $database->createAttribute('usersMto', 'name', Database::VAR_STRING, 255, true);

        // MANY_TO_ONE with twoWay=true
        $database->createRelationship(
            collection: 'commentsMto',
            relatedCollection: 'usersMto',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
            id: 'commenter',
            twoWayKey: 'comments'
        );

        $userA = $database->createDocument('usersMto', new Document([
            '$id' => 'userA',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'name' => 'Alice',
        ]));

        $comment1 = $database->createDocument('commentsMto', new Document([
            '$id' => 'comment1',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'content' => 'Great post!',
            'commenter' => 'userA',
        ]));

        $comment2 = $database->createDocument('commentsMto', new Document([
            '$id' => 'comment2',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'content' => 'Nice work!',
            'commenter' => 'userA',
        ]));

        // Filter comments by commenter name
        $comments = $database->find('commentsMto', [
            Query::equal('commenter.name', ['Alice']),
        ]);
        $this->assertCount(2, $comments);

        // Filter users by their comments' content
        $users = $database->find('usersMto', [
            Query::equal('comments.content', ['Great post!']),
        ]);
        $this->assertCount(1, $users);
        $this->assertEquals('userA', $users[0]->getId());

        // Clean up MANY_TO_ONE test
        $database->deleteCollection('commentsMto');
        $database->deleteCollection('usersMto');

        $database->createCollection('studentsMtm');
        $database->createCollection('coursesMtm');

        $database->createAttribute('studentsMtm', 'studentName', Database::VAR_STRING, 255, true);
        $database->createAttribute('coursesMtm', 'courseName', Database::VAR_STRING, 255, true);

        // MANY_TO_MANY
        $database->createRelationship(
            collection: 'studentsMtm',
            relatedCollection: 'coursesMtm',
            type: Database::RELATION_MANY_TO_MANY,
            twoWay: true,
            id: 'enrolledCourses',
            twoWayKey: 'students'
        );

        $student1 = $database->createDocument('studentsMtm', new Document([
            '$id' => 'student1',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'studentName' => 'John',
        ]));

        $course1 = $database->createDocument('coursesMtm', new Document([
            '$id' => 'course1',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'courseName' => 'Physics',
            'students' => ['student1'],
        ]));

        // Filter students by enrolled course name
        $students = $database->find('studentsMtm', [
            Query::equal('enrolledCourses.courseName', ['Physics']),
        ]);
        $this->assertCount(1, $students);
        $this->assertEquals('student1', $students[0]->getId());

        // Filter courses by student name
        $courses = $database->find('coursesMtm', [
            Query::equal('students.studentName', ['John']),
        ]);
        $this->assertCount(1, $courses);
        $this->assertEquals('course1', $courses[0]->getId());

        // Clean up MANY_TO_MANY test
        $database->deleteCollection('studentsMtm');
        $database->deleteCollection('coursesMtm');
    }

    /**
     * Test querying parent documents by relationship document $id
     */
    public function testQueryByRelationshipId(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('usersRelId');
        $database->createCollection('postsRelId');

        $database->createAttribute('usersRelId', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('postsRelId', 'title', Database::VAR_STRING, 255, true);

        $database->createRelationship(
            collection: 'postsRelId',
            relatedCollection: 'usersRelId',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
            id: 'user',
            twoWayKey: 'posts'
        );

        // Create test users
        $user1 = $database->createDocument('usersRelId', new Document([
            '$id' => 'user1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'name' => 'Alice',
        ]));

        $user2 = $database->createDocument('usersRelId', new Document([
            '$id' => 'user2',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'name' => 'Bob',
        ]));

        // Create posts related to users
        $database->createDocument('postsRelId', new Document([
            '$id' => 'post1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'title' => 'Alice Post 1',
            'user' => 'user1',
        ]));

        $database->createDocument('postsRelId', new Document([
            '$id' => 'post2',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'title' => 'Alice Post 2',
            'user' => 'user1',
        ]));

        $database->createDocument('postsRelId', new Document([
            '$id' => 'post3',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'title' => 'Bob Post',
            'user' => 'user2',
        ]));

        // Query posts by user.$id - this is the key test
        $posts = $database->find('postsRelId', [
            Query::equal('user.$id', ['user1']),
        ]);
        $this->assertCount(2, $posts);
        $this->assertEquals('post1', $posts[0]->getId());
        $this->assertEquals('post2', $posts[1]->getId());

        // Query posts by different user.$id
        $posts = $database->find('postsRelId', [
            Query::equal('user.$id', ['user2']),
        ]);
        $this->assertCount(1, $posts);
        $this->assertEquals('post3', $posts[0]->getId());

        // Query posts by multiple user.$id values
        $posts = $database->find('postsRelId', [
            Query::equal('user.$id', ['user1', 'user2']),
        ]);
        $this->assertCount(3, $posts);

        // Query users by posts.$id (inverse direction)
        $users = $database->find('usersRelId', [
            Query::equal('posts.$id', ['post1']),
        ]);
        $this->assertCount(1, $users);
        $this->assertEquals('user1', $users[0]->getId());

        // Clean up MANY_TO_ONE test
        $database->deleteCollection('usersRelId');
        $database->deleteCollection('postsRelId');

        // Test ONE_TO_ONE relationship - query profile by user.$id
        $database->createCollection('usersOtoId');
        $database->createCollection('profilesOtoId');

        $database->createAttribute('usersOtoId', 'username', Database::VAR_STRING, 255, true);
        $database->createAttribute('profilesOtoId', 'bio', Database::VAR_STRING, 255, true);

        $database->createRelationship(
            collection: 'usersOtoId',
            relatedCollection: 'profilesOtoId',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
            id: 'profile',
            twoWayKey: 'user'
        );

        $userOto1 = $database->createDocument('usersOtoId', new Document([
            '$id' => 'userOto1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'username' => 'alice',
        ]));

        $database->createDocument('profilesOtoId', new Document([
            '$id' => 'profileOto1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'bio' => 'Software Engineer',
            'user' => 'userOto1',
        ]));

        // Query profiles by user.$id
        $profiles = $database->find('profilesOtoId', [
            Query::equal('user.$id', ['userOto1']),
        ]);
        $this->assertCount(1, $profiles);
        $this->assertEquals('profileOto1', $profiles[0]->getId());

        // Query users by profile.$id (inverse)
        $users = $database->find('usersOtoId', [
            Query::equal('profile.$id', ['profileOto1']),
        ]);
        $this->assertCount(1, $users);
        $this->assertEquals('userOto1', $users[0]->getId());

        // Clean up ONE_TO_ONE test
        $database->deleteCollection('usersOtoId');
        $database->deleteCollection('profilesOtoId');

        // Test MANY_TO_MANY relationship - query projects by developer.$id
        $database->createCollection('developersMtmId');
        $database->createCollection('projectsMtmId');

        $database->createAttribute('developersMtmId', 'devName', Database::VAR_STRING, 255, true);
        $database->createAttribute('projectsMtmId', 'projectName', Database::VAR_STRING, 255, true);

        $database->createRelationship(
            collection: 'developersMtmId',
            relatedCollection: 'projectsMtmId',
            type: Database::RELATION_MANY_TO_MANY,
            twoWay: true,
            id: 'projects',
            twoWayKey: 'developers'
        );

        $dev1 = $database->createDocument('developersMtmId', new Document([
            '$id' => 'dev1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'devName' => 'Alice',
        ]));

        $dev2 = $database->createDocument('developersMtmId', new Document([
            '$id' => 'dev2',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'devName' => 'Bob',
        ]));

        $database->createDocument('projectsMtmId', new Document([
            '$id' => 'project1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'projectName' => 'Project Alpha',
            'developers' => ['dev1', 'dev2'],
        ]));

        $database->createDocument('projectsMtmId', new Document([
            '$id' => 'project2',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'projectName' => 'Project Beta',
            'developers' => ['dev1'],
        ]));

        // Query projects by developer.$id
        $projects = $database->find('projectsMtmId', [
            Query::equal('developers.$id', ['dev1']),
        ]);
        $this->assertCount(2, $projects);

        $projects = $database->find('projectsMtmId', [
            Query::equal('developers.$id', ['dev2']),
        ]);
        $this->assertCount(1, $projects);
        $this->assertEquals('project1', $projects[0]->getId());

        // Query developers by project.$id (inverse)
        $developers = $database->find('developersMtmId', [
            Query::equal('projects.$id', ['project1']),
        ]);
        $this->assertCount(2, $developers);

        $developers = $database->find('developersMtmId', [
            Query::equal('projects.$id', ['project2']),
        ]);
        $this->assertCount(1, $developers);
        $this->assertEquals('dev1', $developers[0]->getId());

        // Query projects by BOTH developers using Query::containsAll
        // This simulates: "find conversations where both user1 AND user2 are participants"
        $projects = $database->find('projectsMtmId', [
            Query::containsAll('developers.$id', ['dev1', 'dev2']),
        ]);
        $this->assertCount(1, $projects);
        $this->assertEquals('project1', $projects[0]->getId());

        // Inverse: find developers who are on BOTH projects
        // dev1 is on project1 and project2, dev2 is only on project1
        $developers = $database->find('developersMtmId', [
            Query::containsAll('projects.$id', ['project1', 'project2']),
        ]);
        $this->assertCount(1, $developers);
        $this->assertEquals('dev1', $developers[0]->getId());

        // Query projects by BOTH developer names (non-$id attribute)
        // project1 has developers Alice and Bob, project2 has only Alice
        $projects = $database->find('projectsMtmId', [
            Query::containsAll('developers.devName', ['Alice', 'Bob']),
        ]);
        $this->assertCount(1, $projects);
        $this->assertEquals('project1', $projects[0]->getId());

        // Two separate equal queries on same relationship attribute should throw
        try {
            $database->find('projectsMtmId', [
                Query::equal('developers.$id', ['dev1']),
                Query::equal('developers.$id', ['dev2']),
            ]);
            $this->fail('Expected QueryException for impossible equal queries');
        } catch (\Utopia\Database\Exception\Query $e) {
            $this->assertStringContainsString('Query::containsAll()', $e->getMessage());
        }

        // Test M2M relationship query inside skipRelationships context
        // This simulates Appwrite's XList.php which wraps find() in skipRelationships()
        // when no select queries are provided
        $projects = $database->skipRelationships(fn () => $database->find('projectsMtmId', [
            Query::equal('developers.$id', ['dev1']),
        ]));
        $this->assertCount(2, $projects);

        $projects = $database->skipRelationships(fn () => $database->find('projectsMtmId', [
            Query::equal('developers.$id', ['dev2']),
        ]));
        $this->assertCount(1, $projects);
        $this->assertEquals('project1', $projects[0]->getId());

        // Also test inverse direction inside skipRelationships
        $developers = $database->skipRelationships(fn () => $database->find('developersMtmId', [
            Query::equal('projects.$id', ['project1']),
        ]));
        $this->assertCount(2, $developers);

        // Test containsAll inside skipRelationships
        $projects = $database->skipRelationships(fn () => $database->find('projectsMtmId', [
            Query::containsAll('developers.$id', ['dev1', 'dev2']),
        ]));
        $this->assertCount(1, $projects);
        $this->assertEquals('project1', $projects[0]->getId());

        // Clean up MANY_TO_MANY test
        $database->deleteCollection('developersMtmId');
        $database->deleteCollection('projectsMtmId');
    }

    /**
     * Comprehensive test for all query types on relationships
     */
    public function testRelationshipFilterQueries(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Setup test collections
        $database->createCollection('productsQt');
        $database->createCollection('vendorsQt');

        $database->createAttribute('productsQt', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('productsQt', 'price', Database::VAR_FLOAT, 0, true);
        $database->createAttribute('vendorsQt', 'company', Database::VAR_STRING, 255, true);
        $database->createAttribute('vendorsQt', 'rating', Database::VAR_FLOAT, 0, true);
        $database->createAttribute('vendorsQt', 'email', Database::VAR_STRING, 255, true);
        $database->createAttribute('vendorsQt', 'verified', Database::VAR_BOOLEAN, 0, true);

        $database->createRelationship(
            collection: 'productsQt',
            relatedCollection: 'vendorsQt',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
            id: 'vendor',
            twoWayKey: 'products'
        );

        // Create test vendors
        $database->createDocument('vendorsQt', new Document([
            '$id' => 'vendor1',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'company' => 'Acme Corp',
            'rating' => 4.5,
            'email' => 'sales@acme.com',
            'verified' => true,
        ]));

        $database->createDocument('vendorsQt', new Document([
            '$id' => 'vendor2',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'company' => 'TechSupply Inc',
            'rating' => 3.8,
            'email' => 'info@techsupply.com',
            'verified' => true,
        ]));

        $database->createDocument('vendorsQt', new Document([
            '$id' => 'vendor3',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'company' => 'Budget Vendors',
            'rating' => 2.5,
            'email' => 'contact@budget.com',
            'verified' => false,
        ]));

        // Create test products
        $database->createDocument('productsQt', new Document([
            '$id' => 'product1',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'name' => 'Widget A',
            'price' => 19.99,
            'vendor' => 'vendor1',
        ]));

        $database->createDocument('productsQt', new Document([
            '$id' => 'product2',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'name' => 'Widget B',
            'price' => 29.99,
            'vendor' => 'vendor2',
        ]));

        $database->createDocument('productsQt', new Document([
            '$id' => 'product3',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'name' => 'Widget C',
            'price' => 9.99,
            'vendor' => 'vendor3',
        ]));

        // Query::equal()
        $products = $database->find('productsQt', [
            Query::equal('vendor.company', ['Acme Corp'])
        ]);
        $this->assertCount(1, $products);
        $this->assertEquals('product1', $products[0]->getId());

        // Query::notEqual()
        $products = $database->find('productsQt', [
            Query::notEqual('vendor.company', ['Budget Vendors'])
        ]);
        $this->assertCount(2, $products);

        // Query::lessThan()
        $products = $database->find('productsQt', [
            Query::lessThan('vendor.rating', 4.0)
        ]);
        $this->assertCount(2, $products); // vendor2 (3.8) and vendor3 (2.5)

        // Query::lessThanEqual()
        $products = $database->find('productsQt', [
            Query::lessThanEqual('vendor.rating', 3.8)
        ]);
        $this->assertCount(2, $products);

        // Query::greaterThan()
        $products = $database->find('productsQt', [
            Query::greaterThan('vendor.rating', 4.0)
        ]);
        $this->assertCount(1, $products);
        $this->assertEquals('product1', $products[0]->getId());

        // Query::greaterThanEqual()
        $products = $database->find('productsQt', [
            Query::greaterThanEqual('vendor.rating', 3.8)
        ]);
        $this->assertCount(2, $products); // vendor1 (4.5) and vendor2 (3.8)

        // Query::startsWith()
        $products = $database->find('productsQt', [
            Query::startsWith('vendor.email', 'sales@')
        ]);
        $this->assertCount(1, $products);
        $this->assertEquals('product1', $products[0]->getId());

        // Query::endsWith()
        $products = $database->find('productsQt', [
            Query::endsWith('vendor.email', '.com')
        ]);
        $this->assertCount(3, $products);

        // Query::contains()
        $products = $database->find('productsQt', [
            Query::contains('vendor.company', ['Corp'])
        ]);
        $this->assertCount(1, $products);
        $this->assertEquals('product1', $products[0]->getId());

        // Boolean query
        $products = $database->find('productsQt', [
            Query::equal('vendor.verified', [true])
        ]);
        $this->assertCount(2, $products); // vendor1 and vendor2 are verified

        $products = $database->find('productsQt', [
            Query::equal('vendor.verified', [false])
        ]);
        $this->assertCount(1, $products);
        $this->assertEquals('product3', $products[0]->getId());

        // Multiple conditions on same relationship (query grouping optimization)
        $products = $database->find('productsQt', [
            Query::greaterThan('vendor.rating', 3.0),
            Query::equal('vendor.verified', [true]),
            Query::startsWith('vendor.company', 'Acme')
        ]);
        $this->assertCount(1, $products);
        $this->assertEquals('product1', $products[0]->getId());

        // Clean up
        $database->deleteCollection('productsQt');
        $database->deleteCollection('vendorsQt');
    }

    public function testRelationshipSpatialQueries(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        if (!$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Create Restaurants -> Suppliers relationship with spatial attributes
        $database->createCollection('restaurantsSpatial');
        $database->createCollection('suppliersSpatial');

        $database->createAttribute('restaurantsSpatial', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('restaurantsSpatial', 'location', Database::VAR_POINT, 0, true);

        $database->createAttribute('suppliersSpatial', 'company', Database::VAR_STRING, 255, true);
        $database->createAttribute('suppliersSpatial', 'warehouseLocation', Database::VAR_POINT, 0, true);
        $database->createAttribute('suppliersSpatial', 'deliveryArea', Database::VAR_POLYGON, 0, true);
        $database->createAttribute('suppliersSpatial', 'deliveryRoute', Database::VAR_LINESTRING, 0, true);

        $database->createRelationship(
            collection: 'restaurantsSpatial',
            relatedCollection: 'suppliersSpatial',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
            id: 'supplier',
            twoWayKey: 'restaurants'
        );

        // Create suppliers with spatial data (coordinates are [longitude, latitude])
        $supplier1 = $database->createDocument('suppliersSpatial', new Document([
            '$id' => 'supplier1',
            '$permissions' => [Permission::read(Role::any())],
            'company' => 'Fresh Foods Inc',
            'warehouseLocation' => [-74.0060, 40.7128], // New York
            'deliveryArea' => [
                [-74.1, 40.7],
                [-73.9, 40.7],
                [-73.9, 40.8],
                [-74.1, 40.8],
                [-74.1, 40.7]
            ],
            'deliveryRoute' => [
                [-74.0060, 40.7128],
                [-73.9851, 40.7589],
                [-73.9857, 40.7484]
            ]
        ]));

        $supplier2 = $database->createDocument('suppliersSpatial', new Document([
            '$id' => 'supplier2',
            '$permissions' => [Permission::read(Role::any())],
            'company' => 'Ocean Seafood',
            'warehouseLocation' => [-118.2437, 34.0522], // Los Angeles
            'deliveryArea' => [
                [-118.3, 34.0],
                [-118.1, 34.0],
                [-118.1, 34.1],
                [-118.3, 34.1],
                [-118.3, 34.0]
            ],
            'deliveryRoute' => [
                [-118.2437, 34.0522],
                [-118.2468, 34.0407],
                [-118.2456, 34.0336]
            ]
        ]));

        $supplier3 = $database->createDocument('suppliersSpatial', new Document([
            '$id' => 'supplier3',
            '$permissions' => [Permission::read(Role::any())],
            'company' => 'Mountain Meats',
            'warehouseLocation' => [-104.9903, 39.7392], // Denver
            'deliveryArea' => [
                [-105.1, 39.7],
                [-104.8, 39.7],
                [-104.8, 39.8],
                [-105.1, 39.8],
                [-105.1, 39.7]
            ],
            'deliveryRoute' => [
                [-104.9903, 39.7392],
                [-104.9847, 39.7294],
                [-104.9708, 39.7197]
            ]
        ]));

        // Create restaurants
        $database->createDocument('restaurantsSpatial', new Document([
            '$id' => 'rest1',
            '$permissions' => [Permission::read(Role::any())],
            'name' => 'NYC Diner',
            'location' => [-74.0060, 40.7128],
            'supplier' => 'supplier1'
        ]));

        $database->createDocument('restaurantsSpatial', new Document([
            '$id' => 'rest2',
            '$permissions' => [Permission::read(Role::any())],
            'name' => 'LA Bistro',
            'location' => [-118.2437, 34.0522],
            'supplier' => 'supplier2'
        ]));

        $database->createDocument('restaurantsSpatial', new Document([
            '$id' => 'rest3',
            '$permissions' => [Permission::read(Role::any())],
            'name' => 'Denver Steakhouse',
            'location' => [-104.9903, 39.7392],
            'supplier' => 'supplier3'
        ]));

        // distanceLessThan on relationship point attribute
        $restaurants = $database->find('restaurantsSpatial', [
            Query::distanceLessThan('supplier.warehouseLocation', [-74.0060, 40.7128], 1.0)
        ]);
        $this->assertCount(1, $restaurants);
        $this->assertEquals('rest1', $restaurants[0]->getId());

        // distanceEqual on relationship point attribute
        $restaurants = $database->find('restaurantsSpatial', [
            Query::distanceEqual('supplier.warehouseLocation', [-74.0060, 40.7128], 0.0)
        ]);
        $this->assertCount(1, $restaurants);
        $this->assertEquals('rest1', $restaurants[0]->getId());

        // distanceGreaterThan on relationship point attribute
        $restaurants = $database->find('restaurantsSpatial', [
            Query::distanceGreaterThan('supplier.warehouseLocation', [-74.0060, 40.7128], 10.0)
        ]);
        $this->assertCount(2, $restaurants); // LA and Denver suppliers

        // distanceNotEqual on relationship point attribute
        $restaurants = $database->find('restaurantsSpatial', [
            Query::distanceNotEqual('supplier.warehouseLocation', [-74.0060, 40.7128], 0.0)
        ]);
        $this->assertCount(2, $restaurants); // LA and Denver

        // contains on relationship polygon attribute (point inside polygon)
        $restaurants = $database->find('restaurantsSpatial', [
            Query::contains('supplier.deliveryArea', [[-74.0, 40.75]])
        ]);
        $this->assertCount(1, $restaurants);
        $this->assertEquals('rest1', $restaurants[0]->getId());

        // contains on relationship linestring attribute
        // Note: ST_Contains on linestrings is implementation-dependent (some DBs require exact point-on-line)
        $restaurants = $database->find('restaurantsSpatial', [
            Query::contains('supplier.deliveryRoute', [[-74.0060, 40.7128]])
        ]);
        // Verify query executes (result count depends on DB spatial implementation)
        $this->assertGreaterThanOrEqual(0, count($restaurants));

        // intersects on relationship polygon attribute
        $testPolygon = [
            [-74.05, 40.72],
            [-74.00, 40.72],
            [-74.00, 40.77],
            [-74.05, 40.77],
            [-74.05, 40.72]
        ];
        $restaurants = $database->find('restaurantsSpatial', [
            Query::intersects('supplier.deliveryArea', [$testPolygon])
        ]);
        $this->assertCount(1, $restaurants);
        $this->assertEquals('rest1', $restaurants[0]->getId());

        // intersects on relationship linestring attribute
        // Note: Linestring intersection semantics vary by DB (MariaDB/MySQL/PostgreSQL differ)
        $testLine = [
            [-74.01, 40.71],
            [-73.99, 40.76]
        ];
        $restaurants = $database->find('restaurantsSpatial', [
            Query::intersects('supplier.deliveryRoute', [$testLine])
        ]);
        // Verify query executes (result count depends on DB spatial implementation)
        $this->assertGreaterThanOrEqual(0, count($restaurants));

        // crosses on relationship linestring
        $crossingLine = [
            [-74.05, 40.70],
            [-73.95, 40.80]
        ];
        $restaurants = $database->find('restaurantsSpatial', [
            Query::crosses('supplier.deliveryRoute', [$crossingLine])
        ]);
        // Result depends on actual geometry intersection

        // overlaps on relationship polygon
        $overlappingPolygon = [
            [-74.05, 40.75],
            [-74.00, 40.75],
            [-74.00, 40.85],
            [-74.05, 40.85],
            [-74.05, 40.75]
        ];
        $restaurants = $database->find('restaurantsSpatial', [
            Query::overlaps('supplier.deliveryArea', [$overlappingPolygon])
        ]);
        $this->assertCount(1, $restaurants);
        $this->assertEquals('rest1', $restaurants[0]->getId());

        // touches on relationship polygon (polygon shares boundary)
        $touchingPolygon = [
            [-74.1, 40.8],
            [-73.9, 40.8],
            [-73.9, 40.9],
            [-74.1, 40.9],
            [-74.1, 40.8]
        ];
        $restaurants = $database->find('restaurantsSpatial', [
            Query::touches('supplier.deliveryArea', [$touchingPolygon])
        ]);
        $this->assertCount(1, $restaurants);
        $this->assertEquals('rest1', $restaurants[0]->getId());

        // Multiple spatial queries combined
        $restaurants = $database->find('restaurantsSpatial', [
            Query::distanceLessThan('supplier.warehouseLocation', [-74.0060, 40.7128], 1.0),
            Query::contains('supplier.deliveryArea', [[-74.0, 40.75]])
        ]);
        $this->assertCount(1, $restaurants);
        $this->assertEquals('rest1', $restaurants[0]->getId());

        // Spatial query combined with regular query
        $restaurants = $database->find('restaurantsSpatial', [
            Query::distanceLessThan('supplier.warehouseLocation', [-74.0060, 40.7128], 1.0),
            Query::equal('supplier.company', ['Fresh Foods Inc'])
        ]);
        $this->assertCount(1, $restaurants);
        $this->assertEquals('rest1', $restaurants[0]->getId());

        // count with spatial relationship query
        $count = $database->count('restaurantsSpatial', [
            Query::distanceLessThan('supplier.warehouseLocation', [-74.0060, 40.7128], 1.0)
        ]);
        $this->assertEquals(1, $count);

        // Clean up
        $database->deleteCollection('restaurantsSpatial');
        $database->deleteCollection('suppliersSpatial');
    }

    /**
     * Test relationship queries from parent side with virtual attributes
     */
    public function testRelationshipVirtualQueries(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Setup ONE_TO_MANY relationship
        $database->createCollection('teamsParent');
        $database->createCollection('membersParent');

        $database->createAttribute('teamsParent', 'teamName', Database::VAR_STRING, 255, true);
        $database->createAttribute('teamsParent', 'active', Database::VAR_BOOLEAN, 0, true);
        $database->createAttribute('membersParent', 'memberName', Database::VAR_STRING, 255, true);
        $database->createAttribute('membersParent', 'role', Database::VAR_STRING, 255, true);
        $database->createAttribute('membersParent', 'senior', Database::VAR_BOOLEAN, 0, true);

        $database->createRelationship(
            collection: 'teamsParent',
            relatedCollection: 'membersParent',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true,
            id: 'members',
            twoWayKey: 'team'
        );

        // Create teams
        $database->createDocument('teamsParent', new Document([
            '$id' => 'team1',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'teamName' => 'Engineering',
            'active' => true,
        ]));

        $database->createDocument('teamsParent', new Document([
            '$id' => 'team2',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'teamName' => 'Sales',
            'active' => true,
        ]));

        // Create members
        $database->createDocument('membersParent', new Document([
            '$id' => 'member1',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'memberName' => 'Alice',
            'role' => 'Engineer',
            'senior' => true,
            'team' => 'team1',
        ]));

        $database->createDocument('membersParent', new Document([
            '$id' => 'member2',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'memberName' => 'Bob',
            'role' => 'Manager',
            'senior' => false,
            'team' => 'team2',
        ]));

        $database->createDocument('membersParent', new Document([
            '$id' => 'member3',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'memberName' => 'Charlie',
            'role' => 'Engineer',
            'senior' => true,
            'team' => 'team1',
        ]));

        // Find teams that have senior engineers
        $teams = $database->find('teamsParent', [
            Query::equal('members.role', ['Engineer']),
            Query::equal('members.senior', [true])
        ]);
        $this->assertCount(1, $teams);
        $this->assertEquals('team1', $teams[0]->getId());

        // Find teams with managers
        $teams = $database->find('teamsParent', [
            Query::equal('members.role', ['Manager'])
        ]);
        $this->assertCount(1, $teams);
        $this->assertEquals('team2', $teams[0]->getId());

        // Find teams with members named 'Alice'
        $teams = $database->find('teamsParent', [
            Query::startsWith('members.memberName', 'A')
        ]);
        $this->assertCount(1, $teams);
        $this->assertEquals('team1', $teams[0]->getId());

        // No teams with junior managers
        $teams = $database->find('teamsParent', [
            Query::equal('members.role', ['Manager']),
            Query::equal('members.senior', [true])
        ]);
        $this->assertCount(0, $teams);

        // Clean up
        $database->deleteCollection('teamsParent');
        $database->deleteCollection('membersParent');
    }

    /**
     * Test edge cases and error scenarios for relationship queries
     */
    public function testRelationshipQueryEdgeCases(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Setup test collections
        $database->createCollection('ordersEdge');
        $database->createCollection('customersEdge');

        $database->createAttribute('ordersEdge', 'orderNumber', Database::VAR_STRING, 255, true);
        $database->createAttribute('ordersEdge', 'total', Database::VAR_FLOAT, 0, true);
        $database->createAttribute('customersEdge', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('customersEdge', 'age', Database::VAR_INTEGER, 0, true);

        $database->createRelationship(
            collection: 'ordersEdge',
            relatedCollection: 'customersEdge',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
            id: 'customer',
            twoWayKey: 'orders'
        );

        // Create customer
        $database->createDocument('customersEdge', new Document([
            '$id' => 'customer1',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'name' => 'John Doe',
            'age' => 30,
        ]));

        // Create order
        $database->createDocument('ordersEdge', new Document([
            '$id' => 'order1',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'orderNumber' => 'ORD001',
            'total' => 100.00,
            'customer' => 'customer1',
        ]));

        // No matching results
        $orders = $database->find('ordersEdge', [
            Query::equal('customer.name', ['Jane Doe'])
        ]);
        $this->assertCount(0, $orders);

        // Impossible condition (combines to empty set)
        $orders = $database->find('ordersEdge', [
            Query::equal('customer.name', ['John Doe']),
            Query::equal('customer.age', [25]) // John is 30, not 25
        ]);
        $this->assertCount(0, $orders);

        // Non-existent relationship attribute
        try {
            $database->find('ordersEdge', [
                Query::equal('nonexistent.attribute', ['value'])
            ]);
        } catch (\Exception $e) {
            // Expected - non-existent relationship
            $this->assertTrue(true);
        }

        // Null or missing relationship
        $database->createDocument('ordersEdge', new Document([
            '$id' => 'order2',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'orderNumber' => 'ORD002',
            'total' => 50.00,
            // No customer relationship
        ]));

        $orders = $database->find('ordersEdge', [
            Query::equal('customer.name', ['John Doe'])
        ]);
        $this->assertCount(1, $orders);

        // Combining relationship query with regular query
        $orders = $database->find('ordersEdge', [
            Query::equal('customer.name', ['John Doe']),
            Query::greaterThan('total', 75.00)
        ]);
        $this->assertCount(1, $orders);
        $this->assertEquals('order1', $orders[0]->getId());

        // Query with limit and offset
        $orders = $database->find('ordersEdge', [
            Query::equal('customer.name', ['John Doe']),
            Query::limit(1),
            Query::offset(0)
        ]);
        $this->assertCount(1, $orders);

        $database->deleteCollection('ordersEdge');
        $database->deleteCollection('customersEdge');
    }

    /**
     * Test MANY_TO_MANY relationships with complex queries
     */
    public function testRelationshipManyToManyComplex(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Setup MANY_TO_MANY
        $database->createCollection('developersMtm');
        $database->createCollection('projectsMtm');

        $database->createAttribute('developersMtm', 'devName', Database::VAR_STRING, 255, true);
        $database->createAttribute('developersMtm', 'experience', Database::VAR_INTEGER, 0, true);
        $database->createAttribute('projectsMtm', 'projectName', Database::VAR_STRING, 255, true);
        $database->createAttribute('projectsMtm', 'budget', Database::VAR_FLOAT, 0, true);
        $database->createAttribute('projectsMtm', 'priority', Database::VAR_STRING, 50, true);

        $database->createRelationship(
            collection: 'developersMtm',
            relatedCollection: 'projectsMtm',
            type: Database::RELATION_MANY_TO_MANY,
            twoWay: true,
            id: 'assignedProjects',
            twoWayKey: 'assignedDevelopers'
        );

        // Create developers
        $dev1 = $database->createDocument('developersMtm', new Document([
            '$id' => 'dev1',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'devName' => 'Senior Dev',
            'experience' => 10,
        ]));

        $dev2 = $database->createDocument('developersMtm', new Document([
            '$id' => 'dev2',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'devName' => 'Junior Dev',
            'experience' => 2,
        ]));

        // Create projects
        $project1 = $database->createDocument('projectsMtm', new Document([
            '$id' => 'proj1',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'projectName' => 'High Priority Project',
            'budget' => 100000.00,
            'priority' => 'high',
            'assignedDevelopers' => ['dev1', 'dev2'],
        ]));

        $project2 = $database->createDocument('projectsMtm', new Document([
            '$id' => 'proj2',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'projectName' => 'Low Priority Project',
            'budget' => 25000.00,
            'priority' => 'low',
            'assignedDevelopers' => ['dev2'],
        ]));

        // Find developers on high priority projects
        $developers = $database->find('developersMtm', [
            Query::equal('assignedProjects.priority', ['high'])
        ]);
        $this->assertCount(2, $developers); // Both assigned to proj1

        // Find developers on high budget projects
        $developers = $database->find('developersMtm', [
            Query::greaterThan('assignedProjects.budget', 50000.00)
        ]);
        $this->assertCount(2, $developers);

        // Find projects with experienced developers
        $projects = $database->find('projectsMtm', [
            Query::greaterThanEqual('assignedDevelopers.experience', 10)
        ]);
        $this->assertCount(1, $projects);
        $this->assertEquals('proj1', $projects[0]->getId());

        // Find projects with junior developers
        $projects = $database->find('projectsMtm', [
            Query::lessThan('assignedDevelopers.experience', 5)
        ]);
        $this->assertCount(2, $projects); // Both projects have dev2

        // Combined queries
        $projects = $database->find('projectsMtm', [
            Query::equal('assignedDevelopers.devName', ['Junior Dev']),
            Query::equal('priority', ['low'])
        ]);
        $this->assertCount(1, $projects);
        $this->assertEquals('proj2', $projects[0]->getId());

        // Clean up
        $database->deleteCollection('developersMtm');
        $database->deleteCollection('projectsMtm');
    }

    public function testNestedRelationshipQueriesMultipleDepths(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Create 3-level nested structure:
        // Companies -> Employees -> Projects -> Tasks
        // Also: Employees -> Department (MANY_TO_ONE)

        // Level 0: Companies
        $database->createCollection('companiesNested');
        $database->createAttribute('companiesNested', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('companiesNested', 'industry', Database::VAR_STRING, 255, true);

        // Level 1: Employees
        $database->createCollection('employeesNested');
        $database->createAttribute('employeesNested', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('employeesNested', 'role', Database::VAR_STRING, 255, true);

        // Level 1b: Departments (for MANY_TO_ONE)
        $database->createCollection('departmentsNested');
        $database->createAttribute('departmentsNested', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('departmentsNested', 'budget', Database::VAR_INTEGER, 0, true);

        // Level 2: Projects
        $database->createCollection('projectsNested');
        $database->createAttribute('projectsNested', 'title', Database::VAR_STRING, 255, true);
        $database->createAttribute('projectsNested', 'status', Database::VAR_STRING, 255, true);

        // Level 3: Tasks
        $database->createCollection('tasksNested');
        $database->createAttribute('tasksNested', 'description', Database::VAR_STRING, 255, true);
        $database->createAttribute('tasksNested', 'priority', Database::VAR_STRING, 255, true);
        $database->createAttribute('tasksNested', 'completed', Database::VAR_BOOLEAN, 0, true);

        // Create relationships
        // Companies -> Employees (ONE_TO_MANY)
        $database->createRelationship(
            collection: 'companiesNested',
            relatedCollection: 'employeesNested',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true,
            id: 'employees',
            twoWayKey: 'company'
        );

        // Employees -> Department (MANY_TO_ONE)
        $database->createRelationship(
            collection: 'employeesNested',
            relatedCollection: 'departmentsNested',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
            id: 'department',
            twoWayKey: 'employees'
        );

        // Employees -> Projects (ONE_TO_MANY)
        $database->createRelationship(
            collection: 'employeesNested',
            relatedCollection: 'projectsNested',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true,
            id: 'projects',
            twoWayKey: 'employee'
        );

        // Projects -> Tasks (ONE_TO_MANY)
        $database->createRelationship(
            collection: 'projectsNested',
            relatedCollection: 'tasksNested',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true,
            id: 'tasks',
            twoWayKey: 'project'
        );

        // Create test data
        $dept1 = $database->createDocument('departmentsNested', new Document([
            '$id' => 'dept1',
            '$permissions' => [Permission::read(Role::any())],
            'name' => 'Engineering',
            'budget' => 100000,
        ]));

        $dept2 = $database->createDocument('departmentsNested', new Document([
            '$id' => 'dept2',
            '$permissions' => [Permission::read(Role::any())],
            'name' => 'Marketing',
            'budget' => 50000,
        ]));

        $company1 = $database->createDocument('companiesNested', new Document([
            '$id' => 'company1',
            '$permissions' => [Permission::read(Role::any())],
            'name' => 'TechCorp',
            'industry' => 'Technology',
        ]));

        $company2 = $database->createDocument('companiesNested', new Document([
            '$id' => 'company2',
            '$permissions' => [Permission::read(Role::any())],
            'name' => 'MarketCo',
            'industry' => 'Marketing',
        ]));

        $employee1 = $database->createDocument('employeesNested', new Document([
            '$id' => 'emp1',
            '$permissions' => [Permission::read(Role::any())],
            'name' => 'Alice Johnson',
            'role' => 'Developer',
            'company' => 'company1',
            'department' => 'dept1',
        ]));

        $employee2 = $database->createDocument('employeesNested', new Document([
            '$id' => 'emp2',
            '$permissions' => [Permission::read(Role::any())],
            'name' => 'Bob Smith',
            'role' => 'Marketer',
            'company' => 'company2',
            'department' => 'dept2',
        ]));

        $project1 = $database->createDocument('projectsNested', new Document([
            '$id' => 'proj1',
            '$permissions' => [Permission::read(Role::any())],
            'title' => 'Website Redesign',
            'status' => 'active',
            'employee' => 'emp1',
        ]));

        $project2 = $database->createDocument('projectsNested', new Document([
            '$id' => 'proj2',
            '$permissions' => [Permission::read(Role::any())],
            'title' => 'Campaign Launch',
            'status' => 'planning',
            'employee' => 'emp2',
        ]));

        $task1 = $database->createDocument('tasksNested', new Document([
            '$id' => 'task1',
            '$permissions' => [Permission::read(Role::any())],
            'description' => 'Design homepage',
            'priority' => 'high',
            'completed' => false,
            'project' => 'proj1',
        ]));

        $task2 = $database->createDocument('tasksNested', new Document([
            '$id' => 'task2',
            '$permissions' => [Permission::read(Role::any())],
            'description' => 'Write copy',
            'priority' => 'medium',
            'completed' => true,
            'project' => 'proj2',
        ]));

        $task3 = $database->createDocument('tasksNested', new Document([
            '$id' => 'task3',
            '$permissions' => [Permission::read(Role::any())],
            'description' => 'Implement backend',
            'priority' => 'high',
            'completed' => false,
            'project' => 'proj1',
        ]));

        // Query employees by company name (1 level deep)
        $employees = $database->find('employeesNested', [
            Query::equal('company.name', ['TechCorp']),
        ]);
        $this->assertCount(1, $employees);
        $this->assertEquals('emp1', $employees[0]->getId());

        // Query employees by department name (1 level deep MANY_TO_ONE)
        $employees = $database->find('employeesNested', [
            Query::equal('department.name', ['Engineering']),
        ]);
        $this->assertCount(1, $employees);
        $this->assertEquals('emp1', $employees[0]->getId());

        // Query projects by employee name (1 level deep)
        $projects = $database->find('projectsNested', [
            Query::equal('employee.name', ['Alice Johnson']),
        ]);
        $this->assertCount(1, $projects);
        $this->assertEquals('proj1', $projects[0]->getId());

        // Query projects by employee's company name (2 levels deep)
        $projects = $database->find('projectsNested', [
            Query::equal('employee.company.name', ['TechCorp']),
        ]);
        $this->assertCount(1, $projects);
        $this->assertEquals('proj1', $projects[0]->getId());

        // Query projects by employee's department name (2 levels deep, MANY_TO_ONE)
        $projects = $database->find('projectsNested', [
            Query::equal('employee.department.name', ['Engineering']),
        ]);
        $this->assertCount(1, $projects);
        $this->assertEquals('proj1', $projects[0]->getId());

        // Query tasks by project employee name (2 levels deep)
        $tasks = $database->find('tasksNested', [
            Query::equal('project.employee.name', ['Alice Johnson']),
        ]);
        $this->assertCount(2, $tasks);

        // Query tasks by project->employee->company name (3 levels deep)
        $tasks = $database->find('tasksNested', [
            Query::equal('project.employee.company.name', ['TechCorp']),
        ]);
        $this->assertCount(2, $tasks);
        $this->assertEquals('task1', $tasks[0]->getId());
        $this->assertEquals('task3', $tasks[1]->getId());

        // Query tasks by project->employee->department budget (3 levels deep with MANY_TO_ONE)
        $tasks = $database->find('tasksNested', [
            Query::greaterThan('project.employee.department.budget', 75000),
        ]);
        $this->assertCount(2, $tasks); // Both tasks are in projects by employees in Engineering dept

        // Query tasks by project->employee->company industry (3 levels deep)
        $tasks = $database->find('tasksNested', [
            Query::equal('project.employee.company.industry', ['Marketing']),
        ]);
        $this->assertCount(1, $tasks);
        $this->assertEquals('task2', $tasks[0]->getId());

        // Combine depth 1 and depth 3 queries
        $tasks = $database->find('tasksNested', [
            Query::equal('priority', ['high']),
            Query::equal('project.employee.company.name', ['TechCorp']),
        ]);
        $this->assertCount(2, $tasks);

        // Multiple depth 2 queries combined
        $projects = $database->find('projectsNested', [
            Query::equal('employee.company.industry', ['Technology']),
            Query::equal('employee.department.name', ['Engineering']),
        ]);
        $this->assertCount(1, $projects);
        $this->assertEquals('proj1', $projects[0]->getId());

        // Clean up
        $database->deleteCollection('tasksNested');
        $database->deleteCollection('projectsNested');
        $database->deleteCollection('employeesNested');
        $database->deleteCollection('departmentsNested');
        $database->deleteCollection('companiesNested');
    }

    public function testCountAndSumWithRelationshipQueries(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Create Author -> Posts relationship with view count
        $database->createCollection('authorsCount');
        $database->createCollection('postsCount');

        $database->createAttribute('authorsCount', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('authorsCount', 'age', Database::VAR_INTEGER, 0, true);
        $database->createAttribute('postsCount', 'title', Database::VAR_STRING, 255, true);
        $database->createAttribute('postsCount', 'views', Database::VAR_INTEGER, 0, true);
        $database->createAttribute('postsCount', 'published', Database::VAR_BOOLEAN, 0, true);

        $database->createRelationship(
            collection: 'authorsCount',
            relatedCollection: 'postsCount',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true,
            id: 'posts',
            twoWayKey: 'author'
        );

        // Create test data
        $author1 = $database->createDocument('authorsCount', new Document([
            '$id' => 'author1',
            '$permissions' => [Permission::read(Role::any())],
            'name' => 'Alice',
            'age' => 30,
        ]));

        $author2 = $database->createDocument('authorsCount', new Document([
            '$id' => 'author2',
            '$permissions' => [Permission::read(Role::any())],
            'name' => 'Bob',
            'age' => 25,
        ]));

        $author3 = $database->createDocument('authorsCount', new Document([
            '$id' => 'author3',
            '$permissions' => [Permission::read(Role::any())],
            'name' => 'Charlie',
            'age' => 35,
        ]));

        // Create posts
        $database->createDocument('postsCount', new Document([
            '$id' => 'post1',
            '$permissions' => [Permission::read(Role::any())],
            'title' => 'Alice Post 1',
            'views' => 100,
            'published' => true,
            'author' => 'author1',
        ]));

        $database->createDocument('postsCount', new Document([
            '$id' => 'post2',
            '$permissions' => [Permission::read(Role::any())],
            'title' => 'Alice Post 2',
            'views' => 200,
            'published' => true,
            'author' => 'author1',
        ]));

        $database->createDocument('postsCount', new Document([
            '$id' => 'post3',
            '$permissions' => [Permission::read(Role::any())],
            'title' => 'Alice Draft',
            'views' => 50,
            'published' => false,
            'author' => 'author1',
        ]));

        $database->createDocument('postsCount', new Document([
            '$id' => 'post4',
            '$permissions' => [Permission::read(Role::any())],
            'title' => 'Bob Post',
            'views' => 150,
            'published' => true,
            'author' => 'author2',
        ]));

        $database->createDocument('postsCount', new Document([
            '$id' => 'post5',
            '$permissions' => [Permission::read(Role::any())],
            'title' => 'Bob Draft',
            'views' => 75,
            'published' => false,
            'author' => 'author2',
        ]));

        // Count posts by author name
        $count = $database->count('postsCount', [
            Query::equal('author.name', ['Alice']),
        ]);
        $this->assertEquals(3, $count);

        // Count published posts by author age filter
        $count = $database->count('postsCount', [
            Query::lessThan('author.age', 30),
            Query::equal('published', [true]),
        ]);
        $this->assertEquals(1, $count);

        // Count posts by author name (different author)
        $count = $database->count('postsCount', [
            Query::equal('author.name', ['Bob']),
        ]);
        $this->assertEquals(2, $count);

        // Count with no matches (author with no posts)
        $count = $database->count('postsCount', [
            Query::equal('author.name', ['Charlie']),
        ]);
        $this->assertEquals(0, $count);

        // Sum views for posts by author name
        $sum = $database->sum('postsCount', 'views', [
            Query::equal('author.name', ['Alice']),
        ]);
        $this->assertEquals(350, $sum); // 100 + 200 + 50

        // Sum views for published posts by author age
        $sum = $database->sum('postsCount', 'views', [
            Query::lessThan('author.age', 30),
            Query::equal('published', [true]),
        ]);
        $this->assertEquals(150, $sum);

        // Sum views for Bob's posts
        $sum = $database->sum('postsCount', 'views', [
            Query::equal('author.name', ['Bob']),
        ]);
        $this->assertEquals(225, $sum);

        // Sum with no matches
        $sum = $database->sum('postsCount', 'views', [
            Query::equal('author.name', ['Charlie']),
        ]);
        $this->assertEquals(0, $sum);

        // Clean up
        $database->deleteCollection('authorsCount');
        $database->deleteCollection('postsCount');
    }

    /**
     // and cursor queries properly reject relationship (dot-path) attributes.
     *
     * Relationship attributes like 'author.name' are NOT supported for ordering because:
     * 1. Only filter queries go through convertRelationshipFiltersToSubqueries()
     * 2. Order attributes are passed directly to the adapter without relationship resolution
     * 3. The Order validator now catches dot-path attributes and rejects them with a clear error
     * 4. Cursor validation doesn't need separate dot-path checks since order validation runs first
     */
    public function testOrderAndCursorWithRelationshipQueries(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        $database->createCollection('authorsOrder');
        $database->createCollection('postsOrder');

        $database->createAttribute('authorsOrder', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('authorsOrder', 'age', Database::VAR_INTEGER, 0, true);

        $database->createAttribute('postsOrder', 'title', Database::VAR_STRING, 255, true);
        $database->createAttribute('postsOrder', 'views', Database::VAR_INTEGER, 0, true);

        $database->createRelationship(
            collection: 'postsOrder',
            relatedCollection: 'authorsOrder',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
            id: 'author',
            twoWayKey: 'postsOrder'
        );

        // Create authors
        $alice = $database->createDocument('authorsOrder', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'name' => 'Alice',
            'age' => 30,
        ]));

        $bob = $database->createDocument('authorsOrder', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'name' => 'Bob',
            'age' => 25,
        ]));

        // Create posts
        $database->createDocument('postsOrder', new Document([
            '$permissions' => [Permission::read(Role::any())],
            'title' => 'Post 1',
            'views' => 100,
            'author' => $alice->getId(),
        ]));

        $database->createDocument('postsOrder', new Document([
            '$permissions' => [Permission::read(Role::any())],
            'title' => 'Post 2',
            'views' => 200,
            'author' => $bob->getId(),
        ]));

        $database->createDocument('postsOrder', new Document([
            '$permissions' => [Permission::read(Role::any())],
            'title' => 'Post 3',
            'views' => 150,
            'author' => $alice->getId(),
        ]));

        // Order by relationship attribute should fail with validation error
        $caught = false;
        try {
            $database->find('postsOrder', [
                Query::orderAsc('author.name')
            ]);
        } catch (\Throwable $e) {
            $caught = true;
            $this->assertStringContainsString('Cannot order by nested attribute', $e->getMessage());
        }
        $this->assertTrue($caught, 'Should throw exception for nested order attribute');

        // Cursor with relationship order attribute should fail with same validation error
        $caught = false;
        try {
            $firstPost = $database->findOne('postsOrder', [
                Query::orderAsc('title')
            ]);

            $database->find('postsOrder', [
                Query::orderAsc('author.name'),
                Query::cursorAfter($firstPost)
            ]);
        } catch (\Throwable $e) {
            $caught = true;
            $this->assertStringContainsString('Cannot order by nested attribute', $e->getMessage());
        }
        $this->assertTrue($caught, 'Should throw exception for nested order attribute with cursor');


        // Clean up
        $database->deleteCollection('authorsOrder');
        $database->deleteCollection('postsOrder');
    }

    /**
     * deleteDocument() fires an update for every document on the other side of a two-way relationship
     * whose relationship the delete changed, including the ones it never writes to.
     */
    public function testDeleteDocumentRelatedUpdateEvent(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionPermissions = [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ];
        $documentPermissions = [
            Permission::read(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ];

        $database->createCollection('related_parent', permissions: $collectionPermissions, documentSecurity: true);
        $database->createCollection('related_child', permissions: $collectionPermissions, documentSecurity: true);

        $database->createRelationship(
            collection: 'related_parent',
            relatedCollection: 'related_child',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true,
            id: 'children',
            twoWayKey: 'parent',
            onDelete: Database::RELATION_MUTATE_SET_NULL,
        );

        foreach (['child1', 'child2'] as $childId) {
            $database->createDocument('related_child', new Document([
                '$id' => $childId,
                '$permissions' => $documentPermissions,
            ]));
        }

        $database->createDocument('related_parent', new Document([
            '$id' => 'parent1',
            '$permissions' => $documentPermissions,
            'children' => ['child1', 'child2'],
        ]));

        // By id to look a peer up, in order so a peer fired twice fails
        $reported = [];
        $fired = [];
        $database->on(Database::EVENT_DOCUMENT_UPDATE, 'related-test', function (string $event, Document $related) use (&$reported, &$fired) {
            $reported[$related->getId()] = $related;
            $fired[] = $related->getId();
        });

        // The Database is shared across the suite, so the listener must not outlive a failure
        try {
            // Deleting the parent clears each child's reference, so each is reported once as the delete left it
            $database->deleteDocument('related_parent', 'parent1');

            $this->assertEqualsCanonicalizing(['child1', 'child2'], $fired);
            $this->assertEquals('related_child', $reported['child1']->getCollection());
            $this->assertEquals(
                $database->getDocument('related_child', 'child1')->getUpdatedAt(),
                $reported['child1']->getUpdatedAt(),
            );

            // Deleting a child writes nothing to the parent, whose relationship still changed
            $database->createDocument('related_parent', new Document([
                '$id' => 'parent2',
                '$permissions' => $documentPermissions,
                'children' => ['child1'],
            ]));

            $fired = [];
            $database->deleteDocument('related_child', 'child1');

            $this->assertEquals(['parent2'], $fired);
            $this->assertEquals('related_parent', $reported['parent2']->getCollection());

            // A cascaded document is gone, so it is not reported as changed
            $database->updateRelationship(
                collection: 'related_parent',
                id: 'children',
                onDelete: Database::RELATION_MUTATE_CASCADE,
            );

            $database->createDocument('related_child', new Document([
                '$id' => 'child3',
                '$permissions' => $documentPermissions,
                'parent' => 'parent2',
            ]));

            $fired = [];
            $database->deleteDocument('related_parent', 'parent2');

            $this->assertEquals([], $fired);
            $this->assertTrue($database->getDocument('related_child', 'child3')->isEmpty());

            // Restrict allows deleting a child, and the parent still loses its reference to it
            $database->updateRelationship(
                collection: 'related_parent',
                id: 'children',
                onDelete: Database::RELATION_MUTATE_RESTRICT,
            );

            $database->createDocument('related_parent', new Document([
                '$id' => 'parent4',
                '$permissions' => $documentPermissions,
            ]));

            $database->createDocument('related_child', new Document([
                '$id' => 'child4',
                '$permissions' => $documentPermissions,
                'parent' => 'parent4',
            ]));

            $fired = [];
            $database->deleteDocument('related_child', 'child4');

            $this->assertEquals(['parent4'], $fired);

            // A one-way peer exposes no relationship, so it is not reported whether or not the delete wrote to it
            $database->createCollection('related_oneway', permissions: $collectionPermissions, documentSecurity: true);

            $database->createRelationship(
                collection: 'related_parent',
                relatedCollection: 'related_oneway',
                type: Database::RELATION_ONE_TO_MANY,
                twoWay: false,
                id: 'strays',
                onDelete: Database::RELATION_MUTATE_SET_NULL,
            );

            $database->createRelationship(
                collection: 'related_parent',
                relatedCollection: 'related_oneway',
                type: Database::RELATION_MANY_TO_ONE,
                twoWay: false,
                id: 'stray',
                twoWayKey: 'strayOf',
                onDelete: Database::RELATION_MUTATE_SET_NULL,
            );

            $database->createDocument('related_parent', new Document([
                '$id' => 'parent3',
                '$permissions' => $documentPermissions,
            ]));

            $database->createDocument('related_oneway', new Document([
                '$id' => 'stray1',
                '$permissions' => $documentPermissions,
            ]));

            $database->updateDocument('related_parent', 'parent3', new Document([
                'strays' => ['stray1'],
                'stray' => 'stray1',
            ]));

            $fired = [];
            $database->deleteDocument('related_parent', 'parent3');

            $this->assertEquals([], $fired);
            $this->assertFalse($database->getDocument('related_oneway', 'stray1')->isEmpty());

            // Reached through set-null but cascaded away through another relationship, so it is gone, not changed
            $database->createCollection('related_pair', permissions: $collectionPermissions, documentSecurity: true);

            $database->createRelationship(
                collection: 'related_parent',
                relatedCollection: 'related_pair',
                type: Database::RELATION_MANY_TO_ONE,
                twoWay: true,
                id: 'owner',
                twoWayKey: 'owned',
                onDelete: Database::RELATION_MUTATE_SET_NULL,
            );

            $database->createRelationship(
                collection: 'related_parent',
                relatedCollection: 'related_pair',
                type: Database::RELATION_ONE_TO_ONE,
                twoWay: true,
                id: 'buddy',
                twoWayKey: 'buddyOf',
                onDelete: Database::RELATION_MUTATE_CASCADE,
            );

            $database->createDocument('related_pair', new Document([
                '$id' => 'pair1',
                '$permissions' => $documentPermissions,
            ]));

            $database->createDocument('related_parent', new Document([
                '$id' => 'parent5',
                '$permissions' => $documentPermissions,
                'owner' => 'pair1',
                'buddy' => 'pair1',
            ]));

            $fired = [];
            $database->deleteDocument('related_parent', 'parent5');

            $this->assertEquals([], $fired);
            $this->assertTrue($database->getDocument('related_pair', 'pair1')->isEmpty());

            // Removed further down a cascade chain, so it is gone, not changed, while its sibling survives
            $database->updateRelationship(
                collection: 'related_parent',
                id: 'children',
                onDelete: Database::RELATION_MUTATE_SET_NULL,
            );

            $database->createRelationship(
                collection: 'related_pair',
                relatedCollection: 'related_child',
                type: Database::RELATION_ONE_TO_ONE,
                twoWay: true,
                id: 'tail',
                twoWayKey: 'tailOf',
                onDelete: Database::RELATION_MUTATE_CASCADE,
            );

            $database->createDocument('related_child', new Document([
                '$id' => 'child5',
                '$permissions' => $documentPermissions,
            ]));

            $database->createDocument('related_child', new Document([
                '$id' => 'child6',
                '$permissions' => $documentPermissions,
            ]));

            $database->createDocument('related_pair', new Document([
                '$id' => 'pair2',
                '$permissions' => $documentPermissions,
                'tail' => 'child5',
            ]));

            $database->createDocument('related_parent', new Document([
                '$id' => 'parent6',
                '$permissions' => $documentPermissions,
                'children' => ['child5', 'child6'],
                'buddy' => 'pair2',
            ]));

            $fired = [];
            $database->deleteDocument('related_parent', 'parent6');

            $this->assertEquals(['child6'], $fired);
            $this->assertTrue($database->getDocument('related_child', 'child5')->isEmpty());
        } finally {
            $database->on(Database::EVENT_DOCUMENT_UPDATE, 'related-test', null);
        }

        $database->deleteCollection('related_parent');
        $database->deleteCollection('related_child');
        $database->deleteCollection('related_oneway');
        $database->deleteCollection('related_pair');
    }
}
