<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter;
use Utopia\Database\Database;
use Utopia\Database\Document;

class SpatialFilterTest extends TestCase
{
    /**
     * @param array<float> $point
     */
    private function createDatabase(array $point): Database
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('getSupportForHostname')->willReturn(false);
        $adapter->method('getTenant')->willReturn(null);
        $adapter->method('getNamespace')->willReturn('test');
        $adapter->method('getSharedTables')->willReturn(false);
        $adapter->method('filter')->willReturnArgument(0);
        $adapter->method('decodePoint')->willReturn($point);

        return new Database($adapter, new Cache(new None()));
    }

    private function pointCollection(): Document
    {
        return new Document([
            '$id' => 'places',
            'attributes' => [
                new Document([
                    '$id' => 'location',
                    'type' => Database::VAR_POINT,
                    'array' => false,
                    'filters' => [Database::VAR_POINT],
                ]),
            ],
        ]);
    }

    private function decode(Database $database, string $id): mixed
    {
        return $database
            ->decode($this->pointCollection(), new Document(['$id' => $id, 'location' => 'POINT(0 0)']))
            ->getAttribute('location');
    }

    public function testSpatialDecodeUsesTheCallingDatabaseAdapter(): void
    {
        $first = $this->createDatabase([1.0, 2.0]);
        $second = $this->createDatabase([9.0, 9.0]);

        $this->assertSame([1.0, 2.0], $this->decode($first, 'a'));
        $this->assertSame([9.0, 9.0], $this->decode($second, 'b'));

        // The decode filters live in a static registry shared by both instances,
        // so the first must still reach its own adapter after the second exists.
        $this->assertSame([1.0, 2.0], $this->decode($first, 'c'));
    }
}
