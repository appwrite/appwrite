<?php

namespace Appwrite\Tests;

use Appwrite\Stats\Stats;
use PHPUnit\Framework\TestCase;
use Utopia\App;

class StatsTest extends TestCase
{
    /**
     * @var Stats
     */
    protected $object = null;

    public function setUp(): void
    {
        $host = App::getEnv('_APP_STATSD_HOST', 'telegraf');
        $port = App::getEnv('_APP_STATSD_PORT', 8125);

        $connection = new \Domnikl\Statsd\Connection\UdpSocket($host, $port);
        $statsd = new \Domnikl\Statsd\Client($connection);
        
        $this->object = new Stats($statsd);
    }

    public function tearDown(): void
    {
    }

    public function testParams()
    {
        $this->object
            ->setParam('statsKey1', 'statsValue1')
            ->setParam('statsKey2', 'statsValue2')
        ;

        $this->object->submit();

        $this->assertEquals(null, $this->object->getParam('statsKey1'));
        $this->assertEquals(null, $this->object->getParam('statsKey2'));
        $this->assertEquals(null, $this->object->getParam('statsKey3'));
    }

    public function testReset()
    {
        $this->object
            ->setParam('statsKey1', 'statsValue1')
            ->setParam('statsKey2', 'statsValue2')
        ;

        $this->assertEquals('statsValue1', $this->object->getParam('statsKey1'));
        $this->assertEquals('statsValue2', $this->object->getParam('statsKey2'));

        $this->object->reset();

        $this->assertEquals(null, $this->object->getParam('statsKey1'));
        $this->assertEquals(null, $this->object->getParam('statsKey2'));
        $this->assertEquals(null, $this->object->getParam('statsKey3'));
    }
}
