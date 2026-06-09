<?php

declare(strict_types=1);

namespace Tests\Unit\Task\Validator;

use Appwrite\Task\Validator\Cron;
use PHPUnit\Framework\TestCase;

final class CronTest extends TestCase
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

    public function testValues(): void
    {
        $this->assertEquals(true, $this->object->isValid('0 2 * * *')); // execute at 2am daily
        $this->assertEquals(true, $this->object->isValid('0 5,17 * * *')); // execute twice a day
        $this->assertEquals(true, $this->object->isValid('* * * * *')); // execute on every minutes
        // $this->assertEquals($this->object->isValid('0 17 * * sun'), true); // execute on every Sunday at 5 PM
        $this->assertEquals(true, $this->object->isValid('*/10 * * * *')); // execute on every 10 minutes
        $this->assertEquals(true, $this->object->isValid('*/5 22-23,0-3 * * *')); // execute every 5 minutes from 10pm to 3am
        // $this->assertEquals($this->object->isValid('* * * jan,may,aug *'), true); // execute on selected months
        // $this->assertEquals($this->object->isValid('0 17 * * sun,fri'), true); // execute on selected days
        // $this->assertEquals($this->object->isValid('0 2 * * sun'), true); // execute on first sunday of every month
        $this->assertEquals(true, $this->object->isValid('0 */4 * * *')); //  execute on every four hours
        // $this->assertEquals($this->object->isValid('0 4,17 * * sun,mon'), true); // execute twice on every Sunday and Monday
        $this->assertFalse($this->object->isValid('bad expression'));
        $this->assertFalse($this->object->isValid('*/5 22-3 * * *'));
    }
}
