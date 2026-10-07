<?php

namespace Tests\Unit\Validator;

use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Validator\Spatial;

class SpatialTest extends TestCase
{
    public function testValidPoint(): void
    {
        $validator = new Spatial(Database::VAR_POINT);

        $this->assertTrue($validator->isValid([10, 20]));
        $this->assertTrue($validator->isValid([0, 0]));
        $this->assertTrue($validator->isValid([-180.0, 90.0]));

        // Invalid cases
        $this->assertFalse($validator->isValid([10])); // Only one coordinate
        $this->assertFalse($validator->isValid([10, 'a'])); // Non-numeric
        $this->assertFalse($validator->isValid([[10, 20]])); // Nested array
    }

    public function testValidLineString(): void
    {
        $validator = new Spatial(Database::VAR_LINESTRING);

        $this->assertTrue($validator->isValid([[0, 0], [1, 1]]));

        $this->assertTrue($validator->isValid([[10, 10], [20, 20], [30, 30]]));

        // Invalid cases
        $this->assertFalse($validator->isValid([[10, 10]])); // Only one point
        $this->assertFalse($validator->isValid([[10, 10], [20]])); // Malformed point
        $this->assertFalse($validator->isValid([[10, 10], ['x', 'y']])); // Non-numeric
    }

    public function testValidPolygon(): void
    {
        $validator = new Spatial(Database::VAR_POLYGON);

        // Single ring polygon (closed)
        $this->assertTrue($validator->isValid([
            [0, 0],
            [0, 1],
            [1, 1],
            [1, 0],
            [0, 0]
        ]));

        // Multi-ring polygon
        $this->assertTrue($validator->isValid([
            [   // Outer ring
                [0, 0], [0, 4], [4, 4], [4, 0], [0, 0]
            ],
            [   // Hole
                [1, 1], [1, 2], [2, 2], [2, 1], [1, 1]
            ]
        ]));

        // Invalid polygons
        $this->assertFalse($validator->isValid([])); // Empty
        $this->assertFalse($validator->isValid([
            [0, 0], [1, 1], [2, 2] // Not closed, less than 4 points
        ]));
        $this->assertFalse($validator->isValid([
            [[0, 0], [1, 1], [1, 0]] // Not closed
        ]));
        $this->assertFalse($validator->isValid([
            [[0, 0], [1, 1], [1, 'a'], [0, 0]] // Non-numeric
        ]));
    }

    public function testWKTStrings(): void
    {
        $this->assertTrue(Spatial::isWKTString('POINT(1 2)'));
        $this->assertTrue(Spatial::isWKTString('LINESTRING(0 0,1 1)'));
        $this->assertTrue(Spatial::isWKTString('POLYGON((0 0,1 0,1 1,0 1,0 0))'));

        $this->assertFalse(Spatial::isWKTString('CIRCLE(0 0,1)'));
        $this->assertFalse(Spatial::isWKTString('POINT1(1 2)'));
    }

    public function testInvalidCoordinate(): void
    {
        // Point with invalid longitude
        $validator = new Spatial(Database::VAR_POINT);
        $this->assertFalse($validator->isValid([200, 10])); // longitude > 180
        $this->assertStringContainsString('Longitude', $validator->getDescription());

        // Point with invalid latitude
        $validator = new Spatial(Database::VAR_POINT);
        $this->assertFalse($validator->isValid([10, -100])); // latitude < -90
        $this->assertStringContainsString('Latitude', $validator->getDescription());

        // LineString with invalid coordinates
        $validator = new Spatial(Database::VAR_LINESTRING);
        $this->assertFalse($validator->isValid([
            [0, 0],
            [181, 45] // invalid longitude
        ]));
        $this->assertStringContainsString('Invalid coordinates', $validator->getDescription());

        // Polygon with invalid coordinates
        $validator = new Spatial(Database::VAR_POLYGON);
        $this->assertFalse($validator->isValid([
            [[0, 0], [1, 1], [190, 5], [0, 0]] // invalid longitude in ring
        ]));
        $this->assertStringContainsString('Invalid coordinates', $validator->getDescription());
    }
}
