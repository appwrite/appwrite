<?php

namespace Tests\Unit\Utopia\Pools;

use Appwrite\Utopia\Pools\Connections;
use PHPUnit\Framework\TestCase;
use Utopia\Pools\Connection;
use Utopia\Pools\Pool;

class ConnectionsTest extends TestCase
{
    public function testAdd()
    {
        $connections = new Connections();
        $connection = new Connection('resource');
        $connections->add($connection);
        $this->assertEquals(1, $connections->count());
    }

    public function testRemove()
    {
        $connections = new Connections();
        $connection = new Connection('resource');
        $connections->add($connection);
        $connections->remove($connection->getID());
        $this->assertEquals(0, $connections->count());
    }

    public function testReclaim()
    {
        $connections = new Connections();
        $pool = new Pool('test', 1, function () {
            return 'resource';
        });
        $connection = $pool->pop();
        $connections->add($connection);
        $connections->reclaim();
        $this->assertEquals(1, $pool->count());
    }
}
