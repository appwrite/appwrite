<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Validator;

use Appwrite\Event\Validator\Event;
use PHPUnit\Framework\TestCase;

final class EventValidatorTest extends TestCase
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
        $this->assertTrue($this->object->isValid('users.*.update.phone'));
        $this->assertTrue($this->object->isValid('users.*.update.mfa'));
        $this->assertTrue($this->object->isValid('users.*.update.labels'));
        $this->assertTrue($this->object->isValid('users.*.update.verification'));
        $this->assertTrue($this->object->isValid('users.*.update.impersonator'));
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
        $this->assertTrue($this->object->isValid('tablesdb.books.tables.chapters.rows.prolog.create'));
        $this->assertTrue($this->object->isValid('tablesdb.books.tables.chapters.rows.prolog'));
        $this->assertTrue($this->object->isValid('tablesdb.books.tables.chapters.rows.*.create'));
        $this->assertTrue($this->object->isValid('tablesdb.books.tables.chapters.rows.*'));
        $this->assertTrue($this->object->isValid('tablesdb.books.tables.*.rows.prolog.create'));
        $this->assertTrue($this->object->isValid('tablesdb.books.tables.*.rows.prolog'));
        $this->assertTrue($this->object->isValid('tablesdb.books.tables.*.rows.*.create'));
        $this->assertTrue($this->object->isValid('tablesdb.books.tables.*.rows.*'));
        $this->assertTrue($this->object->isValid('tablesdb.*.tables.chapters.rows.prolog.create'));
        $this->assertTrue($this->object->isValid('tablesdb.*.tables.chapters.rows.prolog'));
        $this->assertTrue($this->object->isValid('tablesdb.*.tables.chapters.rows.*.create'));
        $this->assertTrue($this->object->isValid('tablesdb.*.tables.chapters.rows.*'));
        $this->assertTrue($this->object->isValid('tablesdb.*.tables.*.rows.prolog.create'));
        $this->assertTrue($this->object->isValid('tablesdb.*.tables.*.rows.prolog'));
        $this->assertTrue($this->object->isValid('tablesdb.*.tables.*.rows.*.create'));
        $this->assertTrue($this->object->isValid('tablesdb.*.tables.*.rows.*'));
        $this->assertTrue($this->object->isValid('tablesdb.*.tables.*'));
        $this->assertTrue($this->object->isValid('tablesdb.*'));
        $this->assertTrue($this->object->isValid('tablesdb.books'));
        $this->assertTrue($this->object->isValid('tablesdb.books.tables.chapters'));
        $this->assertTrue($this->object->isValid('tablesdb.books.tables.*'));
        $this->assertTrue($this->object->isValid('tablesdb.books.tables.chapters.columns.name.create'));
        $this->assertTrue($this->object->isValid('documentsdb.books.collections.chapters.documents.prolog.create'));
        $this->assertTrue($this->object->isValid('documentsdb.books.collections.chapters.documents.prolog'));
        $this->assertTrue($this->object->isValid('documentsdb.books.collections.chapters.documents.*.create'));
        $this->assertTrue($this->object->isValid('documentsdb.books.collections.chapters.documents.*'));
        $this->assertTrue($this->object->isValid('documentsdb.books.collections.*.documents.prolog.create'));
        $this->assertTrue($this->object->isValid('documentsdb.books.collections.*.documents.prolog'));
        $this->assertTrue($this->object->isValid('documentsdb.books.collections.*.documents.*.create'));
        $this->assertTrue($this->object->isValid('documentsdb.books.collections.*.documents.*'));
        $this->assertTrue($this->object->isValid('documentsdb.*.collections.chapters.documents.prolog.create'));
        $this->assertTrue($this->object->isValid('documentsdb.*.collections.chapters.documents.prolog'));
        $this->assertTrue($this->object->isValid('documentsdb.*.collections.chapters.documents.*.create'));
        $this->assertTrue($this->object->isValid('documentsdb.*.collections.chapters.documents.*'));
        $this->assertTrue($this->object->isValid('documentsdb.*.collections.*.documents.prolog.create'));
        $this->assertTrue($this->object->isValid('documentsdb.*.collections.*.documents.prolog'));
        $this->assertTrue($this->object->isValid('documentsdb.*.collections.*.documents.*.create'));
        $this->assertTrue($this->object->isValid('documentsdb.*.collections.*.documents.*'));
        $this->assertTrue($this->object->isValid('documentsdb.*.collections.*'));
        $this->assertTrue($this->object->isValid('documentsdb.*'));
        $this->assertTrue($this->object->isValid('documentsdb.books'));
        $this->assertTrue($this->object->isValid('documentsdb.books.collections.chapters'));
        $this->assertTrue($this->object->isValid('documentsdb.books.collections.*'));
        $this->assertTrue($this->object->isValid('documentsdb.books.collections.chapters.attributes.name.create'));
        $this->assertTrue($this->object->isValid('vectorsdb.books.collections.chapters.documents.prolog.create'));
        $this->assertTrue($this->object->isValid('vectorsdb.books.collections.chapters.documents.prolog'));
        $this->assertTrue($this->object->isValid('vectorsdb.books.collections.chapters.documents.*.create'));
        $this->assertTrue($this->object->isValid('vectorsdb.books.collections.chapters.documents.*'));
        $this->assertTrue($this->object->isValid('vectorsdb.books.collections.*.documents.prolog.create'));
        $this->assertTrue($this->object->isValid('vectorsdb.books.collections.*.documents.prolog'));
        $this->assertTrue($this->object->isValid('vectorsdb.books.collections.*.documents.*.create'));
        $this->assertTrue($this->object->isValid('vectorsdb.books.collections.*.documents.*'));
        $this->assertTrue($this->object->isValid('vectorsdb.*.collections.chapters.documents.prolog.create'));
        $this->assertTrue($this->object->isValid('vectorsdb.*.collections.chapters.documents.prolog'));
        $this->assertTrue($this->object->isValid('vectorsdb.*.collections.chapters.documents.*.create'));
        $this->assertTrue($this->object->isValid('vectorsdb.*.collections.chapters.documents.*'));
        $this->assertTrue($this->object->isValid('vectorsdb.*.collections.*.documents.prolog.create'));
        $this->assertTrue($this->object->isValid('vectorsdb.*.collections.*.documents.prolog'));
        $this->assertTrue($this->object->isValid('vectorsdb.*.collections.*.documents.*.create'));
        $this->assertTrue($this->object->isValid('vectorsdb.*.collections.*.documents.*'));
        $this->assertTrue($this->object->isValid('vectorsdb.*.collections.*'));
        $this->assertTrue($this->object->isValid('vectorsdb.*'));
        $this->assertTrue($this->object->isValid('vectorsdb.books'));
        $this->assertTrue($this->object->isValid('vectorsdb.books.collections.chapters'));
        $this->assertTrue($this->object->isValid('vectorsdb.books.collections.*'));
        $this->assertTrue($this->object->isValid('vectorsdb.books.collections.chapters.attributes.name.create'));
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
        $this->assertFalse($this->object->isValid('tablesdb.books.collections.chapters.documents.prolog.create'));
        $this->assertFalse($this->object->isValid('tablesdb.books.tables.chapters.rows.prolog.unknown'));
        $this->assertFalse($this->object->isValid('documentsdb.books.tables.chapters.rows.prolog.create'));
        $this->assertFalse($this->object->isValid('documentsdb.books.collections.chapters.documents.prolog.unknown'));
        $this->assertFalse($this->object->isValid('vectorsdb.books.tables.chapters.rows.prolog.create'));
        $this->assertFalse($this->object->isValid('vectorsdb.books.collections.chapters.documents.prolog.unknown'));
        $this->assertFalse($this->object->isValid('users.torsten.unknown'));
        $this->assertFalse($this->object->isValid('users.torsten.delete.email'));
        $this->assertFalse($this->object->isValid('teams.*.memberships.*.update.unknown'));
    }
}
