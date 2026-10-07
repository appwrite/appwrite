<?php

namespace Tests\E2E\Adapter\Scopes\Relationships;

use Exception;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\Restricted as RestrictedException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

trait OneToOneTests
{
    public function testOneToOneOneWayRelationship(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('person');
        $database->createCollection('library');

        $database->createAttribute('person', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('library', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('library', 'area', Database::VAR_STRING, 255, true);

        $database->createRelationship(
            collection: 'person',
            relatedCollection: 'library',
            type: Database::RELATION_ONE_TO_ONE
        );

        // Check metadata for collection
        $collection = $database->getCollection('person');
        $attributes = $collection->getAttribute('attributes', []);

        foreach ($attributes as $attribute) {
            if ($attribute['key'] === 'library') {
                $this->assertEquals('relationship', $attribute['type']);
                $this->assertEquals('library', $attribute['$id']);
                $this->assertEquals('library', $attribute['key']);
                $this->assertEquals('library', $attribute['options']['relatedCollection']);
                $this->assertEquals(Database::RELATION_ONE_TO_ONE, $attribute['options']['relationType']);
                $this->assertEquals(false, $attribute['options']['twoWay']);
                $this->assertEquals('person', $attribute['options']['twoWayKey']);
            }
        }

        try {
            $database->deleteAttribute('person', 'library');
            $this->fail('Failed to throw Exception');
        } catch (Exception $e) {
            $this->assertEquals('Cannot delete relationship as an attribute', $e->getMessage());
        }

        // Create document with relationship with nested data
        $person1 = $database->createDocument('person', new Document([
            '$id' => 'person1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Person 1',
            'library' => [
                '$id' => 'library1',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'name' => 'Library 1',
                'area' => 'Area 1',
            ],
        ]));

        // Update a document with non existing related document. It should not get added to the list.
        $database->updateDocument(
            'person',
            'person1',
            $person1->setAttribute('library', 'no-library')
        );

        $person1Document = $database->getDocument('person', 'person1');
        // Assert document does not contain non existing relation document.
        $this->assertEquals(null, $person1Document->getAttribute('library'));

        $database->updateDocument(
            'person',
            'person1',
            $person1->setAttribute('library', 'library1')
        );

        // Update through create
        $library10 = $database->createDocument('library', new Document([
            '$id' => 'library10',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'name' => 'Library 10',
            'area' => 'Area 10',
        ]));
        $person10 = $database->createDocument('person', new Document([
            '$id' => 'person10',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Person 10',
            'library' => [
                '$id' => $library10->getId(),
                'name' => 'Library 10 Updated',
                'area' => 'Area 10 Updated',
            ],
        ]));
        $this->assertEquals('Library 10 Updated', $person10->getAttribute('library')->getAttribute('name'));
        $library10 = $database->getDocument('library', $library10->getId());
        $this->assertEquals('Library 10 Updated', $library10->getAttribute('name'));

        // Create document with relationship with related ID
        $database->createDocument('library', new Document([
            '$id' => 'library2',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Library 2',
            'area' => 'Area 2',
        ]));
        $database->createDocument('person', new Document([
            '$id' => 'person2',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Person 2',
            'library' => 'library2',
        ]));

        // Get documents with relationship
        $person1 = $database->getDocument('person', 'person1');
        $library = $person1->getAttribute('library');
        $this->assertEquals('library1', $library['$id']);
        $this->assertArrayNotHasKey('person', $library);

        $person = $database->getDocument('person', 'person2');
        $library = $person->getAttribute('library');
        $this->assertEquals('library2', $library['$id']);
        $this->assertArrayNotHasKey('person', $library);

        // Get related documents
        $library = $database->getDocument('library', 'library1');
        $this->assertArrayNotHasKey('person', $library);

        $library = $database->getDocument('library', 'library2');
        $this->assertArrayNotHasKey('person', $library);

        $people = $database->find('person', [
            Query::select(['name'])
        ]);

        $this->assertArrayNotHasKey('library', $people[0]);

        $people = $database->find('person');
        $this->assertEquals(3, \count($people));

        // Select related document attributes
        $person = $database->findOne('person', [
            Query::select(['*', 'library.name'])
        ]);

        if ($person->isEmpty()) {
            throw new Exception('Person not found');
        }

        $this->assertEquals('Library 1', $person->getAttribute('library')->getAttribute('name'));
        $this->assertArrayNotHasKey('area', $person->getAttribute('library'));

        $person = $database->getDocument('person', 'person1', [
            Query::select(['*', 'library.name', '$id'])
        ]);

        $this->assertEquals('Library 1', $person->getAttribute('library')->getAttribute('name'));
        $this->assertArrayNotHasKey('area', $person->getAttribute('library'));



        $document = $database->getDocument('person', $person->getId(), [
            Query::select(['name']),
        ]);
        $this->assertArrayNotHasKey('library', $document);
        $this->assertEquals('Person 1', $document['name']);

        $document = $database->getDocument('person', $person->getId(), [
            Query::select(['*']),
        ]);
        $this->assertEquals('library1', $document['library']);

        $document = $database->getDocument('person', $person->getId(), [
            Query::select(['library.*']),
        ]);
        $this->assertEquals('Library 1', $document['library']['name']);
        $this->assertArrayNotHasKey('name', $document);

        // Update root document attribute without altering relationship
        $person1 = $database->updateDocument(
            'person',
            $person1->getId(),
            $person1->setAttribute('name', 'Person 1 Updated')
        );

        $this->assertEquals('Person 1 Updated', $person1->getAttribute('name'));
        $person1 = $database->getDocument('person', 'person1');
        $this->assertEquals('Person 1 Updated', $person1->getAttribute('name'));

        // Update nested document attribute
        $person1 = $database->updateDocument(
            'person',
            $person1->getId(),
            $person1->setAttribute(
                'library',
                $person1
                    ->getAttribute('library')
                    ->setAttribute('name', 'Library 1 Updated')
            )
        );

        $this->assertEquals('Library 1 Updated', $person1->getAttribute('library')->getAttribute('name'));
        $person1 = $database->getDocument('person', 'person1');
        $this->assertEquals('Library 1 Updated', $person1->getAttribute('library')->getAttribute('name'));

        // Create new document with no relationship
        $person3 = $database->createDocument('person', new Document([
            '$id' => 'person3',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Person 3',
        ]));

        // Update to relate to created document
        $person3 = $database->updateDocument(
            'person',
            $person3->getId(),
            $person3->setAttribute('library', new Document([
                '$id' => 'library3',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                ],
                'name' => 'Library 3',
                'area' => 'Area 3',
            ]))
        );

        $this->assertEquals('library3', $person3->getAttribute('library')['$id']);
        $person3 = $database->getDocument('person', 'person3');
        $this->assertEquals('Library 3', $person3['library']['name']);

        $libraryDocument = $database->getDocument('library', 'library3');
        $libraryDocument->setAttribute('name', 'Library 3 updated');
        $database->updateDocument('library', 'library3', $libraryDocument);
        $libraryDocument = $database->getDocument('library', 'library3');
        $this->assertEquals('Library 3 updated', $libraryDocument['name']);

        $person3 = $database->getDocument('person', 'person3');
        // Todo: This is failing
        $this->assertEquals($libraryDocument['name'], $person3['library']['name']);
        $this->assertEquals('library3', $person3->getAttribute('library')['$id']);

        // One to one can't relate to multiple documents, unique index throws duplicate
        try {
            $database->updateDocument(
                'person',
                $person1->getId(),
                $person1->setAttribute('library', 'library2')
            );
            $this->fail('Failed to throw duplicate exception');
        } catch (Exception $e) {
            $this->assertInstanceOf(DuplicateException::class, $e);
        }

        // Create new document
        $library4 = $database->createDocument('library', new Document([
            '$id' => 'library4',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Library 4',
            'area' => 'Area 4',
        ]));

        // Relate existing document to new document
        $database->updateDocument(
            'person',
            $person1->getId(),
            $person1->setAttribute('library', 'library4')
        );

        // Relate existing document to new document as nested data
        $database->updateDocument(
            'person',
            $person1->getId(),
            $person1->setAttribute('library', $library4)
        );

        // Rename relationship key
        $database->updateRelationship(
            collection: 'person',
            id: 'library',
            newKey: 'newLibrary'
        );

        // Get document with again
        $person = $database->getDocument('person', 'person1');
        $library = $person->getAttribute('newLibrary');
        $this->assertEquals('library4', $library['$id']);

        // Create person with no relationship
        $database->createDocument('person', new Document([
            '$id' => 'person4',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Person 4',
        ]));

        // Can delete parent document with no relation with on delete set to restrict
        $deleted = $database->deleteDocument('person', 'person4');
        $this->assertEquals(true, $deleted);

        $person4 = $database->getDocument('person', 'person4');
        $this->assertEquals(true, $person4->isEmpty());

        // Cannot delete document while still related to another with on delete set to restrict
        try {
            $database->deleteDocument('person', 'person1');
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertEquals('Cannot delete document because it has at least one related document.', $e->getMessage());
        }

        // Can delete child document while still related to another with on delete set to restrict
        $person5 = $database->createDocument('person', new Document([
            '$id' => 'person5',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Person 5',
            'newLibrary' => [
                '$id' => 'library5',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'name' => 'Library 5',
                'area' => 'Area 5',
            ],
        ]));
        $deleted = $database->deleteDocument('library', 'library5');
        $this->assertEquals(true, $deleted);
        $person5 = $database->getDocument('person', 'person5');
        $this->assertEquals(null, $person5->getAttribute('newLibrary'));

        // Change on delete to set null
        $database->updateRelationship(
            collection: 'person',
            id: 'newLibrary',
            onDelete: Database::RELATION_MUTATE_SET_NULL
        );

        // Delete parent, no effect on children for one-way
        $database->deleteDocument('person', 'person1');

        // Delete child, set parent relating attribute to null for one-way
        $database->deleteDocument('library', 'library2');

        // Check relation was set to null
        $person2 = $database->getDocument('person', 'person2');
        $this->assertEquals(null, $person2->getAttribute('newLibrary', ''));

        // Relate to another document
        $database->updateDocument(
            'person',
            $person2->getId(),
            $person2->setAttribute('newLibrary', 'library4')
        );

        // Change on delete to cascade
        $database->updateRelationship(
            collection: 'person',
            id: 'newLibrary',
            onDelete: Database::RELATION_MUTATE_CASCADE
        );

        // Delete parent, will delete child
        $database->deleteDocument('person', 'person2');

        // Check parent and child were deleted
        $person = $database->getDocument('person', 'person2');
        $this->assertEquals(true, $person->isEmpty());

        $library = $database->getDocument('library', 'library4');
        $this->assertEquals(true, $library->isEmpty());

        // Delete relationship
        $database->deleteRelationship(
            'person',
            'newLibrary'
        );

        // Check parent doesn't have relationship anymore
        $person = $database->getDocument('person', 'person1');
        $library = $person->getAttribute('newLibrary', '');
        $this->assertEquals(null, $library);
    }

    /**
     * @throws AuthorizationException
     * @throws LimitException
     * @throws DuplicateException
     * @throws StructureException
     * @throws \Throwable
     */
    public function testOneToOneTwoWayRelationship(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('country');
        $database->createCollection('city');

        $database->createAttribute('country', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('city', 'code', Database::VAR_STRING, 3, true);
        $database->createAttribute('city', 'name', Database::VAR_STRING, 255, true);

        $database->createRelationship(
            collection: 'country',
            relatedCollection: 'city',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true
        );

        $collection = $database->getCollection('country');
        $attributes = $collection->getAttribute('attributes', []);
        foreach ($attributes as $attribute) {
            if ($attribute['key'] === 'city') {
                $this->assertEquals('relationship', $attribute['type']);
                $this->assertEquals('city', $attribute['$id']);
                $this->assertEquals('city', $attribute['key']);
                $this->assertEquals('city', $attribute['options']['relatedCollection']);
                $this->assertEquals(Database::RELATION_ONE_TO_ONE, $attribute['options']['relationType']);
                $this->assertEquals(true, $attribute['options']['twoWay']);
                $this->assertEquals('country', $attribute['options']['twoWayKey']);
            }
        }

        $collection = $database->getCollection('city');
        $attributes = $collection->getAttribute('attributes', []);
        foreach ($attributes as $attribute) {
            if ($attribute['key'] === 'country') {
                $this->assertEquals('relationship', $attribute['type']);
                $this->assertEquals('country', $attribute['$id']);
                $this->assertEquals('country', $attribute['key']);
                $this->assertEquals('country', $attribute['options']['relatedCollection']);
                $this->assertEquals(Database::RELATION_ONE_TO_ONE, $attribute['options']['relationType']);
                $this->assertEquals(true, $attribute['options']['twoWay']);
                $this->assertEquals('city', $attribute['options']['twoWayKey']);
            }
        }

        // Create document with relationship with nested data
        $doc = new Document([
            '$id' => 'country1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'England',
            'city' => [
                '$id' => 'city1',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'name' => 'London',
                'code' => 'LON',
            ],
        ]);

        $database->createDocument('country', new Document($doc->getArrayCopy()));
        $country1 = $database->getDocument('country', 'country1');
        $this->assertEquals('London', $country1->getAttribute('city')->getAttribute('name'));

        // Update a document with non existing related document. It should not get added to the list.
        $database->updateDocument('country', 'country1', (new Document($doc->getArrayCopy()))->setAttribute('city', 'no-city'));

        $country1Document = $database->getDocument('country', 'country1');
        // Assert document does not contain non existing relation document.
        $this->assertEquals(null, $country1Document->getAttribute('city'));
        $database->updateDocument('country', 'country1', (new Document($doc->getArrayCopy()))->setAttribute('city', 'city1'));
        try {
            $database->deleteDocument('country', 'country1');
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertInstanceOf(RestrictedException::class, $e);
        }

        $this->assertTrue($database->deleteDocument('city', 'city1'));

        $city1 = $database->getDocument('city', 'city1');
        $this->assertTrue($city1->isEmpty());

        $country1 = $database->getDocument('country', 'country1');
        $this->assertTrue($country1->getAttribute('city')->isEmpty());

        $this->assertTrue($database->deleteDocument('country', 'country1'));

        $database->createDocument('country', new Document($doc->getArrayCopy()));
        $country1 = $database->getDocument('country', 'country1');
        $this->assertEquals('London', $country1->getAttribute('city')->getAttribute('name'));

        // Create document with relationship with related ID
        $database->createDocument('city', new Document([
            '$id' => 'city2',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Paris',
            'code' => 'PAR',
        ]));
        $database->createDocument('country', new Document([
            '$id' => 'country2',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'France',
            'city' => 'city2',
        ]));

        // Create from child side
        $database->createDocument('city', new Document([
            '$id' => 'city3',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Christchurch',
            'code' => 'CHC',
            'country' => [
                '$id' => 'country3',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'name' => 'New Zealand',
            ],
        ]));
        $database->createDocument('country', new Document([
            '$id' => 'country4',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Australia',
        ]));
        $database->createDocument('city', new Document([
            '$id' => 'city4',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Sydney',
            'code' => 'SYD',
            'country' => 'country4',
        ]));

        // Get document with relationship
        $city = $database->getDocument('city', 'city1');
        $country = $city->getAttribute('country');
        $this->assertEquals('country1', $country['$id']);
        $this->assertArrayNotHasKey('city', $country);

        $city = $database->getDocument('city', 'city2');
        $country = $city->getAttribute('country');
        $this->assertEquals('country2', $country['$id']);
        $this->assertArrayNotHasKey('city', $country);

        $city = $database->getDocument('city', 'city3');
        $country = $city->getAttribute('country');
        $this->assertEquals('country3', $country['$id']);
        $this->assertArrayNotHasKey('city', $country);

        $city = $database->getDocument('city', 'city4');
        $country = $city->getAttribute('country');
        $this->assertEquals('country4', $country['$id']);
        $this->assertArrayNotHasKey('city', $country);

        // Get inverse document with relationship
        $country = $database->getDocument('country', 'country1');
        $city = $country->getAttribute('city');
        $this->assertEquals('city1', $city['$id']);
        $this->assertArrayNotHasKey('country', $city);

        $country = $database->getDocument('country', 'country2');
        $city = $country->getAttribute('city');
        $this->assertEquals('city2', $city['$id']);
        $this->assertArrayNotHasKey('country', $city);

        $country = $database->getDocument('country', 'country3');
        $city = $country->getAttribute('city');
        $this->assertEquals('city3', $city['$id']);
        $this->assertArrayNotHasKey('country', $city);

        $country = $database->getDocument('country', 'country4');
        $city = $country->getAttribute('city');
        $this->assertEquals('city4', $city['$id']);
        $this->assertArrayNotHasKey('country', $city);

        $countries = $database->find('country');

        $this->assertEquals(4, \count($countries));

        // Select related document attributes
        $country = $database->findOne('country', [
            Query::select(['*', 'city.name'])
        ]);

        if ($country->isEmpty()) {
            throw new Exception('Country not found');
        }

        $this->assertEquals('London', $country->getAttribute('city')->getAttribute('name'));
        $this->assertArrayNotHasKey('code', $country->getAttribute('city'));

        $country = $database->getDocument('country', 'country1', [
            Query::select(['*', 'city.name'])
        ]);

        $this->assertEquals('London', $country->getAttribute('city')->getAttribute('name'));
        $this->assertArrayNotHasKey('code', $country->getAttribute('city'));

        $country1 = $database->getDocument('country', 'country1');

        // Update root document attribute without altering relationship
        $country1 = $database->updateDocument(
            'country',
            $country1->getId(),
            $country1->setAttribute('name', 'Country 1 Updated')
        );

        $this->assertEquals('Country 1 Updated', $country1->getAttribute('name'));
        $country1 = $database->getDocument('country', 'country1');
        $this->assertEquals('Country 1 Updated', $country1->getAttribute('name'));

        $city2 = $database->getDocument('city', 'city2');

        // Update inverse root document attribute without altering relationship
        $city2 = $database->updateDocument(
            'city',
            $city2->getId(),
            $city2->setAttribute('name', 'City 2 Updated')
        );

        $this->assertEquals('City 2 Updated', $city2->getAttribute('name'));
        $city2 = $database->getDocument('city', 'city2');
        $this->assertEquals('City 2 Updated', $city2->getAttribute('name'));

        // Update nested document attribute
        $country1 = $database->updateDocument(
            'country',
            $country1->getId(),
            $country1->setAttribute(
                'city',
                $country1
                    ->getAttribute('city')
                    ->setAttribute('name', 'City 1 Updated')
            )
        );

        $this->assertEquals('City 1 Updated', $country1->getAttribute('city')->getAttribute('name'));
        $country1 = $database->getDocument('country', 'country1');
        $this->assertEquals('City 1 Updated', $country1->getAttribute('city')->getAttribute('name'));

        // Update inverse nested document attribute
        $city2 = $database->updateDocument(
            'city',
            $city2->getId(),
            $city2->setAttribute(
                'country',
                $city2
                    ->getAttribute('country')
                    ->setAttribute('name', 'Country 2 Updated')
            )
        );

        $this->assertEquals('Country 2 Updated', $city2->getAttribute('country')->getAttribute('name'));
        $city2 = $database->getDocument('city', 'city2');
        $this->assertEquals('Country 2 Updated', $city2->getAttribute('country')->getAttribute('name'));

        // Create new document with no relationship
        $country5 = $database->createDocument('country', new Document([
            '$id' => 'country5',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Country 5',
        ]));

        // Update to relate to created document
        $country5 = $database->updateDocument(
            'country',
            $country5->getId(),
            $country5->setAttribute('city', new Document([
                '$id' => 'city5',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                ],
                'name' => 'City 5',
                'code' => 'C5',
            ]))
        );

        $this->assertEquals('city5', $country5->getAttribute('city')['$id']);
        $country5 = $database->getDocument('country', 'country5');
        $this->assertEquals('city5', $country5->getAttribute('city')['$id']);

        // Create new document with no relationship
        $city6 = $database->createDocument('city', new Document([
            '$id' => 'city6',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'City6',
            'code' => 'C6',
        ]));

        // Update to relate to created document
        $city6 = $database->updateDocument(
            'city',
            $city6->getId(),
            $city6->setAttribute('country', new Document([
                '$id' => 'country6',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                ],
                'name' => 'Country 6',
            ]))
        );

        $this->assertEquals('country6', $city6->getAttribute('country')['$id']);
        $city6 = $database->getDocument('city', 'city6');
        $this->assertEquals('country6', $city6->getAttribute('country')['$id']);

        // One to one can't relate to multiple documents, unique index throws duplicate
        try {
            $database->updateDocument(
                'country',
                $country1->getId(),
                $country1->setAttribute('city', 'city2')
            );
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertInstanceOf(DuplicateException::class, $e);
        }

        $city1 = $database->getDocument('city', 'city1');

        // Set relationship to null
        $city1 = $database->updateDocument(
            'city',
            $city1->getId(),
            $city1->setAttribute('country', null)
        );

        $this->assertEquals(null, $city1->getAttribute('country'));
        $city1 = $database->getDocument('city', 'city1');
        $this->assertEquals(null, $city1->getAttribute('country'));

        // Create a new city with no relation
        $city7 = $database->createDocument('city', new Document([
            '$id' => 'city7',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Copenhagen',
            'code' => 'CPH',
        ]));

        // Update document with relation to new document
        $database->updateDocument(
            'country',
            $country1->getId(),
            $country1->setAttribute('city', 'city7')
        );

        // Relate existing document to new document as nested data
        $database->updateDocument(
            'country',
            $country1->getId(),
            $country1->setAttribute('city', $city7)
        );

        // Create a new country with no relation
        $database->createDocument('country', new Document([
            '$id' => 'country7',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Denmark'
        ]));

        // Update inverse document with new related document
        $database->updateDocument(
            'city',
            $city1->getId(),
            $city1->setAttribute('country', 'country7')
        );

        // Rename relationship keys on both sides
        $database->updateRelationship(
            'country',
            'city',
            'newCity',
            'newCountry'
        );

        // Get document with new relationship key
        $city = $database->getDocument('city', 'city1');
        $country = $city->getAttribute('newCountry');
        $this->assertEquals('country7', $country['$id']);

        // Get inverse document with new relationship key
        $country = $database->getDocument('country', 'country7');
        $city = $country->getAttribute('newCity');
        $this->assertEquals('city1', $city['$id']);

        // Create a new country with no relation
        $database->createDocument('country', new Document([
            '$id' => 'country8',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Denmark'
        ]));

        // Can delete parent document with no relation with on delete set to restrict
        $deleted = $database->deleteDocument('country', 'country8');
        $this->assertEquals(1, $deleted);

        $country8 = $database->getDocument('country', 'country8');
        $this->assertEquals(true, $country8->isEmpty());


        // Cannot delete document while still related to another with on delete set to restrict
        try {
            $database->deleteDocument('country', 'country1');
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertEquals('Cannot delete document because it has at least one related document.', $e->getMessage());
        }

        // Change on delete to set null
        $database->updateRelationship(
            collection: 'country',
            id: 'newCity',
            onDelete: Database::RELATION_MUTATE_SET_NULL
        );

        $database->updateDocument('city', 'city1', new Document(['newCountry' => null, '$id' => 'city1']));
        $city1 = $database->getDocument('city', 'city1');
        $this->assertNull($city1->getAttribute('newCountry'));

        // Check Delete TwoWay TRUE && RELATION_MUTATE_SET_NULL && related value NULL
        $this->assertTrue($database->deleteDocument('city', 'city1'));
        $city1 = $database->getDocument('city', 'city1');
        $this->assertTrue($city1->isEmpty());

        // Delete parent, will set child relationship to null for two-way
        $database->deleteDocument('country', 'country1');

        // Check relation was set to null
        $city7 = $database->getDocument('city', 'city7');
        $this->assertEquals(null, $city7->getAttribute('country', ''));

        // Delete child, set parent relationship to null for two-way
        $database->deleteDocument('city', 'city2');

        // Check relation was set to null
        $country2 = $database->getDocument('country', 'country2');
        $this->assertEquals(null, $country2->getAttribute('city', ''));

        // Relate again
        $database->updateDocument(
            'city',
            $city7->getId(),
            $city7->setAttribute('newCountry', 'country2')
        );

        // Change on delete to cascade
        $database->updateRelationship(
            collection: 'country',
            id: 'newCity',
            onDelete: Database::RELATION_MUTATE_CASCADE
        );

        // Delete parent, will delete child
        $database->deleteDocument('country', 'country7');

        // Check parent and child were deleted
        $library = $database->getDocument('country', 'country7');
        $this->assertEquals(true, $library->isEmpty());

        $library = $database->getDocument('city', 'city1');
        $this->assertEquals(true, $library->isEmpty());

        // Delete child, will delete parent for two-way
        $database->deleteDocument('city', 'city7');

        // Check parent and child were deleted
        $library = $database->getDocument('city', 'city7');
        $this->assertEquals(true, $library->isEmpty());

        $library = $database->getDocument('country', 'country2');
        $this->assertEquals(true, $library->isEmpty());

        // Create new document to check after deleting relationship
        $database->createDocument('city', new Document([
            '$id' => 'city7',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Munich',
            'code' => 'MUC',
            'newCountry' => [
                '$id' => 'country7',
                'name' => 'Germany'
            ]
        ]));

        // Delete relationship
        $database->deleteRelationship(
            'country',
            'newCity'
        );

        // Try to get document again
        $country = $database->getDocument('country', 'country4');
        $city = $country->getAttribute('newCity');
        $this->assertEquals(null, $city);

        // Try to get inverse document again
        $city = $database->getDocument('city', 'city7');
        $country = $city->getAttribute('newCountry');
        $this->assertEquals(null, $country);
    }

    public function testIdenticalTwoWayKeyRelationship(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('parent');
        $database->createCollection('child');

        $database->createRelationship(
            collection: 'parent',
            relatedCollection: 'child',
            type: Database::RELATION_ONE_TO_ONE,
            id: 'child1'
        );

        try {
            $database->createRelationship(
                collection: 'parent',
                relatedCollection: 'child',
                type: Database::RELATION_ONE_TO_MANY,
                id: 'children',
            );
            $this->fail('Failed to throw Exception');
        } catch (Exception $e) {
            $this->assertEquals('Related attribute already exists', $e->getMessage());
        }

        $database->createRelationship(
            collection: 'parent',
            relatedCollection: 'child',
            type: Database::RELATION_ONE_TO_MANY,
            id: 'children',
            twoWayKey: 'parent_id'
        );

        $collection = $database->getCollection('parent');
        $attributes = $collection->getAttribute('attributes', []);
        foreach ($attributes as $attribute) {
            if ($attribute['key'] === 'child1') {
                $this->assertEquals('parent', $attribute['options']['twoWayKey']);
            }

            if ($attribute['key'] === 'children') {
                $this->assertEquals('parent_id', $attribute['options']['twoWayKey']);
            }
        }

        $database->createDocument('parent', new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'child1' => [
                '$id' => 'foo',
                '$permissions' => [Permission::read(Role::any())],
            ],
            'children' => [
                [
                    '$id' => 'bar',
                    '$permissions' => [Permission::read(Role::any())],
                ],
            ],
        ]));

        $documents = $database->find('parent', []);
        $document  = array_pop($documents);
        $this->assertArrayHasKey('child1', $document);
        $this->assertEquals('foo', $document->getAttribute('child1')->getId());
        $this->assertArrayHasKey('children', $document);
        $this->assertEquals('bar', $document->getAttribute('children')[0]->getId());

        try {
            $database->updateRelationship(
                collection: 'parent',
                id: 'children',
                newKey: 'child1'
            );
            $this->fail('Failed to throw Exception');
        } catch (Exception $e) {
            $this->assertEquals('Relationship already exists', $e->getMessage());
        }

        try {
            $database->updateRelationship(
                collection: 'parent',
                id: 'children',
                newTwoWayKey: 'parent'
            );
            $this->fail('Failed to throw Exception');
        } catch (Exception $e) {
            $this->assertEquals('Related attribute already exists', $e->getMessage());
        }
    }

    public function testNestedOneToOne_OneToOneRelationship(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('pattern');
        $database->createCollection('shirt');
        $database->createCollection('team');

        $database->createAttribute('pattern', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('shirt', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('team', 'name', Database::VAR_STRING, 255, true);

        $database->createRelationship(
            collection: 'pattern',
            relatedCollection: 'shirt',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
            id: 'shirt',
            twoWayKey: 'pattern'
        );
        $database->createRelationship(
            collection: 'shirt',
            relatedCollection: 'team',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
            id: 'team',
            twoWayKey: 'shirt'
        );

        $database->createDocument('pattern', new Document([
            '$id' => 'stripes',
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'name' => 'Stripes',
            'shirt' => [
                '$id' => 'red',
                '$permissions' => [
                    Permission::read(Role::any()),
                ],
                'name' => 'Red',
                'team' => [
                    '$id' => 'reds',
                    '$permissions' => [
                        Permission::read(Role::any()),
                    ],
                    'name' => 'Reds',
                ],
            ],
        ]));

        $pattern = $database->getDocument('pattern', 'stripes');
        $this->assertEquals('red', $pattern['shirt']['$id']);
        $this->assertArrayNotHasKey('pattern', $pattern['shirt']);
        $this->assertEquals('reds', $pattern['shirt']['team']['$id']);
        $this->assertArrayNotHasKey('shirt', $pattern['shirt']['team']);

        $database->createDocument('team', new Document([
            '$id' => 'blues',
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'name' => 'Blues',
            'shirt' => [
                '$id' => 'blue',
                '$permissions' => [
                    Permission::read(Role::any()),
                ],
                'name' => 'Blue',
                'pattern' => [
                    '$id' => 'plain',
                    '$permissions' => [
                        Permission::read(Role::any()),
                    ],
                    'name' => 'Plain',
                ],
            ],
        ]));

        $team = $database->getDocument('team', 'blues');
        $this->assertEquals('blue', $team['shirt']['$id']);
        $this->assertArrayNotHasKey('team', $team['shirt']);
        $this->assertEquals('plain', $team['shirt']['pattern']['$id']);
        $this->assertArrayNotHasKey('shirt', $team['shirt']['pattern']);
    }

    public function testNestedOneToOne_OneToManyRelationship(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('teachers');
        $database->createCollection('classrooms');
        $database->createCollection('children');

        $database->createAttribute('children', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('teachers', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('classrooms', 'name', Database::VAR_STRING, 255, true);

        $database->createRelationship(
            collection: 'teachers',
            relatedCollection: 'classrooms',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
            id: 'classroom',
            twoWayKey: 'teacher'
        );
        $database->createRelationship(
            collection: 'classrooms',
            relatedCollection: 'children',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true,
            twoWayKey: 'classroom'
        );

        $database->createDocument('teachers', new Document([
            '$id' => 'teacher1',
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'name' => 'Teacher 1',
            'classroom' => [
                '$id' => 'classroom1',
                '$permissions' => [
                    Permission::read(Role::any()),
                ],
                'name' => 'Classroom 1',
                'children' => [
                    [
                        '$id' => 'child1',
                        '$permissions' => [
                            Permission::read(Role::any()),
                        ],
                        'name' => 'Child 1',
                    ],
                    [
                        '$id' => 'child2',
                        '$permissions' => [
                            Permission::read(Role::any()),
                        ],
                        'name' => 'Child 2',
                    ],
                ],
            ],
        ]));

        $teacher1 = $database->getDocument('teachers', 'teacher1');
        $this->assertEquals('classroom1', $teacher1['classroom']['$id']);
        $this->assertArrayNotHasKey('teacher', $teacher1['classroom']);
        $this->assertEquals(2, \count($teacher1['classroom']['children']));
        $this->assertEquals('Child 1', $teacher1['classroom']['children'][0]['name']);
        $this->assertEquals('Child 2', $teacher1['classroom']['children'][1]['name']);

        $database->createDocument('children', new Document([
            '$id' => 'child3',
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'name' => 'Child 3',
            'classroom' => [
                '$id' => 'classroom2',
                '$permissions' => [
                    Permission::read(Role::any()),
                ],
                'name' => 'Classroom 2',
                'teacher' => [
                    '$id' => 'teacher2',
                    '$permissions' => [
                        Permission::read(Role::any()),
                    ],
                    'name' => 'Teacher 2',
                ],
            ],
        ]));

        $child3 = $database->getDocument('children', 'child3');
        $this->assertEquals('classroom2', $child3['classroom']['$id']);
        $this->assertArrayNotHasKey('children', $child3['classroom']);
        $this->assertEquals('teacher2', $child3['classroom']['teacher']['$id']);
        $this->assertArrayNotHasKey('classroom', $child3['classroom']['teacher']);
    }

    public function testNestedOneToOne_ManyToOneRelationship(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('users');
        $database->createCollection('profiles');
        $database->createCollection('avatars');

        $database->createAttribute('users', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('profiles', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('avatars', 'name', Database::VAR_STRING, 255, true);

        $database->createRelationship(
            collection: 'users',
            relatedCollection: 'profiles',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
            id: 'profile',
            twoWayKey: 'user'
        );
        $database->createRelationship(
            collection: 'profiles',
            relatedCollection: 'avatars',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
            id: 'avatar',
        );

        $database->createDocument('users', new Document([
            '$id' => 'user1',
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'name' => 'User 1',
            'profile' => [
                '$id' => 'profile1',
                '$permissions' => [
                    Permission::read(Role::any()),
                ],
                'name' => 'Profile 1',
                'avatar' => [
                    '$id' => 'avatar1',
                    '$permissions' => [
                        Permission::read(Role::any()),
                    ],
                    'name' => 'Avatar 1',
                ],
            ],
        ]));

        $user1 = $database->getDocument('users', 'user1');
        $this->assertEquals('profile1', $user1['profile']['$id']);
        $this->assertArrayNotHasKey('user', $user1['profile']);
        $this->assertEquals('avatar1', $user1['profile']['avatar']['$id']);
        $this->assertArrayNotHasKey('profile', $user1['profile']['avatar']);

        $database->createDocument('avatars', new Document([
            '$id' => 'avatar2',
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'name' => 'Avatar 2',
            'profiles' => [
                [
                    '$id' => 'profile2',
                    '$permissions' => [
                        Permission::read(Role::any()),
                    ],
                    'name' => 'Profile 2',
                    'user' => [
                        '$id' => 'user2',
                        '$permissions' => [
                            Permission::read(Role::any()),
                        ],
                        'name' => 'User 2',
                    ],
                ]
            ],
        ]));

        $avatar2 = $database->getDocument('avatars', 'avatar2');
        $this->assertEquals('profile2', $avatar2['profiles'][0]['$id']);
        $this->assertArrayNotHasKey('avatars', $avatar2['profiles'][0]);
        $this->assertEquals('user2', $avatar2['profiles'][0]['user']['$id']);
        $this->assertArrayNotHasKey('profiles', $avatar2['profiles'][0]['user']);
    }

    public function testNestedOneToOne_ManyToManyRelationship(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('addresses');
        $database->createCollection('houses');
        $database->createCollection('buildings');

        $database->createAttribute('addresses', 'street', Database::VAR_STRING, 255, true);
        $database->createAttribute('houses', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('buildings', 'name', Database::VAR_STRING, 255, true);

        $database->createRelationship(
            collection: 'addresses',
            relatedCollection: 'houses',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
            id: 'house',
            twoWayKey: 'address'
        );
        $database->createRelationship(
            collection: 'houses',
            relatedCollection: 'buildings',
            type: Database::RELATION_MANY_TO_MANY,
            twoWay: true,
        );

        $database->createDocument('addresses', new Document([
            '$id' => 'address1',
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'street' => 'Street 1',
            'house' => [
                '$id' => 'house1',
                '$permissions' => [
                    Permission::read(Role::any()),
                ],
                'name' => 'House 1',
                'buildings' => [
                    [
                        '$id' => 'building1',
                        '$permissions' => [
                            Permission::read(Role::any()),
                        ],
                        'name' => 'Building 1',
                    ],
                    [
                        '$id' => 'building2',
                        '$permissions' => [
                            Permission::read(Role::any()),
                        ],
                        'name' => 'Building 2',
                    ],
                ],
            ],
        ]));

        $address1 = $database->getDocument('addresses', 'address1');
        $this->assertEquals('house1', $address1['house']['$id']);
        $this->assertArrayNotHasKey('address', $address1['house']);
        $this->assertEquals('building1', $address1['house']['buildings'][0]['$id']);
        $this->assertEquals('building2', $address1['house']['buildings'][1]['$id']);
        $this->assertArrayNotHasKey('houses', $address1['house']['buildings'][0]);
        $this->assertArrayNotHasKey('houses', $address1['house']['buildings'][1]);

        $database->createDocument('buildings', new Document([
            '$id' => 'building3',
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'name' => 'Building 3',
            'houses' => [
                [
                    '$id' => 'house2',
                    '$permissions' => [
                        Permission::read(Role::any()),
                    ],
                    'name' => 'House 2',
                    'address' => [
                        '$id' => 'address2',
                        '$permissions' => [
                            Permission::read(Role::any()),
                        ],
                        'street' => 'Street 2',
                    ],
                ],
            ],
        ]));
    }

    public function testExceedMaxDepthOneToOne(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $level1Collection = 'level1OneToOne';
        $level2Collection = 'level2OneToOne';
        $level3Collection = 'level3OneToOne';
        $level4Collection = 'level4OneToOne';

        $database->createCollection($level1Collection);
        $database->createCollection($level2Collection);
        $database->createCollection($level3Collection);
        $database->createCollection($level4Collection);

        $database->createRelationship(
            collection: $level1Collection,
            relatedCollection: $level2Collection,
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
        );
        $database->createRelationship(
            collection: $level2Collection,
            relatedCollection: $level3Collection,
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
        );
        $database->createRelationship(
            collection: $level3Collection,
            relatedCollection: $level4Collection,
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
        );

        // Exceed create depth
        $level1 = $database->createDocument($level1Collection, new Document([
            '$id' => 'level1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            $level2Collection => [
                '$id' => 'level2',
                $level3Collection => [
                    '$id' => 'level3',
                    $level4Collection => [
                        '$id' => 'level4',
                    ],
                ],
            ],
        ]));
        $this->assertArrayHasKey($level2Collection, $level1);
        $this->assertEquals('level2', $level1[$level2Collection]->getId());
        $this->assertArrayHasKey($level3Collection, $level1[$level2Collection]);
        $this->assertEquals('level3', $level1[$level2Collection][$level3Collection]->getId());
        $this->assertArrayNotHasKey($level4Collection, $level1[$level2Collection][$level3Collection]);

        // Confirm the 4th level document does not exist
        $level3 = $database->getDocument($level3Collection, 'level3');
        $this->assertNull($level3[$level4Collection]);

        // Create level 4 document
        $level3->setAttribute($level4Collection, new Document([
            '$id' => 'level4',
        ]));
        $level3 = $database->updateDocument($level3Collection, $level3->getId(), $level3);
        $this->assertEquals('level4', $level3[$level4Collection]->getId());

        // Exceed fetch depth
        $level1 = $database->getDocument($level1Collection, 'level1');
        $this->assertArrayHasKey($level2Collection, $level1);
        $this->assertEquals('level2', $level1[$level2Collection]->getId());
        $this->assertArrayHasKey($level3Collection, $level1[$level2Collection]);
        $this->assertEquals('level3', $level1[$level2Collection][$level3Collection]->getId());
        $this->assertArrayNotHasKey($level4Collection, $level1[$level2Collection][$level3Collection]);
    }

    public function testExceedMaxDepthOneToOneNull(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $level1Collection = 'level1OneToOneNull';
        $level2Collection = 'level2OneToOneNull';
        $level3Collection = 'level3OneToOneNull';
        $level4Collection = 'level4OneToOneNull';

        $database->createCollection($level1Collection);
        $database->createCollection($level2Collection);
        $database->createCollection($level3Collection);
        $database->createCollection($level4Collection);

        $database->createRelationship(
            collection: $level1Collection,
            relatedCollection: $level2Collection,
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
        );
        $database->createRelationship(
            collection: $level2Collection,
            relatedCollection: $level3Collection,
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
        );
        $database->createRelationship(
            collection: $level3Collection,
            relatedCollection: $level4Collection,
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
        );

        $level1 = $database->createDocument($level1Collection, new Document([
            '$id' => 'level1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            $level2Collection => [
                '$id' => 'level2',
                $level3Collection => [
                    '$id' => 'level3',
                    $level4Collection => [
                        '$id' => 'level4',
                    ],
                ],
            ],
        ]));
        $this->assertArrayHasKey($level2Collection, $level1);
        $this->assertEquals('level2', $level1[$level2Collection]->getId());
        $this->assertArrayHasKey($level3Collection, $level1[$level2Collection]);
        $this->assertEquals('level3', $level1[$level2Collection][$level3Collection]->getId());
        $this->assertArrayNotHasKey($level4Collection, $level1[$level2Collection][$level3Collection]);

        // Confirm the 4th level document does not exist
        $level3 = $database->getDocument($level3Collection, 'level3');
        $this->assertNull($level3[$level4Collection]);

        // Create level 4 document
        $level3->setAttribute($level4Collection, new Document([
            '$id' => 'level4',
        ]));
        $level3 = $database->updateDocument($level3Collection, $level3->getId(), $level3);
        $this->assertEquals('level4', $level3[$level4Collection]->getId());
        $level3 = $database->getDocument($level3Collection, 'level3');
        $this->assertEquals('level4', $level3[$level4Collection]->getId());

        // Exceed fetch depth
        $level1 = $database->getDocument($level1Collection, 'level1');
        $this->assertArrayHasKey($level2Collection, $level1);
        $this->assertEquals('level2', $level1[$level2Collection]->getId());
        $this->assertArrayHasKey($level3Collection, $level1[$level2Collection]);
        $this->assertEquals('level3', $level1[$level2Collection][$level3Collection]->getId());
        $this->assertArrayNotHasKey($level4Collection, $level1[$level2Collection][$level3Collection]);
    }

    public function testOneToOneRelationshipKeyWithSymbols(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('$symbols_coll.ection1');
        $database->createCollection('$symbols_coll.ection2');

        $database->createRelationship(
            collection: '$symbols_coll.ection1',
            relatedCollection: '$symbols_coll.ection2',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
        );

        $doc1 = $database->createDocument('$symbols_coll.ection2', new Document([
            '$id' => ID::unique(),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any())
            ]
        ]));
        $doc2 = $database->createDocument('$symbols_coll.ection1', new Document([
            '$id' => ID::unique(),
            '$symbols_coll.ection2' => $doc1->getId(),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any())
            ]
        ]));

        $doc1 = $database->getDocument('$symbols_coll.ection2', $doc1->getId());
        $doc2 = $database->getDocument('$symbols_coll.ection1', $doc2->getId());

        $this->assertEquals($doc2->getId(), $doc1->getAttribute('$symbols_coll.ection1')->getId());
        $this->assertEquals($doc1->getId(), $doc2->getAttribute('$symbols_coll.ection2')->getId());
    }

    public function testRecreateOneToOneOneWayRelationshipFromChild(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }
        $database->createCollection('one', [
            new Document([
                '$id' => ID::custom('name'),
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => 100,
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
        $database->createCollection('two', [
            new Document([
                '$id' => ID::custom('name'),
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => 100,
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
            collection: 'one',
            relatedCollection: 'two',
            type: Database::RELATION_ONE_TO_ONE,
        );

        $database->deleteRelationship('two', 'one');

        $result = $database->createRelationship(
            collection: 'one',
            relatedCollection: 'two',
            type: Database::RELATION_ONE_TO_ONE,
        );

        $this->assertTrue($result);

        $database->deleteCollection('one');
        $database->deleteCollection('two');
    }

    public function testRecreateOneToOneTwoWayRelationshipFromParent(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }
        $database->createCollection('one', [
            new Document([
                '$id' => ID::custom('name'),
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => 100,
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
        $database->createCollection('two', [
            new Document([
                '$id' => ID::custom('name'),
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => 100,
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
            collection: 'one',
            relatedCollection: 'two',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
        );

        $database->deleteRelationship('one', 'two');

        $result = $database->createRelationship(
            collection: 'one',
            relatedCollection: 'two',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
        );

        $this->assertTrue($result);

        $database->deleteCollection('one');
        $database->deleteCollection('two');
    }

    public function testRecreateOneToOneTwoWayRelationshipFromChild(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }
        $database->createCollection('one', [
            new Document([
                '$id' => ID::custom('name'),
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => 100,
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
        $database->createCollection('two', [
            new Document([
                '$id' => ID::custom('name'),
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => 100,
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
            collection: 'one',
            relatedCollection: 'two',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
        );

        $database->deleteRelationship('two', 'one');

        $result = $database->createRelationship(
            collection: 'one',
            relatedCollection: 'two',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
        );

        $this->assertTrue($result);

        $database->deleteCollection('one');
        $database->deleteCollection('two');
    }

    public function testRecreateOneToOneOneWayRelationshipFromParent(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }
        $database->createCollection('one', [
            new Document([
                '$id' => ID::custom('name'),
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => 100,
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
        $database->createCollection('two', [
            new Document([
                '$id' => ID::custom('name'),
                'type' => Database::VAR_STRING,
                'format' => '',
                'size' => 100,
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
            collection: 'one',
            relatedCollection: 'two',
            type: Database::RELATION_ONE_TO_ONE,
        );

        $database->deleteRelationship('one', 'two');

        $result = $database->createRelationship(
            collection: 'one',
            relatedCollection: 'two',
            type: Database::RELATION_ONE_TO_ONE,
        );

        $this->assertTrue($result);

        $database->deleteCollection('one');
        $database->deleteCollection('two');
    }

    public function testDeleteBulkDocumentsOneToOneRelationship(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships() || !$database->getAdapter()->getSupportForBatchOperations()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $this->getDatabase()->createCollection('bulk_delete_person_o2o');
        $this->getDatabase()->createCollection('bulk_delete_library_o2o');

        $this->getDatabase()->createAttribute('bulk_delete_person_o2o', 'name', Database::VAR_STRING, 255, true);
        $this->getDatabase()->createAttribute('bulk_delete_library_o2o', 'name', Database::VAR_STRING, 255, true);
        $this->getDatabase()->createAttribute('bulk_delete_library_o2o', 'area', Database::VAR_STRING, 255, true);

        // Restrict
        $this->getDatabase()->createRelationship(
            collection: 'bulk_delete_person_o2o',
            relatedCollection: 'bulk_delete_library_o2o',
            type: Database::RELATION_ONE_TO_ONE,
            onDelete: Database::RELATION_MUTATE_RESTRICT
        );

        $person1 = $this->getDatabase()->createDocument('bulk_delete_person_o2o', new Document([
            '$id' => 'person1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Person 1',
            'bulk_delete_library_o2o' => [
                '$id' => 'library1',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'name' => 'Library 1',
                'area' => 'Area 1',
            ],
        ]));

        $person1 = $this->getDatabase()->getDocument('bulk_delete_person_o2o', 'person1');
        $library = $person1->getAttribute('bulk_delete_library_o2o');
        $this->assertEquals('library1', $library['$id']);
        $this->assertArrayNotHasKey('bulk_delete_person_o2o', $library);

        // Delete person
        try {
            $this->getDatabase()->deleteDocuments('bulk_delete_person_o2o');
            $this->fail('Failed to throw exception');
        } catch (RestrictedException $e) {
            $this->assertEquals('Cannot delete document because it has at least one related document.', $e->getMessage());
        }

        $this->getDatabase()->updateDocument('bulk_delete_person_o2o', 'person1', new Document([
            '$id' => 'person1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Person 1',
            'bulk_delete_library_o2o' => null,
        ]));

        $this->getDatabase()->deleteDocuments('bulk_delete_person_o2o');
        $this->assertCount(0, $this->getDatabase()->find('bulk_delete_person_o2o'));
        $this->getDatabase()->deleteDocuments('bulk_delete_library_o2o');
        $this->assertCount(0, $this->getDatabase()->find('bulk_delete_library_o2o'));

        // NULL
        $this->getDatabase()->updateRelationship(
            collection: 'bulk_delete_person_o2o',
            id: 'bulk_delete_library_o2o',
            onDelete: Database::RELATION_MUTATE_SET_NULL
        );

        $person1 = $this->getDatabase()->createDocument('bulk_delete_person_o2o', new Document([
            '$id' => 'person1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Person 1',
            'bulk_delete_library_o2o' => [
                '$id' => 'library1',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'name' => 'Library 1',
                'area' => 'Area 1',
            ],
        ]));

        $person1 = $this->getDatabase()->getDocument('bulk_delete_person_o2o', 'person1');
        $library = $person1->getAttribute('bulk_delete_library_o2o');
        $this->assertEquals('library1', $library['$id']);
        $this->assertArrayNotHasKey('bulk_delete_person_o2o', $library);

        $person = $this->getDatabase()->getDocument('bulk_delete_person_o2o', 'person1');

        $this->getDatabase()->deleteDocuments('bulk_delete_library_o2o');
        $this->assertCount(0, $this->getDatabase()->find('bulk_delete_library_o2o'));
        $this->assertCount(1, $this->getDatabase()->find('bulk_delete_person_o2o'));

        $person = $this->getDatabase()->getDocument('bulk_delete_person_o2o', 'person1');
        $library = $person->getAttribute('bulk_delete_library_o2o');
        $this->assertNull($library);

        // NULL - Cleanup
        $this->getDatabase()->deleteDocuments('bulk_delete_person_o2o');
        $this->assertCount(0, $this->getDatabase()->find('bulk_delete_person_o2o'));
        $this->getDatabase()->deleteDocuments('bulk_delete_library_o2o');
        $this->assertCount(0, $this->getDatabase()->find('bulk_delete_library_o2o'));

        // Cascade
        $this->getDatabase()->updateRelationship(
            collection: 'bulk_delete_person_o2o',
            id: 'bulk_delete_library_o2o',
            onDelete: Database::RELATION_MUTATE_CASCADE
        );

        $person1 = $this->getDatabase()->createDocument('bulk_delete_person_o2o', new Document([
            '$id' => 'person1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Person 1',
            'bulk_delete_library_o2o' => [
                '$id' => 'library1',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'name' => 'Library 1',
                'area' => 'Area 1',
            ],
        ]));

        $person1 = $this->getDatabase()->getDocument('bulk_delete_person_o2o', 'person1');
        $library = $person1->getAttribute('bulk_delete_library_o2o');
        $this->assertEquals('library1', $library['$id']);
        $this->assertArrayNotHasKey('bulk_delete_person_o2o', $library);

        $person = $this->getDatabase()->getDocument('bulk_delete_person_o2o', 'person1');

        $this->getDatabase()->deleteDocuments('bulk_delete_library_o2o');
        $this->assertCount(0, $this->getDatabase()->find('bulk_delete_library_o2o'));
        $this->assertCount(1, $this->getDatabase()->find('bulk_delete_person_o2o'));

        $person = $this->getDatabase()->getDocument('bulk_delete_person_o2o', 'person1');
        $library = $person->getAttribute('bulk_delete_library_o2o');
        $this->assertEmpty($library);
        $this->assertNotNull($library);

        // Test Bulk delete parent
        $this->getDatabase()->deleteDocuments('bulk_delete_person_o2o');
        $this->assertCount(0, $this->getDatabase()->find('bulk_delete_person_o2o'));

        $person1 = $this->getDatabase()->createDocument('bulk_delete_person_o2o', new Document([
            '$id' => 'person1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Person 1',
            'bulk_delete_library_o2o' => [
                '$id' => 'library1',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'name' => 'Library 1',
                'area' => 'Area 1',
            ],
        ]));

        $person1 = $this->getDatabase()->getDocument('bulk_delete_person_o2o', 'person1');
        $library = $person1->getAttribute('bulk_delete_library_o2o');
        $this->assertEquals('library1', $library['$id']);
        $this->assertArrayNotHasKey('bulk_delete_person_o2o', $library);

        $this->getDatabase()->deleteDocuments('bulk_delete_person_o2o');
        $this->assertCount(0, $this->getDatabase()->find('bulk_delete_person_o2o'));
        $this->assertCount(0, $this->getDatabase()->find('bulk_delete_library_o2o'));
    }

    public function testDeleteTwoWayRelationshipFromChild(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('drivers');
        $database->createCollection('licenses');

        $database->createRelationship(
            collection: 'drivers',
            relatedCollection: 'licenses',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
            id: 'license',
            twoWayKey: 'driver'
        );

        $drivers = $database->getCollection('drivers');
        $licenses = $database->getCollection('licenses');

        $this->assertEquals(1, \count($drivers->getAttribute('attributes')));
        $this->assertEquals(1, \count($drivers->getAttribute('indexes')));
        $this->assertEquals(1, \count($licenses->getAttribute('attributes')));
        $this->assertEquals(1, \count($licenses->getAttribute('indexes')));

        $database->deleteRelationship('licenses', 'driver');

        $drivers = $database->getCollection('drivers');
        $licenses = $database->getCollection('licenses');

        $this->assertEquals(0, \count($drivers->getAttribute('attributes')));
        $this->assertEquals(0, \count($drivers->getAttribute('indexes')));
        $this->assertEquals(0, \count($licenses->getAttribute('attributes')));
        $this->assertEquals(0, \count($licenses->getAttribute('indexes')));

        $database->createRelationship(
            collection: 'drivers',
            relatedCollection: 'licenses',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true,
            id: 'licenses',
            twoWayKey: 'driver'
        );

        $drivers = $database->getCollection('drivers');
        $licenses = $database->getCollection('licenses');

        $this->assertEquals(1, \count($drivers->getAttribute('attributes')));
        $this->assertEquals(0, \count($drivers->getAttribute('indexes')));
        $this->assertEquals(1, \count($licenses->getAttribute('attributes')));
        $this->assertEquals(1, \count($licenses->getAttribute('indexes')));

        $database->deleteRelationship('licenses', 'driver');

        $drivers = $database->getCollection('drivers');
        $licenses = $database->getCollection('licenses');

        $this->assertEquals(0, \count($drivers->getAttribute('attributes')));
        $this->assertEquals(0, \count($drivers->getAttribute('indexes')));
        $this->assertEquals(0, \count($licenses->getAttribute('attributes')));
        $this->assertEquals(0, \count($licenses->getAttribute('indexes')));

        $database->createRelationship(
            collection: 'licenses',
            relatedCollection: 'drivers',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
            id: 'driver',
            twoWayKey: 'licenses'
        );

        $drivers = $database->getCollection('drivers');
        $licenses = $database->getCollection('licenses');

        $this->assertEquals(1, \count($drivers->getAttribute('attributes')));
        $this->assertEquals(0, \count($drivers->getAttribute('indexes')));
        $this->assertEquals(1, \count($licenses->getAttribute('attributes')));
        $this->assertEquals(1, \count($licenses->getAttribute('indexes')));

        $database->deleteRelationship('drivers', 'licenses');

        $drivers = $database->getCollection('drivers');
        $licenses = $database->getCollection('licenses');

        $this->assertEquals(0, \count($drivers->getAttribute('attributes')));
        $this->assertEquals(0, \count($drivers->getAttribute('indexes')));
        $this->assertEquals(0, \count($licenses->getAttribute('attributes')));
        $this->assertEquals(0, \count($licenses->getAttribute('indexes')));

        $database->createRelationship(
            collection: 'licenses',
            relatedCollection: 'drivers',
            type: Database::RELATION_MANY_TO_MANY,
            twoWay: true,
            id: 'drivers',
            twoWayKey: 'licenses'
        );

        $drivers = $database->getCollection('drivers');
        $licenses = $database->getCollection('licenses');
        $junction = $database->getCollection('_' . $licenses->getSequence() . '_' . $drivers->getSequence());

        $this->assertEquals(1, \count($drivers->getAttribute('attributes')));
        $this->assertEquals(0, \count($drivers->getAttribute('indexes')));
        $this->assertEquals(1, \count($licenses->getAttribute('attributes')));
        $this->assertEquals(0, \count($licenses->getAttribute('indexes')));
        $this->assertEquals(2, \count($junction->getAttribute('attributes')));
        $this->assertEquals(2, \count($junction->getAttribute('indexes')));

        $database->deleteRelationship('drivers', 'licenses');

        $drivers = $database->getCollection('drivers');
        $licenses = $database->getCollection('licenses');
        $junction = $database->getCollection('_licenses_drivers');

        $this->assertEquals(0, \count($drivers->getAttribute('attributes')));
        $this->assertEquals(0, \count($drivers->getAttribute('indexes')));
        $this->assertEquals(0, \count($licenses->getAttribute('attributes')));
        $this->assertEquals(0, \count($licenses->getAttribute('indexes')));

        $this->assertEquals(true, $junction->isEmpty());
    }
    public function testUpdateParentAndChild_OneToOne(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (
            !$database->getAdapter()->getSupportForRelationships() ||
            !$database->getAdapter()->getSupportForBatchOperations()
        ) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $parentCollection = 'parent_combined_o2o';
        $childCollection = 'child_combined_o2o';

        $database->createCollection($parentCollection);
        $database->createCollection($childCollection);

        $database->createAttribute($parentCollection, 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute($childCollection, 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute($childCollection, 'parentNumber', Database::VAR_INTEGER, 0, false);

        $database->createRelationship(
            collection: $parentCollection,
            relatedCollection: $childCollection,
            type: Database::RELATION_ONE_TO_ONE,
            id: 'parentNumber'
        );

        $database->createDocument($parentCollection, new Document([
            '$id' => 'parent1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Parent 1',
        ]));

        $database->createDocument($childCollection, new Document([
            '$id' => 'child1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Child 1',
            'parentNumber' => null,
        ]));

        $database->updateDocuments(
            $parentCollection,
            new Document(['name' => 'Parent 1 Updated']),
            [Query::equal('$id', ['parent1'])]
        );

        $parentDoc = $database->getDocument($parentCollection, 'parent1');
        $this->assertEquals('Parent 1 Updated', $parentDoc->getAttribute('name'), 'Parent should be updated');

        $childDoc = $database->getDocument($childCollection, 'child1');
        $this->assertEquals('Child 1', $childDoc->getAttribute('name'), 'Child should remain unchanged');

        // invalid update to child
        try {
            $database->updateDocuments(
                $childCollection,
                new Document(['parentNumber' => 'not-a-number']),
                [Query::equal('$id', ['child1'])]
            );
            $this->fail('Expected exception was not thrown for invalid parentNumber type');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(StructureException::class, $e);
        }

        // parent remains unaffected
        $parentDocAfter = $database->getDocument($parentCollection, 'parent1');
        $this->assertEquals('Parent 1 Updated', $parentDocAfter->getAttribute('name'), 'Parent should not be affected by failed child update');

        $database->deleteCollection($parentCollection);
        $database->deleteCollection($childCollection);
    }

    public function testDeleteDocumentsRelationshipErrorDoesNotDeleteParent_OneToOne(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships() || !$database->getAdapter()->getSupportForBatchOperations()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $parentCollection = 'parent_relationship_error_one_to_one';
        $childCollection = 'child_relationship_error_one_to_one';

        $database->createCollection($parentCollection);
        $database->createCollection($childCollection);
        $database->createAttribute($parentCollection, 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute($childCollection, 'name', Database::VAR_STRING, 255, true);

        $database->createRelationship(
            collection: $parentCollection,
            relatedCollection: $childCollection,
            type: Database::RELATION_ONE_TO_ONE,
            onDelete: Database::RELATION_MUTATE_RESTRICT
        );

        $parent = $database->createDocument($parentCollection, new Document([
            '$id' => 'parent1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Parent 1',
            $childCollection => [
                '$id' => 'child1',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'name' => 'Child 1',
            ]
        ]));

        try {
            $database->deleteDocuments($parentCollection, [Query::equal('$id', ['parent1'])]);
            $this->fail('Expected exception was not thrown');
        } catch (RestrictedException $e) {
            $this->assertEquals('Cannot delete document because it has at least one related document.', $e->getMessage());
        }
        $parentDoc = $database->getDocument($parentCollection, 'parent1');
        $childDoc = $database->getDocument($childCollection, 'child1');
        $this->assertFalse($parentDoc->isEmpty(), 'Parent should not be deleted');
        $this->assertFalse($childDoc->isEmpty(), 'Child should not be deleted');
        $database->deleteCollection($parentCollection);
        $database->deleteCollection($childCollection);
    }

    public function testPartialUpdateOneToOneWithRelationships(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Setup collections with relationships
        $database->createCollection('cities_partial');
        $database->createCollection('mayors_partial');

        $database->createAttribute('cities_partial', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('cities_partial', 'population', Database::VAR_INTEGER, 0, false);
        $database->createAttribute('mayors_partial', 'name', Database::VAR_STRING, 255, true);

        $database->createRelationship(
            collection: 'cities_partial',
            relatedCollection: 'mayors_partial',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
            id: 'mayor',
            twoWayKey: 'city'
        );

        // Create a city with a mayor
        $database->createDocument('cities_partial', new Document([
            '$id' => 'city1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'name' => 'Test City',
            'population' => 100000,
            'mayor' => [
                '$id' => 'mayor1',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                ],
                'name' => 'Test Mayor',
            ],
        ]));

        // Verify initial state
        $city = $database->getDocument('cities_partial', 'city1');
        $this->assertEquals('Test City', $city->getAttribute('name'));
        $this->assertEquals(100000, $city->getAttribute('population'));
        $this->assertEquals('mayor1', $city->getAttribute('mayor')->getId());

        $mayor = $database->getDocument('mayors_partial', 'mayor1');
        $this->assertEquals('Test Mayor', $mayor->getAttribute('name'));
        $this->assertEquals('city1', $mayor->getAttribute('city')->getId());

        // Perform a partial update - ONLY update the city name, NOT the mayor relationship
        $database->updateDocument('cities_partial', 'city1', new Document([
            '$id' => 'city1',
            '$collection' => 'cities_partial',
            'name' => 'Updated City Name',
            // NOTE: We deliberately do NOT include the 'mayor' field here - this is a partial update
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
        ]));

        // Verify that the city name was updated but the mayor relationship was preserved
        $cityAfterUpdate = $database->getDocument('cities_partial', 'city1');
        $this->assertEquals('Updated City Name', $cityAfterUpdate->getAttribute('name'), 'City name should be updated');
        $this->assertEquals(100000, $cityAfterUpdate->getAttribute('population'), 'Population should be preserved');

        // This is the critical test - the mayor relationship should still exist
        $mayorAfterUpdate = $cityAfterUpdate->getAttribute('mayor');
        $this->assertNotNull($mayorAfterUpdate, 'Mayor relationship should be preserved after partial update');
        $this->assertEquals('mayor1', $mayorAfterUpdate->getId(), 'Mayor ID should still be mayor1');

        // Verify the bidirectional relationship is still intact
        $mayor = $database->getDocument('mayors_partial', 'mayor1');
        $this->assertEquals('city1', $mayor->getAttribute('city')->getId(), 'Reverse relationship should be preserved');
        $this->assertEquals('Updated City Name', $mayor->getAttribute('city')->getAttribute('name'), 'Reverse relationship should reflect updated city name');

        $database->deleteCollection('cities_partial');
        $database->deleteCollection('mayors_partial');
    }

    public function testPartialUpdateOneToOneWithoutRelationshipField(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Recreate the exact scenario from testNestedOneToMany_OneToOneRelationship
        $database->createCollection('cities_strict');
        $database->createCollection('mayors_strict');

        $database->createAttribute('cities_strict', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('mayors_strict', 'name', Database::VAR_STRING, 255, true);

        $database->createRelationship(
            collection: 'cities_strict',
            relatedCollection: 'mayors_strict',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
            id: 'mayor',
            twoWayKey: 'city'
        );

        // Create city with mayor
        $database->createDocument('cities_strict', new Document([
            '$id' => 'city1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'name' => 'City 1',
            'mayor' => [
                '$id' => 'mayor1',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                ],
                'name' => 'Mayor 1',
            ],
        ]));

        // Get the current state to verify
        $cityBefore = $database->getDocument('cities_strict', 'city1');
        $this->assertEquals('City 1', $cityBefore->getAttribute('name'));
        $this->assertEquals('mayor1', $cityBefore->getAttribute('mayor')->getId());

        // Now do what the comment says we "don't support" - update WITHOUT including mayor field
        // Creating a fresh Document object with only the fields we want to update
        $partialUpdate = new Document([
            '$id' => 'city1',
            '$collection' => 'cities_strict',
            'name' => 'City 1 updated',  // Update only name
            // Deliberately NOT including 'mayor' at all
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
        ]);

        $database->updateDocument('cities_strict', 'city1', $partialUpdate);

        // Now check if the mayor relationship was preserved
        $cityAfter = $database->getDocument('cities_strict', 'city1');
        $this->assertEquals('City 1 updated', $cityAfter->getAttribute('name'));

        // The relationship should still exist
        $mayorAttr = $cityAfter->getAttribute('mayor');
        $this->assertNotNull($mayorAttr, 'Mayor should still be set after partial update without mayor field');
        $this->assertEquals('mayor1', $mayorAttr->getId());

        // Also verify the reverse relationship
        $mayor = $database->getDocument('mayors_strict', 'mayor1');
        $this->assertEquals('city1', $mayor->getAttribute('city')->getId());

        $database->deleteCollection('cities_strict');
        $database->deleteCollection('mayors_strict');
    }

    public function testOneToOneRelationshipRejectsArrayOperators(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        if (!$database->getAdapter()->getSupportForOperators()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Cleanup any leftover collections from previous runs
        try {
            $database->deleteCollection('user_o2o');
        } catch (\Throwable $e) {
        }
        try {
            $database->deleteCollection('profile_o2o');
        } catch (\Throwable $e) {
        }

        $database->createCollection('user_o2o');
        $database->createCollection('profile_o2o');

        $database->createAttribute('user_o2o', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('profile_o2o', 'bio', Database::VAR_STRING, 255, true);

        $database->createRelationship(
            collection: 'user_o2o',
            relatedCollection: 'profile_o2o',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
            id: 'profile',
            twoWayKey: 'user'
        );

        // Create a profile
        $database->createDocument('profile_o2o', new Document([
            '$id' => 'profile1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'bio' => 'Test bio',
        ]));

        // Create user with profile
        $database->createDocument('user_o2o', new Document([
            '$id' => 'user1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            'name' => 'User 1',
            'profile' => 'profile1',
        ]));

        // Array operators should fail on one-to-one relationships
        try {
            $database->updateDocument('user_o2o', 'user1', new Document([
                'profile' => \Utopia\Database\Operator::arrayAppend(['profile2']),
            ]));
            $this->fail('Expected exception for array operator on one-to-one relationship');
        } catch (\Utopia\Database\Exception\Structure $e) {
            $this->assertStringContainsString('single-value relationship', $e->getMessage());
        }

        // Cleanup
        $database->deleteCollection('user_o2o');
        $database->deleteCollection('profile_o2o');
    }

    /**
     * Deleting the child must clear the parent foreign key even when the delete ran through a select query.
     */
    public function testOneToOneSetNullAfterSelectDelete(): void
    {
        $database = static::getDatabase();

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

        $database->createCollection('oto_select_parent', permissions: $collectionPermissions, documentSecurity: true);
        $database->createCollection('oto_select_child', permissions: $collectionPermissions, documentSecurity: true);
        $database->createAttribute('oto_select_parent', 'name', Database::VAR_STRING, 255, false);
        $database->createAttribute('oto_select_child', 'name', Database::VAR_STRING, 255, false);

        $database->createRelationship(
            collection: 'oto_select_parent',
            relatedCollection: 'oto_select_child',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
            id: 'child',
            twoWayKey: 'parent',
            onDelete: Database::RELATION_MUTATE_SET_NULL,
        );

        $database->createDocument('oto_select_child', new Document([
            '$id' => 'child1',
            '$permissions' => $documentPermissions,
            'name' => 'Child',
        ]));

        $database->createDocument('oto_select_parent', new Document([
            '$id' => 'parent1',
            '$permissions' => $documentPermissions,
            'name' => 'Parent',
            'child' => 'child1',
        ]));

        // A select query turns relationship population off, so the deleted document
        // reaches deleteSetNull() without its relationship value
        $database->deleteDocuments('oto_select_child', [
            Query::select(['$id', 'name']),
            Query::equal('$id', ['child1']),
        ]);

        $survivor = $database->getDocument('oto_select_parent', 'parent1');
        $this->assertFalse($survivor->isEmpty());
        $this->assertNull($survivor->getAttribute('child'));
    }
}
