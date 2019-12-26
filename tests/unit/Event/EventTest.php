<?php

namespace Appwrite\Tests;

use Exception;
use Utopia\Request;
use Event\Event;
use PHPUnit\Framework\TestCase;

class LocaleTest extends TestCase
{
    /**
     * @var Event
     */
    protected $object = null;
    
    /**
     * @var string
     */
    protected $queue = '';

    public function setUp()
    {
        $request = new Request();
        $redisHost = $request->getServer('_APP_REDIS_HOST', '');
        $redisPort = $request->getServer('_APP_REDIS_PORT', '');
        \Resque::setBackend($redisHost.':'.$redisPort);
        
        $this->queue = 'v1-tests' . uniqid();
        $this->object = new Event($this->queue, 'TestsV1');
    }

    public function tearDown()
    {
    }

    public function testParams()
    {
        $this->object
            ->setParam('key1', 'value1')
            ->setParam('key2', 'value2')
        ;

        $this->object->trigger();

        $this->assertEquals('value1', $this->object->getParam('key1'));
        $this->assertEquals('value2', $this->object->getParam('key2'));
        $this->assertEquals(null, $this->object->getParam('key3'));
        $this->assertEquals(\Resque::size($this->queue), 1);
    }
}