<?php

namespace Utopia\Tests\Unit\General;

use PHPUnit\Framework\TestCase;
use Utopia\Migration\Cache;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Database\Database;
use Utopia\Migration\Resources\Database\Row;
use Utopia\Migration\Resources\Database\Table;

class CacheTest extends TestCase
{
    public function testTestCache(): void
    {
        $cache = new Cache();

        $db1 = new Database(id: 'db1', name: 'db1');
        $cache->add($db1);

        $this->assertEquals('database', $db1::getName());
        $this->assertEquals(Resource::STATUS_PENDING, $db1->getStatus());
        $this->assertArrayHasKey('database', $cache->getAll());
        $this->assertEquals(1, count($cache->getAll()['database']));
        $this->assertEquals(1, count($cache->get('database')));

        $db2 = new Database(id: 'db2', name: 'db2');
        $cache->add($db2);

        $this->assertEquals('database', $db1::getName());
        $this->assertEquals(Resource::STATUS_PENDING, $db1->getStatus());
        $this->assertArrayHasKey('database', $cache->getAll());
        $this->assertEquals(2, count($cache->getAll()['database']));
        $this->assertEquals(2, count($cache->get('database')));

        /**
         * Add same resource second time, check overwrite
         */
        $cache->add($db2);
        $this->assertEquals(2, count($cache->get('database')));


        $db1->setStatus(Resource::STATUS_SUCCESS);
        $this->assertEquals(Resource::STATUS_SUCCESS, $db1->getStatus());

        /**
         * Update cache
         */
        $cache->update($db1);
        $this->assertEquals(2, count($cache->getAll()['database']));
        $this->assertEquals(2, count($cache->get('database')));

        $key = $cache->resolveResourceCacheKey($db1);

        /**
         * @var Resource $resource
         */
        $resource = $cache->get('database')[$key];
        $this->assertEquals('success', $resource->getStatus());

        $table = new Table(
            database: $db1,
            id: 'table1',
            name: 'table',
        );
        $this->assertEquals('table', $table::getName());
        $this->assertEquals(Resource::STATUS_PENDING, $table->getStatus());

        $cache->add($table);
        $this->assertEquals(1, count($cache->getAll()['table']));
        $this->assertEquals(1, count($cache->get('table')));

        /**
         * Check overwrite
         */
        $cache->add($table);
        $this->assertEquals(1, count($cache->getAll()['table']));
        $this->assertEquals(1, count($cache->get('table')));


        $row = new Row(
            id: 'row1',
            table: $table,
        );

        $this->assertEquals('row', $row::getName());
        $this->assertEquals(Resource::STATUS_PENDING, $row->getStatus());

        $cache->add($row);
        $this->assertEquals(1, count($cache->getAll()['row']));
        $this->assertEquals(1, count($cache->get('row')));

        /**
         * Rows have only counter on status key
         */
        $this->assertEquals(['pending' => '1'], $cache->get('row'));

        $key = $cache->resolveResourceCacheKey($row);
        $this->assertArrayNotHasKey($key, $cache->get('row'));

        $row->setStatus(Resource::STATUS_SUCCESS);
        $cache->update($row);
        $this->assertEquals(['pending' => '1', 'success' => '1'], $cache->get('row'));

        $row->setStatus(Resource::STATUS_SUCCESS);
        $cache->update($row);
        $this->assertEquals(['pending' => '1', 'success' => '2'], $cache->get('row'));
    }
}
