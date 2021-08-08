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

    public function testNamespace()
    {
        $this->object->setNamespace('appwritetest.usage');
        $this->assertEquals('appwritetest.usage', $this->object->getNamespace());
    }

    public function testParams()
    {
        $this->object
            ->setParam('projectId', 'appwrite_test')
            ->setParam('networkRequestSize', 100)
        ;

        $this->assertEquals('appwrite_test', $this->object->getParam('projectId'));
        $this->assertEquals(100, $this->object->getParam('networkRequestSize'));

        $this->object->submit();

        $this->assertEquals(null, $this->object->getParam('projectId'));
        $this->assertEquals(null, $this->object->getParam('networkRequestSize'));
    }

    public function testReset()
    {
        $this->object
            ->setParam('projectId', 'appwrite_test')
            ->setParam('networkRequestSize', 100)
        ;

        $this->assertEquals('appwrite_test', $this->object->getParam('projectId'));
        $this->assertEquals(100, $this->object->getParam('networkRequestSize'));

        $this->object->reset();

        $this->assertEquals(null, $this->object->getParam('projectId'));
        $this->assertEquals(null, $this->object->getParam('networkRequestSize'));
        $this->assertEquals('appwrite.usage', $this->object->getNamespace());
    }
}
