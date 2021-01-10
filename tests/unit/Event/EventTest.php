<?php

namespace Appwrite\Tests;

use Appwrite\Event\Event;
use PHPUnit\Framework\TestCase;
use Utopia\App;

class EventTest extends TestCase
{
    /**
     * @var Event
     */
    protected $object = null;
    
    /**
     * @var string
     */
    protected $queue = '';

    public function setUp(): void
    {
        $redisHost = App::getEnv('_APP_REDIS_HOST', '');
        $redisPort = App::getEnv('_APP_REDIS_PORT', '');
        \Resque::setBackend($redisHost.':'.$redisPort);
        
        $this->queue = 'v1-tests' . uniqid();
        $this->object = new Event($this->queue, 'TestsV1');
    }

    public function tearDown(): void
    {
    }

    public function testQueue()
    {
        $this->assertEquals($this->queue, $this->object->getQueue());

        $this->object->setQueue('demo');
        
        $this->assertEquals('demo', $this->object->getQueue());
        
        $this->object->setQueue($this->queue);
    }

    public function testClass()
    {
        $this->assertEquals('TestsV1', $this->object->getClass());

        $this->object->setClass('TestsV2');
        
        $this->assertEquals('TestsV2', $this->object->getClass());
        
        $this->object->setClass('TestsV1');
    }

    public function testParams()
    {
        $this->object
            ->setParam('eventKey1', 'eventValue1')
            ->setParam('eventKey2', 'eventValue2')
        ;

        $this->object->trigger();

        $this->assertEquals(null, $this->object->getParam('eventKey1'));
        $this->assertEquals(null, $this->object->getParam('eventKey2'));
        $this->assertEquals(null, $this->object->getParam('eventKey3'));
        $this->assertEquals(\Resque::size($this->queue), 1);
    }

    public function testReset()
    {
        $this->object
            ->setParam('eventKey1', 'eventValue1')
            ->setParam('eventKey2', 'eventValue2')
        ;

        $this->assertEquals('eventValue1', $this->object->getParam('eventKey1'));
        $this->assertEquals('eventValue2', $this->object->getParam('eventKey2'));

        $this->object->reset();

        $this->assertEquals(null, $this->object->getParam('eventKey1'));
        $this->assertEquals(null, $this->object->getParam('eventKey2'));
        $this->assertEquals(null, $this->object->getParam('eventKey3'));
    }
}
