<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Documents;

use Appwrite\Utopia\Database\Documents\TransactionLog;
use PDO;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Permission;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;

final class TransactionLogTest extends TestCase
{
    private const string VERSION = '2026-01-01T10:00:00.000+00:00';

    private const string STAGED = '2026-01-01T10:00:05.000+00:00';

    private Database $database;

    protected function setUp(): void
    {
        $authorization = new Authorization();
        $authorization->addRole(Role::any()->toString());

        $this->database = new Database(new SQLite(new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])), new Cache(new None()));
        $this->database
            ->setAuthorization($authorization)
            ->setDatabase('transactionLog')
            ->setNamespace('transaction_log_' . \uniqid());
        $this->database->create();
        $this->database->createCollection(Collection::create(
            id: 'rows',
            attributes: [Attribute::string('owner', size: 32)],
            permissions: [Permission::read(Role::any()), Permission::create(Role::any()), Permission::update(Role::any())],
        ));
        $this->database->withPreserveDates(true, fn () => $this->database->createDocument('rows', new Document([
            '$id' => 'claim',
            'owner' => '',
            '$createdAt' => self::VERSION,
            '$updatedAt' => self::VERSION,
        ])));
    }

    public function testRequestTimestampIsTheRowVersionWhenStaged(): void
    {
        $log = new TransactionLog(['$createdAt' => self::STAGED, 'documentUpdatedAt' => self::VERSION]);

        $this->assertEquals(new \DateTime(self::VERSION), $log->getRequestTimestamp());
    }

    public function testRequestTimestampFallsBackToTheStagingTime(): void
    {
        $log = new TransactionLog(['$createdAt' => self::STAGED, 'documentUpdatedAt' => null]);

        $this->assertEquals(new \DateTime(self::STAGED), $log->getRequestTimestamp());
    }

    public function testCommitConflictsWithAWriteStampedBeforeStaging(): void
    {
        $log = new TransactionLog(['$createdAt' => self::STAGED, 'documentUpdatedAt' => self::VERSION]);

        $this->database->withPreserveDates(true, fn () => $this->database->updateDocument('rows', 'claim', new Document([
            'owner' => 'first',
            '$updatedAt' => '2026-01-01T10:00:02.000+00:00',
        ])));

        $this->expectException(ConflictException::class);

        $this->database->withRequestTimestamp(
            $log->getRequestTimestamp(),
            fn () => $this->database->updateDocument('rows', 'claim', new Document(['owner' => 'second'])),
        );
    }

    public function testCommitGoesThroughWhenTheRowIsUnchangedSinceStaging(): void
    {
        $log = new TransactionLog(['$createdAt' => self::STAGED, 'documentUpdatedAt' => self::VERSION]);

        $row = $this->database->withRequestTimestamp(
            $log->getRequestTimestamp(),
            fn () => $this->database->updateDocument('rows', 'claim', new Document(['owner' => 'second'])),
        );

        $this->assertSame('second', $row->getAttribute('owner'));
    }
}
