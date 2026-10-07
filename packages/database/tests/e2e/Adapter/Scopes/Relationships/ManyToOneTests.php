<?php

namespace Tests\E2E\Adapter\Scopes\Relationships;

use Exception;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Restricted as RestrictedException;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

trait ManyToOneTests
{
    public function testManyToOneOneWayRelationship(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('review');
        $database->createCollection('movie');

        $database->createAttribute('review', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('movie', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('movie', 'length', Database::VAR_INTEGER, 0, true, formatOptions: ['min' => 0, 'max' => 999]);
        $database->createAttribute('movie', 'date', Database::VAR_DATETIME, 0, false, filters: ['datetime']);
        $database->createAttribute('review', 'date', Database::VAR_DATETIME, 0, false, filters: ['datetime']);
        $database->createRelationship(
            collection: 'review',
            relatedCollection: 'movie',
            type: Database::RELATION_MANY_TO_ONE,
            twoWayKey: 'reviews'
        );

        // Check metadata for collection
        $collection = $database->getCollection('review');
        $attributes = $collection->getAttribute('attributes', []);
        foreach ($attributes as $attribute) {
            if ($attribute['key'] === 'movie') {
                $this->assertEquals('relationship', $attribute['type']);
                $this->assertEquals('movie', $attribute['$id']);
                $this->assertEquals('movie', $attribute['key']);
                $this->assertEquals('movie', $attribute['options']['relatedCollection']);
                $this->assertEquals(Database::RELATION_MANY_TO_ONE, $attribute['options']['relationType']);
                $this->assertEquals(false, $attribute['options']['twoWay']);
                $this->assertEquals('reviews', $attribute['options']['twoWayKey']);
            }
        }

        // Check metadata for related collection
        $collection = $database->getCollection('movie');
        $attributes = $collection->getAttribute('attributes', []);
        foreach ($attributes as $attribute) {
            if ($attribute['key'] === 'reviews') {
                $this->assertEquals('relationship', $attribute['type']);
                $this->assertEquals('reviews', $attribute['$id']);
                $this->assertEquals('reviews', $attribute['key']);
                $this->assertEquals('review', $attribute['options']['relatedCollection']);
                $this->assertEquals(Database::RELATION_MANY_TO_ONE, $attribute['options']['relationType']);
                $this->assertEquals(false, $attribute['options']['twoWay']);
                $this->assertEquals('movie', $attribute['options']['twoWayKey']);
            }
        }

        // Create document with relationship with nested data
        $review1 = $database->createDocument('review', new Document([
            '$id' => 'review1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Review 1',
            'date' => '2023-04-03 10:35:27.390',
            'movie' => [
                '$id' => 'movie1',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'name' => 'Movie 1',
                'date' => '2023-04-03 10:35:27.390',
                'length' => 120,
            ],
        ]));

        // Update a document with non existing related document. It should not get added to the list.
        $database->updateDocument('review', 'review1', $review1->setAttribute('movie', 'no-movie'));

        $review1Document = $database->getDocument('review', 'review1');
        // Assert document does not contain non existing relation document.
        $this->assertEquals(null, $review1Document->getAttribute('movie'));

        $database->updateDocument('review', 'review1', $review1->setAttribute('movie', 'movie1'));

        // Create document with relationship to existing document by ID
        $review10 = $database->createDocument('review', new Document([
            '$id' => 'review10',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Review 10',
            'movie' => 'movie1',
            'date' => '2023-04-03 10:35:27.390',
        ]));

        // Create document with relationship with related ID
        $database->createDocument('movie', new Document([
            '$id' => 'movie2',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Movie 2',
            'length' => 90,
            'date' => '2023-04-03 10:35:27.390',
        ]));
        $database->createDocument('review', new Document([
            '$id' => 'review2',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Review 2',
            'movie' => 'movie2',
            'date' => '2023-04-03 10:35:27.390',
        ]));

        // Get document with relationship
        $review = $database->getDocument('review', 'review1');
        $movie = $review->getAttribute('movie', []);
        $this->assertEquals('movie1', $movie['$id']);
        $this->assertArrayNotHasKey('reviews', $movie);

        $documents = $database->find('review', [
            Query::select(['date', 'movie.date'])
        ]);

        $this->assertCount(3, $documents);

        $document = $documents[0];
        $this->assertArrayHasKey('date', $document);
        $this->assertArrayHasKey('movie', $document);
        $this->assertArrayHasKey('date', $document->getAttribute('movie'));
        $this->assertArrayNotHasKey('name', $document);
        $this->assertEquals(29, strlen($document['date'])); // checks filter
        $this->assertEquals(29, strlen($document['movie']['date']));

        $review = $database->getDocument('review', 'review2');
        $movie = $review->getAttribute('movie', []);
        $this->assertEquals('movie2', $movie['$id']);
        $this->assertArrayNotHasKey('reviews', $movie);

        // Get related document
        $movie = $database->getDocument('movie', 'movie1');
        $this->assertArrayNotHasKey('reviews', $movie);

        $movie = $database->getDocument('movie', 'movie2');
        $this->assertArrayNotHasKey('reviews', $movie);

        $reviews = $database->find('review');

        $this->assertEquals(3, \count($reviews));

        // Select related document attributes
        $review = $database->findOne('review', [
            Query::select(['*', 'movie.name'])
        ]);

        if ($review->isEmpty()) {
            throw new Exception('Review not found');
        }

        $this->assertEquals('Movie 1', $review->getAttribute('movie')->getAttribute('name'));
        $this->assertArrayNotHasKey('length', $review->getAttribute('movie'));

        $review = $database->getDocument('review', 'review1', [
            Query::select(['*', 'movie.name'])
        ]);

        $this->assertEquals('Movie 1', $review->getAttribute('movie')->getAttribute('name'));
        $this->assertArrayNotHasKey('length', $review->getAttribute('movie'));

        // Update root document attribute without altering relationship
        $review1 = $database->updateDocument(
            'review',
            $review1->getId(),
            $review1->setAttribute('name', 'Review 1 Updated')
        );

        $this->assertEquals('Review 1 Updated', $review1->getAttribute('name'));
        $review1 = $database->getDocument('review', 'review1');
        $this->assertEquals('Review 1 Updated', $review1->getAttribute('name'));

        // Update nested document attribute
        $movie = $review1->getAttribute('movie');
        $movie->setAttribute('name', 'Movie 1 Updated');

        $review1 = $database->updateDocument(
            'review',
            $review1->getId(),
            $review1->setAttribute('movie', $movie)
        );

        $this->assertEquals('Movie 1 Updated', $review1->getAttribute('movie')->getAttribute('name'));
        $review1 = $database->getDocument('review', 'review1');
        $this->assertEquals('Movie 1 Updated', $review1->getAttribute('movie')->getAttribute('name'));

        // Create new document with no relationship
        $review5 = $database->createDocument('review', new Document([
            '$id' => 'review5',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Review 5',
        ]));

        // Update to relate to created document
        $review5 = $database->updateDocument(
            'review',
            $review5->getId(),
            $review5->setAttribute('movie', new Document([
                '$id' => 'movie5',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'name' => 'Movie 5',
                'length' => 90,
            ]))
        );

        $this->assertEquals('Movie 5', $review5->getAttribute('movie')->getAttribute('name'));
        $review5 = $database->getDocument('review', 'review5');
        $this->assertEquals('Movie 5', $review5->getAttribute('movie')->getAttribute('name'));

        // Update document with new related document
        $database->updateDocument(
            'review',
            $review1->getId(),
            $review1->setAttribute('movie', 'movie2')
        );

        // Rename relationship keys on both sides
        $database->updateRelationship(
            'review',
            'movie',
            'newMovie',
        );

        // Get document with new relationship key
        $review = $database->getDocument('review', 'review1');
        $movie = $review->getAttribute('newMovie');
        $this->assertEquals('movie2', $movie['$id']);

        // Reset values
        $review1 = $database->getDocument('review', 'review1');

        $database->updateDocument(
            'review',
            $review1->getId(),
            $review1->setAttribute('newMovie', 'movie1')
        );

        // Create new document with no relationship
        $database->createDocument('movie', new Document([
            '$id' => 'movie3',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Movie 3',
            'length' => 90,
        ]));

        // Can delete document with no relationship when on delete is set to restrict
        $deleted = $database->deleteDocument('movie', 'movie3');
        $this->assertEquals(true, $deleted);

        $movie3 = $database->getDocument('movie', 'movie3');
        $this->assertEquals(true, $movie3->isEmpty());

        // Try to delete document while still related to another with on delete: restrict
        try {
            $database->deleteDocument('movie', 'movie1');
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertEquals('Cannot delete document because it has at least one related document.', $e->getMessage());
        }

        // Change on delete to set null
        $database->updateRelationship(
            collection: 'review',
            id: 'newMovie',
            onDelete: Database::RELATION_MUTATE_SET_NULL
        );

        // Delete child, set parent relationship to null
        $database->deleteDocument('movie', 'movie1');

        // Check relation was set to null
        $review1 = $database->getDocument('review', 'review1');
        $this->assertEquals(null, $review1->getAttribute('newMovie'));

        // Change on delete to cascade
        $database->updateRelationship(
            collection: 'review',
            id: 'newMovie',
            onDelete: Database::RELATION_MUTATE_CASCADE
        );

        // Delete child, will delete parent
        $database->deleteDocument('movie', 'movie2');

        // Check parent and child were deleted
        $library = $database->getDocument('movie', 'movie2');
        $this->assertEquals(true, $library->isEmpty());

        $library = $database->getDocument('review', 'review2');
        $this->assertEquals(true, $library->isEmpty());


        // Delete relationship
        $database->deleteRelationship(
            'review',
            'newMovie'
        );

        // Try to get document again
        $review = $database->getDocument('review', 'review1');
        $movie = $review->getAttribute('newMovie');
        $this->assertEquals(null, $movie);
    }

    public function testManyToOneTwoWayRelationship(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('product');
        $database->createCollection('store');

        $database->createAttribute('store', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('store', 'opensAt', Database::VAR_STRING, 5, true);

        $database->createAttribute(
            collection: 'product',
            id: 'name',
            type: Database::VAR_STRING,
            size: 255,
            required: true
        );

        $database->createRelationship(
            collection: 'product',
            relatedCollection: 'store',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
            twoWayKey: 'products'
        );

        // Check metadata for collection
        $collection = $database->getCollection('product');
        $attributes = $collection->getAttribute('attributes', []);
        foreach ($attributes as $attribute) {
            if ($attribute['key'] === 'store') {
                $this->assertEquals('relationship', $attribute['type']);
                $this->assertEquals('store', $attribute['$id']);
                $this->assertEquals('store', $attribute['key']);
                $this->assertEquals('store', $attribute['options']['relatedCollection']);
                $this->assertEquals(Database::RELATION_MANY_TO_ONE, $attribute['options']['relationType']);
                $this->assertEquals(true, $attribute['options']['twoWay']);
                $this->assertEquals('products', $attribute['options']['twoWayKey']);
            }
        }

        // Check metadata for related collection
        $collection = $database->getCollection('store');
        $attributes = $collection->getAttribute('attributes', []);
        foreach ($attributes as $attribute) {
            if ($attribute['key'] === 'products') {
                $this->assertEquals('relationship', $attribute['type']);
                $this->assertEquals('products', $attribute['$id']);
                $this->assertEquals('products', $attribute['key']);
                $this->assertEquals('product', $attribute['options']['relatedCollection']);
                $this->assertEquals(Database::RELATION_MANY_TO_ONE, $attribute['options']['relationType']);
                $this->assertEquals(true, $attribute['options']['twoWay']);
                $this->assertEquals('store', $attribute['options']['twoWayKey']);
            }
        }

        // Create document with relationship with nested data
        $product1 = $database->createDocument('product', new Document([
            '$id' => 'product1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Product 1',
            'store' => [
                '$id' => 'store1',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'name' => 'Store 1',
                'opensAt' => '09:00',
            ],
        ]));

        // Update a document with non existing related document. It should not get added to the list.
        $database->updateDocument('product', 'product1', $product1->setAttribute('store', 'no-store'));

        $product1Document = $database->getDocument('product', 'product1');
        // Assert document does not contain non existing relation document.
        $this->assertEquals(null, $product1Document->getAttribute('store'));

        $database->updateDocument('product', 'product1', $product1->setAttribute('store', 'store1'));

        // Create document with relationship with related ID
        $database->createDocument('store', new Document([
            '$id' => 'store2',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Store 2',
            'opensAt' => '09:30',
        ]));
        $database->createDocument('product', new Document([
            '$id' => 'product2',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Product 2',
            'store' => 'store2',
        ]));

        // Create from child side
        $database->createDocument('store', new Document([
            '$id' => 'store3',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Store 3',
            'opensAt' => '11:30',
            'products' => [
                [
                    '$id' => 'product3',
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::update(Role::any()),
                        Permission::delete(Role::any()),
                    ],
                    'name' => 'Product 3',
                ],
            ],
        ]));

        $database->createDocument('product', new Document([
            '$id' => 'product4',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Product 4',
        ]));
        $database->createDocument('store', new Document([
            '$id' => 'store4',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Store 4',
            'opensAt' => '11:30',
            'products' => [
                'product4',
            ],
        ]));

        // Get document with relationship
        $product = $database->getDocument('product', 'product1');
        $store = $product->getAttribute('store', []);
        $this->assertEquals('store1', $store['$id']);
        $this->assertArrayNotHasKey('products', $store);

        $product = $database->getDocument('product', 'product2');
        $store = $product->getAttribute('store', []);
        $this->assertEquals('store2', $store['$id']);
        $this->assertArrayNotHasKey('products', $store);

        $product = $database->getDocument('product', 'product3');
        $store = $product->getAttribute('store', []);
        $this->assertEquals('store3', $store['$id']);
        $this->assertArrayNotHasKey('products', $store);

        $product = $database->getDocument('product', 'product4');
        $store = $product->getAttribute('store', []);
        $this->assertEquals('store4', $store['$id']);
        $this->assertArrayNotHasKey('products', $store);

        // Get related document
        $store = $database->getDocument('store', 'store1');
        $products = $store->getAttribute('products');
        $this->assertEquals('product1', $products[0]['$id']);
        $this->assertArrayNotHasKey('store', $products[0]);

        $store = $database->getDocument('store', 'store2');
        $products = $store->getAttribute('products');
        $this->assertEquals('product2', $products[0]['$id']);
        $this->assertArrayNotHasKey('store', $products[0]);

        $store = $database->getDocument('store', 'store3');
        $products = $store->getAttribute('products');
        $this->assertEquals('product3', $products[0]['$id']);
        $this->assertArrayNotHasKey('store', $products[0]);

        $store = $database->getDocument('store', 'store4');
        $products = $store->getAttribute('products');
        $this->assertEquals('product4', $products[0]['$id']);
        $this->assertArrayNotHasKey('store', $products[0]);

        $products = $database->find('product');

        $this->assertEquals(4, \count($products));

        // Select related document attributes
        $product = $database->findOne('product', [
            Query::select(['*', 'store.name'])
        ]);

        if ($product->isEmpty()) {
            throw new Exception('Product not found');
        }

        $this->assertEquals('Store 1', $product->getAttribute('store')->getAttribute('name'));
        $this->assertArrayNotHasKey('opensAt', $product->getAttribute('store'));

        $product = $database->getDocument('product', 'product1', [
            Query::select(['*', 'store.name'])
        ]);

        $this->assertEquals('Store 1', $product->getAttribute('store')->getAttribute('name'));
        $this->assertArrayNotHasKey('opensAt', $product->getAttribute('store'));

        // Update root document attribute without altering relationship
        $product1 = $database->updateDocument(
            'product',
            $product1->getId(),
            $product1->setAttribute('name', 'Product 1 Updated')
        );

        $this->assertEquals('Product 1 Updated', $product1->getAttribute('name'));
        $product1 = $database->getDocument('product', 'product1');
        $this->assertEquals('Product 1 Updated', $product1->getAttribute('name'));

        // Update inverse document attribute without altering relationship
        $store1 = $database->getDocument('store', 'store1');
        $store1 = $database->updateDocument(
            'store',
            $store1->getId(),
            $store1->setAttribute('name', 'Store 1 Updated')
        );

        $this->assertEquals('Store 1 Updated', $store1->getAttribute('name'));
        $store1 = $database->getDocument('store', 'store1');
        $this->assertEquals('Store 1 Updated', $store1->getAttribute('name'));

        // Update nested document attribute
        $store = $product1->getAttribute('store');
        $store->setAttribute('name', 'Store 1 Updated');

        $product1 = $database->updateDocument(
            'product',
            $product1->getId(),
            $product1->setAttribute('store', $store)
        );

        $this->assertEquals('Store 1 Updated', $product1->getAttribute('store')->getAttribute('name'));
        $product1 = $database->getDocument('product', 'product1');
        $this->assertEquals('Store 1 Updated', $product1->getAttribute('store')->getAttribute('name'));

        // Update inverse nested document attribute
        $product = $store1->getAttribute('products')[0];
        $product->setAttribute('name', 'Product 1 Updated');

        $store1 = $database->updateDocument(
            'store',
            $store1->getId(),
            $store1->setAttribute('products', [$product])
        );

        $this->assertEquals('Product 1 Updated', $store1->getAttribute('products')[0]->getAttribute('name'));
        $store1 = $database->getDocument('store', 'store1');
        $this->assertEquals('Product 1 Updated', $store1->getAttribute('products')[0]->getAttribute('name'));

        // Create new document with no relationship
        $product5 = $database->createDocument('product', new Document([
            '$id' => 'product5',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Product 5',
        ]));

        // Update to relate to created document
        $product5 = $database->updateDocument(
            'product',
            $product5->getId(),
            $product5->setAttribute('store', new Document([
                '$id' => 'store5',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'name' => 'Store 5',
                'opensAt' => '09:00',
            ]))
        );

        $this->assertEquals('Store 5', $product5->getAttribute('store')->getAttribute('name'));
        $product5 = $database->getDocument('product', 'product5');
        $this->assertEquals('Store 5', $product5->getAttribute('store')->getAttribute('name'));

        // Create new child document with no relationship
        $store6 = $database->createDocument('store', new Document([
            '$id' => 'store6',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Store 6',
            'opensAt' => '09:00',
        ]));

        // Update inverse to related to newly created document
        $store6 = $database->updateDocument(
            'store',
            $store6->getId(),
            $store6->setAttribute('products', [new Document([
                '$id' => 'product6',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'name' => 'Product 6',
            ])])
        );

        $this->assertEquals('Product 6', $store6->getAttribute('products')[0]->getAttribute('name'));
        $store6 = $database->getDocument('store', 'store6');
        $this->assertEquals('Product 6', $store6->getAttribute('products')[0]->getAttribute('name'));

        // Update document with new related document
        $database->updateDocument(
            'product',
            $product1->getId(),
            $product1->setAttribute('store', 'store2')
        );

        $store1 = $database->getDocument('store', 'store1');

        // Update inverse document
        $database->updateDocument(
            'store',
            $store1->getId(),
            $store1->setAttribute('products', ['product1'])
        );

        $store2 = $database->getDocument('store', 'store2');

        // Update inverse document
        $database->updateDocument(
            'store',
            $store2->getId(),
            $store2->setAttribute('products', ['product1', 'product2'])
        );

        // Rename relationship keys on both sides
        $database->updateRelationship(
            'product',
            'store',
            'newStore',
            'newProducts'
        );

        // Get document with new relationship key
        $store = $database->getDocument('store', 'store2');
        $products = $store->getAttribute('newProducts');
        $this->assertEquals('product1', $products[0]['$id']);

        // Get inverse document with new relationship key
        $product = $database->getDocument('product', 'product1');
        $store = $product->getAttribute('newStore');
        $this->assertEquals('store2', $store['$id']);

        // Reset relationships
        $store1 = $database->getDocument('store', 'store1');
        $database->updateDocument(
            'store',
            $store1->getId(),
            $store1->setAttribute('newProducts', ['product1'])
        );

        // Create new document with no relationship
        $database->createDocument('store', new Document([
            '$id' => 'store7',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Store 7',
            'opensAt' => '09:00',
        ]));

        // Can delete document with no relationship when on delete is set to restrict
        $deleted = $database->deleteDocument('store', 'store7');
        $this->assertEquals(true, $deleted);

        $store7 = $database->getDocument('store', 'store7');
        $this->assertEquals(true, $store7->isEmpty());

        // Try to delete child while still related to another with on delete: restrict
        try {
            $database->deleteDocument('store', 'store1');
            $this->fail('Failed to throw exception');
        } catch (Exception $e) {
            $this->assertEquals('Cannot delete document because it has at least one related document.', $e->getMessage());
        }

        // Delete parent while still related to another with on delete: restrict
        $result = $database->deleteDocument('product', 'product5');
        $this->assertEquals(true, $result);

        // Change on delete to set null
        $database->updateRelationship(
            collection: 'product',
            id: 'newStore',
            onDelete: Database::RELATION_MUTATE_SET_NULL
        );

        // Delete child, set parent relationship to null
        $database->deleteDocument('store', 'store1');

        // Check relation was set to null
        $database->getDocument('product', 'product1');
        $this->assertEquals(null, $product1->getAttribute('newStore'));


        // Create child with no related parents and verify deleteSetNull succeeds
        $database->createDocument('store', new Document([
            '$id' => 'store8',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Store 8',
            'opensAt' => '10:00',
        ]));

        $deleted = $database->deleteDocument('store', 'store8');
        $this->assertEquals(true, $deleted);

        $store8 = $database->getDocument('store', 'store8');
        $this->assertEquals(true, $store8->isEmpty());
        // Change on delete to cascade
        $database->updateRelationship(
            collection: 'product',
            id: 'newStore',
            onDelete: Database::RELATION_MUTATE_CASCADE
        );

        // Delete child, will delete parent
        $database->deleteDocument('store', 'store2');

        // Check parent and child were deleted
        $library = $database->getDocument('store', 'store2');
        $this->assertEquals(true, $library->isEmpty());

        $library = $database->getDocument('product', 'product2');
        $this->assertEquals(true, $library->isEmpty());

        // Delete relationship
        $database->deleteRelationship(
            'product',
            'newStore'
        );

        // Try to get document again
        $products = $database->getDocument('product', 'product1');
        $store = $products->getAttribute('newStore');
        $this->assertEquals(null, $store);

        // Try to get inverse document again
        $store = $database->getDocument('store', 'store1');
        $products = $store->getAttribute('newProducts');
        $this->assertEquals(null, $products);
    }

    public function testNestedManyToOne_OneToOneRelationship(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('towns');
        $database->createCollection('homelands');
        $database->createCollection('capitals');

        $database->createAttribute('towns', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('homelands', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('capitals', 'name', Database::VAR_STRING, 255, true);

        $database->createRelationship(
            collection: 'towns',
            relatedCollection: 'homelands',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
            id: 'homeland'
        );
        $database->createRelationship(
            collection: 'homelands',
            relatedCollection: 'capitals',
            type: Database::RELATION_ONE_TO_ONE,
            twoWay: true,
            id: 'capital',
            twoWayKey: 'homeland'
        );

        $database->createDocument('towns', new Document([
            '$id' => 'town1',
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'name' => 'City 1',
            'homeland' => [
                '$id' => 'homeland1',
                '$permissions' => [
                    Permission::read(Role::any()),
                ],
                'name' => 'Country 1',
                'capital' => [
                    '$id' => 'capital1',
                    '$permissions' => [
                        Permission::read(Role::any()),
                    ],
                    'name' => 'Flag 1',
                ],
            ],
        ]));

        $town1 = $database->getDocument('towns', 'town1');
        $this->assertEquals('homeland1', $town1['homeland']['$id']);
        $this->assertArrayNotHasKey('towns', $town1['homeland']);
        $this->assertEquals('capital1', $town1['homeland']['capital']['$id']);
        $this->assertArrayNotHasKey('homeland', $town1['homeland']['capital']);

        $database->createDocument('capitals', new Document([
            '$id' => 'capital2',
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'name' => 'Flag 2',
            'homeland' => [
                '$id' => 'homeland2',
                '$permissions' => [
                    Permission::read(Role::any()),
                ],
                'name' => 'Country 2',
                'towns' => [
                    [
                        '$id' => 'town2',
                        '$permissions' => [
                            Permission::read(Role::any()),
                        ],
                        'name' => 'Town 2',
                    ],
                    [
                        '$id' => 'town3',
                        '$permissions' => [
                            Permission::read(Role::any()),
                        ],
                        'name' => 'Town 3',
                    ],
                ],
            ],
        ]));

        $capital2 = $database->getDocument('capitals', 'capital2');
        $this->assertEquals('homeland2', $capital2['homeland']['$id']);
        $this->assertArrayNotHasKey('capital', $capital2['homeland']);
        $this->assertEquals(2, \count($capital2['homeland']['towns']));
        $this->assertEquals('town2', $capital2['homeland']['towns'][0]['$id']);
        $this->assertEquals('town3', $capital2['homeland']['towns'][1]['$id']);
    }

    public function testNestedManyToOne_OneToManyRelationship(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('players');
        $database->createCollection('teams');
        $database->createCollection('supporters');

        $database->createAttribute('players', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('teams', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('supporters', 'name', Database::VAR_STRING, 255, true);

        $database->createRelationship(
            collection: 'players',
            relatedCollection: 'teams',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
            id: 'team'
        );
        $database->createRelationship(
            collection: 'teams',
            relatedCollection: 'supporters',
            type: Database::RELATION_ONE_TO_MANY,
            twoWay: true,
            id: 'supporters',
            twoWayKey: 'team'
        );

        $database->createDocument('players', new Document([
            '$id' => 'player1',
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'name' => 'Player 1',
            'team' => [
                '$id' => 'team1',
                '$permissions' => [
                    Permission::read(Role::any()),
                ],
                'name' => 'Team 1',
                'supporters' => [
                    [
                        '$id' => 'supporter1',
                        '$permissions' => [
                            Permission::read(Role::any()),
                        ],
                        'name' => 'Supporter 1',
                    ],
                    [
                        '$id' => 'supporter2',
                        '$permissions' => [
                            Permission::read(Role::any()),
                        ],
                        'name' => 'Supporter 2',
                    ],
                ],
            ],
        ]));

        $player1 = $database->getDocument('players', 'player1');
        $this->assertEquals('team1', $player1['team']['$id']);
        $this->assertArrayNotHasKey('players', $player1['team']);
        $this->assertEquals(2, \count($player1['team']['supporters']));
        $this->assertEquals('supporter1', $player1['team']['supporters'][0]['$id']);
        $this->assertEquals('supporter2', $player1['team']['supporters'][1]['$id']);

        $database->createDocument('supporters', new Document([
            '$id' => 'supporter3',
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'name' => 'Supporter 3',
            'team' => [
                '$id' => 'team2',
                '$permissions' => [
                    Permission::read(Role::any()),
                ],
                'name' => 'Team 2',
                'players' => [
                    [
                        '$id' => 'player2',
                        '$permissions' => [
                            Permission::read(Role::any()),
                        ],
                        'name' => 'Player 2',
                    ],
                    [
                        '$id' => 'player3',
                        '$permissions' => [
                            Permission::read(Role::any()),
                        ],
                        'name' => 'Player 3',
                    ],
                ],
            ],
        ]));

        $supporter3 = $database->getDocument('supporters', 'supporter3');
        $this->assertEquals('team2', $supporter3['team']['$id']);
        $this->assertArrayNotHasKey('supporters', $supporter3['team']);
        $this->assertEquals(2, \count($supporter3['team']['players']));
        $this->assertEquals('player2', $supporter3['team']['players'][0]['$id']);
        $this->assertEquals('player3', $supporter3['team']['players'][1]['$id']);
    }

    public function testNestedManyToOne_ManyToOne(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('cows');
        $database->createCollection('farms');
        $database->createCollection('farmer');

        $database->createAttribute('cows', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('farms', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('farmer', 'name', Database::VAR_STRING, 255, true);

        $database->createRelationship(
            collection: 'cows',
            relatedCollection: 'farms',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
            id: 'farm'
        );
        $database->createRelationship(
            collection: 'farms',
            relatedCollection: 'farmer',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
            id: 'farmer'
        );

        $database->createDocument('cows', new Document([
            '$id' => 'cow1',
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'name' => 'Cow 1',
            'farm' => [
                '$id' => 'farm1',
                '$permissions' => [
                    Permission::read(Role::any()),
                ],
                'name' => 'Farm 1',
                'farmer' => [
                    '$id' => 'farmer1',
                    '$permissions' => [
                        Permission::read(Role::any()),
                    ],
                    'name' => 'Farmer 1',
                ],
            ],
        ]));

        $cow1 = $database->getDocument('cows', 'cow1');
        $this->assertEquals('farm1', $cow1['farm']['$id']);
        $this->assertArrayNotHasKey('cows', $cow1['farm']);
        $this->assertEquals('farmer1', $cow1['farm']['farmer']['$id']);
        $this->assertArrayNotHasKey('farms', $cow1['farm']['farmer']);

        $database->createDocument('farmer', new Document([
            '$id' => 'farmer2',
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'name' => 'Farmer 2',
            'farms' => [
                [
                    '$id' => 'farm2',
                    '$permissions' => [
                        Permission::read(Role::any()),
                    ],
                    'name' => 'Farm 2',
                    'cows' => [
                        [
                            '$id' => 'cow2',
                            '$permissions' => [
                                Permission::read(Role::any()),
                            ],
                            'name' => 'Cow 2',
                        ],
                        [
                            '$id' => 'cow3',
                            '$permissions' => [
                                Permission::read(Role::any()),
                            ],
                            'name' => 'Cow 3',
                        ],
                    ],
                ],
            ],
        ]));

        $farmer2 = $database->getDocument('farmer', 'farmer2');
        $this->assertEquals('farm2', $farmer2['farms'][0]['$id']);
        $this->assertArrayNotHasKey('farmer', $farmer2['farms'][0]);
        $this->assertEquals(2, \count($farmer2['farms'][0]['cows']));
        $this->assertEquals('cow2', $farmer2['farms'][0]['cows'][0]['$id']);
        $this->assertEquals('cow3', $farmer2['farms'][0]['cows'][1]['$id']);
    }

    public function testNestedManyToOne_ManyToManyRelationship(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('books');
        $database->createCollection('entrants');
        $database->createCollection('rooms');

        $database->createAttribute('books', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('entrants', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('rooms', 'name', Database::VAR_STRING, 255, true);

        $database->createRelationship(
            collection: 'books',
            relatedCollection: 'entrants',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
            id: 'entrant'
        );
        $database->createRelationship(
            collection: 'entrants',
            relatedCollection: 'rooms',
            type: Database::RELATION_MANY_TO_MANY,
            twoWay: true,
        );

        $database->createDocument('books', new Document([
            '$id' => 'book1',
            '$permissions' => [
                Permission::read(Role::any()),
            ],
            'name' => 'Book 1',
            'entrant' => [
                '$id' => 'entrant1',
                '$permissions' => [
                    Permission::read(Role::any()),
                ],
                'name' => 'Entrant 1',
                'rooms' => [
                    [
                        '$id' => 'class1',
                        '$permissions' => [
                            Permission::read(Role::any()),
                        ],
                        'name' => 'Class 1',
                    ],
                    [
                        '$id' => 'class2',
                        '$permissions' => [
                            Permission::read(Role::any()),
                        ],
                        'name' => 'Class 2',
                    ],
                ],
            ],
        ]));

        $book1 = $database->getDocument('books', 'book1');
        $this->assertEquals('entrant1', $book1['entrant']['$id']);
        $this->assertArrayNotHasKey('books', $book1['entrant']);
        $this->assertEquals(2, \count($book1['entrant']['rooms']));
        $this->assertEquals('class1', $book1['entrant']['rooms'][0]['$id']);
        $this->assertEquals('class2', $book1['entrant']['rooms'][1]['$id']);
    }

    public function testExceedMaxDepthManyToOneParent(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $level1Collection = 'level1ManyToOneParent';
        $level2Collection = 'level2ManyToOneParent';
        $level3Collection = 'level3ManyToOneParent';
        $level4Collection = 'level4ManyToOneParent';

        $database->createCollection($level1Collection);
        $database->createCollection($level2Collection);
        $database->createCollection($level3Collection);
        $database->createCollection($level4Collection);

        $database->createRelationship(
            collection: $level1Collection,
            relatedCollection: $level2Collection,
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
        );
        $database->createRelationship(
            collection: $level2Collection,
            relatedCollection: $level3Collection,
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
        );
        $database->createRelationship(
            collection: $level3Collection,
            relatedCollection: $level4Collection,
            type: Database::RELATION_MANY_TO_ONE,
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

    public function testManyToOneRelationshipKeyWithSymbols(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('$symbols_coll.ection5');
        $database->createCollection('$symbols_coll.ection6');

        $database->createRelationship(
            collection: '$symbols_coll.ection5',
            relatedCollection: '$symbols_coll.ection6',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
        );

        $doc1 = $database->createDocument('$symbols_coll.ection6', new Document([
            '$id' => ID::unique(),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any())
            ]
        ]));
        $doc2 = $database->createDocument('$symbols_coll.ection5', new Document([
            '$id' => ID::unique(),
            '$symbols_coll.ection6' => $doc1->getId(),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any())
            ]
        ]));

        $doc1 = $database->getDocument('$symbols_coll.ection6', $doc1->getId());
        $doc2 = $database->getDocument('$symbols_coll.ection5', $doc2->getId());

        $this->assertEquals($doc2->getId(), $doc1->getAttribute('$symbols_coll.ection5')[0]->getId());
        $this->assertEquals($doc1->getId(), $doc2->getAttribute('$symbols_coll.ection6')->getId());
    }


    public function testRecreateManyToOneOneWayRelationshipFromParent(): void
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
            type: Database::RELATION_MANY_TO_ONE,
        );

        $database->deleteRelationship('one', 'two');

        $result = $database->createRelationship(
            collection: 'one',
            relatedCollection: 'two',
            type: Database::RELATION_MANY_TO_ONE,
        );

        $this->assertTrue($result);

        $database->deleteCollection('one');
        $database->deleteCollection('two');
    }

    public function testRecreateManyToOneOneWayRelationshipFromChild(): void
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
            type: Database::RELATION_MANY_TO_ONE,
        );

        $database->deleteRelationship('two', 'one');

        $result = $database->createRelationship(
            collection: 'one',
            relatedCollection: 'two',
            type: Database::RELATION_MANY_TO_ONE,
        );

        $this->assertTrue($result);

        $database->deleteCollection('one');
        $database->deleteCollection('two');
    }

    public function testRecreateManyToOneTwoWayRelationshipFromParent(): void
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
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
        );

        $database->deleteRelationship('one', 'two');

        $result = $database->createRelationship(
            collection: 'one',
            relatedCollection: 'two',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
        );

        $this->assertTrue($result);

        $database->deleteCollection('one');
        $database->deleteCollection('two');
    }
    public function testRecreateManyToOneTwoWayRelationshipFromChild(): void
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
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
        );

        $database->deleteRelationship('two', 'one');

        $result = $database->createRelationship(
            collection: 'one',
            relatedCollection: 'two',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
        );

        $this->assertTrue($result);

        $database->deleteCollection('one');
        $database->deleteCollection('two');
    }

    public function testDeleteBulkDocumentsManyToOneRelationship(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships() || !$database->getAdapter()->getSupportForBatchOperations()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $this->getDatabase()->createCollection('bulk_delete_person_m2o');
        $this->getDatabase()->createCollection('bulk_delete_library_m2o');

        $this->getDatabase()->createAttribute('bulk_delete_person_m2o', 'name', Database::VAR_STRING, 255, true);
        $this->getDatabase()->createAttribute('bulk_delete_library_m2o', 'name', Database::VAR_STRING, 255, true);
        $this->getDatabase()->createAttribute('bulk_delete_library_m2o', 'area', Database::VAR_STRING, 255, true);

        // Many-to-One Relationship
        $this->getDatabase()->createRelationship(
            collection: 'bulk_delete_person_m2o',
            relatedCollection: 'bulk_delete_library_m2o',
            type: Database::RELATION_MANY_TO_ONE,
            onDelete: Database::RELATION_MUTATE_RESTRICT
        );

        $person1 = $this->getDatabase()->createDocument('bulk_delete_person_m2o', new Document([
            '$id' => 'person1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Person 1',
            'bulk_delete_library_m2o' => [
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

        $person2 = $this->getDatabase()->createDocument('bulk_delete_person_m2o', new Document([
            '$id' => 'person2',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Person 2',
            'bulk_delete_library_m2o' => [
                '$id' => 'library1',
            ]
        ]));

        $person1 = $this->getDatabase()->getDocument('bulk_delete_person_m2o', 'person1');
        $library = $person1->getAttribute('bulk_delete_library_m2o');
        $this->assertEquals('library1', $library['$id']);

        // Delete library
        try {
            $this->getDatabase()->deleteDocuments('bulk_delete_library_m2o');
            $this->fail('Failed to throw exception');
        } catch (RestrictedException $e) {
            $this->assertEquals('Cannot delete document because it has at least one related document.', $e->getMessage());
        }

        $this->assertEquals(2, count($this->getDatabase()->find('bulk_delete_person_m2o')));

        // Test delete people
        $this->getDatabase()->deleteDocuments('bulk_delete_person_m2o');
        $this->assertEquals(0, count($this->getDatabase()->find('bulk_delete_person_m2o')));

        // Restrict Cleanup
        $this->getDatabase()->deleteDocuments('bulk_delete_library_m2o');
        $this->assertCount(0, $this->getDatabase()->find('bulk_delete_library_m2o'));

        $this->getDatabase()->deleteDocuments('bulk_delete_person_m2o');
        $this->assertCount(0, $this->getDatabase()->find('bulk_delete_person_m2o'));
    }
    public function testUpdateParentAndChild_ManyToOne(): void
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

        $parentCollection = 'parent_combined_m2o';
        $childCollection = 'child_combined_m2o';

        $database->createCollection($parentCollection);
        $database->createCollection($childCollection);

        $database->createAttribute($parentCollection, 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute($childCollection, 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute($childCollection, 'parentNumber', Database::VAR_INTEGER, 0, false);

        $database->createRelationship(
            collection: $childCollection,
            relatedCollection: $parentCollection,
            type: Database::RELATION_MANY_TO_ONE,
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
            $this->assertInstanceOf(Structure::class, $e);
        }

        // parent remains unaffected
        $parentDocAfter = $database->getDocument($parentCollection, 'parent1');
        $this->assertEquals('Parent 1 Updated', $parentDocAfter->getAttribute('name'), 'Parent should not be affected by failed child update');

        $database->deleteCollection($parentCollection);
        $database->deleteCollection($childCollection);
    }

    public function testDeleteDocumentsRelationshipErrorDoesNotDeleteParent_ManyToOne(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships() || !$database->getAdapter()->getSupportForBatchOperations()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $parentCollection = 'parent_relationship_error_many_to_one';
        $childCollection = 'child_relationship_error_many_to_one';

        $database->createCollection($parentCollection);
        $database->createCollection($childCollection);
        $database->createAttribute($parentCollection, 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute($childCollection, 'name', Database::VAR_STRING, 255, true);

        $database->createRelationship(
            collection: $childCollection,
            relatedCollection: $parentCollection,
            type: Database::RELATION_MANY_TO_ONE,
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
        ]));

        $child = $database->createDocument($childCollection, new Document([
            '$id' => 'child1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Child 1',
            $parentCollection => 'parent1'
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

    public function testPartialUpdateManyToOneParentSide(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('companies');
        $database->createCollection('employees');

        $database->createAttribute('companies', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('employees', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('employees', 'salary', Database::VAR_INTEGER, 0, false);

        $database->createRelationship(
            collection: 'employees',
            relatedCollection: 'companies',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
            id: 'company',
            twoWayKey: 'employees'
        );

        // Create company
        $database->createDocument('companies', new Document([
            '$id' => 'company1',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'name' => 'Tech Corp',
        ]));

        $database->createDocument('companies', new Document([
            '$id' => 'company2',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'name' => 'Design Inc',
        ]));

        // Create employee with company (MANY_TO_ONE from employee side)
        $database->createDocument('employees', new Document([
            '$id' => 'emp1',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'name' => 'Alice',
            'salary' => 100000,
            'company' => 'company1',
        ]));

        // Partial update from child (employee) side - update only salary, preserve company
        $database->updateDocument('employees', 'emp1', new Document([
            '$id' => 'emp1',
            '$collection' => 'employees',
            'salary' => 120000,
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
        ]));

        $emp = $database->getDocument('employees', 'emp1');
        $this->assertEquals('Alice', $emp->getAttribute('name'), 'Name should be preserved');
        $this->assertEquals(120000, $emp->getAttribute('salary'), 'Salary should be updated');
        $this->assertEquals('company1', $emp->getAttribute('company')->getId(), 'Company relationship should be preserved');

        // Partial update - change only company relationship
        $database->updateDocument('employees', 'emp1', new Document([
            '$id' => 'emp1',
            '$collection' => 'employees',
            'company' => 'company2',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
        ]));

        $emp = $database->getDocument('employees', 'emp1');
        $this->assertEquals('Alice', $emp->getAttribute('name'), 'Name should be preserved');
        $this->assertEquals(120000, $emp->getAttribute('salary'), 'Salary should be preserved');
        $this->assertEquals('company2', $emp->getAttribute('company')->getId(), 'Company should be updated');

        $database->deleteCollection('companies');
        $database->deleteCollection('employees');
    }

    public function testPartialUpdateManyToOneChildSide(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('departments');
        $database->createCollection('staff');

        $database->createAttribute('departments', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('departments', 'budget', Database::VAR_INTEGER, 0, false);
        $database->createAttribute('staff', 'name', Database::VAR_STRING, 255, true);

        $database->createRelationship(
            collection: 'staff',
            relatedCollection: 'departments',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
            id: 'department',
            twoWayKey: 'staff'
        );

        // Create department with staff
        $database->createDocument('departments', new Document([
            '$id' => 'dept1',
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
            'name' => 'Engineering',
            'budget' => 1000000,
            'staff' => [
                ['$id' => 'staff1', '$permissions' => [Permission::read(Role::any())], 'name' => 'Bob'],
                ['$id' => 'staff2', '$permissions' => [Permission::read(Role::any())], 'name' => 'Carol'],
            ],
        ]));

        // Partial update from parent (department) side - update budget only, preserve staff
        $database->updateDocument('departments', 'dept1', new Document([
            '$id' => 'dept1',
            '$collection' => 'departments',
            'budget' => 1200000,
            '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
        ]));

        $dept = $database->getDocument('departments', 'dept1');
        $this->assertEquals('Engineering', $dept->getAttribute('name'), 'Name should be preserved');
        $this->assertEquals(1200000, $dept->getAttribute('budget'), 'Budget should be updated');
        $this->assertCount(2, $dept->getAttribute('staff'), 'Staff should be preserved');

        $database->deleteCollection('departments');
        $database->deleteCollection('staff');
    }

    /**
     * Deleting the child must clear the parent foreign keys even when the delete ran through a select query.
     */
    public function testManyToOneSetNullAfterSelectDelete(): void
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

        $database->createCollection('mto_select_parent', permissions: $collectionPermissions, documentSecurity: true);
        $database->createCollection('mto_select_child', permissions: $collectionPermissions, documentSecurity: true);
        $database->createAttribute('mto_select_parent', 'name', Database::VAR_STRING, 255, false);
        $database->createAttribute('mto_select_child', 'name', Database::VAR_STRING, 255, false);

        $database->createRelationship(
            collection: 'mto_select_parent',
            relatedCollection: 'mto_select_child',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
            id: 'child',
            twoWayKey: 'parent',
            onDelete: Database::RELATION_MUTATE_SET_NULL,
        );

        $database->createDocument('mto_select_child', new Document([
            '$id' => 'child1',
            '$permissions' => $documentPermissions,
            'name' => 'Child',
        ]));

        $database->createDocument('mto_select_parent', new Document([
            '$id' => 'parent1',
            '$permissions' => $documentPermissions,
            'name' => 'Parent',
            'child' => 'child1',
        ]));

        // A select query turns relationship population off, so the deleted document
        // reaches deleteSetNull() without its relationship value
        $database->deleteDocuments('mto_select_child', [
            Query::select(['$id', 'name']),
            Query::equal('$id', ['child1']),
        ]);

        $survivor = $database->getDocument('mto_select_parent', 'parent1');
        $this->assertFalse($survivor->isEmpty());
        $this->assertNull($survivor->getAttribute('child'));
    }

    /**
     * A referencing document the caller cannot read must still have its foreign key
     * cleared, otherwise it is left pointing at a deleted row.
     */
    public function testManyToOneSetNullClearsUnreadableReferences(): void
    {
        $database = static::getDatabase();

        // No collection-level read: only document permissions grant access
        $database->createCollection('mto_hidden_product', permissions: [
            Permission::create(Role::any()),
        ], documentSecurity: true);
        $database->createCollection('mto_hidden_store', permissions: [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
            Permission::delete(Role::any()),
        ], documentSecurity: true);

        $database->createAttribute('mto_hidden_product', 'name', Database::VAR_STRING, 255, false);
        $database->createAttribute('mto_hidden_store', 'name', Database::VAR_STRING, 255, false);

        $database->createRelationship(
            collection: 'mto_hidden_product',
            relatedCollection: 'mto_hidden_store',
            type: Database::RELATION_MANY_TO_ONE,
            twoWay: true,
            id: 'store',
            twoWayKey: 'products',
            onDelete: Database::RELATION_MUTATE_SET_NULL,
        );

        $database->createDocument('mto_hidden_store', new Document([
            '$id' => 'store1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Store',
        ]));

        $database->createDocument('mto_hidden_product', new Document([
            '$id' => 'product1',
            '$permissions' => [
                Permission::read(Role::user('someone-else')),
                Permission::update(Role::user('someone-else')),
            ],
            'name' => 'Hidden Product',
            'store' => 'store1',
        ]));

        $this->assertTrue($database->getDocument('mto_hidden_product', 'product1')->isEmpty());

        $this->assertTrue($database->deleteDocument('mto_hidden_store', 'store1'));

        $hidden = $database->getAuthorization()->skip(
            fn () => $database->getDocument('mto_hidden_product', 'product1')
        );
        $this->assertFalse($hidden->isEmpty());
        $this->assertNull($hidden->getAttribute('store'));
    }
}
