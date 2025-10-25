<?php

namespace Tests\Unit\Event\Validator;

use Appwrite\Event\Validator\Event;
use PHPUnit\Framework\TestCase;

class EventValidatorTest extends TestCase
{
    protected ?Event $object = null;

    public function setUp(): void
    {
        $this->object = new Event();
    }

    public function tearDown(): void
    {
    }

    public function testValues(): void
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
        $this->assertTrue($this->object->isValid('databases.books.tables.chapters.rows.prolog.create'));
        $this->assertTrue($this->object->isValid('databases.books.tables.chapters.rows.prolog'));
        $this->assertTrue($this->object->isValid('databases.books.tables.chapters.rows.*.create'));
        $this->assertTrue($this->object->isValid('databases.books.tables.chapters.rows.*'));
        $this->assertTrue($this->object->isValid('databases.books.tables.*.rows.prolog.create'));
        $this->assertTrue($this->object->isValid('databases.books.tables.*.rows.prolog'));
        $this->assertTrue($this->object->isValid('databases.books.tables.*.rows.*.create'));
        $this->assertTrue($this->object->isValid('databases.books.tables.*.rows.*'));
        $this->assertTrue($this->object->isValid('databases.*.tables.chapters.rows.prolog.create'));
        $this->assertTrue($this->object->isValid('databases.*.tables.chapters.rows.prolog'));
        $this->assertTrue($this->object->isValid('databases.*.tables.chapters.rows.*.create'));
        $this->assertTrue($this->object->isValid('databases.*.tables.chapters.rows.*'));
        $this->assertTrue($this->object->isValid('databases.*.tables.*.rows.prolog.create'));
        $this->assertTrue($this->object->isValid('databases.*.tables.*.rows.prolog'));
        $this->assertTrue($this->object->isValid('databases.*.tables.*.rows.*.create'));
        $this->assertTrue($this->object->isValid('databases.*.tables.*.rows.*'));
        $this->assertTrue($this->object->isValid('databases.*.tables.*'));
        $this->assertTrue($this->object->isValid('databases.*'));
        $this->assertTrue($this->object->isValid('databases.books'));
        $this->assertTrue($this->object->isValid('databases.books.tables.chapters'));
        $this->assertTrue($this->object->isValid('databases.books.tables.*'));
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
        $this->assertFalse($this->object->isValid('tables'));
        $this->assertFalse($this->object->isValid('tables.*.unknown'));
        $this->assertFalse($this->object->isValid('tables.*.rows.*.unknown'));
        $this->assertFalse($this->object->isValid('users.torsten.unknown'));
        $this->assertFalse($this->object->isValid('users.torsten.delete.email'));
        $this->assertFalse($this->object->isValid('teams.*.memberships.*.update.unknown'));
    }
}
