<?php

namespace Tests\E2E\Adapter\Scopes;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception;
use Utopia\Database\Exception\Index as IndexException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

trait SpatialTests
{
    public function testSpatialCollection(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        $collectionName = "test_spatial_Col";
        if (!$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        };
        $attributes = [
            new Document([
                '$id' => ID::custom('attribute1'),
                'type' => Database::VAR_STRING,
                'size' => 256,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ]),
            new Document([
                '$id' => ID::custom('attribute2'),
                'type' => Database::VAR_POINT,
                'size' => 0,
                'required' => true,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ])
        ];

        $indexes = [
            new Document([
                '$id' => ID::custom('index1'),
                'type' => Database::INDEX_KEY,
                'attributes' => ['attribute1'],
                'lengths' => [256],
                'orders' => [],
            ]),
            new Document([
                '$id' => ID::custom('index2'),
                'type' => Database::INDEX_SPATIAL,
                'attributes' => ['attribute2'],
                'lengths' => [],
                'orders' => [],
            ]),
        ];

        $col =  $database->createCollection($collectionName, $attributes, $indexes);

        $this->assertIsArray($col->getAttribute('attributes'));
        $this->assertCount(2, $col->getAttribute('attributes'));

        $this->assertIsArray($col->getAttribute('indexes'));
        $this->assertCount(2, $col->getAttribute('indexes'));

        $col = $database->getCollection($collectionName);
        $this->assertIsArray($col->getAttribute('attributes'));
        $this->assertCount(2, $col->getAttribute('attributes'));

        $this->assertIsArray($col->getAttribute('indexes'));
        $this->assertCount(2, $col->getAttribute('indexes'));

        $database->createAttribute($collectionName, 'attribute3', Database::VAR_POINT, 0, true);
        $database->createIndex($collectionName, ID::custom("index3"), Database::INDEX_SPATIAL, ['attribute3']);

        $col = $database->getCollection($collectionName);
        $this->assertIsArray($col->getAttribute('attributes'));
        $this->assertCount(3, $col->getAttribute('attributes'));

        $this->assertIsArray($col->getAttribute('indexes'));
        $this->assertCount(3, $col->getAttribute('indexes'));

        $database->deleteCollection($collectionName);
    }

    public function testSpatialTypeDocuments(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        if (!$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionName = 'test_spatial_doc_';
        try {

            // Create collection first
            $database->createCollection($collectionName);

            // Create spatial attributes using createAttribute method
            $this->assertEquals(true, $database->createAttribute($collectionName, 'pointAttr', Database::VAR_POINT, 0, $database->getAdapter()->getSupportForSpatialIndexNull() ? false : true));
            $this->assertEquals(true, $database->createAttribute($collectionName, 'lineAttr', Database::VAR_LINESTRING, 0, $database->getAdapter()->getSupportForSpatialIndexNull() ? false : true));
            $this->assertEquals(true, $database->createAttribute($collectionName, 'polyAttr', Database::VAR_POLYGON, 0, $database->getAdapter()->getSupportForSpatialIndexNull() ? false : true));

            // Create spatial indexes
            $this->assertEquals(true, $database->createIndex($collectionName, 'point_spatial', Database::INDEX_SPATIAL, ['pointAttr']));
            $this->assertEquals(true, $database->createIndex($collectionName, 'line_spatial', Database::INDEX_SPATIAL, ['lineAttr']));
            $this->assertEquals(true, $database->createIndex($collectionName, 'poly_spatial', Database::INDEX_SPATIAL, ['polyAttr']));

            $point = [5.0, 5.0];
            $linestring = [[1.0, 2.0], [3.0, 4.0]];
            $polygon = [[[0.0, 0.0], [0.0, 10.0], [10.0, 10.0], [10.0, 0.0], [0.0, 0.0]]];

            // Create test document
            $doc1 = new Document([
                '$id' => 'doc1',
                'pointAttr' => $point,
                'lineAttr' => $linestring,
                'polyAttr' => $polygon,
                '$permissions' => [Permission::update(Role::any()), Permission::read(Role::any())]
            ]);
            $createdDoc = $database->createDocument($collectionName, $doc1);
            $this->assertInstanceOf(Document::class, $createdDoc);
            $this->assertEquals($point, $createdDoc->getAttribute('pointAttr'));
            $this->assertEquals($linestring, $createdDoc->getAttribute('lineAttr'));
            $this->assertEquals($polygon, $createdDoc->getAttribute('polyAttr'));

            $createdDoc = $database->getDocument($collectionName, 'doc1');
            $this->assertInstanceOf(Document::class, $createdDoc);
            $this->assertEquals($point, $createdDoc->getAttribute('pointAttr'));
            $this->assertEquals($linestring, $createdDoc->getAttribute('lineAttr'));
            $this->assertEquals($polygon, $createdDoc->getAttribute('polyAttr'));

            // Update spatial data
            $doc1->setAttribute('pointAttr', [6.0, 6.0]);
            $updatedDoc = $database->updateDocument($collectionName, 'doc1', $doc1);

            $this->assertEquals([6.0, 6.0], $updatedDoc->getAttribute('pointAttr'));


            // Test spatial queries with appropriate operations for each geometry type
            // Point attribute tests - use operations valid for points
            $pointQueries = [
                'equals' => Query::equal('pointAttr', [[6.0, 6.0]]),
                'notEquals' => Query::notEqual('pointAttr', [[1.0, 1.0]]),
                'distanceEqual' => Query::distanceEqual('pointAttr', [5.0, 5.0], 1.4142135623730951),
                'distanceNotEqual' => Query::distanceNotEqual('pointAttr', [1.0, 1.0], 0.0),
                'intersects' => Query::intersects('pointAttr', [6.0, 6.0]),
                'notIntersects' => Query::notIntersects('pointAttr', [1.0, 1.0])
            ];

            foreach ($pointQueries as $queryType => $query) {
                $result = $database->find($collectionName, [$query], Database::PERMISSION_READ);
                $this->assertNotEmpty($result, sprintf('Failed spatial query: %s on pointAttr', $queryType));
                $this->assertEquals('doc1', $result[0]->getId(), sprintf('Incorrect document returned for %s on pointAttr', $queryType));
            }

            // LineString attribute tests - use operations valid for linestrings
            $lineQueries = [
                'contains' => Query::contains('lineAttr', [[1.0, 2.0]]), // Point on the line (endpoint)
                'notContains' => Query::notContains('lineAttr', [[5.0, 6.0]]), // Point not on the line
                'equals' => query::equal('lineAttr', [[[1.0, 2.0], [3.0, 4.0]]]), // Exact same linestring
                'notEquals' => query::notEqual('lineAttr', [[[5.0, 6.0], [7.0, 8.0]]]), // Different linestring
                'intersects' => Query::intersects('lineAttr', [1.0, 2.0]), // Point on the line should intersect
                'notIntersects' => Query::notIntersects('lineAttr', [5.0, 6.0]) // Point not on the line should not intersect
            ];

            foreach ($lineQueries as $queryType => $query) {
                if (!$database->getAdapter()->getSupportForBoundaryInclusiveContains() && in_array($queryType, ['contains','notContains'])) {
                    continue;
                }
                $result = $database->find($collectionName, [$query], Database::PERMISSION_READ);
                $this->assertNotEmpty($result, sprintf('Failed spatial query: %s on polyAttr', $queryType));
                $this->assertEquals('doc1', $result[0]->getId(), sprintf('Incorrect document returned for %s on polyAttr', $queryType));
            }

            // Distance queries for linestring attribute
            $lineDistanceQueries = [
                'distanceEqual' => Query::distanceEqual('lineAttr', [[1.0, 2.0], [3.0, 4.0]], 0.0),
                'distanceNotEqual' => Query::distanceNotEqual('lineAttr', [[5.0, 6.0], [7.0, 8.0]], 0.0),
                'distanceLessThan' => Query::distanceLessThan('lineAttr', [[1.0, 2.0], [3.0, 4.0]], 0.1),
                'distanceGreaterThan' => Query::distanceGreaterThan('lineAttr', [[5.0, 6.0], [7.0, 8.0]], 0.1)
            ];

            foreach ($lineDistanceQueries as $queryType => $query) {
                $result = $database->find($collectionName, [$query], Database::PERMISSION_READ);
                $this->assertNotEmpty($result, sprintf('Failed distance query: %s on lineAttr', $queryType));
                $this->assertEquals('doc1', $result[0]->getId(), sprintf('Incorrect document for distance %s on lineAttr', $queryType));
            }

            // Polygon attribute tests - use operations valid for polygons
            $polyQueries = [
                'contains' => Query::contains('polyAttr', [[5.0, 5.0]]), // Point inside polygon
                'notContains' => Query::notContains('polyAttr', [[15.0, 15.0]]), // Point outside polygon
                'intersects' => Query::intersects('polyAttr', [0.0, 0.0]), // Point inside polygon should intersect
                'notIntersects' => Query::notIntersects('polyAttr', [15.0, 15.0]), // Point outside polygon should not intersect
                'equals' => query::equal('polyAttr', [[
                    [
                        [0.0, 0.0],
                        [0.0, 10.0],
                        [10.0, 10.0],
                        [10.0, 0.0],
                        [0.0, 0.0]
                    ]
                ]]), // Exact same polygon
                'notEquals' => query::notEqual('polyAttr', [[[[20.0, 20.0], [20.0, 30.0], [30.0, 30.0], [20.0, 20.0]]]]), // Different polygon
                'overlaps' => Query::overlaps('polyAttr', [[[5.0, 5.0], [5.0, 15.0], [15.0, 15.0], [15.0, 5.0], [5.0, 5.0]]]), // Overlapping polygon
                'notOverlaps' => Query::notOverlaps('polyAttr', [[[20.0, 20.0], [20.0, 30.0], [30.0, 30.0], [30.0, 20.0], [20.0, 20.0]]]) // Non-overlapping polygon
            ];

            foreach ($polyQueries as $queryType => $query) {
                if (!$database->getAdapter()->getSupportForBoundaryInclusiveContains() && in_array($queryType, ['contains','notContains'])) {
                    continue;
                }
                $result = $database->find($collectionName, [$query], Database::PERMISSION_READ);
                $this->assertNotEmpty($result, sprintf('Failed spatial query: %s on polyAttr', $queryType));
                $this->assertEquals('doc1', $result[0]->getId(), sprintf('Incorrect document returned for %s on polyAttr', $queryType));
            }

            // Distance queries for polygon attribute
            $polyDistanceQueries = [
                'distanceEqual' => Query::distanceEqual('polyAttr', [[[0.0, 0.0], [0.0, 10.0], [10.0, 10.0], [0.0, 0.0]]], 0.0),
                'distanceNotEqual' => Query::distanceNotEqual('polyAttr', [[[20.0, 20.0], [20.0, 30.0], [30.0, 30.0], [20.0, 20.0]]], 0.0),
                'distanceLessThan' => Query::distanceLessThan('polyAttr', [[[0.0, 0.0], [0.0, 10.0], [10.0, 10.0], [0.0, 0.0]]], 0.1),
                'distanceGreaterThan' => Query::distanceGreaterThan('polyAttr', [[[20.0, 20.0], [20.0, 30.0], [30.0, 30.0], [20.0, 20.0]]], 0.1)
            ];

            foreach ($polyDistanceQueries as $queryType => $query) {
                $result = $database->find($collectionName, [$query], Database::PERMISSION_READ);
                $this->assertNotEmpty($result, sprintf('Failed distance query: %s on polyAttr', $queryType));
                $this->assertEquals('doc1', $result[0]->getId(), sprintf('Incorrect document for distance %s on polyAttr', $queryType));
            }
        } finally {
            $database->deleteCollection($collectionName);
        }
    }

    public function testSpatialRelationshipOneToOne(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();

        if (!$database->getAdapter()->getSupportForRelationships() || !$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $database->createCollection('location');
        $database->createCollection('building');

        $database->createAttribute('location', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('location', 'coordinates', Database::VAR_POINT, 0, true);
        $database->createAttribute('building', 'name', Database::VAR_STRING, 255, true);
        $database->createAttribute('building', 'area', Database::VAR_STRING, 255, true);

        // Create spatial indexes
        $database->createIndex('location', 'coordinates_spatial', Database::INDEX_SPATIAL, ['coordinates']);

        // Create building document first
        $building1 = $database->createDocument('building', new Document([
            '$id' => 'building1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Empire State Building',
            'area' => 'Manhattan',
        ]));

        $database->createRelationship(
            collection: 'location',
            relatedCollection: 'building',
            type: Database::RELATION_ONE_TO_ONE,
            id: 'building',
            twoWay: false
        );

        // Create location with spatial data and relationship
        $location1 = $database->createDocument('location', new Document([
            '$id' => 'location1',
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => 'Downtown',
            'coordinates' => [40.7128, -74.0060], // New York coordinates
            'building' => 'building1',
        ]));

        $this->assertInstanceOf(Document::class, $location1);
        $this->assertEquals([40.7128, -74.0060], $location1->getAttribute('coordinates'));

        // Check if building attribute is populated (could be ID string or Document object)
        $buildingAttr = $location1->getAttribute('building');
        if (is_string($buildingAttr)) {
            $this->assertEquals('building1', $buildingAttr);
        } else {
            $this->assertInstanceOf(Document::class, $buildingAttr);
            $this->assertEquals('building1', $buildingAttr->getId());
        }

        // Test spatial queries on related documents
        $nearbyLocations = $database->find('location', [
            Query::distanceLessThan('coordinates', [40.7128, -74.0060], 0.1)
        ], Database::PERMISSION_READ);

        $this->assertNotEmpty($nearbyLocations);
        $this->assertEquals('location1', $nearbyLocations[0]->getId());

        // Test relationship with spatial data update
        $location1->setAttribute('coordinates', [40.7589, -73.9851]); // Times Square coordinates
        $updatedLocation = $database->updateDocument('location', 'location1', $location1);

        $this->assertEquals([40.7589, -73.9851], $updatedLocation->getAttribute('coordinates'));

        // Test spatial query after update
        $timesSquareLocations = $database->find('location', [
            Query::distanceLessThan('coordinates', [40.7589, -73.9851], 0.1)
        ], Database::PERMISSION_READ);

        $this->assertNotEmpty($timesSquareLocations);
        $this->assertEquals('location1', $timesSquareLocations[0]->getId());

        // Test relationship integrity with spatial data
        $building = $database->getDocument('building', 'building1');
        $this->assertInstanceOf(Document::class, $building);
        $this->assertEquals('building1', $building->getId());

        // Test one-way relationship (building doesn't have location attribute)
        $this->assertArrayNotHasKey('location', $building->getArrayCopy());

        // Test basic relationship integrity
        $this->assertInstanceOf(Document::class, $building);
        $this->assertEquals('Empire State Building', $building->getAttribute('name'));

        // Clean up
        $database->deleteCollection('location');
        $database->deleteCollection('building');
    }

    public function testSpatialAttributes(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        if (!$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionName = 'spatial_attrs_';
        try {
            $database->createCollection($collectionName);

            $required = $database->getAdapter()->getSupportForSpatialIndexNull() ? false : true;
            $this->assertEquals(true, $database->createAttribute($collectionName, 'pointAttr', Database::VAR_POINT, 0, $required));
            $this->assertEquals(true, $database->createAttribute($collectionName, 'lineAttr', Database::VAR_LINESTRING, 0, $required));
            $this->assertEquals(true, $database->createAttribute($collectionName, 'polyAttr', Database::VAR_POLYGON, 0, $required));

            // Create spatial indexes
            $this->assertEquals(true, $database->createIndex($collectionName, 'idx_point', Database::INDEX_SPATIAL, ['pointAttr']));
            if ($database->getAdapter()->getSupportForSpatialIndexNull()) {
                $this->assertEquals(true, $database->createIndex($collectionName, 'idx_line', Database::INDEX_SPATIAL, ['lineAttr']));
            } else {
                // Attribute was created as required above; directly create index once
                $this->assertEquals(true, $database->createIndex($collectionName, 'idx_line', Database::INDEX_SPATIAL, ['lineAttr']));
            }
            $this->assertEquals(true, $database->createIndex($collectionName, 'idx_poly', Database::INDEX_SPATIAL, ['polyAttr']));

            $collection = $database->getCollection($collectionName);
            $this->assertIsArray($collection->getAttribute('attributes'));
            $this->assertCount(3, $collection->getAttribute('attributes'));
            $this->assertIsArray($collection->getAttribute('indexes'));
            $this->assertCount(3, $collection->getAttribute('indexes'));

            // Create a simple document to ensure structure is valid
            $doc = $database->createDocument($collectionName, new Document([
                '$id' => ID::custom('sdoc'),
                'pointAttr' => [1.0, 1.0],
                'lineAttr' => [[0.0, 0.0], [1.0, 1.0]],
                'polyAttr' => [[[0.0, 0.0], [0.0, 2.0], [2.0, 2.0], [0.0, 0.0]]],
                '$permissions' => [Permission::read(Role::any())]
            ]));
            $this->assertInstanceOf(Document::class, $doc);
        } finally {
            $database->deleteCollection($collectionName);
        }
    }

    public function testSpatialOneToMany(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        if (!$database->getAdapter()->getSupportForRelationships() || !$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $parent = 'regions_';
        $child = 'places_';
        try {
            $database->createCollection($parent);
            $database->createCollection($child);

            $database->createAttribute($parent, 'name', Database::VAR_STRING, 255, true);
            $database->createAttribute($child, 'name', Database::VAR_STRING, 255, true);
            $database->createAttribute($child, 'coord', Database::VAR_POINT, 0, true);
            $database->createIndex($child, 'coord_spatial', Database::INDEX_SPATIAL, ['coord']);

            $database->createRelationship(
                collection: $parent,
                relatedCollection: $child,
                type: Database::RELATION_ONE_TO_MANY,
                twoWay: true,
                id: 'places',
                twoWayKey: 'region'
            );

            $r1 = $database->createDocument($parent, new Document([
                '$id' => 'r1',
                'name' => 'Region 1',
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())]
            ]));
            $this->assertInstanceOf(Document::class, $r1);

            $p1 = $database->createDocument($child, new Document([
                '$id' => 'p1',
                'name' => 'Place 1',
                'coord' => [10.0, 10.0],
                'region' => 'r1',
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())]
            ]));
            $p2 = $database->createDocument($child, new Document([
                '$id' => 'p2',
                'name' => 'Place 2',
                'coord' => [10.1, 10.1],
                'region' => 'r1',
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())]
            ]));
            $this->assertInstanceOf(Document::class, $p1);
            $this->assertInstanceOf(Document::class, $p2);

            // Spatial query on child collection
            $near = $database->find($child, [
                Query::distanceLessThan('coord', [10.0, 10.0], 1.0)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($near);

            // Test distanceGreaterThan: places far from center (should find p2 which is 0.141 units away)
            $far = $database->find($child, [
                Query::distanceGreaterThan('coord', [10.0, 10.0], 0.05)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($far);

            // Test distanceLessThan: places very close to center (should find p1 which is exactly at center)
            $close = $database->find($child, [
                Query::distanceLessThan('coord', [10.0, 10.0], 0.2)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($close);

            // Test distanceGreaterThan with various thresholds
            // Test: places more than 0.12 units from center (should find p2)
            $moderatelyFar = $database->find($child, [
                Query::distanceGreaterThan('coord', [10.0, 10.0], 0.12)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($moderatelyFar);

            // Test: places more than 0.05 units from center (should find p2)
            $slightlyFar = $database->find($child, [
                Query::distanceGreaterThan('coord', [10.0, 10.0], 0.05)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($slightlyFar);

            // Test: places more than 10 units from center (should find none)
            $extremelyFar = $database->find($child, [
                Query::distanceGreaterThan('coord', [10.0, 10.0], 10.0)
            ], Database::PERMISSION_READ);
            $this->assertEmpty($extremelyFar);

            // Equal-distanceEqual semantics: distanceEqual (<=) and distanceNotEqual (>), threshold exactly at 0
            $equalZero = $database->find($child, [
                Query::distanceEqual('coord', [10.0, 10.0], 0.0)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($equalZero);
            $this->assertEquals('p1', $equalZero[0]->getId());

            $notEqualZero = $database->find($child, [
                Query::distanceNotEqual('coord', [10.0, 10.0], 0.0)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($notEqualZero);
            $this->assertEquals('p2', $notEqualZero[0]->getId());

            $region = $database->getDocument($parent, 'r1');
            $this->assertArrayHasKey('places', $region);
            $this->assertEquals(2, \count($region['places']));
        } finally {
            $database->deleteCollection($child);
            $database->deleteCollection($parent);
        }
    }

    public function testSpatialManyToOne(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        if (!$database->getAdapter()->getSupportForRelationships() || !$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $parent = 'cities_';
        $child = 'stops_';
        try {
            $database->createCollection($parent);
            $database->createCollection($child);

            $database->createAttribute($parent, 'name', Database::VAR_STRING, 255, true);
            $database->createAttribute($child, 'name', Database::VAR_STRING, 255, true);
            $database->createAttribute($child, 'coord', Database::VAR_POINT, 0, true);
            $database->createIndex($child, 'coord_spatial', Database::INDEX_SPATIAL, ['coord']);

            $database->createRelationship(
                collection: $child,
                relatedCollection: $parent,
                type: Database::RELATION_MANY_TO_ONE,
                twoWay: true,
                id: 'city',
                twoWayKey: 'stops'
            );

            $c1 = $database->createDocument($parent, new Document([
                '$id' => 'c1',
                'name' => 'City 1',
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())]
            ]));

            $s1 = $database->createDocument($child, new Document([
                '$id' => 's1',
                'name' => 'Stop 1',
                'coord' => [20.0, 20.0],
                'city' => 'c1',
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())]
            ]));
            $s2 = $database->createDocument($child, new Document([
                '$id' => 's2',
                'name' => 'Stop 2',
                'coord' => [20.2, 20.2],
                'city' => 'c1',
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())]
            ]));
            $this->assertInstanceOf(Document::class, $c1);
            $this->assertInstanceOf(Document::class, $s1);
            $this->assertInstanceOf(Document::class, $s2);

            $near = $database->find($child, [
                Query::distanceLessThan('coord', [20.0, 20.0], 1.0)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($near);

            // Test distanceLessThan: stops very close to center (should find s1 which is exactly at center)
            $close = $database->find($child, [
                Query::distanceLessThan('coord', [20.0, 20.0], 0.1)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($close);

            // Test distanceGreaterThan with various thresholds
            // Test: stops more than 0.25 units from center (should find s2)
            $moderatelyFar = $database->find($child, [
                Query::distanceGreaterThan('coord', [20.0, 20.0], 0.25)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($moderatelyFar);

            // Test: stops more than 0.05 units from center (should find s2)
            $slightlyFar = $database->find($child, [
                Query::distanceGreaterThan('coord', [20.0, 20.0], 0.05)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($slightlyFar);

            // Test: stops more than 5 units from center (should find none)
            $veryFar = $database->find($child, [
                Query::distanceGreaterThan('coord', [20.0, 20.0], 5.0)
            ], Database::PERMISSION_READ);
            $this->assertEmpty($veryFar);

            // Equal-distanceEqual semantics: distanceEqual (<=) and distanceNotEqual (>), threshold exactly at 0
            $equalZero = $database->find($child, [
                Query::distanceEqual('coord', [20.0, 20.0], 0.0)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($equalZero);
            $this->assertEquals('s1', $equalZero[0]->getId());

            $notEqualZero = $database->find($child, [
                Query::distanceNotEqual('coord', [20.0, 20.0], 0.0)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($notEqualZero);
            $this->assertEquals('s2', $notEqualZero[0]->getId());

            $city = $database->getDocument($parent, 'c1');
            $this->assertArrayHasKey('stops', $city);
            $this->assertEquals(2, \count($city['stops']));
        } finally {
            $database->deleteCollection($child);
            $database->deleteCollection($parent);
        }
    }

    public function testSpatialManyToMany(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        if (!$database->getAdapter()->getSupportForRelationships() || !$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $a = 'drivers_';
        $b = 'routes_';
        try {
            $database->createCollection($a);
            $database->createCollection($b);

            $database->createAttribute($a, 'name', Database::VAR_STRING, 255, true);
            $database->createAttribute($a, 'home', Database::VAR_POINT, 0, true);
            $database->createIndex($a, 'home_spatial', Database::INDEX_SPATIAL, ['home']);
            $database->createAttribute($b, 'title', Database::VAR_STRING, 255, true);
            $database->createAttribute($b, 'area', Database::VAR_POLYGON, 0, true);
            $database->createIndex($b, 'area_spatial', Database::INDEX_SPATIAL, ['area']);

            $database->createRelationship(
                collection: $a,
                relatedCollection: $b,
                type: Database::RELATION_MANY_TO_MANY,
                twoWay: true,
                id: 'routes',
                twoWayKey: 'drivers'
            );

            $d1 = $database->createDocument($a, new Document([
                '$id' => 'd1',
                'name' => 'Driver 1',
                'home' => [30.0, 30.0],
                'routes' => [
                    [
                        '$id' => 'rte1',
                        'title' => 'Route 1',
                        'area' => [[[29.5,29.5],[29.5,30.5],[30.5,30.5],[29.5,29.5]]]
                    ]
                ],
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())]
            ]));
            $this->assertInstanceOf(Document::class, $d1);

            // Spatial query on "drivers" using point distanceEqual
            $near = $database->find($a, [
                Query::distanceLessThan('home', [30.0, 30.0], 0.5)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($near);

            // Test distanceGreaterThan: drivers far from center (using large threshold to find the driver)
            $far = $database->find($a, [
                Query::distanceGreaterThan('home', [30.0, 30.0], 100.0)
            ], Database::PERMISSION_READ);
            $this->assertEmpty($far);

            // Test distanceLessThan: drivers very close to center (should find d1 which is exactly at center)
            $close = $database->find($a, [
                Query::distanceLessThan('home', [30.0, 30.0], 0.1)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($close);

            // Test distanceGreaterThan with various thresholds
            // Test: drivers more than 0.05 units from center (should find none since d1 is exactly at center)
            $slightlyFar = $database->find($a, [
                Query::distanceGreaterThan('home', [30.0, 30.0], 0.05)
            ], Database::PERMISSION_READ);
            $this->assertEmpty($slightlyFar);

            // Test: drivers more than 0.001 units from center (should find none since d1 is exactly at center)
            $verySlightlyFar = $database->find($a, [
                Query::distanceGreaterThan('home', [30.0, 30.0], 0.001)
            ], Database::PERMISSION_READ);
            $this->assertEmpty($verySlightlyFar);

            // Test: drivers more than 0.5 units from center (should find none since d1 is at center)
            $moderatelyFar = $database->find($a, [
                Query::distanceGreaterThan('home', [30.0, 30.0], 0.5)
            ], Database::PERMISSION_READ);
            $this->assertEmpty($moderatelyFar);

            // Equal-distanceEqual semantics: distanceEqual (<=) and distanceNotEqual (>), threshold exactly at 0
            $equalZero = $database->find($a, [
                Query::distanceEqual('home', [30.0, 30.0], 0.0)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($equalZero);
            $this->assertEquals('d1', $equalZero[0]->getId());

            $notEqualZero = $database->find($a, [
                Query::distanceNotEqual('home', [30.0, 30.0], 0.0)
            ], Database::PERMISSION_READ);
            $this->assertEmpty($notEqualZero);

            // Ensure relationship present
            $d1 = $database->getDocument($a, 'd1');
            $this->assertArrayHasKey('routes', $d1);
            $this->assertEquals(1, \count($d1['routes']));
        } finally {
            $database->deleteCollection($b);
            $database->deleteCollection($a);
        }
    }

    public function testSpatialIndex(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        if (!$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        // Basic spatial index create/delete
        $collectionName = 'spatial_index_';
        try {
            $database->createCollection($collectionName);
            $database->createAttribute($collectionName, 'loc', Database::VAR_POINT, 0, true);
            $this->assertEquals(true, $database->createIndex($collectionName, 'loc_spatial', Database::INDEX_SPATIAL, ['loc']));

            $collection = $database->getCollection($collectionName);
            $this->assertIsArray($collection->getAttribute('indexes'));
            $this->assertCount(1, $collection->getAttribute('indexes'));
            $this->assertEquals('loc_spatial', $collection->getAttribute('indexes')[0]['$id']);
            $this->assertEquals(Database::INDEX_SPATIAL, $collection->getAttribute('indexes')[0]['type']);

            $this->assertEquals(true, $database->deleteIndex($collectionName, 'loc_spatial'));
            $collection = $database->getCollection($collectionName);
            $this->assertCount(0, $collection->getAttribute('indexes'));
        } finally {
            $database->deleteCollection($collectionName);
        }

        // Edge cases: Spatial Index Order support (createCollection and createIndex)
        $orderSupported = $database->getAdapter()->getSupportForSpatialIndexOrder();

        // createCollection with orders
        $collOrderCreate = 'spatial_idx_order_create';
        try {
            $attributes = [new Document([
                '$id' => ID::custom('loc'),
                'type' => Database::VAR_POINT,
                'size' => 0,
                'required' => true,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ])];
            $indexes = [new Document([
                '$id' => ID::custom('idx_loc'),
                'type' => Database::INDEX_SPATIAL,
                'attributes' => ['loc'],
                'lengths' => [],
                'orders' => $orderSupported ? [Database::ORDER_ASC] : ['ASC'],
            ])];

            if ($orderSupported) {
                $database->createCollection($collOrderCreate, $attributes, $indexes);
                $meta = $database->getCollection($collOrderCreate);
                $this->assertEquals('idx_loc', $meta->getAttribute('indexes')[0]['$id']);
            } else {
                try {
                    $database->createCollection($collOrderCreate, $attributes, $indexes);
                    $this->fail('Expected exception when orders are provided for spatial index on unsupported adapter');
                } catch (\Throwable $e) {
                    $this->assertStringContainsString('Spatial index', $e->getMessage());
                }
            }
        } finally {
            if ($orderSupported) {
                $database->deleteCollection($collOrderCreate);
            }
        }

        // createIndex with orders
        $collOrderIndex = 'spatial_idx_order_index_' . uniqid();
        try {
            $database->createCollection($collOrderIndex);
            $database->createAttribute($collOrderIndex, 'loc', Database::VAR_POINT, 0, true);
            if ($orderSupported) {
                $this->assertTrue($database->createIndex($collOrderIndex, 'idx_loc', Database::INDEX_SPATIAL, ['loc'], [], [Database::ORDER_DESC]));
            } else {
                try {
                    $database->createIndex($collOrderIndex, 'idx_loc', Database::INDEX_SPATIAL, ['loc'], [], ['DESC']);
                    $this->fail('Expected exception when orders are provided for spatial index on unsupported adapter');
                } catch (\Throwable $e) {
                    $this->assertStringContainsString('Spatial index', $e->getMessage());
                }
            }
        } finally {
            $database->deleteCollection($collOrderIndex);
        }

        // Edge cases: Spatial Index Nullability (createCollection and createIndex)
        $nullSupported = $database->getAdapter()->getSupportForSpatialIndexNull();

        // createCollection with required=false
        $collNullCreate = 'spatial_idx_null_create_' . uniqid();
        try {
            $attributes = [new Document([
                '$id' => ID::custom('loc'),
                'type' => Database::VAR_POINT,
                'size' => 0,
                'required' => false, // edge case
                'signed' => true,
                'array' => false,
                'filters' => [],
            ])];
            $indexes = [new Document([
                '$id' => ID::custom('idx_loc'),
                'type' => Database::INDEX_SPATIAL,
                'attributes' => ['loc'],
                'lengths' => [],
                'orders' => [],
            ])];

            if ($nullSupported) {
                $database->createCollection($collNullCreate, $attributes, $indexes);
                $meta = $database->getCollection($collNullCreate);
                $this->assertEquals('idx_loc', $meta->getAttribute('indexes')[0]['$id']);
            } else {
                try {
                    $database->createCollection($collNullCreate, $attributes, $indexes);
                    $this->fail('Expected exception when spatial index is created on NULL-able geometry attribute');
                } catch (\Throwable $e) {
                    $this->assertTrue(true); // exception expected; exact message is adapter-specific
                }
            }
        } finally {
            if ($nullSupported) {
                $database->deleteCollection($collNullCreate);
            }
        }

        // createIndex with required=false
        $collNullIndex = 'spatial_idx_null_index_' . uniqid();
        try {
            $database->createCollection($collNullIndex);
            $database->createAttribute($collNullIndex, 'loc', Database::VAR_POINT, 0, false);
            if ($nullSupported) {
                $this->assertTrue($database->createIndex($collNullIndex, 'idx_loc', Database::INDEX_SPATIAL, ['loc']));
            } else {
                try {
                    $database->createIndex($collNullIndex, 'idx_loc', Database::INDEX_SPATIAL, ['loc']);
                    $this->fail('Expected exception when spatial index is created on NULL-able geometry attribute');
                } catch (\Throwable $e) {
                    $this->assertTrue(true); // exception expected; exact message is adapter-specific
                }
            }
        } finally {
            $database->deleteCollection($collNullIndex);
        }

        $collUpdateNull = 'spatial_idx_req';
        try {
            $database->createCollection($collUpdateNull);

            $database->createAttribute($collUpdateNull, 'loc', Database::VAR_POINT, 0, false);
            if (!$nullSupported) {
                try {
                    $database->createIndex($collUpdateNull, 'idx_loc_required', Database::INDEX_SPATIAL, ['loc']);
                    $this->fail('Expected exception when creating spatial index on NULL-able attribute');
                } catch (\Throwable $e) {
                    $this->assertInstanceOf(Exception::class, $e);
                }
            } else {
                $this->assertTrue($database->createIndex($collUpdateNull, 'idx_loc', Database::INDEX_SPATIAL, ['loc']));
            }

            $database->updateAttribute($collUpdateNull, 'loc', required: true);

            $this->assertTrue($database->createIndex($collUpdateNull, 'idx_loc_req', Database::INDEX_SPATIAL, ['loc']));
        } finally {
            $database->deleteCollection($collUpdateNull);
        }


        $collUpdateNull = 'spatial_idx_index_null_required_true';
        try {
            $database->createCollection($collUpdateNull);

            $database->createAttribute($collUpdateNull, 'loc', Database::VAR_POINT, 0, false);
            if (!$nullSupported) {
                try {
                    $database->createIndex($collUpdateNull, 'idx_loc', Database::INDEX_SPATIAL, ['loc']);
                    $this->fail('Expected exception when creating spatial index on NULL-able attribute');
                } catch (\Throwable $e) {
                    $this->assertInstanceOf(Exception::class, $e);
                }
            } else {
                $this->assertTrue($database->createIndex($collUpdateNull, 'idx_loc', Database::INDEX_SPATIAL, ['loc']));
            }

            $database->updateAttribute($collUpdateNull, 'loc', required: true);

            $this->assertTrue($database->createIndex($collUpdateNull, 'new index', Database::INDEX_SPATIAL, ['loc']));
        } finally {
            $database->deleteCollection($collUpdateNull);
        }
    }

    public function testComplexGeometricShapes(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        if (!$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionName = 'complex_shapes_';
        try {
            $database->createCollection($collectionName);

            // Create spatial attributes for different geometric shapes
            $this->assertEquals(true, $database->createAttribute($collectionName, 'rectangle', Database::VAR_POLYGON, 0, true));
            $this->assertEquals(true, $database->createAttribute($collectionName, 'square', Database::VAR_POLYGON, 0, true));
            $this->assertEquals(true, $database->createAttribute($collectionName, 'triangle', Database::VAR_POLYGON, 0, true));
            $this->assertEquals(true, $database->createAttribute($collectionName, 'circle_center', Database::VAR_POINT, 0, true));
            $this->assertEquals(true, $database->createAttribute($collectionName, 'complex_polygon', Database::VAR_POLYGON, 0, true));
            $this->assertEquals(true, $database->createAttribute($collectionName, 'multi_linestring', Database::VAR_LINESTRING, 0, true));

            // Create spatial indexes
            $this->assertEquals(true, $database->createIndex($collectionName, 'idx_rectangle', Database::INDEX_SPATIAL, ['rectangle']));
            $this->assertEquals(true, $database->createIndex($collectionName, 'idx_square', Database::INDEX_SPATIAL, ['square']));
            $this->assertEquals(true, $database->createIndex($collectionName, 'idx_triangle', Database::INDEX_SPATIAL, ['triangle']));
            $this->assertEquals(true, $database->createIndex($collectionName, 'idx_circle_center', Database::INDEX_SPATIAL, ['circle_center']));
            $this->assertEquals(true, $database->createIndex($collectionName, 'idx_complex_polygon', Database::INDEX_SPATIAL, ['complex_polygon']));
            $this->assertEquals(true, $database->createIndex($collectionName, 'idx_multi_linestring', Database::INDEX_SPATIAL, ['multi_linestring']));

            // Create documents with different geometric shapes
            $doc1 = new Document([
                '$id' => 'rect1',
                'rectangle' => [[[0, 0], [0, 10], [20, 10], [20, 0], [0, 0]]], // 20x10 rectangle
                'square' => [[[5, 5], [5, 15], [15, 15], [15, 5], [5, 5]]], // 10x10 square
                'triangle' => [[[25, 0], [35, 20], [15, 20], [25, 0]]], // triangle
                'circle_center' => [10, 5], // center of rectangle
                'complex_polygon' => [[[0, 0], [0, 20], [20, 20], [20, 15], [15, 15], [15, 5], [20, 5], [20, 0], [0, 0]]], // L-shaped polygon
                'multi_linestring' => [[0, 0], [10, 10], [20, 0], [0, 20], [20, 20]], // single linestring with multiple points
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())]
            ]);

            $doc2 = new Document([
                '$id' => 'rect2',
                'rectangle' => [[[30, 0], [30, 8], [50, 8], [50, 0], [30, 0]]], // 20x8 rectangle
                'square' => [[[35, 10], [35, 20], [45, 20], [45, 10], [35, 10]]], // 10x10 square
                'triangle' => [[[55, 0], [65, 15], [45, 15], [55, 0]]], // triangle
                'circle_center' => [40, 4], // center of second rectangle
                'complex_polygon' => [[[30, 0], [30, 20], [50, 20], [50, 10], [40, 10], [40, 0], [30, 0]]], // T-shaped polygon
                'multi_linestring' => [[30, 0], [40, 10], [50, 0], [30, 20], [50, 20]], // single linestring with multiple points
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())]
            ]);

            $createdDoc1 = $database->createDocument($collectionName, $doc1);
            $createdDoc2 = $database->createDocument($collectionName, $doc2);

            $this->assertInstanceOf(Document::class, $createdDoc1);
            $this->assertInstanceOf(Document::class, $createdDoc2);

            // Test rectangle contains point
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $insideRect1 = $database->find($collectionName, [
                    Query::contains('rectangle', [[5, 5]]) // Point inside first rectangle
                ], Database::PERMISSION_READ);
                $this->assertNotEmpty($insideRect1);
                $this->assertEquals('rect1', $insideRect1[0]->getId());
            }

            // Test rectangle doesn't contain point outside
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $outsideRect1 = $database->find($collectionName, [
                    Query::notContains('rectangle', [[25, 25]]) // Point outside first rectangle
                ], Database::PERMISSION_READ);
                $this->assertNotEmpty($outsideRect1);
            }

            // Test failure case: rectangle should NOT contain distant point
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $distantPoint = $database->find($collectionName, [
                    Query::contains('rectangle', [[100, 100]]) // Point far outside rectangle
                ], Database::PERMISSION_READ);
                $this->assertEmpty($distantPoint);
            }

            // Test failure case: rectangle should NOT contain point outside
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $outsidePoint = $database->find($collectionName, [
                    Query::contains('rectangle', [[-1, -1]]) // Point clearly outside rectangle
                ], Database::PERMISSION_READ);
                $this->assertEmpty($outsidePoint);
            }

            // Test rectangle intersects with another rectangle
            $overlappingRect = $database->find($collectionName, [
                Query::and([
                    Query::intersects('rectangle', [[15, 5], [15, 15], [25, 15], [25, 5], [15, 5]]),
                    Query::notTouches('rectangle', [[15, 5], [15, 15], [25, 15], [25, 5], [15, 5]])
                ]),
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($overlappingRect);


            // Test square contains point
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $insideSquare1 = $database->find($collectionName, [
                    Query::contains('square', [[10, 10]]) // Point inside first square
                ], Database::PERMISSION_READ);
                $this->assertNotEmpty($insideSquare1);
                $this->assertEquals('rect1', $insideSquare1[0]->getId());
            }

            // Test rectangle contains square (shape contains shape)
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $rectContainsSquare = $database->find($collectionName, [
                    Query::contains('rectangle', [[[5, 2], [5, 8], [15, 8], [15, 2], [5, 2]]]) // Square geometry that fits within rectangle
                ], Database::PERMISSION_READ);
                $this->assertNotEmpty($rectContainsSquare);
                $this->assertEquals('rect1', $rectContainsSquare[0]->getId());
            }

            // Test rectangle contains triangle (shape contains shape)
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $rectContainsTriangle = $database->find($collectionName, [
                    Query::contains('rectangle', [[[10, 2], [18, 2], [14, 8], [10, 2]]]) // Triangle geometry that fits within rectangle
                ], Database::PERMISSION_READ);
                $this->assertNotEmpty($rectContainsTriangle);
                $this->assertEquals('rect1', $rectContainsTriangle[0]->getId());
            }

            // Test L-shaped polygon contains smaller rectangle (shape contains shape)
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $lShapeContainsRect = $database->find($collectionName, [
                    Query::contains('complex_polygon', [[[5, 5], [5, 10], [10, 10], [10, 5], [5, 5]]]) // Small rectangle inside L-shape
                ], Database::PERMISSION_READ);
                $this->assertNotEmpty($lShapeContainsRect);
                $this->assertEquals('rect1', $lShapeContainsRect[0]->getId());
            }

            // Test T-shaped polygon contains smaller square (shape contains shape)
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $tShapeContainsSquare = $database->find($collectionName, [
                    Query::contains('complex_polygon', [[[35, 5], [35, 10], [40, 10], [40, 5], [35, 5]]]) // Small square inside T-shape
                ], Database::PERMISSION_READ);
                $this->assertNotEmpty($tShapeContainsSquare);
                $this->assertEquals('rect2', $tShapeContainsSquare[0]->getId());
            }

            // Test failure case: square should NOT contain rectangle (smaller shape cannot contain larger shape)
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $squareNotContainsRect = $database->find($collectionName, [
                    Query::notContains('square', [[[0, 0], [0, 20], [20, 20], [20, 0], [0, 0]]]) // Larger rectangle
                ], Database::PERMISSION_READ);
                $this->assertNotEmpty($squareNotContainsRect);
            }

            // Test failure case: triangle should NOT contain rectangle
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $triangleNotContainsRect = $database->find($collectionName, [
                    Query::notContains('triangle', [[[20, 0], [20, 25], [30, 25], [30, 0], [20, 0]]]) // Rectangle that extends beyond triangle
                ], Database::PERMISSION_READ);
                $this->assertNotEmpty($triangleNotContainsRect);
            }

            // Test failure case: L-shape should NOT contain T-shape (different complex polygons)
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $lShapeNotContainsTShape = $database->find($collectionName, [
                    Query::notContains('complex_polygon', [[[30, 0], [30, 20], [50, 20], [50, 0], [30, 0]]]) // T-shape geometry
                ], Database::PERMISSION_READ);
                $this->assertNotEmpty($lShapeNotContainsTShape);
            }

            // Test square doesn't contain point outside
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $outsideSquare1 = $database->find($collectionName, [
                    Query::notContains('square', [[20, 20]]) // Point outside first square
                ], Database::PERMISSION_READ);
                $this->assertNotEmpty($outsideSquare1);
            }

            // Test failure case: square should NOT contain distant point
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $distantPointSquare = $database->find($collectionName, [
                    Query::contains('square', [[100, 100]]) // Point far outside square
                ], Database::PERMISSION_READ);
                $this->assertEmpty($distantPointSquare);
            }

            // Test failure case: square should NOT contain point on boundary
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $boundaryPointSquare = $database->find($collectionName, [
                    Query::contains('square', [[5, 5]]) // Point on square boundary (should be empty if boundary not inclusive)
                ], Database::PERMISSION_READ);
                // Note: This may or may not be empty depending on boundary inclusivity
            }

            // Test square equals same geometry using contains when supported, otherwise intersects
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $exactSquare = $database->find($collectionName, [
                    Query::contains('square', [[[5, 5], [5, 15], [15, 15], [15, 5], [5, 5]]])
                ], Database::PERMISSION_READ);
            } else {
                $exactSquare = $database->find($collectionName, [
                    Query::intersects('square', [[5, 5], [5, 15], [15, 15], [15, 5], [5, 5]])
                ], Database::PERMISSION_READ);
            }
            $this->assertNotEmpty($exactSquare);
            $this->assertEquals('rect1', $exactSquare[0]->getId());

            // Test square doesn't equal different square
            $differentSquare = $database->find($collectionName, [
                query::notEqual('square', [[[0, 0], [0, 10], [10, 10], [10, 0], [0, 0]]]) // Different square
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($differentSquare);

            // Test triangle contains point
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $insideTriangle1 = $database->find($collectionName, [
                    Query::contains('triangle', [[25, 10]]) // Point inside first triangle
                ], Database::PERMISSION_READ);
                $this->assertNotEmpty($insideTriangle1);
                $this->assertEquals('rect1', $insideTriangle1[0]->getId());
            }

            // Test triangle doesn't contain point outside
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $outsideTriangle1 = $database->find($collectionName, [
                    Query::notContains('triangle', [[25, 25]]) // Point outside first triangle
                ], Database::PERMISSION_READ);
                $this->assertNotEmpty($outsideTriangle1);
            }

            // Test failure case: triangle should NOT contain distant point
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $distantPointTriangle = $database->find($collectionName, [
                    Query::contains('triangle', [[100, 100]]) // Point far outside triangle
                ], Database::PERMISSION_READ);
                $this->assertEmpty($distantPointTriangle);
            }

            // Test failure case: triangle should NOT contain point outside its area
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $outsideTriangleArea = $database->find($collectionName, [
                    Query::contains('triangle', [[35, 25]]) // Point outside triangle area
                ], Database::PERMISSION_READ);
                $this->assertEmpty($outsideTriangleArea);
            }

            // Test triangle intersects with point
            $intersectingTriangle = $database->find($collectionName, [
                Query::intersects('triangle', [25, 10]) // Point inside triangle should intersect
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($intersectingTriangle);

            // Test triangle doesn't intersect with distant point
            $nonIntersectingTriangle = $database->find($collectionName, [
                Query::notIntersects('triangle', [10, 10]) // Distant point should not intersect
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($nonIntersectingTriangle);

            // Test L-shaped polygon contains point
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $insideLShape = $database->find($collectionName, [
                    Query::contains('complex_polygon', [[10, 10]]) // Point inside L-shape
                ], Database::PERMISSION_READ);
                $this->assertNotEmpty($insideLShape);
                $this->assertEquals('rect1', $insideLShape[0]->getId());
            }

            // Test L-shaped polygon doesn't contain point in "hole"
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $inHole = $database->find($collectionName, [
                    Query::notContains('complex_polygon', [[17, 10]]) // Point in the "hole" of L-shape
                ], Database::PERMISSION_READ);
                $this->assertNotEmpty($inHole);
            }

            // Test failure case: L-shaped polygon should NOT contain distant point
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $distantPointLShape = $database->find($collectionName, [
                    Query::contains('complex_polygon', [[100, 100]]) // Point far outside L-shape
                ], Database::PERMISSION_READ);
                $this->assertEmpty($distantPointLShape);
            }

            // Test failure case: L-shaped polygon should NOT contain point in the hole
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $holePoint = $database->find($collectionName, [
                    Query::contains('complex_polygon', [[17, 10]]) // Point in the "hole" of L-shape
                ], Database::PERMISSION_READ);
                $this->assertEmpty($holePoint);
            }

            // Test T-shaped polygon contains point
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $insideTShape = $database->find($collectionName, [
                    Query::contains('complex_polygon', [[40, 5]]) // Point inside T-shape
                ], Database::PERMISSION_READ);
                $this->assertNotEmpty($insideTShape);
                $this->assertEquals('rect2', $insideTShape[0]->getId());
            }

            // Test failure case: T-shaped polygon should NOT contain distant point
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $distantPointTShape = $database->find($collectionName, [
                    Query::contains('complex_polygon', [[100, 100]]) // Point far outside T-shape
                ], Database::PERMISSION_READ);
                $this->assertEmpty($distantPointTShape);
            }

            // Test failure case: T-shaped polygon should NOT contain point outside its area
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $outsideTShapeArea = $database->find($collectionName, [
                    Query::contains('complex_polygon', [[25, 25]]) // Point outside T-shape area
                ], Database::PERMISSION_READ);
                $this->assertEmpty($outsideTShapeArea);
            }

            // Test complex polygon intersects with line
            $intersectingLine = $database->find($collectionName, [
                Query::intersects('complex_polygon', [[0, 10], [20, 10]]) // Horizontal line through L-shape
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($intersectingLine);

            // Test linestring contains point
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $onLine1 = $database->find($collectionName, [
                    Query::contains('multi_linestring', [[5, 5]]) // Point on first line segment
                ], Database::PERMISSION_READ);
                $this->assertNotEmpty($onLine1);
            }

            // Test linestring doesn't contain point off line
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $offLine1 = $database->find($collectionName, [
                    Query::notContains('multi_linestring', [[5, 15]]) // Point not on any line
                ], Database::PERMISSION_READ);
                $this->assertNotEmpty($offLine1);
            }

            // Test linestring intersects with point
            $intersectingPoint = $database->find($collectionName, [
                Query::intersects('multi_linestring', [10, 10]) // Point on diagonal line
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($intersectingPoint);

            // Test linestring intersects with a horizontal line coincident at y=20
            $touchingLine = $database->find($collectionName, [
                Query::intersects('multi_linestring', [[0, 20], [20, 20]])
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($touchingLine);

            // Test distanceEqual queries between shapes
            $nearCenter = $database->find($collectionName, [
                Query::distanceLessThan('circle_center', [10, 5], 5.0) // Points within 5 units of first center
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($nearCenter);
            $this->assertEquals('rect1', $nearCenter[0]->getId());

            // Test distanceEqual queries to find nearby shapes
            $nearbyShapes = $database->find($collectionName, [
                Query::distanceLessThan('circle_center', [40, 4], 15.0) // Points within 15 units of second center
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($nearbyShapes);
            $this->assertEquals('rect2', $nearbyShapes[0]->getId());

            // Test distanceGreaterThan queries
            $farShapes = $database->find($collectionName, [
                Query::distanceGreaterThan('circle_center', [10, 5], 10.0) // Points more than 10 units from first center
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($farShapes);
            $this->assertEquals('rect2', $farShapes[0]->getId());

            // Test distanceLessThan queries
            $closeShapes = $database->find($collectionName, [
                Query::distanceLessThan('circle_center', [10, 5], 3.0) // Points less than 3 units from first center
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($closeShapes);
            $this->assertEquals('rect1', $closeShapes[0]->getId());

            // Test distanceGreaterThan queries with various thresholds
            // Test: points more than 20 units from first center (should find rect2)
            $veryFarShapes = $database->find($collectionName, [
                Query::distanceGreaterThan('circle_center', [10, 5], 20.0)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($veryFarShapes);
            $this->assertEquals('rect2', $veryFarShapes[0]->getId());

            // Test: points more than 5 units from second center (should find rect1)
            $farFromSecondCenter = $database->find($collectionName, [
                Query::distanceGreaterThan('circle_center', [40, 4], 5.0)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($farFromSecondCenter);
            $this->assertEquals('rect1', $farFromSecondCenter[0]->getId());

            // Test: points more than 30 units from origin (should find only rect2)
            $farFromOrigin = $database->find($collectionName, [
                Query::distanceGreaterThan('circle_center', [0, 0], 30.0)
            ], Database::PERMISSION_READ);
            $this->assertCount(1, $farFromOrigin);

            // Equal-distanceEqual semantics for circle_center
            // rect1 is exactly at [10,5], so distanceEqual 0
            $equalZero = $database->find($collectionName, [
                Query::distanceEqual('circle_center', [10, 5], 0.0)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($equalZero);
            $this->assertEquals('rect1', $equalZero[0]->getId());

            $notEqualZero = $database->find($collectionName, [
                Query::distanceNotEqual('circle_center', [10, 5], 0.0)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($notEqualZero);
            $this->assertEquals('rect2', $notEqualZero[0]->getId());

            // Additional distance queries for complex shapes (polygon and linestring)
            $rectDistanceEqual = $database->find($collectionName, [
                Query::distanceEqual('rectangle', [[[0, 0], [0, 10], [20, 10], [20, 0], [0, 0]]], 0.0)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($rectDistanceEqual);
            $this->assertEquals('rect1', $rectDistanceEqual[0]->getId());

            $lineDistanceEqual = $database->find($collectionName, [
                Query::distanceEqual('multi_linestring', [[0, 0], [10, 10], [20, 0], [0, 20], [20, 20]], 0.0)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($lineDistanceEqual);
            $this->assertEquals('rect1', $lineDistanceEqual[0]->getId());

        } finally {
            $database->deleteCollection($collectionName);
        }
    }

    public function testSpatialQueryCombinations(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        if (!$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionName = 'spatial_combinations_';
        try {
            $database->createCollection($collectionName);

            // Create spatial attributes
            $this->assertEquals(true, $database->createAttribute($collectionName, 'location', Database::VAR_POINT, 0, true));
            $this->assertEquals(true, $database->createAttribute($collectionName, 'area', Database::VAR_POLYGON, 0, true));
            $this->assertEquals(true, $database->createAttribute($collectionName, 'route', Database::VAR_LINESTRING, 0, true));
            $this->assertEquals(true, $database->createAttribute($collectionName, 'name', Database::VAR_STRING, 255, true));

            // Create spatial indexes
            $this->assertEquals(true, $database->createIndex($collectionName, 'idx_location', Database::INDEX_SPATIAL, ['location']));
            $this->assertEquals(true, $database->createIndex($collectionName, 'idx_area', Database::INDEX_SPATIAL, ['area']));
            $this->assertEquals(true, $database->createIndex($collectionName, 'idx_route', Database::INDEX_SPATIAL, ['route']));

            // Create test documents
            $doc1 = new Document([
                '$id' => 'park1',
                'name' => 'Central Park',
                'location' => [40.7829, -73.9654],
                'area' => [[[40.7649, -73.9814], [40.7649, -73.9494], [40.8009, -73.9494], [40.8009, -73.9814], [40.7649, -73.9814]]],
                'route' => [[40.7649, -73.9814], [40.8009, -73.9494]],
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())]
            ]);

            $doc2 = new Document([
                '$id' => 'park2',
                'name' => 'Prospect Park',
                'location' => [40.6602, -73.9690],
                'area' => [[[40.6502, -73.9790], [40.6502, -73.9590], [40.6702, -73.9590], [40.6702, -73.9790], [40.6502, -73.9790]]],
                'route' => [[40.6502, -73.9790], [40.6702, -73.9590]],
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())]
            ]);

            $doc3 = new Document([
                '$id' => 'park3',
                'name' => 'Battery Park',
                'location' => [40.6033, -74.0170],
                'area' => [[[40.5933, -74.0270], [40.5933, -74.0070], [40.6133, -74.0070], [40.6133, -74.0270], [40.5933, -74.0270]]],
                'route' => [[40.5933, -74.0270], [40.6133, -74.0070]],
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())]
            ]);

            $database->createDocument($collectionName, $doc1);
            $database->createDocument($collectionName, $doc2);
            $database->createDocument($collectionName, $doc3);

            // Test complex spatial queries with logical combinations
            // Test AND combination: parks within area AND near specific location
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $nearbyAndInArea = $database->find($collectionName, [
                    Query::and([
                        Query::distanceLessThan('location', [40.7829, -73.9654], 0.01), // Near Central Park
                        Query::contains('area', [[40.7829, -73.9654]]) // Location is within area
                    ])
                ], Database::PERMISSION_READ);
                $this->assertNotEmpty($nearbyAndInArea);
                $this->assertEquals('park1', $nearbyAndInArea[0]->getId());
            }

            // Test OR combination: parks near either location
            $nearEitherLocation = $database->find($collectionName, [
                Query::or([
                    Query::distanceLessThan('location', [40.7829, -73.9654], 0.01), // Near Central Park
                    Query::distanceLessThan('location', [40.6602, -73.9690], 0.01) // Near Prospect Park
                ])
            ], Database::PERMISSION_READ);
            $this->assertCount(2, $nearEitherLocation);

            // Test distanceGreaterThan: parks far from Central Park
            $farFromCentral = $database->find($collectionName, [
                Query::distanceGreaterThan('location', [40.7829, -73.9654], 0.1) // More than 0.1 degrees from Central Park
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($farFromCentral);

            // Test distanceLessThan: parks very close to Central Park
            $veryCloseToCentral = $database->find($collectionName, [
                Query::distanceLessThan('location', [40.7829, -73.9654], 0.001) // Less than 0.001 degrees from Central Park
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($veryCloseToCentral);

            // Test distanceGreaterThan with various thresholds
            // Test: parks more than 0.3 degrees from Central Park (should find none since all parks are closer)
            $veryFarFromCentral = $database->find($collectionName, [
                Query::distanceGreaterThan('location', [40.7829, -73.9654], 0.3)
            ], Database::PERMISSION_READ);
            $this->assertCount(0, $veryFarFromCentral);

            // Test: parks more than 0.3 degrees from Prospect Park (should find other parks)
            $farFromProspect = $database->find($collectionName, [
                Query::distanceGreaterThan('location', [40.6602, -73.9690], 0.1)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($farFromProspect);

            // Test: parks more than 0.3 degrees from Times Square (should find none since all parks are closer)
            $farFromTimesSquare = $database->find($collectionName, [
                Query::distanceGreaterThan('location', [40.7589, -73.9851], 0.3)
            ], Database::PERMISSION_READ);
            $this->assertCount(0, $farFromTimesSquare);

            // Test ordering by distanceEqual from a specific point
            $orderedByDistance = $database->find($collectionName, [
                Query::distanceLessThan('location', [40.7829, -73.9654], 0.01), // Within ~1km
                Query::limit(10)
            ], Database::PERMISSION_READ);

            $this->assertNotEmpty($orderedByDistance);
            // First result should be closest to the reference point
            $this->assertEquals('park1', $orderedByDistance[0]->getId());

            // Test spatial queries with limits
            $limitedResults = $database->find($collectionName, [
                Query::distanceLessThan('location', [40.7829, -73.9654], 1.0), // Within 1 degree
                Query::limit(2)
            ], Database::PERMISSION_READ);

            $this->assertCount(2, $limitedResults);
        } finally {
            $database->deleteCollection($collectionName);
        }
    }

    public function testSpatialIsNullIsNotNull(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        if (!$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionName = 'spatial_null_checks';
        try {
            $database->createCollection($collectionName);

            $this->assertEquals(true, $database->createAttribute($collectionName, 'name', Database::VAR_STRING, 255, true));
            // Optional spatial attribute: some documents legitimately have no location set.
            $this->assertEquals(true, $database->createAttribute($collectionName, 'location', Database::VAR_POINT, 0, false));

            $database->createDocument($collectionName, new Document([
                '$id' => 'withLocation',
                'name' => 'Has a location',
                'location' => [40.7829, -73.9654],
                '$permissions' => [Permission::read(Role::any())],
            ]));

            $database->createDocument($collectionName, new Document([
                '$id' => 'withoutLocation',
                'name' => 'No location set',
                'location' => null,
                '$permissions' => [Permission::read(Role::any())],
            ]));

            $withLocation = $database->find($collectionName, [
                Query::isNotNull('location'),
            ], Database::PERMISSION_READ);
            $this->assertCount(1, $withLocation);
            $this->assertEquals('withLocation', $withLocation[0]->getId());

            $withoutLocation = $database->find($collectionName, [
                Query::isNull('location'),
            ], Database::PERMISSION_READ);
            $this->assertCount(1, $withoutLocation);
            $this->assertEquals('withoutLocation', $withoutLocation[0]->getId());
        } finally {
            $database->deleteCollection($collectionName);
        }
    }

    public function testSpatialBulkOperation(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        if (!$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionName = 'test_spatial_bulk_ops';

        // Create collection with spatial attributes
        $attributes = [
            new Document([
                '$id' => ID::custom('name'),
                'type' => Database::VAR_STRING,
                'size' => 256,
                'required' => true,
                'signed' => true,
                'array' => false,
            ]),
            new Document([
                '$id' => ID::custom('location'),
                'type' => Database::VAR_POINT,
                'size' => 0,
                'required' => true,
                'signed' => true,
                'array' => false,
            ]),
            new Document([
                '$id' => ID::custom('area'),
                'type' => Database::VAR_POLYGON,
                'size' => 0,
                'required' => false,
                'signed' => true,
                'array' => false,
            ])
        ];

        $indexes = [
            new Document([
                '$id' => ID::custom('spatial_idx'),
                'type' => Database::INDEX_SPATIAL,
                'attributes' => ['location'],
                'lengths' => [],
                'orders' => [],
            ])
        ];

        $database->createCollection($collectionName, $attributes, $indexes);

        // Test 1: createDocuments with spatial data
        $spatialDocuments = [];
        for ($i = 0; $i < 5; $i++) {
            $spatialDocuments[] = new Document([
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'name' => 'Location ' . $i,
                'location' => [10.0 + $i, 20.0 + $i], // POINT
                'area' => [
                    [10.0 + $i, 20.0 + $i],
                    [11.0 + $i, 20.0 + $i],
                    [11.0 + $i, 21.0 + $i],
                    [10.0 + $i, 21.0 + $i],
                    [10.0 + $i, 20.0 + $i]
                ] // POLYGON
            ]);
        }

        $results = [];
        $count = $database->createDocuments($collectionName, $spatialDocuments, 3, onNext: function ($doc) use (&$results) {
            $results[] = $doc;
        });

        $this->assertEquals(5, $count);
        $this->assertEquals(5, count($results));

        // Verify created documents
        foreach ($results as $document) {
            $this->assertNotEmpty($document->getId());
            $this->assertNotEmpty($document->getAttribute('name'));
            $this->assertNotEmpty($document->getSequence());
            $this->assertIsArray($document->getAttribute('location'));
            $this->assertIsArray($document->getAttribute('area'));
            $this->assertCount(2, $document->getAttribute('location')); // POINT has 2 coordinates
            $this->assertGreaterThan(1, count($document->getAttribute('area')[0])); // POLYGON has multiple points
        }

        $results = $database->find($collectionName);
        foreach ($results as $document) {
            $this->assertNotEmpty($document->getId());
            $this->assertNotEmpty($document->getAttribute('name'));
            $this->assertNotEmpty($document->getSequence());
            $this->assertIsArray($document->getAttribute('location'));
            $this->assertIsArray($document->getAttribute('area'));
            $this->assertCount(2, $document->getAttribute('location')); // POINT has 2 coordinates
            $this->assertGreaterThan(1, count($document->getAttribute('area')[0])); // POLYGON has multiple points
        }

        foreach ($results as $doc) {
            $document = $database->getDocument($collectionName, $doc->getId());
            $this->assertNotEmpty($document->getId());
            $this->assertNotEmpty($document->getAttribute('name'));
            $this->assertEquals($document->getAttribute('name'), $doc->getAttribute('name'));
            $this->assertNotEmpty($document->getSequence());
            $this->assertIsArray($document->getAttribute('location'));
            $this->assertIsArray($document->getAttribute('area'));
            $this->assertCount(2, $document->getAttribute('location')); // POINT has 2 coordinates
            $this->assertGreaterThan(1, count($document->getAttribute('area')[0])); // POLYGON has multiple points
        }

        $results = $database->find($collectionName, [Query::select(["name"])]);
        foreach ($results as $document) {
            $this->assertNotEmpty($document->getAttribute('name'));
        }

        $results = $database->find($collectionName, [Query::select(["location"])]);
        foreach ($results as $document) {
            $this->assertCount(2, $document->getAttribute('location')); // POINT has 2 coordinates
        }

        $results = $database->find($collectionName, [Query::select(["area","location"])]);
        foreach ($results as $document) {
            $this->assertCount(2, $document->getAttribute('location')); // POINT has 2 coordinates
            $this->assertGreaterThan(1, count($document->getAttribute('area')[0])); // POLYGON has multiple points
        }

        // Test 2: updateDocuments with spatial data
        $updateResults = [];
        $updateCount = $database->updateDocuments($collectionName, new Document([
            'name' => 'Updated Location',
            'location' => [15.0, 25.0], // New POINT
            'area' => [
                [15.0, 25.0],
                [16.0, 25.0],
                [16.0, 26.0],
                [15.0, 26.0],
                [15.0, 25.0]
            ] // New POLYGON
        ]), [
            Query::greaterThanEqual('$sequence', $results[0]->getSequence())
        ], onNext: function ($doc) use (&$updateResults) {
            $updateResults[] = $doc;
        });

        // should fail due to invalid structure
        try {
            $database->updateDocuments($collectionName, new Document([
                'name' => 'Updated Location',
                'location' => [15.0, 25.0],
                'area' => [15.0, 25.0] // invalid polygon
            ]));
            $this->fail("fail to throw structure exception for the invalid spatial type");
        } catch (\Throwable $th) {
            $this->assertInstanceOf(StructureException::class, $th);

        }

        $this->assertGreaterThan(0, $updateCount);

        // Verify updated documents
        foreach ($updateResults as $document) {
            $this->assertEquals('Updated Location', $document->getAttribute('name'));
            $this->assertEquals([15.0, 25.0], $document->getAttribute('location'));
            $this->assertEquals([[
                [15.0, 25.0],
                [16.0, 25.0],
                [16.0, 26.0],
                [15.0, 26.0],
                [15.0, 25.0]
            ]], $document->getAttribute('area'));
        }

        // Test 3: upsertDocuments with spatial data
        $upsertDocuments = [
            new Document([
                '$id' => 'upsert1',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'name' => 'Upsert Location 1',
                'location' => [30.0, 40.0],
                'area' => [
                    [30.0, 40.0],
                    [31.0, 40.0],
                    [31.0, 41.0],
                    [30.0, 41.0],
                    [30.0, 40.0]
                ]
            ]),
            new Document([
                '$id' => 'upsert2',
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'name' => 'Upsert Location 2',
                'location' => [35.0, 45.0],
                'area' => [
                    [35.0, 45.0],
                    [36.0, 45.0],
                    [36.0, 46.0],
                    [35.0, 46.0],
                    [35.0, 45.0]
                ]
            ])
        ];

        $upsertResults = [];
        $upsertCount = $database->upsertDocuments($collectionName, $upsertDocuments, onNext: function ($doc) use (&$upsertResults) {
            $upsertResults[] = $doc;
        });

        $this->assertEquals(2, $upsertCount);
        $this->assertEquals(2, count($upsertResults));

        // Verify upserted documents
        foreach ($upsertResults as $document) {
            $this->assertNotEmpty($document->getId());
            $this->assertNotEmpty($document->getSequence());
            $this->assertIsArray($document->getAttribute('location'));
            $this->assertIsArray($document->getAttribute('area'));
        }

        // Test 4: Query spatial data after bulk operations
        $allDocuments = $database->find($collectionName, [
            Query::orderAsc('$sequence')
        ]);

        $this->assertGreaterThan(5, count($allDocuments)); // Should have original 5 + upserted 2

        // Test 5: Spatial queries on bulk created data
        $nearbyDocuments = $database->find($collectionName, [
            Query::distanceLessThan('location', [15.0, 25.0], 1.0) // Find documents within 1 unit
        ]);

        $this->assertGreaterThan(0, count($nearbyDocuments));

        // Test 6: distanceGreaterThan queries on bulk created data
        $farDocuments = $database->find($collectionName, [
            Query::distanceGreaterThan('location', [15.0, 25.0], 5.0) // Find documents more than 5 units away
        ]);

        $this->assertGreaterThan(0, count($farDocuments));

        // Test 7: distanceLessThan queries on bulk created data
        $closeDocuments = $database->find($collectionName, [
            Query::distanceLessThan('location', [15.0, 25.0], 0.5) // Find documents less than 0.5 units away
        ]);

        $this->assertGreaterThan(0, count($closeDocuments));

        // Test 8: Additional distanceGreaterThan queries on bulk created data
        $veryFarDocuments = $database->find($collectionName, [
            Query::distanceGreaterThan('location', [15.0, 25.0], 10.0) // Find documents more than 10 units away
        ]);

        $this->assertGreaterThan(0, count($veryFarDocuments));

        // Test 9: distanceGreaterThan with very small threshold (should find most documents)
        $slightlyFarDocuments = $database->find($collectionName, [
            Query::distanceGreaterThan('location', [15.0, 25.0], 0.1) // Find documents more than 0.1 units away
        ]);

        $this->assertGreaterThan(0, count($slightlyFarDocuments));

        // Test 10: distanceGreaterThan with very large threshold (should find none)
        $extremelyFarDocuments = $database->find($collectionName, [
            Query::distanceGreaterThan('location', [15.0, 25.0], 100.0) // Find documents more than 100 units away
        ]);

        $this->assertEquals(0, count($extremelyFarDocuments));

        // Test 11: Update specific spatial documents
        $specificUpdateCount = $database->updateDocuments($collectionName, new Document([
            'name' => 'Specifically Updated'
        ]), [
            Query::equal('$id', ['upsert1'])
        ]);

        $this->assertEquals(1, $specificUpdateCount);

        // Verify the specific update
        $specificDoc = $database->find($collectionName, [
            Query::equal('$id', ['upsert1'])
        ]);

        $this->assertCount(1, $specificDoc);
        $this->assertEquals('Specifically Updated', $specificDoc[0]->getAttribute('name'));

        // Cleanup
        $database->deleteCollection($collectionName);
    }

    public function testSptialAggregation(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        if (!$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }
        $collectionName = 'spatial_agg_';
        try {
            // Create collection with spatial and numeric attributes
            $database->createCollection($collectionName);
            $database->createAttribute($collectionName, 'name', Database::VAR_STRING, 255, true);
            $database->createAttribute($collectionName, 'loc', Database::VAR_POINT, 0, true);
            $database->createAttribute($collectionName, 'area', Database::VAR_POLYGON, 0, true);
            $database->createAttribute($collectionName, 'score', Database::VAR_INTEGER, 0, true);

            // Spatial indexes
            $database->createIndex($collectionName, 'idx_loc', Database::INDEX_SPATIAL, ['loc']);
            $database->createIndex($collectionName, 'idx_area', Database::INDEX_SPATIAL, ['area']);

            // Seed documents
            $a = $database->createDocument($collectionName, new Document([
                '$id' => 'a',
                'name' => 'A',
                'loc' => [10.0, 10.0],
                'area' => [[[9.0, 9.0], [9.0, 11.0], [11.0, 11.0], [11.0, 9.0], [9.0, 9.0]]],
                'score' => 10,
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())]
            ]));
            $b = $database->createDocument($collectionName, new Document([
                '$id' => 'b',
                'name' => 'B',
                'loc' => [10.05, 10.05],
                'area' => [[[9.5, 9.5], [9.5, 10.6], [10.6, 10.6], [10.6, 9.5], [9.5, 9.5]]],
                'score' => 20,
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())]
            ]));
            $c = $database->createDocument($collectionName, new Document([
                '$id' => 'c',
                'name' => 'C',
                'loc' => [50.0, 50.0],
                'area' => [[[49.0, 49.0], [49.0, 51.0], [51.0, 51.0], [51.0, 49.0], [49.0, 49.0]]],
                'score' => 30,
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())]
            ]));

            $this->assertInstanceOf(Document::class, $a);
            $this->assertInstanceOf(Document::class, $b);
            $this->assertInstanceOf(Document::class, $c);

            // COUNT with spatial distanceEqual filter
            $queries = [
                Query::distanceLessThan('loc', [10.0, 10.0], 0.1)
            ];
            $this->assertEquals(2, $database->count($collectionName, $queries));
            $this->assertCount(2, $database->find($collectionName, $queries));

            // SUM with spatial distanceEqual filter
            $sumNear = $database->sum($collectionName, 'score', $queries);
            $this->assertEquals(10 + 20, $sumNear);

            // COUNT and SUM with distanceGreaterThan (should only include far point "c")
            $queriesFar = [
                Query::distanceGreaterThan('loc', [10.0, 10.0], 10.0)
            ];
            $this->assertEquals(1, $database->count($collectionName, $queriesFar));
            $this->assertEquals(30, $database->sum($collectionName, 'score', $queriesFar));

            // COUNT and SUM with polygon contains filter (adapter-dependent boundary inclusivity)
            if ($database->getAdapter()->getSupportForBoundaryInclusiveContains()) {
                $queriesContain = [
                    Query::contains('area', [[10.0, 10.0]])
                ];
                $this->assertEquals(2, $database->count($collectionName, $queriesContain));
                $this->assertEquals(30, $database->sum($collectionName, 'score', $queriesContain));

                $queriesNotContain = [
                    Query::notContains('area', [[10.0, 10.0]])
                ];
                $this->assertEquals(1, $database->count($collectionName, $queriesNotContain));
                $this->assertEquals(30, $database->sum($collectionName, 'score', $queriesNotContain));
            }
        } finally {
            $database->deleteCollection($collectionName);
        }
    }

    public function testUpdateSpatialAttributes(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        if (!$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionName = 'spatial_update_attrs_';
        try {
            $database->createCollection($collectionName);

            // 0) Disallow creation of spatial attributes with size or array
            try {
                $database->createAttribute($collectionName, 'geom_bad_size', Database::VAR_POINT, 10, true);
                $this->fail('Expected DatabaseException when creating spatial attribute with non-zero size');
            } catch (\Throwable $e) {
                $this->assertInstanceOf(Exception::class, $e);
            }

            try {
                $database->createAttribute($collectionName, 'geom_bad_array', Database::VAR_POINT, 0, true, array: true);
                $this->fail('Expected DatabaseException when creating spatial attribute with array=true');
            } catch (\Throwable $e) {
                $this->assertInstanceOf(Exception::class, $e);
            }

            // Create a single spatial attribute (required=true)
            $this->assertEquals(true, $database->createAttribute($collectionName, 'geom', Database::VAR_POINT, 0, true));
            $this->assertEquals(true, $database->createIndex($collectionName, 'idx_geom', Database::INDEX_SPATIAL, ['geom']));

            // 1) Disallow size and array updates on spatial attributes: expect DatabaseException
            try {
                $database->updateAttribute($collectionName, 'geom', size: 10);
                $this->fail('Expected DatabaseException when updating size on spatial attribute');
            } catch (\Throwable $e) {
                $this->assertInstanceOf(Exception::class, $e);
            }

            try {
                $database->updateAttribute($collectionName, 'geom', array: true);
                $this->fail('Expected DatabaseException when updating array on spatial attribute');
            } catch (\Throwable $e) {
                $this->assertInstanceOf(Exception::class, $e);
            }

            // 2) required=true -> create index -> update required=false
            $nullSupported = $database->getAdapter()->getSupportForSpatialIndexNull();
            if ($nullSupported) {
                // Should succeed on adapters that allow nullable spatial indexes
                $database->updateAttribute($collectionName, 'geom', required: false);
                $meta = $database->getCollection($collectionName);
                $this->assertEquals(false, $meta->getAttribute('attributes')[0]['required']);
            } else {
                // Should error (index constraint) when making required=false while spatial index exists
                $threw = false;
                try {
                    $database->updateAttribute($collectionName, 'geom', required: false);
                } catch (\Throwable $e) {
                    $threw = true;
                }
                $this->assertTrue($threw, 'Expected error when setting required=false with existing spatial index and adapter not supporting nullable indexes');
                // Ensure attribute remains required
                $meta = $database->getCollection($collectionName);
                $this->assertEquals(true, $meta->getAttribute('attributes')[0]['required']);
            }

            // 3) Spatial index order support: providing orders should fail if not supported
            $orderSupported = $database->getAdapter()->getSupportForSpatialIndexOrder();
            if ($orderSupported) {
                $this->assertTrue($database->createIndex($collectionName, 'idx_geom_desc', Database::INDEX_SPATIAL, ['geom'], [], [Database::ORDER_DESC]));
                // cleanup
                $this->assertTrue($database->deleteIndex($collectionName, 'idx_geom_desc'));
            } else {
                try {
                    $database->createIndex($collectionName, 'idx_geom_desc', Database::INDEX_SPATIAL, ['geom'], [], ['DESC']);
                    $this->fail('Expected error when providing orders for spatial index on adapter without order support');
                } catch (\Throwable $e) {
                    $this->assertTrue(true);
                }
            }
        } finally {
            $database->deleteCollection($collectionName);
        }
    }

    public function testSpatialAttributeDefaults(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        if (!$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionName = 'spatial_defaults_';
        try {
            $database->createCollection($collectionName);

            // Create spatial attributes with defaults and no indexes to avoid nullability/index constraints
            $this->assertEquals(true, $database->createAttribute($collectionName, 'pt', Database::VAR_POINT, 0, false, [1.0, 2.0]));
            $this->assertEquals(true, $database->createAttribute($collectionName, 'ln', Database::VAR_LINESTRING, 0, false, [[0.0, 0.0], [1.0, 1.0]]));
            $this->assertEquals(true, $database->createAttribute($collectionName, 'pg', Database::VAR_POLYGON, 0, false, [[[0.0, 0.0], [0.0, 2.0], [2.0, 2.0], [0.0, 0.0]]]));

            // Create non-spatial attributes (mix of defaults and no defaults)
            $this->assertEquals(true, $database->createAttribute($collectionName, 'title', Database::VAR_STRING, 255, false, 'Untitled'));
            $this->assertEquals(true, $database->createAttribute($collectionName, 'count', Database::VAR_INTEGER, 0, false, 0));
            $this->assertEquals(true, $database->createAttribute($collectionName, 'rating', Database::VAR_FLOAT, 0, false)); // no default
            $this->assertEquals(true, $database->createAttribute($collectionName, 'active', Database::VAR_BOOLEAN, 0, false, true));

            // Create document without providing spatial values, expect defaults applied
            $doc = $database->createDocument($collectionName, new Document([
                '$id' => ID::custom('d1'),
                '$permissions' => [Permission::read(Role::any())]
            ]));
            $this->assertInstanceOf(Document::class, $doc);
            $this->assertEquals([1.0, 2.0], $doc->getAttribute('pt'));
            $this->assertEquals([[0.0, 0.0], [1.0, 1.0]], $doc->getAttribute('ln'));
            $this->assertEquals([[[0.0, 0.0], [0.0, 2.0], [2.0, 2.0], [0.0, 0.0]]], $doc->getAttribute('pg'));
            // Non-spatial defaults
            $this->assertEquals('Untitled', $doc->getAttribute('title'));
            $this->assertEquals(0, $doc->getAttribute('count'));
            $this->assertNull($doc->getAttribute('rating'));
            $this->assertTrue($doc->getAttribute('active'));

            // Create document overriding defaults
            $doc2 = $database->createDocument($collectionName, new Document([
                '$id' => ID::custom('d2'),
                '$permissions' => [Permission::read(Role::any())],
                'pt' => [9.0, 9.0],
                'ln' => [[2.0, 2.0], [3.0, 3.0]],
                'pg' => [[[1.0, 1.0], [1.0, 3.0], [3.0, 3.0], [1.0, 1.0]]],
                'title' => 'Custom',
                'count' => 5,
                'rating' => 4.5,
                'active' => false
            ]));
            $this->assertInstanceOf(Document::class, $doc2);
            $this->assertEquals([9.0, 9.0], $doc2->getAttribute('pt'));
            $this->assertEquals([[2.0, 2.0], [3.0, 3.0]], $doc2->getAttribute('ln'));
            $this->assertEquals([[[1.0, 1.0], [1.0, 3.0], [3.0, 3.0], [1.0, 1.0]]], $doc2->getAttribute('pg'));
            $this->assertEquals('Custom', $doc2->getAttribute('title'));
            $this->assertEquals(5, $doc2->getAttribute('count'));
            $this->assertEquals(4.5, $doc2->getAttribute('rating'));
            $this->assertFalse($doc2->getAttribute('active'));

            // Update defaults and ensure they are applied for new documents
            $database->updateAttributeDefault($collectionName, 'pt', [5.0, 6.0]);
            $database->updateAttributeDefault($collectionName, 'ln', [[10.0, 10.0], [20.0, 20.0]]);
            $database->updateAttributeDefault($collectionName, 'pg', [[[5.0, 5.0], [5.0, 7.0], [7.0, 7.0], [5.0, 5.0]]]);
            $database->updateAttributeDefault($collectionName, 'title', 'Updated');
            $database->updateAttributeDefault($collectionName, 'count', 10);
            $database->updateAttributeDefault($collectionName, 'active', false);

            $doc3 = $database->createDocument($collectionName, new Document([
                '$id' => ID::custom('d3'),
                '$permissions' => [Permission::read(Role::any())]
            ]));
            $this->assertInstanceOf(Document::class, $doc3);
            $this->assertEquals([5.0, 6.0], $doc3->getAttribute('pt'));
            $this->assertEquals([[10.0, 10.0], [20.0, 20.0]], $doc3->getAttribute('ln'));
            $this->assertEquals([[[5.0, 5.0], [5.0, 7.0], [7.0, 7.0], [5.0, 5.0]]], $doc3->getAttribute('pg'));
            $this->assertEquals('Updated', $doc3->getAttribute('title'));
            $this->assertEquals(10, $doc3->getAttribute('count'));
            $this->assertNull($doc3->getAttribute('rating'));
            $this->assertFalse($doc3->getAttribute('active'));

            // Invalid defaults should raise errors
            try {
                $database->updateAttributeDefault($collectionName, 'pt', [[1.0, 2.0]]); // wrong dimensionality
                $this->fail('Expected exception for invalid point default shape');
            } catch (\Throwable $e) {
                $this->assertTrue(true);
            }
            try {
                $database->updateAttributeDefault($collectionName, 'ln', [1.0, 2.0]); // wrong dimensionality
                $this->fail('Expected exception for invalid linestring default shape');
            } catch (\Throwable $e) {
                $this->assertTrue(true);
            }
            try {
                $database->updateAttributeDefault($collectionName, 'pg', [[1.0, 2.0]]); // wrong dimensionality
                $this->fail('Expected exception for invalid polygon default shape');
            } catch (\Throwable $e) {
                $this->assertTrue(true);
            }
        } finally {
            $database->deleteCollection($collectionName);
        }
    }

    public function testInvalidSpatialTypes(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        if (!$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionName = 'test_invalid_spatial_types';

        $attributes = [
            new Document([
                '$id' => ID::custom('pointAttr'),
                'type' => Database::VAR_POINT,
                'size' => 0,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ]),
            new Document([
                '$id' => ID::custom('lineAttr'),
                'type' => Database::VAR_LINESTRING,
                'size' => 0,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ]),
            new Document([
                '$id' => ID::custom('polyAttr'),
                'type' => Database::VAR_POLYGON,
                'size' => 0,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ])
        ];

        $database->createCollection($collectionName, $attributes);

        // Invalid Point (must be [x, y])
        try {
            $database->createDocument($collectionName, new Document([
                'pointAttr' => [10.0], // only 1 coordinate
            ]));
            $this->fail("Expected StructureException for invalid point");
        } catch (\Throwable $th) {
            $this->assertInstanceOf(StructureException::class, $th);
        }

        // Invalid LineString (must be [[x,y],[x,y],...], at least 2 points)
        try {
            $database->createDocument($collectionName, new Document([
                'lineAttr' => [[10.0, 20.0]], // only one point
            ]));
            $this->fail("Expected StructureException for invalid line");
        } catch (\Throwable $th) {
            $this->assertInstanceOf(StructureException::class, $th);
        }

        try {
            $database->createDocument($collectionName, new Document([
                'lineAttr' => [10.0, 20.0], // not an array of arrays
            ]));
            $this->fail("Expected StructureException for invalid line structure");
        } catch (\Throwable $th) {
            $this->assertInstanceOf(StructureException::class, $th);
        }

        try {
            $database->createDocument($collectionName, new Document([
                'polyAttr' => [10.0, 20.0] // not an array of arrays
            ]));
            $this->fail("Expected StructureException for invalid polygon structure");
        } catch (\Throwable $th) {
            $this->assertInstanceOf(StructureException::class, $th);
        }

        $invalidPolygons = [
            [[0,0],[1,1],[0,1]],
            [[0,0],['a',1],[1,1],[0,0]],
            [[0,0],[1,0],[1,1],[0,1]],
            [],
            [[0,0,5],[1,0,5],[1,1,5],[0,0,5]],
            [
                [[0,0],[2,0],[2,2],[0,0]], // valid
                [[0,0,1],[1,0,1],[1,1,1],[0,0,1]] // invalid 3D
            ]
        ];
        foreach ($invalidPolygons as $invalidPolygon) {
            try {
                $database->createDocument($collectionName, new Document([
                    'polyAttr' => $invalidPolygon
                ]));
                $this->fail("Expected StructureException for invalid polygon structure");
            } catch (\Throwable $th) {
                $this->assertInstanceOf(StructureException::class, $th);
            }
        }
        // Cleanup
        $database->deleteCollection($collectionName);
    }

    public function testSpatialDistanceInMeter(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        if (!$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionName = 'spatial_distance_meters_';
        try {
            $database->createCollection($collectionName);
            $this->assertEquals(true, $database->createAttribute($collectionName, 'loc', Database::VAR_POINT, 0, true));
            $this->assertEquals(true, $database->createIndex($collectionName, 'idx_loc', Database::INDEX_SPATIAL, ['loc']));

            // Two points roughly ~1000 meters apart by latitude delta (~0.009 deg ≈ 1km)
            $p0 = $database->createDocument($collectionName, new Document([
                '$id' => 'p0',
                'loc' => [0.0000, 0.0000],
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())]
            ]));
            $p1 = $database->createDocument($collectionName, new Document([
                '$id' => 'p1',
                'loc' => [0.0090, 0.0000],
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())]
            ]));

            $this->assertInstanceOf(Document::class, $p0);
            $this->assertInstanceOf(Document::class, $p1);

            // distanceLessThan with meters=true: within 1500m should include both
            $within1_5km = $database->find($collectionName, [
                Query::distanceLessThan('loc', [0.0000, 0.0000], 1500, true)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($within1_5km);
            $this->assertCount(2, $within1_5km);

            // Within 500m should include only p0 (exact point)
            $within500m = $database->find($collectionName, [
                Query::distanceLessThan('loc', [0.0000, 0.0000], 500, true)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($within500m);
            $this->assertCount(1, $within500m);
            $this->assertEquals('p0', $within500m[0]->getId());

            // distanceGreaterThan 500m should include only p1
            $greater500m = $database->find($collectionName, [
                Query::distanceGreaterThan('loc', [0.0000, 0.0000], 500, true)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($greater500m);
            $this->assertCount(1, $greater500m);
            $this->assertEquals('p1', $greater500m[0]->getId());

            // distanceEqual with 0m should return exact match p0
            $equalZero = $database->find($collectionName, [
                Query::distanceEqual('loc', [0.0000, 0.0000], 0, true)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($equalZero);
            $this->assertEquals('p0', $equalZero[0]->getId());

            // distanceNotEqual with 0m should return p1
            $notEqualZero = $database->find($collectionName, [
                Query::distanceNotEqual('loc', [0.0000, 0.0000], 0, true)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($notEqualZero);
            $this->assertEquals('p1', $notEqualZero[0]->getId());
        } finally {
            $database->deleteCollection($collectionName);
        }
    }

    public function testSpatialDistanceInMeterForMultiDimensionGeometry(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        if (!$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        if (!$database->getAdapter()->getSupportForDistanceBetweenMultiDimensionGeometryInMeters()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $multiCollection = 'spatial_distance_meters_multi_';
        try {
            $database->createCollection($multiCollection);

            // Create spatial attributes
            $this->assertEquals(true, $database->createAttribute($multiCollection, 'loc', Database::VAR_POINT, 0, true));
            $this->assertEquals(true, $database->createAttribute($multiCollection, 'line', Database::VAR_LINESTRING, 0, true));
            $this->assertEquals(true, $database->createAttribute($multiCollection, 'poly', Database::VAR_POLYGON, 0, true));

            // Create indexes
            $this->assertEquals(true, $database->createIndex($multiCollection, 'idx_loc', Database::INDEX_SPATIAL, ['loc']));
            $this->assertEquals(true, $database->createIndex($multiCollection, 'idx_line', Database::INDEX_SPATIAL, ['line']));
            $this->assertEquals(true, $database->createIndex($multiCollection, 'idx_poly', Database::INDEX_SPATIAL, ['poly']));

            // Geometry sets: near origin and far east
            $docNear = $database->createDocument($multiCollection, new Document([
                '$id' => 'near',
                'loc' => [0.0000, 0.0000],
                'line' => [[0.0000, 0.0000], [0.0010, 0.0000]], // ~111m
                'poly' => [[
                    [-0.0010, -0.0010],
                    [-0.0010,  0.0010],
                    [ 0.0010,  0.0010],
                    [ 0.0010, -0.0010],
                    [-0.0010, -0.0010] // closed
                ]],
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())]
            ]));

            $docFar = $database->createDocument($multiCollection, new Document([
                '$id' => 'far',
                'loc' => [0.2000, 0.0000], // ~22 km east
                'line' => [[0.2000, 0.0000], [0.2020, 0.0000]],
                'poly' => [[
                    [0.1980, -0.0020],
                    [0.1980,  0.0020],
                    [0.2020,  0.0020],
                    [0.2020, -0.0020],
                    [0.1980, -0.0020] // closed
                ]],
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())]
            ]));

            $this->assertInstanceOf(Document::class, $docNear);
            $this->assertInstanceOf(Document::class, $docFar);

            // polygon vs polygon (~1 km from near, ~22 km from far)
            $polyPolyWithin3km = $database->find($multiCollection, [
                Query::distanceLessThan('poly', [[
                    [0.0080, -0.0010],
                    [0.0080,  0.0010],
                    [0.0110,  0.0010],
                    [0.0110, -0.0010],
                    [0.0080, -0.0010] // closed
                ]], 3000, true)
            ], Database::PERMISSION_READ);
            $this->assertCount(1, $polyPolyWithin3km);
            $this->assertEquals('near', $polyPolyWithin3km[0]->getId());

            $polyPolyGreater3km = $database->find($multiCollection, [
                Query::distanceGreaterThan('poly', [[
                    [0.0080, -0.0010],
                    [0.0080,  0.0010],
                    [0.0110,  0.0010],
                    [0.0110, -0.0010],
                    [0.0080, -0.0010] // closed
                ]], 3000, true)
            ], Database::PERMISSION_READ);
            $this->assertCount(1, $polyPolyGreater3km);
            $this->assertEquals('far', $polyPolyGreater3km[0]->getId());

            // point vs polygon (~0 km near, ~22 km far)
            $ptPolyWithin500 = $database->find($multiCollection, [
                Query::distanceLessThan('loc', [[
                    [-0.0010, -0.0010],
                    [-0.0010,  0.0020],
                    [ 0.0020,  0.0020],
                    [-0.0010, -0.0010]
                ]], 500, true)
            ], Database::PERMISSION_READ);
            $this->assertCount(1, $ptPolyWithin500);
            $this->assertEquals('near', $ptPolyWithin500[0]->getId());

            $ptPolyGreater500 = $database->find($multiCollection, [
                Query::distanceGreaterThan('loc', [[
                    [-0.0010, -0.0010],
                    [-0.0010,  0.0020],
                    [ 0.0020,  0.0020],
                    [-0.0010, -0.0010]
                ]], 500, true)
            ], Database::PERMISSION_READ);
            $this->assertCount(1, $ptPolyGreater500);
            $this->assertEquals('far', $ptPolyGreater500[0]->getId());

            // Zero-distance checks
            $lineEqualZero = $database->find($multiCollection, [
                Query::distanceEqual('line', [[0.0000, 0.0000], [0.0010, 0.0000]], 0, true)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($lineEqualZero);
            $this->assertEquals('near', $lineEqualZero[0]->getId());

            $polyEqualZero = $database->find($multiCollection, [
                Query::distanceEqual('poly', [[
                    [-0.0010, -0.0010],
                    [-0.0010,  0.0010],
                    [ 0.0010,  0.0010],
                    [ 0.0010, -0.0010],
                    [-0.0010, -0.0010]
                ]], 0, true)
            ], Database::PERMISSION_READ);
            $this->assertNotEmpty($polyEqualZero);
            $this->assertEquals('near', $polyEqualZero[0]->getId());

        } finally {
            $database->deleteCollection($multiCollection);
        }
    }

    public function testSpatialDistanceInMeterError(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        if (!$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        if ($database->getAdapter()->getSupportForDistanceBetweenMultiDimensionGeometryInMeters()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collection = 'spatial_distance_error_test';
        $database->createCollection($collection);
        $this->assertEquals(true, $database->createAttribute($collection, 'loc', Database::VAR_POINT, 0, true));
        $this->assertEquals(true, $database->createAttribute($collection, 'line', Database::VAR_LINESTRING, 0, true));
        $this->assertEquals(true, $database->createAttribute($collection, 'poly', Database::VAR_POLYGON, 0, true));

        $doc = $database->createDocument($collection, new Document([
            '$id' => 'doc1',
            'loc' => [0.0, 0.0],
            'line' => [[0.0, 0.0], [0.001, 0.0]],
            'poly' => [[[ -0.001, -0.001 ], [ -0.001, 0.001 ], [ 0.001, 0.001 ], [ -0.001, -0.001 ]]],
            '$permissions' => []
        ]));
        $this->assertInstanceOf(Document::class, $doc);

        // Invalid geometry pairs
        $cases = [
            ['attr' => 'line', 'geom' => [0.002, 0.0], 'expected' => ['linestring', 'point']],
            ['attr' => 'poly', 'geom' => [0.002, 0.0], 'expected' => ['polygon', 'point']],
            ['attr' => 'loc', 'geom' => [[0.0, 0.0], [0.001, 0.001]], 'expected' => ['point', 'linestring']],
            ['attr' => 'poly', 'geom' => [[0.0, 0.0], [0.001, 0.001]], 'expected' => ['polygon', 'linestring']],
            ['attr' => 'loc', 'geom' => [[[0.0, 0.0], [0.001, 0.0], [0.001, 0.001], [0.0, 0.0]]], 'expected' => ['point', 'polygon']],
            ['attr' => 'line', 'geom' => [[[0.0, 0.0], [0.001, 0.0], [0.001, 0.001], [0.0, 0.0]]], 'expected' => ['linestring', 'polygon']],
            ['attr' => 'poly', 'geom' => [[[0.002, -0.001], [0.002, 0.001], [0.004, 0.001], [0.002, -0.001]]], 'expected' => ['polygon', 'polygon']],
            ['attr' => 'line', 'geom' => [[0.002, 0.0], [0.003, 0.0]], 'expected' => ['linestring', 'linestring']],
        ];

        foreach ($cases as $case) {
            try {
                $database->find($collection, [
                    Query::distanceLessThan($case['attr'], $case['geom'], 1000, true)
                ]);
                $this->fail('Expected Exception not thrown for ' . implode(' vs ', $case['expected']));
            } catch (\Exception $e) {
                $this->assertInstanceOf(QueryException::class, $e);

                // Validate exception message contains correct type names
                $msg = strtolower($e->getMessage());
                $this->assertStringContainsString($case['expected'][0], $msg, 'Attr type missing in exception');
                $this->assertStringContainsString($case['expected'][1], $msg, 'Geom type missing in exception');
            }
        }
    }
    public function testSpatialEncodeDecode(): void
    {
        $collection = new Document([
            '$collection' => ID::custom(Database::METADATA),
            '$id' => ID::custom('users'),
            'name' => 'Users',
            'attributes' => [
                [
                    '$id' => ID::custom('point'),
                    'type' => Database::VAR_POINT,
                    'required' => false,
                    'filters' => [Database::VAR_POINT],
                ],
                [
                    '$id' => ID::custom('line'),
                    'type' => Database::VAR_LINESTRING,
                    'format' => '',
                    'required' => false,
                    'filters' => [Database::VAR_LINESTRING],
                ],
                [
                    '$id' => ID::custom('poly'),
                    'type' => Database::VAR_POLYGON,
                    'format' => '',
                    'required' => false,
                    'filters' => [Database::VAR_POLYGON],
                ]
            ]
        ]);

        /** @var Database $database */
        $database = $this->getDatabase();
        if (!$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }
        $point = "POINT(1 2)";
        $line = "LINESTRING(1 2, 1 2)";
        $poly = "POLYGON((0 0, 0 10, 10 10, 0 0))";

        $pointArr = [1,2];
        $lineArr = [[1,2],[1,2]];
        $polyArr = [[[0.0, 0.0], [0.0, 10.0], [10.0, 10.0], [0.0, 0.0]]];
        $doc = new Document(['point' => $pointArr ,'line' => $lineArr, 'poly' => $polyArr]);

        $result = $database->encode($collection, $doc);

        $this->assertEquals($result->getAttribute('point'), $point);
        $this->assertEquals($result->getAttribute('line'), $line);
        $this->assertEquals($result->getAttribute('poly'), $poly);


        $result = $database->decode($collection, $doc);
        $this->assertEquals($result->getAttribute('point'), $pointArr);
        $this->assertEquals($result->getAttribute('line'), $lineArr);
        $this->assertEquals($result->getAttribute('poly'), $polyArr);

        $stringDoc = new Document(['point' => $point,'line' => $line, 'poly' => $poly]);
        $result = $database->decode($collection, $stringDoc);
        $this->assertEquals($result->getAttribute('point'), $pointArr);
        $this->assertEquals($result->getAttribute('line'), $lineArr);
        $this->assertEquals($result->getAttribute('poly'), $polyArr);

        $nullDoc = new Document(['point' => null,'line' => null, 'poly' => null]);
        $result = $database->decode($collection, $nullDoc);
        $this->assertEquals($result->getAttribute('point'), null);
        $this->assertEquals($result->getAttribute('line'), null);
        $this->assertEquals($result->getAttribute('poly'), null);
    }

    public function testSpatialIndexSingleAttributeOnly(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        if (!$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionName = 'spatial_idx_single_attr_' . uniqid();
        try {
            $database->createCollection($collectionName);

            // Create a spatial attribute
            $database->createAttribute($collectionName, 'loc', Database::VAR_POINT, 0, true);
            $database->createAttribute($collectionName, 'loc2', Database::VAR_POINT, 0, true);
            $database->createAttribute($collectionName, 'title', Database::VAR_STRING, 255, true);

            // Case 1: Valid spatial index on a single spatial attribute
            $this->assertTrue(
                $database->createIndex($collectionName, 'idx_loc', Database::INDEX_SPATIAL, ['loc'])
            );

            // Case 2: Fail when trying to create spatial index with multiple attributes
            try {
                $database->createIndex($collectionName, 'idx_multi', Database::INDEX_SPATIAL, ['loc', 'loc2']);
                $this->fail('Expected exception when creating spatial index on multiple attributes');
            } catch (\Throwable $e) {
                $this->assertInstanceOf(IndexException::class, $e);
            }

            // Case 3: Fail when trying to create non-spatial index on a spatial attribute
            try {
                $database->createIndex($collectionName, 'idx_wrong_type', Database::INDEX_KEY, ['loc']);
                $this->fail('Expected exception when creating non-spatial index on spatial attribute');
            } catch (\Throwable $e) {
                $this->assertInstanceOf(IndexException::class, $e);
            }

            // Case 4: Fail when trying to mix spatial + non-spatial attributes in a spatial index
            try {
                $database->createIndex($collectionName, 'idx_mix', Database::INDEX_SPATIAL, ['loc', 'title']);
                $this->fail('Expected exception when creating spatial index with mixed attribute types');
            } catch (\Throwable $e) {
                $this->assertInstanceOf(IndexException::class, $e);
            }

        } finally {
            $database->deleteCollection($collectionName);
        }
    }

    public function testSpatialIndexRequiredToggling(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        if (!$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }
        if ($database->getAdapter()->getSupportForSpatialIndexNull()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        try {
            $collUpdateNull = 'spatial_idx_toggle';
            $database->createCollection($collUpdateNull);

            $database->createAttribute($collUpdateNull, 'loc', Database::VAR_POINT, 0, false);
            try {
                $database->createIndex($collUpdateNull, 'idx_loc', Database::INDEX_SPATIAL, ['loc']);
                $this->fail('Expected exception when creating spatial index on NULL-able attribute');
            } catch (\Throwable $e) {
                $this->assertInstanceOf(Exception::class, $e);
            }
            $database->updateAttribute($collUpdateNull, 'loc', required: true);
            $this->assertTrue($database->createIndex($collUpdateNull, 'new index', Database::INDEX_SPATIAL, ['loc']));
            $this->assertTrue($database->deleteIndex($collUpdateNull, 'new index'));
            $database->updateAttribute($collUpdateNull, 'loc', required: false);

            $database->createDocument($collUpdateNull, new Document(['loc' => null]));
        } finally {
            $database->deleteCollection($collUpdateNull);
        }
    }

    public function testSpatialIndexOnNonSpatial(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        if (!$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        try {
            $collUpdateNull = 'spatial_idx_toggle';
            $database->createCollection($collUpdateNull);

            $database->createAttribute($collUpdateNull, 'loc', Database::VAR_POINT, 0, true);
            $database->createAttribute($collUpdateNull, 'name', Database::VAR_STRING, 4, true);
            try {
                $database->createIndex($collUpdateNull, 'idx_loc', Database::INDEX_SPATIAL, ['name']);
                $this->fail('Expected exception when creating spatial index on NULL-able attribute');
            } catch (\Throwable $e) {
                $this->assertInstanceOf(IndexException::class, $e);
            }

            try {
                $database->createIndex($collUpdateNull, 'idx_loc', Database::INDEX_KEY, ['loc']);
                $this->fail('Expected exception when creating non spatial index on spatial attribute');
            } catch (\Throwable $e) {
                $this->assertInstanceOf(IndexException::class, $e);
            }

            try {
                $database->createIndex($collUpdateNull, 'idx_loc', Database::INDEX_KEY, ['loc,name']);
                $this->fail('Expected exception when creating index');
            } catch (\Throwable $e) {
                $this->assertInstanceOf(IndexException::class, $e);
            }

            try {
                $database->createIndex($collUpdateNull, 'idx_loc', Database::INDEX_KEY, ['name,loc']);
                $this->fail('Expected exception when creating index');
            } catch (\Throwable $e) {
                $this->assertInstanceOf(IndexException::class, $e);
            }

            try {
                $database->createIndex($collUpdateNull, 'idx_loc', Database::INDEX_SPATIAL, ['name,loc']);
                $this->fail('Expected exception when creating index');
            } catch (\Throwable $e) {
                $this->assertInstanceOf(IndexException::class, $e);
            }

            try {
                $database->createIndex($collUpdateNull, 'idx_loc', Database::INDEX_SPATIAL, ['loc,name']);
                $this->fail('Expected exception when creating index');
            } catch (\Throwable $e) {
                $this->assertInstanceOf(IndexException::class, $e);
            }

        } finally {
            $database->deleteCollection($collUpdateNull);
        }
    }

    public function testSpatialDocOrder(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        if (!$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionName = 'test_spatial_order_axis';
        // Create collection first
        $database->createCollection($collectionName);

        // Create spatial attributes using createAttribute method
        $this->assertEquals(true, $database->createAttribute($collectionName, 'pointAttr', Database::VAR_POINT, 0, $database->getAdapter()->getSupportForSpatialIndexNull() ? false : true));

        // Create test document
        $doc1 = new Document(
            [
                '$id' => 'doc1',
                'pointAttr' => [5.0, 5.5],
                '$permissions' => [Permission::update(Role::any()), Permission::read(Role::any())]
            ]
        );
        $database->createDocument($collectionName, $doc1);

        $result = $database->getDocument($collectionName, 'doc1');
        $this->assertEquals($result->getAttribute('pointAttr')[0], 5.0);
        $this->assertEquals($result->getAttribute('pointAttr')[1], 5.5);
        $database->deleteCollection($collectionName);
    }

    public function testInvalidCoordinateDocuments(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        if (!$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionName = 'test_invalid_coord_';
        try {
            $database->createCollection($collectionName);

            $database->createAttribute($collectionName, 'pointAttr', Database::VAR_POINT, 0, true);
            $database->createAttribute($collectionName, 'lineAttr', Database::VAR_LINESTRING, 0, true);
            $database->createAttribute($collectionName, 'polyAttr', Database::VAR_POLYGON, 0, true);

            $invalidDocs = [
                // Invalid POINT (longitude > 180)
                [
                    '$id' => 'invalidDoc1',
                    'pointAttr' => [200.0, 20.0],
                    'lineAttr' => [[1.0, 2.0], [3.0, 4.0]],
                    'polyAttr' => [
                        [
                            [0.0, 0.0],
                            [0.0, 10.0],
                            [10.0, 10.0],
                            [10.0, 0.0],
                            [0.0, 0.0]
                        ]
                    ]
                ],
                // Invalid POINT (latitude < -90)
                [
                    '$id' => 'invalidDoc2',
                    'pointAttr' => [50.0, -100.0],
                    'lineAttr' => [[1.0, 2.0], [3.0, 4.0]],
                    'polyAttr' => [
                        [
                            [0.0, 0.0],
                            [0.0, 10.0],
                            [10.0, 10.0],
                            [10.0, 0.0],
                            [0.0, 0.0]
                        ]
                    ]
                ],
                // Invalid LINESTRING (point outside valid range)
                [
                    '$id' => 'invalidDoc3',
                    'pointAttr' => [50.0, 20.0],
                    'lineAttr' => [[1.0, 2.0], [300.0, 4.0]], // invalid longitude in line
                    'polyAttr' => [
                        [
                            [0.0, 0.0],
                            [0.0, 10.0],
                            [10.0, 10.0],
                            [10.0, 0.0],
                            [0.0, 0.0]
                        ]
                    ]
                ],
                // Invalid POLYGON (point outside valid range)
                [
                    '$id' => 'invalidDoc4',
                    'pointAttr' => [50.0, 20.0],
                    'lineAttr' => [[1.0, 2.0], [3.0, 4.0]],
                    'polyAttr' => [
                        [
                            [0.0, 0.0],
                            [0.0, 10.0],
                            [190.0, 10.0], // invalid longitude
                            [10.0, 0.0],
                            [0.0, 0.0]
                        ]
                    ]
                ],
            ];
            foreach ($invalidDocs as $docData) {
                $this->expectException(StructureException::class);
                $docData['$permissions'] = [Permission::update(Role::any()), Permission::read(Role::any())];
                $doc = new Document($docData);
                $database->createDocument($collectionName, $doc);
            }


        } finally {
            $database->deleteCollection($collectionName);
        }
    }

    public function testCreateSpatialColumnWithExistingData(): void
    {
        /** @var Database $database */
        $database = $this->getDatabase();
        if (!$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }
        if ($database->getAdapter()->getSupportForSpatialIndexNull()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        if ($database->getAdapter()->getSupportForOptionalSpatialAttributeWithExistingRows()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $col = 'spatial_col_existing_data';
        try {
            $database->createCollection($col);

            $database->createAttribute($col, 'name', Database::VAR_STRING, 40, false);
            $database->createDocument($col, new Document(['name' => 'test-doc','$permissions' => [Permission::update(Role::any()), Permission::read(Role::any())]]));
            try {
                $database->createAttribute($col, 'loc', Database::VAR_POINT, 0, true);
            } catch (\Throwable $e) {
                $this->assertInstanceOf(StructureException::class, $e);
            }
        } finally {
            $database->deleteCollection($col);
        }
    }

    /**
     * Test that spatial arrays are properly converted to WKT in updateDocument
     * Tests: Spatial attributes should use ST_GeomFromText with WKT conversion, not JSON encoding
     */
    public function testSpatialArrayWKTConversionInUpdateDocument(): void
    {
        /** @var Database $database */
        $database = static::getDatabase();

        if (!$database->getAdapter()->getSupportForSpatialAttributes()) {
            $this->expectNotToPerformAssertions();
            return;
        }

        $collectionName = 'test_spatial_wkt_conversion';

        try {
            $database->createCollection($collectionName);
            // Use required=true for spatial attributes to support spatial indexes (MariaDB requires this)
            $database->createAttribute($collectionName, 'location', Database::VAR_POINT, 0, true);
            $database->createAttribute($collectionName, 'route', Database::VAR_LINESTRING, 0, $database->getAdapter()->getSupportForSpatialIndexNull() ? false : true);
            $database->createAttribute($collectionName, 'area', Database::VAR_POLYGON, 0, $database->getAdapter()->getSupportForSpatialIndexNull() ? false : true);
            $database->createAttribute($collectionName, 'name', Database::VAR_STRING, 100, false);

            // Create indexes for spatial queries
            $database->createIndex($collectionName, 'location_idx', Database::INDEX_SPATIAL, ['location']);
            $database->createIndex($collectionName, 'route_idx', Database::INDEX_SPATIAL, ['route']);
            $database->createIndex($collectionName, 'area_idx', Database::INDEX_SPATIAL, ['area']);

            // Create initial document with spatial arrays
            $initialPoint = [10.0, 20.0];
            $initialLine = [[0.0, 0.0], [5.0, 5.0]];
            $initialPolygon = [[[0.0, 0.0], [0.0, 5.0], [5.0, 5.0], [5.0, 0.0], [0.0, 0.0]]];

            $doc = $database->createDocument($collectionName, new Document([
                '$id' => 'spatial_doc',
                '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
                'location' => $initialPoint,
                'route' => $initialLine,
                'area' => $initialPolygon,
                'name' => 'Original'
            ]));

            // Verify initial values
            $this->assertEquals($initialPoint, $doc->getAttribute('location'));
            $this->assertEquals($initialLine, $doc->getAttribute('route'));
            $this->assertEquals($initialPolygon, $doc->getAttribute('area'));

            // Update document with NEW spatial arrays (this triggers the WKT conversion path)
            $newPoint = [30.0, 40.0];
            $newLine = [[10.0, 10.0], [20.0, 20.0], [30.0, 30.0]];
            $newPolygon = [[[10.0, 10.0], [10.0, 20.0], [20.0, 20.0], [20.0, 10.0], [10.0, 10.0]]];

            $updated = $database->updateDocument($collectionName, 'spatial_doc', new Document([
                'location' => $newPoint,
                'route' => $newLine,
                'area' => $newPolygon,
                'name' => 'Updated'
            ]));

            // Verify updated spatial values are correctly stored and retrieved
            $this->assertEquals($newPoint, $updated->getAttribute('location'), 'Point should be updated correctly via WKT conversion');
            $this->assertEquals($newLine, $updated->getAttribute('route'), 'LineString should be updated correctly via WKT conversion');
            $this->assertEquals($newPolygon, $updated->getAttribute('area'), 'Polygon should be updated correctly via WKT conversion');
            $this->assertEquals('Updated', $updated->getAttribute('name'));

            // Refetch from database to ensure data was persisted correctly
            $refetched = $database->getDocument($collectionName, 'spatial_doc');
            $this->assertEquals($newPoint, $refetched->getAttribute('location'), 'Point should persist correctly after WKT conversion');
            $this->assertEquals($newLine, $refetched->getAttribute('route'), 'LineString should persist correctly after WKT conversion');
            $this->assertEquals($newPolygon, $refetched->getAttribute('area'), 'Polygon should persist correctly after WKT conversion');

            // Test spatial queries work with updated data
            $results = $database->find($collectionName, [
                Query::equal('location', [$newPoint])
            ]);
            $this->assertCount(1, $results, 'Should find document by exact point match');
            $this->assertEquals('spatial_doc', $results[0]->getId());

            // Test mixed update (spatial + non-spatial attributes)
            $updated2 = $database->updateDocument($collectionName, 'spatial_doc', new Document([
                'location' => [50.0, 60.0],
                'name' => 'Mixed Update'
            ]));
            $this->assertEquals([50.0, 60.0], $updated2->getAttribute('location'));
            $this->assertEquals('Mixed Update', $updated2->getAttribute('name'));
            // Other spatial attributes should remain unchanged
            $this->assertEquals($newLine, $updated2->getAttribute('route'));
            $this->assertEquals($newPolygon, $updated2->getAttribute('area'));

        } finally {
            $database->deleteCollection($collectionName);
        }
    }
}
