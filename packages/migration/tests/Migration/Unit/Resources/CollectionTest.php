<?php

namespace Utopia\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Database\Collection;
use Utopia\Migration\Resources\Database\VectorsDB;

class CollectionTest extends TestCase
{
    private const DATABASE = [
        'id' => 'vectors',
        'name' => 'Vectors',
        'type' => Resource::TYPE_DATABASE_VECTORSDB,
    ];

    public function testDimensionRoundTrip(): void
    {
        $collection = Collection::fromArray([
            'database' => self::DATABASE,
            'id' => 'embeddings-store',
            'name' => 'Embeddings Store',
            'rowSecurity' => true,
            'permissions' => [],
            'createdAt' => '2026-01-01T00:00:00.000+00:00',
            'updatedAt' => '2026-01-01T00:00:00.000+00:00',
            'enabled' => true,
            'dimension' => 1536,
        ]);

        $this->assertInstanceOf(VectorsDB::class, $collection->getDatabase());
        $this->assertSame(1536, $collection->getDimension());

        $serialized = $collection->jsonSerialize();
        $this->assertSame(1536, $serialized['dimension']);

        $rehydrated = Collection::fromArray(\json_decode(\json_encode($collection), true));
        $this->assertSame(1536, $rehydrated->getDimension());
    }

    public function testDimensionDefaultsToNull(): void
    {
        $collection = Collection::fromArray([
            'database' => self::DATABASE,
            'id' => 'embeddings-store',
            'name' => 'Embeddings Store',
            'rowSecurity' => true,
            'permissions' => [],
            'createdAt' => '',
            'updatedAt' => '',
            'enabled' => true,
        ]);

        $this->assertNull($collection->getDimension());
        $this->assertArrayNotHasKey('dimension', $collection->jsonSerialize());
    }
}
