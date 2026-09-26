<?php

namespace Utopia\Registry\Tests;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Utopia\Registry\Registry;

class RegistryTest extends TestCase
{
    private Registry $registry;

    public function setUp(): void
    {
        $this->registry = new Registry();
    }

    /**
     * @return ArrayObject<array-key, mixed>
     */
    private function array(bool $fresh = false): ArrayObject
    {
        $array = $this->registry->get('array', $fresh);
        $this->assertInstanceOf(ArrayObject::class, $array);

        return $array;
    }

    /**
     * @throws \Exception
     */
    public function testGet(): void
    {
        $this->registry->set('array', fn (): ArrayObject => new ArrayObject(['test']));
        $this->array()[] = 'Hello World';

        $this->assertCount(2, $this->array());
    }

    /**
     * @throws \Exception
     */
    public function testSet(): void
    {
        $this->registry->set('array', fn (): ArrayObject => new ArrayObject(['test']));
        $this->assertCount(1, $this->array());
    }

    /**
     * @throws \Exception
     */
    public function testHas(): void
    {
        $this->registry->set('item', fn (): array => ['test']);
        $this->assertTrue($this->registry->has('item'));
    }

    /**
     * @throws \Exception
     */
    public function testGetFresh(): void
    {
        $this->registry->set('array', fn (): ArrayObject => new ArrayObject(['test']));
        $this->assertCount(1, $this->array(true));
    }

    /**
     * @throws \Exception
     */
    public function testSetFresh(): void
    {
        $this->registry->set('fresh', fn (): string => microtime(), true);

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
        $this->registry->set('time', fn (): string => microtime());

        $timeX = $this->registry->get('time');
        $timeY = $this->registry->get('time');

        $this->assertEquals($timeX, $timeY);
    }

    /**
     * @throws \Exception
     */
    public function testContextSwitching(): void
    {
        $this->registry->set('time', fn (): string => microtime());

        $timeX = $this->registry->get('time');
        $timeY = $this->registry->get('time');

        $this->registry->context('new');

        $timeY = $this->registry->get('time');

        $this->assertNotEquals($timeX, $timeY);
    }
}
