<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Realtime;
use Appwrite\Platform\Modules\Databases\Workers\Databases;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Attribute;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\NotFound;
use Utopia\Database\Exception\Timeout;
use Utopia\Database\Relationship;
use Utopia\Database\RelationshipDeleteAction;
use Utopia\Database\RelationshipType;
use Utopia\Query\Schema\ColumnType;
use Utopia\Queue\Message;
use Utopia\Queue\PermanentFailure;

require_once __DIR__ . '/../../../../app/init.php';

final class DatabasesTest extends TestCase
{
    private const string PHYSICAL_COLLECTION = 'database_30360_collection_1';

    /**
     * A dedicated database (tablesdb/documentsdb/vectorsdb) hosts its collections
     * on the backing resolved by getDatabasesDB, not the shared project database.
     * createAttribute must run its DDL on that resolved database — as its siblings
     * createIndex/deleteAttribute already do — or, for a dedicated backing, it
     * queries the shared pool, cannot find the collection and fails
     * "Collection not found" (DAT-1967).
     */
    public function testCreateAttributeTargetsResolvedDatabaseNotProjectDb(): void
    {
        $attribute = new Document([
            '$id' => 'attr1',
            'key' => 'note',
            'type' => ColumnType::String->value,
            'size' => 64,
            'required' => false,
            'default' => null,
        ]);

        // The shared project DB owns the `attributes` metadata but must never
        // receive the collection DDL for a dedicated backing.
        $dbForProject = $this->createMock(Database::class);
        $dbForProject->method('getDocument')->willReturnCallback(
            fn (string $collection, string $id) => $collection === 'attributes' ? $attribute : new Document()
        );
        $dbForProject->method('updateDocument')->willReturnArgument(2);
        $dbForProject->expects($this->never())->method('createAttribute');

        // The resolved (dedicated) backing must receive the physical DDL.
        $dbForDatabases = $this->createMock(Database::class);
        $dbForDatabases->expects($this->once())
            ->method('createAttribute')
            ->with(self::PHYSICAL_COLLECTION, $this->callback(
                function (Attribute $created): bool {
                    $this->assertSame('note', $created->key);
                    $this->assertSame(ColumnType::String, $created->type);
                    $this->assertSame(64, $created->size);
                    return true;
                }
            ))
            ->willReturnArgument(1);

        $this->process(DATABASE_TYPE_CREATE_ATTRIBUTE, ['$id' => 'attr1'], [], $dbForProject, $dbForDatabases);
    }

    /**
     * A wide table of varchar columns passes the library's 65535-byte width check but not
     * InnoDB's 8126-byte inline row limit, so MySQL refuses the ALTER with 1118. That is the
     * customer's definition being refused: the column is failed with the reason and the job
     * returns, which acknowledges it. Rethrowing redelivered it five times over ~22 minutes of
     * backoff, each attempt holding an ack-pending slot, until 16 of them blocked the pool.
     */
    public function testCreateAttributeRefusedForRowSizeIsFailedAndNotRetried(): void
    {
        $refusal = self::mapped(self::engineError('42000', 1118, 'SQLSTATE[42000]: Syntax error or access violation: 1118 Row size too large. The maximum row size for the used table type, not counting BLOBs, is 8126. This includes storage overhead, check the manual. You have to change some columns to TEXT or BLOBs'));

        $updates = $this->processFailedCreate(DATABASE_TYPE_CREATE_ATTRIBUTE, 'attributes', self::column(), 'createAttribute', $refusal);

        $this->assertSame([['attributes/attr1', 'failed', 'Row width limit reached']], $updates);
    }

    /**
     * @return \Iterator<string, array{\PDOException, string}>
     */
    public static function engineLimitProvider(): \Iterator
    {
        yield 'too many columns' => [self::engineError('HY000', 1117, 'SQLSTATE[HY000]: General error: 1117 Too many columns'), 'Column limit reached'];
        yield 'too many indexes' => [self::engineError('42000', 1069, 'SQLSTATE[42000]: Syntax error or access violation: 1069 Too many keys specified; max 64 keys allowed'), 'Index limit reached'];
    }

    #[DataProvider('engineLimitProvider')]
    public function testCreateIndexRefusedForAnEngineLimitIsFailedAndNotRetried(PDOException $error, string $reason): void
    {
        $updates = $this->processFailedCreate(DATABASE_TYPE_CREATE_INDEX, 'indexes', new Document(['$id' => 'index1', 'key' => 'by_notes', 'type' => 'key', 'attributes' => ['notes']]), 'createIndex', self::mapped($error));

        $this->assertSame([['indexes/index1', 'failed', $reason]], $updates);
    }

    public function testCreateAttributeOnATableThatNoLongerExistsIsFailedAndNotRetried(): void
    {
        $updates = $this->processFailedCreate(DATABASE_TYPE_CREATE_ATTRIBUTE, 'attributes', self::column(), 'createAttribute', new NotFound('Collection not found'));

        $this->assertSame([['attributes/attr1', 'failed', 'Collection not found']], $updates);
    }

    public function testCreateRelationshipToACollectionThatNoLongerExistsIsFailedAndNotRetried(): void
    {
        $attribute = new Document([
            '$id' => 'attr1',
            'key' => 'author',
            'type' => ColumnType::Relationship->value,
            'options' => ['relatedCollection' => 'authors'],
        ]);

        $dbForProject = $this->createStub(Database::class);
        $dbForProject->method('getDocument')->willReturnCallback(
            static fn (string $collection): Document => $collection === 'attributes' ? $attribute : new Document()
        );
        $updates = [];
        $dbForProject->method('updateDocument')->willReturnCallback(self::recordUpdates($updates));

        $dbForDatabases = $this->createMock(Database::class);
        $dbForDatabases->expects($this->never())->method('createRelationship');

        $this->process(DATABASE_TYPE_CREATE_ATTRIBUTE, ['$id' => 'attr1'], [], $dbForProject, $dbForDatabases);

        $this->assertSame([['attributes/attr1', 'failed', 'Collection/Table not found']], $updates);
    }

    /**
     * A timeout or a lost connection says nothing about the definition: the next delivery may
     * well succeed, so the job still fails and is redelivered on the queue's backoff.
     */
    public function testCreateAttributeInterruptedByATimeoutIsRetried(): void
    {
        $this->expectException(Timeout::class);

        $this->processFailedCreate(DATABASE_TYPE_CREATE_ATTRIBUTE, 'attributes', self::column(), 'createAttribute', new Timeout('Query timed out'));
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function collectionJobProvider(): \Iterator
    {
        yield 'create attribute' => [DATABASE_TYPE_CREATE_ATTRIBUTE];
        yield 'delete attribute' => [DATABASE_TYPE_DELETE_ATTRIBUTE];
        yield 'create index' => [DATABASE_TYPE_CREATE_INDEX];
        yield 'delete index' => [DATABASE_TYPE_DELETE_INDEX];
        yield 'delete collection' => [DATABASE_TYPE_DELETE_COLLECTION];
    }

    /**
     * A job whose payload names no collection fails the same way on every delivery, so it is
     * dead-lettered at the first attempt instead of holding an ack-pending slot through the
     * whole backoff.
     */
    #[DataProvider('collectionJobProvider')]
    public function testJobWithoutACollectionIsAPermanentFailure(string $type): void
    {
        $this->expectException(PermanentFailure::class);
        $this->expectExceptionMessage('Missing collection/table');

        $this->process($type, ['$id' => 'attr1'], null, $this->createStub(Database::class), $this->createStub(Database::class));
    }

    public function testJobOfAnUnknownTypeIsAPermanentFailure(): void
    {
        $this->expectException(PermanentFailure::class);
        $this->expectExceptionMessage('No database operation for type: compactCollection');

        $this->process('compactCollection', [], [], $this->createStub(Database::class), $this->createStub(Database::class));
    }

    public function testCreateRelationshipBuildsTheRelationshipFromStoredOptions(): void
    {
        $attribute = new Document([
            '$id' => 'attr1',
            'key' => 'author',
            'type' => ColumnType::Relationship->value,
            'options' => [
                'relatedCollection' => 'authors',
                'relationType' => RelationshipType::ManyToOne->value,
                'twoWay' => false,
                'twoWayKey' => 'books',
                'onDelete' => RelationshipDeleteAction::SetNull->value,
            ],
        ]);

        $dbForProject = $this->createStub(Database::class);
        $dbForProject->method('getDocument')->willReturnCallback(
            static fn (string $collection, string $id): Document => match ($collection) {
                'attributes' => $attribute,
                'database_30360' => new Document(['$id' => 'authors', '$sequence' => '2']),
                default => new Document(),
            }
        );
        $dbForProject->method('updateDocument')->willReturnArgument(2);

        $dbForDatabases = $this->createMock(Database::class);
        $dbForDatabases->expects($this->once())
            ->method('createRelationship')
            ->with(self::PHYSICAL_COLLECTION, $this->callback(
                function (Relationship $relationship): bool {
                    $this->assertSame('database_30360_collection_2', $relationship->relatedCollection);
                    $this->assertSame(RelationshipType::ManyToOne, $relationship->type);
                    $this->assertSame('author', $relationship->key);
                    $this->assertSame('books', $relationship->twoWayKey);
                    $this->assertSame(RelationshipDeleteAction::SetNull, $relationship->onDelete);
                    return true;
                }
            ))
            ->willReturnArgument(1);

        $this->process(DATABASE_TYPE_CREATE_ATTRIBUTE, ['$id' => 'attr1'], [], $dbForProject, $dbForDatabases);
    }

    public function testDeleteAttributeRemovesTheMetadataWhenTheColumnIsDropped(): void
    {
        $dbForProject = $this->createMock(Database::class);
        $dbForProject->method('getDocument')->willReturn(new Document());
        $dbForProject->expects($this->never())->method('updateDocument');
        $dbForProject->expects($this->once())
            ->method('deleteDocument')
            ->with('attributes', 'attr1')
            ->willReturn(true);

        $dbForDatabases = $this->createMock(Database::class);
        $dbForDatabases->expects($this->once())
            ->method('deleteAttribute')
            ->with(self::PHYSICAL_COLLECTION, 'note');

        $this->process(DATABASE_TYPE_DELETE_ATTRIBUTE, [
            '$id' => 'attr1',
            'key' => 'note',
            'type' => ColumnType::String->value,
        ], [], $dbForProject, $dbForDatabases);
    }

    public function testDeleteRelationshipFailureMarksBothSidesStuck(): void
    {
        $relatedAttribute = new Document(['$id' => '30360_2_books', 'key' => 'books']);

        $dbForProject = $this->createMock(Database::class);
        $dbForProject->method('getDocument')->willReturnCallback(
            static fn (string $collection, string $id): Document => match ([$collection, $id]) {
                ['database_30360', 'authors'] => new Document(['$id' => 'authors', '$sequence' => '2']),
                ['attributes', '30360_2_books'] => $relatedAttribute,
                default => new Document(),
            }
        );
        $dbForProject->expects($this->never())->method('deleteDocument');

        $stuck = [];
        $dbForProject->method('updateDocument')->willReturnCallback(
            static function (string $collection, string $id, Document $update) use (&$stuck): Document {
                $stuck[$collection . '/' . $id] = [$update->getAttribute('status'), $update->getAttribute('error')];

                return $update;
            }
        );

        $dbForDatabases = $this->createMock(Database::class);
        $dbForDatabases->expects($this->once())
            ->method('deleteRelationship')
            ->with(self::PHYSICAL_COLLECTION, 'author')
            ->willThrowException(new DatabaseException('Failed to delete relationship'));

        try {
            $this->process(DATABASE_TYPE_DELETE_ATTRIBUTE, [
                '$id' => 'attr1',
                'key' => 'author',
                'type' => ColumnType::Relationship->value,
                'options' => [
                    'relatedCollection' => 'authors',
                    'twoWay' => true,
                    'twoWayKey' => 'books',
                ],
            ], [], $dbForProject, $dbForDatabases);
            $this->fail('A failed relationship delete must propagate');
        } catch (DatabaseException $exception) {
            $this->assertSame('Failed to delete relationship', $exception->getMessage());
        }

        $this->assertSame([
            'attributes/attr1' => ['stuck', 'Failed to delete relationship'],
            'attributes/30360_2_books' => ['stuck', 'Failed to delete relationship'],
        ], $stuck);
    }

    public function testDeleteIndexRemovesTheMetadataWhenTheIndexIsDropped(): void
    {
        $dbForProject = $this->createMock(Database::class);
        $dbForProject->expects($this->never())->method('updateDocument');
        $dbForProject->expects($this->once())
            ->method('deleteDocument')
            ->with('indexes', 'index1')
            ->willReturn(true);

        $dbForDatabases = $this->createMock(Database::class);
        $dbForDatabases->expects($this->once())
            ->method('deleteIndex')
            ->with(self::PHYSICAL_COLLECTION, 'title_key');

        $this->process(DATABASE_TYPE_DELETE_INDEX, [
            '$id' => 'index1',
            'key' => 'title_key',
            'status' => 'deleting',
        ], [], $dbForProject, $dbForDatabases);
    }

    /**
     * Runs a create job whose DDL fails with $failure and returns the status and error writes
     * made to its metadata document. Returning at all means the job was acknowledged.
     *
     * @return list<array{0: string, 1: mixed, 2: mixed}>
     */
    private function processFailedCreate(string $type, string $metadata, Document $document, string $method, \Throwable $failure): array
    {
        $dbForProject = $this->createStub(Database::class);
        $dbForProject->method('getDocument')->willReturnCallback(
            static fn (string $collection): Document => $collection === $metadata ? $document : new Document()
        );
        $updates = [];
        $dbForProject->method('updateDocument')->willReturnCallback(self::recordUpdates($updates));

        $dbForDatabases = $this->createMock(Database::class);
        $dbForDatabases->expects($this->once())
            ->method($method)
            ->with(self::PHYSICAL_COLLECTION)
            ->willThrowException($failure);

        $this->process($type, $document->getArrayCopy(), [], $dbForProject, $dbForDatabases);

        return $updates;
    }

    private static function column(): Document
    {
        return new Document(['$id' => 'attr1', 'key' => 'notes', 'type' => ColumnType::String->value, 'size' => 255]);
    }

    /**
     * @param list<array{0: string, 1: mixed, 2: mixed}> $updates
     */
    private static function recordUpdates(array &$updates): \Closure
    {
        return static function (string $collection, string $id, Document $update) use (&$updates): Document {
            $updates[] = [$collection . '/' . $id, $update->getAttribute('status'), $update->getAttribute('error')];

            return $update;
        };
    }

    /**
     * What the adapter the worker runs on makes of an engine error.
     */
    private static function mapped(PDOException $error): \Throwable
    {
        $adapter = new class (new stdClass()) extends MySQL {
            public function map(PDOException $error): \Throwable
            {
                return $this->processException($error);
            }
        };

        return $adapter->map($error);
    }

    private static function engineError(string $state, int $code, string $message): PDOException
    {
        $error = new class ($message, $state) extends PDOException {
            public function __construct(string $message, string $state)
            {
                parent::__construct($message);
                $this->code = $state;
            }
        };
        $error->errorInfo = [$state, $code, $message];

        return $error;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed>|null $collection Merged over the default collection, or null for none.
     */
    private function process(string $type, array $document, ?array $collection, Database $dbForProject, Database $dbForDatabases): void
    {
        $dbForPlatform = $this->createStub(Database::class);
        $dbForPlatform->method('getDocument')->willReturn(new Document(['$id' => 'proj1']));

        $worker = new class () extends Databases {
            #[\Override]
            protected function trigger(
                Document $database,
                Document $collection,
                Document $project,
                string $event,
                Realtime $queueForRealtime,
                Document|null $attribute = null,
                Document|null $index = null,
            ): void {
            }
        };

        $message = new Message([
            'pid' => 'pid',
            'queue' => 'v1-databases',
            'timestamp' => \time(),
            'payload' => [
                'type' => $type,
                'database' => ['$id' => 'db1', '$sequence' => '30360'],
                'collection' => $collection === null ? [] : ['$id' => 'proof', '$sequence' => '1', ...$collection],
                'document' => $document,
            ],
        ]);

        $worker->action(
            $message,
            new Document(['$id' => 'proj1']),
            $dbForPlatform,
            $dbForProject,
            static fn () => $dbForDatabases,
            $this->createStub(Realtime::class),
        );
    }
}
