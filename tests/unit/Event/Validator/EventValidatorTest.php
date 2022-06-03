<?php

namespace Appwrite\Tests;

use Appwrite\Event\Validator\Event;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;

class EventValidatorTest extends TestCase
{
    protected ?Event $object = null;

    public function setUp(): void
    {
        Config::load('events', __DIR__ . '/../../../../app/config/events.php');
        $this->object = new Event();
    }

    public function tearDown(): void
    {
    }

    public function testValues()
    {
        /**
         * Test for SUCCESS
         */
        $this->assertTrue($this->object->isValid('users.*.create'));
        $this->assertTrue($this->object->isValid('users.torsten.update'));
        $this->assertTrue($this->object->isValid('users.torsten'));
        $this->assertTrue($this->object->isValid('users.*.update.email'));
        $this->assertTrue($this->object->isValid('users.*.update'));
        $this->assertTrue($this->object->isValid('users.*'));
        $this->assertTrue($this->object->isValid('collections.chapters.documents.prolog.create'));
        $this->assertTrue($this->object->isValid('collections.chapters.documents.prolog'));
        $this->assertTrue($this->object->isValid('collections.chapters.documents.*.create'));
        $this->assertTrue($this->object->isValid('collections.chapters.documents.*'));
        $this->assertTrue($this->object->isValid('collections.*.documents.prolog.create'));
        $this->assertTrue($this->object->isValid('collections.*.documents.prolog'));
        $this->assertTrue($this->object->isValid('collections.*.documents.*.create'));
        $this->assertTrue($this->object->isValid('collections.*.documents.*'));
        $this->assertTrue($this->object->isValid('collections.*'));
        $this->assertTrue($this->object->isValid('functions.*'));
        $this->assertTrue($this->object->isValid('buckets.*'));
        $this->assertTrue($this->object->isValid('teams.*'));
        $this->assertTrue($this->object->isValid('users.*'));
        $this->assertTrue($this->object->isValid('teams.*.memberships.*.update.status'));

        /**
         * Test for FAILURE
         */
        $this->assertFalse($this->object->isValid(false));
        $this->assertFalse($this->object->isValid(null));
        $this->assertFalse($this->object->isValid(''));
        $this->assertFalse($this->object->isValid('unknown.*'));
        $this->assertFalse($this->object->isValid('collections'));
        $this->assertFalse($this->object->isValid('collections.*.unknown'));
        $this->assertFalse($this->object->isValid('collections.*.documents.*.unknown'));
        $this->assertFalse($this->object->isValid('users.torsten.unknown'));
        $this->assertFalse($this->object->isValid('users.torsten.delete.email'));
        $this->assertFalse($this->object->isValid('teams.*.memberships.*.update.unknown'));
    }
}
