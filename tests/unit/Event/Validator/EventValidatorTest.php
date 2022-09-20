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
        $this->assertTrue($this->object->isValid('databases.books.collections.chapters.documents.prolog.create'));
        $this->assertTrue($this->object->isValid('databases.books.collections.chapters.documents.prolog'));
        $this->assertTrue($this->object->isValid('databases.books.collections.chapters.documents.*.create'));
        $this->assertTrue($this->object->isValid('databases.books.collections.chapters.documents.*'));
        $this->assertTrue($this->object->isValid('databases.books.collections.*.documents.prolog.create'));
        $this->assertTrue($this->object->isValid('databases.books.collections.*.documents.prolog'));
        $this->assertTrue($this->object->isValid('databases.books.collections.*.documents.*.create'));
        $this->assertTrue($this->object->isValid('databases.books.collections.*.documents.*'));
        $this->assertTrue($this->object->isValid('databases.*.collections.chapters.documents.prolog.create'));
        $this->assertTrue($this->object->isValid('databases.*.collections.chapters.documents.prolog'));
        $this->assertTrue($this->object->isValid('databases.*.collections.chapters.documents.*.create'));
        $this->assertTrue($this->object->isValid('databases.*.collections.chapters.documents.*'));
        $this->assertTrue($this->object->isValid('databases.*.collections.*.documents.prolog.create'));
        $this->assertTrue($this->object->isValid('databases.*.collections.*.documents.prolog'));
        $this->assertTrue($this->object->isValid('databases.*.collections.*.documents.*.create'));
        $this->assertTrue($this->object->isValid('databases.*.collections.*.documents.*'));
        $this->assertTrue($this->object->isValid('databases.*.collections.*'));
        $this->assertTrue($this->object->isValid('databases.*'));
        $this->assertTrue($this->object->isValid('databases.books'));
        $this->assertTrue($this->object->isValid('databases.books.collections.chapters'));
        $this->assertTrue($this->object->isValid('databases.books.collections.*'));
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
