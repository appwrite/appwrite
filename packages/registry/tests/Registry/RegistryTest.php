<?php

namespace Utopia\Tests;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Utopia\Registry\Registry;

class RegistryTest extends TestCase
{
    protected ?Registry $registry = null;

    public function setUp(): void
    {
        $this->registry = new Registry();
    }

    public function tearDown(): void
    {
        $this->registry = null;
    }

    /**
     * @throws \Exception
     */
    public function testGet(): void
    {
        $this->registry->set('array', function () {
            return new ArrayObject(['test']);
        });
        $this->registry->get('array')[] = 'Hello World';

        $this->assertCount(2, $this->registry->get('array'));
    }

    /**
     * @throws \Exception
     */
    public function testSet(): void
    {
        $this->registry->set('array', function () {
            return new ArrayObject(['test']);
        });
        $this->assertCount(1, $this->registry->get('array'));
    }

    /**
     * @throws \Exception
     */
    public function testHas(): void
    {
        $this->registry->set('item', function () {
            return ['test'];
        });
        $this->assertTrue($this->registry->has('item'));
    }

    /**
     * @throws \Exception
     */
    public function testGetFresh(): void
    {
        $this->registry->set('array', function () {
            return new ArrayObject(['test']);
        });
        $this->assertCount(1, $this->registry->get('array', true));
    }

    /**
     * @throws \Exception
     */
    public function testSetFresh(): void
    {
        $this->registry->set('fresh', function () {
            return microtime();
        }, true);

        // Added usleep because some runs were so fast that the microtime was the same
        $copy1 = $this->registry->get('fresh');
        usleep(1);
        $copy2 = $this->registry->get('fresh');
        usleep(1);
        $copy3 = $this->registry->get('fresh');

        $this->assertNotEquals($copy1, $copy2);
        $this->assertNotEquals($copy2, $copy3);
        $this->assertNotEquals($copy1, $copy3);
    }

    /**
     * @throws \Exception
     */
    public function testGetCaching(): void
    {
        $this->registry->set('time', function () {
            return microtime();
        });

        $timeX = $this->registry->get('time');
        $timeY = $this->registry->get('time');

        $this->assertEquals($timeX, $timeY);
    }

    /**
     * @throws \Exception
     */
    public function testContextSwitching(): void
    {
        $this->registry->set('time', function () {
            return microtime();
        });

        $timeX = $this->registry->get('time');
        $timeY = $this->registry->get('time');

        $this->registry->context('new');

        $timeY = $this->registry->get('time');

        $this->assertNotEquals($timeX, $timeY);
    }
}
