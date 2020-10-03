<?php

namespace Appwrite\Tests;

use Appwrite\Task\Validator\Cron;
use PHPUnit\Framework\TestCase;

class CronTest extends TestCase
{
    /**
     * @var Cron
     */
    protected $object = null;

    public function setUp(): void
    {
        $this->object = new Cron();
    }

    public function tearDown(): void
    {
    }

    public function testValues()
    {
        $this->assertEquals($this->object->isValid('0 2 * * *'), true); // execute at 2am daily
        $this->assertEquals($this->object->isValid('0 5,17 * * *'), true); // execute twice a day
        $this->assertEquals($this->object->isValid('* * * * *'), true); // execute on every minutes
        // $this->assertEquals($this->object->isValid('0 17 * * sun'), true); // execute on every Sunday at 5 PM
        $this->assertEquals($this->object->isValid('*/10 * * * *'), true); // execute on every 10 minutes
        // $this->assertEquals($this->object->isValid('* * * jan,may,aug *'), true); // execute on selected months
        // $this->assertEquals($this->object->isValid('0 17 * * sun,fri'), true); // execute on selected days
        // $this->assertEquals($this->object->isValid('0 2 * * sun'), true); // execute on first sunday of every month
        $this->assertEquals($this->object->isValid('0 */4 * * *'), true); //  execute on every four hours
        // $this->assertEquals($this->object->isValid('0 4,17 * * sun,mon'), true); // execute twice on every Sunday and Monday
        $this->assertEquals($this->object->isValid('bad expression'), false);
        $this->assertEquals(null, false);
        $this->assertEquals('', false);
    }
}
