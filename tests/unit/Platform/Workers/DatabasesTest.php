<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Realtime;
use Appwrite\Platform\Modules\Databases\Workers\Databases;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\NotFound;
use Utopia\Database\Index;
use Utopia\Database\Relationship;
use Utopia\Database\RelationshipDeleteAction;
use Utopia\Database\RelationshipType;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Schema\ColumnType;
use Utopia\Query\Schema\IndexType;
use Utopia\Queue\Message;

require_once __DIR__ . '/../../../../app/init.php';

final class DatabasesTest extends TestCase
{
    private const string PHYSICAL_COLLECTION = 'database_30360_collection_1';

    private const string UPDATE_EVENT = 'databases.[databaseId].collections.[collectionId].indexes.[indexId].update';

    private const string DELETE_EVENT = 'databases.[databaseId].collections.[collectionId].indexes.[indexId].delete';

    /**
     * @var array<string, Document>
     */
    private array $stored = [];

    /**
     * @var list<string>
     */
    private array $statusWrites = [];

    /**
     * @var list<array{event: string, payload: array<string, mixed>|null}>
     */
    private array $triggers = [];

    private ?\Closure $beforeUpdate = null;

    private ?\Closure $beforeRead = null;

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
        $dbForProject->method('getDocument')->willReturnCallback(
            static fn (string $collection, string $id, mixed ...$rest): Document => [$collection, $id] === ['indexes', 'index1']
                ? new Document(['$id' => 'index1', '$sequence' => '7', 'status' => 'deleting'])
                : new Document()
        );
        $dbForProject->expects($this->never())->method('updateDocument');
        $dbForProject->expects($this->once())
            ->method('deleteDocument')
            ->with('indexes', 'index1')
            ->willReturn(true);

        $dbForDatabases = $this->createMock(Database::class);
        $dbForDatabases->expects($this->once())
            ->method('deleteIndex')
            ->with(self::PHYSICAL_COLLECTION, 'title_key');
        $dbForDatabases->expects($this->never())->method('findCollection');

        $this->process(DATABASE_TYPE_DELETE_INDEX, [
            '$id' => 'index1',
            '$sequence' => '7',
            'key' => 'title_key',
            'status' => 'deleting',
        ], [], $dbForProject, $dbForDatabases);
    }

    public function testSecondDeleteIndexJobIsSkipped(): void
    {
        $dbForDatabases = $this->databases($this->libraryIndex('title_key', ['title']));
        $index = $this->store($this->indexDocument('title_key', 'deleting'));

        $this->process(DATABASE_TYPE_DELETE_INDEX, $index->getArrayCopy(), [], $this->projectDatabase(), $dbForDatabases);
        $this->process(DATABASE_TYPE_DELETE_INDEX, $index->getArrayCopy(), [], $this->projectDatabase(), $dbForDatabases);

        $this->assertArrayNotHasKey($index->getId(), $this->stored);
        $this->assertSame([], $this->libraryIndexKeys($dbForDatabases));
        $this->assertSame([], $this->statusWrites);
        $this->assertCount(1, $this->triggers);
        $this->assertSame(self::DELETE_EVENT, $this->triggers[0]['event']);
        $this->assertSame('deleted', $this->triggers[0]['payload']['status'] ?? null);
    }

    public function testRetryAfterTheSchemaDropFinishesTheCleanup(): void
    {
        $dbForDatabases = $this->databases();
        $index = $this->store($this->indexDocument('title_key', 'stuck'));

        $this->process(DATABASE_TYPE_DELETE_INDEX, $index->getArrayCopy(), [], $this->projectDatabase(), $dbForDatabases);

        $this->assertArrayNotHasKey($index->getId(), $this->stored);
        $this->assertSame([], $this->statusWrites);
    }

    public function testDeleteIndexJobWithoutSequenceStillDeletes(): void
    {
        $dbForDatabases = $this->databases($this->libraryIndex('title_key', ['title']));
        $index = $this->store($this->indexDocument('title_key', 'deleting'));
        $queued = $index->getArrayCopy();
        unset($queued['$sequence']);

        $this->process(DATABASE_TYPE_DELETE_INDEX, $queued, [], $this->projectDatabase(), $dbForDatabases);

        $this->assertArrayNotHasKey($index->getId(), $this->stored);
        $this->assertSame([], $this->libraryIndexKeys($dbForDatabases));
    }

    public function testDeletingAFailedIndexDropsItsLeftoverLibraryIndex(): void
    {
        $dbForDatabases = $this->databases($this->libraryIndex('title_key', ['title']));
        $index = $this->store($this->indexDocument('title_key', 'failed'));

        $this->process(DATABASE_TYPE_DELETE_INDEX, $index->getArrayCopy(), [], $this->projectDatabase(), $dbForDatabases);

        $this->assertArrayNotHasKey($index->getId(), $this->stored);
        $this->assertSame([], $this->libraryIndexKeys($dbForDatabases));
        $this->assertSame([], $this->statusWrites);
    }

    public function testDeletingAFailedIndexWithoutALibraryIndexRemovesTheMetadata(): void
    {
        $dbForDatabases = $this->databases();
        $index = $this->store($this->indexDocument('title_key', 'failed'));

        $this->process(DATABASE_TYPE_DELETE_INDEX, $index->getArrayCopy(), [], $this->projectDatabase(), $dbForDatabases);

        $this->assertArrayNotHasKey($index->getId(), $this->stored);
        $this->assertSame([], $this->statusWrites);
    }

    public function testDeletingAProcessingIndexStopsItsCreateJob(): void
    {
        $dbForDatabases = $this->databases();
        $index = $this->store($this->indexDocument('title_key', 'processing'));

        $this->process(DATABASE_TYPE_DELETE_INDEX, $index->getArrayCopy(), [], $this->projectDatabase(), $dbForDatabases);
        $this->process(DATABASE_TYPE_CREATE_INDEX, $index->getArrayCopy(), [], $this->projectDatabase(), $dbForDatabases);

        $this->assertArrayNotHasKey($index->getId(), $this->stored);
        $this->assertNotContains('title_key', $this->libraryIndexKeys($dbForDatabases));
    }

    public function testDeleteIndexFailureStillMarksTheIndexStuck(): void
    {
        $index = $this->store($this->indexDocument('title_key', 'deleting'));

        $dbForDatabases = $this->createMock(Database::class);
        $dbForDatabases->expects($this->once())
            ->method('deleteIndex')
            ->with(self::PHYSICAL_COLLECTION, 'title_key')
            ->willThrowException(new DatabaseException('boom'));
        $dbForDatabases->expects($this->never())->method('findCollection');

        try {
            $this->process(DATABASE_TYPE_DELETE_INDEX, $index->getArrayCopy(), [], $this->projectDatabase(), $dbForDatabases);
            $this->fail('A failed index delete must propagate');
        } catch (DatabaseException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertSame('stuck', $this->stored[$index->getId()]->getAttribute('status'));
        $this->assertSame('boom', $this->stored[$index->getId()]->getAttribute('error'));
        $this->assertCount(1, $this->triggers);
        $this->assertSame(self::DELETE_EVENT, $this->triggers[0]['event']);
        $this->assertSame('stuck', $this->triggers[0]['payload']['status'] ?? null);
        $this->assertSame('boom', $this->triggers[0]['payload']['error'] ?? null);
    }

    public function testDeleteIndexFailureClearsAnEarlierError(): void
    {
        $index = $this->store(new Document([
            ...$this->indexDocument('title_key', 'deleting')->getArrayCopy(),
            'error' => 'earlier',
        ]));

        $dbForDatabases = $this->createStub(Database::class);
        $dbForDatabases->method('deleteIndex')->willThrowException(new \RuntimeException('engine down'));

        try {
            $this->process(DATABASE_TYPE_DELETE_INDEX, $index->getArrayCopy(), [], $this->projectDatabase(), $dbForDatabases);
            $this->fail('A failed index delete must propagate');
        } catch (\RuntimeException $exception) {
            $this->assertSame('engine down', $exception->getMessage());
        }

        $this->assertSame('stuck', $this->stored[$index->getId()]->getAttribute('status'));
        $this->assertSame('', $this->stored[$index->getId()]->getAttribute('error'));
    }

    public function testStaleDeleteIndexJobLeavesARecreatedIndexAlone(): void
    {
        $dbForDatabases = $this->databases($this->libraryIndex('title_key', ['title']));
        $this->store($this->indexDocument('title_key', 'available', sequence: '8'));
        $stale = $this->indexDocument('title_key', 'deleting');

        $this->process(DATABASE_TYPE_DELETE_INDEX, $stale->getArrayCopy(), [], $this->projectDatabase(), $dbForDatabases);

        $this->assertSame(['title_key'], $this->libraryIndexKeys($dbForDatabases));
        $this->assertSame('8', $this->stored[$stale->getId()]->getSequence());
        $this->assertSame('available', $this->stored[$stale->getId()]->getAttribute('status'));
        $this->assertSame([], $this->statusWrites);
        $this->assertSame([], $this->triggers);
    }

    public function testMissingPhysicalCollectionIsStillAFailure(): void
    {
        $dbForDatabases = $this->databasesWithoutCollection();
        $index = $this->store($this->indexDocument('title_key', 'deleting'));

        try {
            $this->process(DATABASE_TYPE_DELETE_INDEX, $index->getArrayCopy(), [], $this->projectDatabase(), $dbForDatabases);
            $this->fail('Deleting an index of a missing collection must propagate');
        } catch (NotFound $exception) {
            $this->assertSame('Collection not found', $exception->getMessage());
        }

        $this->assertSame('stuck', $this->stored[$index->getId()]->getAttribute('status'));
        $this->assertSame('Collection not found', $this->stored[$index->getId()]->getAttribute('error'));
    }

    public function testDuplicateIndexLeftAfterDeletingAnAttributeIsDropped(): void
    {
        $dbForDatabases = $this->databases(
            $this->libraryIndex('composite', ['title', 'note']),
            $this->libraryIndex('single', ['title']),
        );
        $composite = $this->store($this->indexDocument('composite', 'available', ['title', 'note']));
        $single = $this->store($this->indexDocument('single', 'available', sequence: '8'));

        $this->process(DATABASE_TYPE_DELETE_ATTRIBUTE, [
            '$id' => '30360_1_note',
            'key' => 'note',
            'type' => ColumnType::String->value,
        ], ['indexes' => [$composite->getArrayCopy(), $single->getArrayCopy()]], $this->projectDatabase(), $dbForDatabases);

        $this->assertSame(['single'], $this->libraryIndexKeys($dbForDatabases));
        $this->assertArrayNotHasKey($composite->getId(), $this->stored);
        $this->assertArrayHasKey($single->getId(), $this->stored);
    }

    public function testCreateIndexMakesTheIndexAvailable(): void
    {
        $dbForDatabases = $this->databases();
        $index = $this->store($this->indexDocument('title_key', 'processing'));

        $this->process(DATABASE_TYPE_CREATE_INDEX, $index->getArrayCopy(), [], $this->projectDatabase(), $dbForDatabases);

        $this->assertSame(['title_key'], $this->libraryIndexKeys($dbForDatabases));
        $this->assertSame('available', $this->stored[$index->getId()]->getAttribute('status'));
        $this->assertSame(['available'], $this->statusWrites);
        $this->assertCount(1, $this->triggers);
        $this->assertSame(self::UPDATE_EVENT, $this->triggers[0]['event']);
        $this->assertSame('available', $this->triggers[0]['payload']['status'] ?? null);
    }

    public function testStaleCreateIndexJobIsSkipped(): void
    {
        $dbForDatabases = $this->databases();
        $this->store($this->indexDocument('title_key', 'processing', sequence: '8'));
        $stale = $this->indexDocument('title_key', 'processing');

        $this->process(DATABASE_TYPE_CREATE_INDEX, $stale->getArrayCopy(), [], $this->projectDatabase(), $dbForDatabases);

        $this->assertSame([], $this->libraryIndexKeys($dbForDatabases));
        $this->assertSame('processing', $this->stored[$stale->getId()]->getAttribute('status'));
        $this->assertSame([], $this->statusWrites);
        $this->assertSame([], $this->triggers);
    }

    public function testCreateIndexFailureMarksTheIndexFailed(): void
    {
        $index = $this->store($this->indexDocument('title_key', 'processing'));

        $dbForDatabases = $this->createStub(Database::class);
        $dbForDatabases->method('createIndex')->willThrowException(new DatabaseException('boom'));

        try {
            $this->process(DATABASE_TYPE_CREATE_INDEX, $index->getArrayCopy(), [], $this->projectDatabase(), $dbForDatabases);
            $this->fail('A failed index create must propagate');
        } catch (DatabaseException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertSame('failed', $this->stored[$index->getId()]->getAttribute('status'));
        $this->assertSame('boom', $this->stored[$index->getId()]->getAttribute('error'));
        $this->assertSame(['failed'], $this->statusWrites);
    }

    public function testCreateIndexIsUndoneWhenTheIndexIsDeletedDuringCreation(): void
    {
        $dbForDatabases = $this->databases();
        $index = $this->store($this->indexDocument('title_key', 'processing'));
        $this->beforeUpdate = function (): void {
            $this->stored = [];
        };

        $this->process(DATABASE_TYPE_CREATE_INDEX, $index->getArrayCopy(), [], $this->projectDatabase(), $dbForDatabases);

        $this->assertSame([], $this->libraryIndexKeys($dbForDatabases));
        $this->assertSame([], $this->triggers);
    }

    public function testCreateIndexIsUndoneWhenTheIndexIsRecreatedDuringCreation(): void
    {
        $dbForDatabases = $this->databases();
        $index = $this->store($this->indexDocument('title_key', 'processing'));
        $recreated = $this->indexDocument('title_key', 'processing', sequence: '8');
        $this->replaceOnRead(2, $recreated);

        $this->process(DATABASE_TYPE_CREATE_INDEX, $index->getArrayCopy(), [], $this->projectDatabase(), $dbForDatabases);

        $this->assertSame([], $this->libraryIndexKeys($dbForDatabases));
        $this->assertSame($recreated->getArrayCopy(), $this->stored[$index->getId()]->getArrayCopy());
        $this->assertSame([], $this->statusWrites);
        $this->assertSame([], $this->triggers);
    }

    public function testFailedUndoOfARecreatedIndexPropagatesWithoutTouchingIt(): void
    {
        $index = $this->store($this->indexDocument('title_key', 'processing'));
        $recreated = $this->indexDocument('title_key', 'processing', sequence: '8');
        $this->replaceOnRead(2, $recreated);

        $dbForDatabases = $this->createStub(Database::class);
        $dbForDatabases->method('createIndex')->willReturnArgument(1);
        $dbForDatabases->method('deleteIndex')->willThrowException(new DatabaseException('undo failed'));

        try {
            $this->process(DATABASE_TYPE_CREATE_INDEX, $index->getArrayCopy(), [], $this->projectDatabase(), $dbForDatabases);
            $this->fail('A failed undo must propagate');
        } catch (DatabaseException $exception) {
            $this->assertSame('undo failed', $exception->getMessage());
        }

        $this->assertSame($recreated->getArrayCopy(), $this->stored[$index->getId()]->getArrayCopy());
        $this->assertSame([], $this->statusWrites);
        $this->assertSame([], $this->triggers);
    }

    public function testCreateIndexFailureForARecreatedIndexLeavesItAlone(): void
    {
        $dbForDatabases = $this->databases($this->libraryIndex('title_key', ['title']));
        $index = $this->store($this->indexDocument('title_key', 'processing'));
        $recreated = $this->indexDocument('title_key', 'available', sequence: '8');
        $this->replaceOnRead(2, $recreated);

        $this->process(DATABASE_TYPE_CREATE_INDEX, $index->getArrayCopy(), [], $this->projectDatabase(), $dbForDatabases);

        $this->assertSame(['title_key'], $this->libraryIndexKeys($dbForDatabases));
        $this->assertSame($recreated->getArrayCopy(), $this->stored[$index->getId()]->getArrayCopy());
        $this->assertSame([], $this->statusWrites);
        $this->assertSame([], $this->triggers);
    }

    public function testCreateIndexIsUndoneWhenTheIndexIsDeletedBeforeItsStatusIsWritten(): void
    {
        $dbForDatabases = $this->databases();
        $index = $this->store($this->indexDocument('title_key', 'processing'));
        $this->removeOnRead(3, $index->getId());
        $this->beforeUpdate = static function (): void {
            throw new \RuntimeException('connection lost');
        };

        $this->process(DATABASE_TYPE_CREATE_INDEX, $index->getArrayCopy(), [], $this->projectDatabase(), $dbForDatabases);

        $this->assertSame([], $this->libraryIndexKeys($dbForDatabases));
        $this->assertArrayNotHasKey($index->getId(), $this->stored);
        $this->assertSame([], $this->statusWrites);
        $this->assertSame([], $this->triggers);
    }

    public function testFailedCreateIndexForADeletedIndexLeavesTheExistingLibraryIndex(): void
    {
        $dbForDatabases = $this->databases($this->libraryIndex('title_key', ['title']));
        $index = $this->store($this->indexDocument('title_key', 'processing'));
        $this->removeOnRead(2, $index->getId());

        $this->process(DATABASE_TYPE_CREATE_INDEX, $index->getArrayCopy(), [], $this->projectDatabase(), $dbForDatabases);

        $this->assertSame(['title_key'], $this->libraryIndexKeys($dbForDatabases));
        $this->assertSame([], $this->statusWrites);
        $this->assertSame([], $this->triggers);
    }

    public function testUndoOfAnIndexAlreadyDroppedElsewhereSucceeds(): void
    {
        $index = $this->store($this->indexDocument('title_key', 'processing'));
        $recreated = $this->indexDocument('title_key', 'processing', sequence: '8');
        $this->replaceOnRead(2, $recreated);

        $dbForDatabases = $this->createStub(Database::class);
        $dbForDatabases->method('createIndex')->willReturnArgument(1);
        $dbForDatabases->method('deleteIndex')->willThrowException(new NotFound('Index not found'));

        $this->process(DATABASE_TYPE_CREATE_INDEX, $index->getArrayCopy(), [], $this->projectDatabase(), $dbForDatabases);

        $this->assertSame($recreated->getArrayCopy(), $this->stored[$index->getId()]->getArrayCopy());
        $this->assertSame([], $this->statusWrites);
        $this->assertSame([], $this->triggers);
    }

    public function testConcurrentCreateIndexDeliveryLeavesTheBuiltIndexAvailable(): void
    {
        $dbForDatabases = $this->databases($this->libraryIndex('title_key', ['title']));
        $index = $this->store($this->indexDocument('title_key', 'processing'));
        $built = $this->indexDocument('title_key', 'available');
        $this->replaceOnRead(2, $built);

        $this->process(DATABASE_TYPE_CREATE_INDEX, $index->getArrayCopy(), [], $this->projectDatabase(), $dbForDatabases);

        $this->assertSame(['title_key'], $this->libraryIndexKeys($dbForDatabases));
        $this->assertSame($built->getArrayCopy(), $this->stored[$index->getId()]->getArrayCopy());
        $this->assertSame([], $this->statusWrites);
        $this->assertSame([], $this->triggers);
    }

    public function testRedeliveredCreateIndexJobForAnAvailableIndexIsSkipped(): void
    {
        $dbForDatabases = $this->databases($this->libraryIndex('title_key', ['title']));
        $index = $this->store($this->indexDocument('title_key', 'available'));

        $this->process(DATABASE_TYPE_CREATE_INDEX, $index->getArrayCopy(), [], $this->projectDatabase(), $dbForDatabases);

        $this->assertSame([], $this->statusWrites);
        $this->assertSame('available', $this->stored[$index->getId()]->getAttribute('status'));
        $this->assertSame(['title_key'], $this->libraryIndexKeys($dbForDatabases));
        $this->assertSame([], $this->triggers);
    }

    private function databasesWithoutCollection(): Database
    {
        $authorization = new Authorization();
        $authorization->disable();

        $database = (new Database(new Memory(), new Cache(new MemoryCache())))
            ->setDatabase('appwrite')
            ->setNamespace('worker')
            ->setAuthorization($authorization);
        $database->create();

        return $database;
    }

    private function databases(Index ...$indexes): Database
    {
        $database = $this->databasesWithoutCollection();
        $database->createCollection(Collection::create(
            id: self::PHYSICAL_COLLECTION,
            attributes: [
                Attribute::string(key: 'title', size: 64),
                Attribute::string(key: 'note', size: 64),
            ],
        ));

        foreach ($indexes as $index) {
            $database->createIndex(self::PHYSICAL_COLLECTION, $index);
        }

        return $database;
    }

    /**
     * @param list<string> $attributes
     */
    private function libraryIndex(string $key, array $attributes): Index
    {
        return Index::fromArray([
            'key' => $key,
            'type' => IndexType::Key,
            'attributes' => $attributes,
            'lengths' => [],
            'orders' => [],
        ]);
    }

    /**
     * @return list<string>
     */
    private function libraryIndexKeys(Database $database): array
    {
        return \array_map(
            static fn (Index $index): string => $index->key,
            $database->getCollection(self::PHYSICAL_COLLECTION)->indexes(),
        );
    }

    /**
     * @param list<string> $attributes
     */
    private function indexDocument(string $key, string $status, array $attributes = ['title'], string $sequence = '7'): Document
    {
        return new Document([
            '$id' => '30360_1_' . $key,
            '$sequence' => $sequence,
            'key' => $key,
            'type' => IndexType::Key->value,
            'status' => $status,
            'attributes' => $attributes,
            'lengths' => [],
            'orders' => [],
        ]);
    }

    private function store(Document $index): Document
    {
        $this->stored[$index->getId()] = $index;

        return $index;
    }

    private function replaceOnRead(int $read, Document $replacement): void
    {
        $this->onRead($read, function () use ($replacement): void {
            $this->store($replacement);
        });
    }

    private function removeOnRead(int $read, string $id): void
    {
        $this->onRead($read, function () use ($id): void {
            unset($this->stored[$id]);
        });
    }

    private function onRead(int $read, \Closure $change): void
    {
        $reads = 0;
        $this->beforeRead = function () use (&$reads, $read, $change): void {
            if (++$reads === $read) {
                $change();
            }
        };
    }

    private function projectDatabase(): Database
    {
        $database = $this->createStub(Database::class);
        $database->method('getDocument')->willReturnCallback(
            function (string $collection, string $id, mixed ...$rest): Document {
                if ($collection !== 'indexes') {
                    return new Document();
                }
                if ($this->beforeRead !== null) {
                    ($this->beforeRead)();
                }

                return isset($this->stored[$id]) ? new Document($this->stored[$id]->getArrayCopy()) : new Document();
            }
        );
        $database->method('deleteDocument')->willReturnCallback(
            function (string $collection, string $id): bool {
                if ($collection !== 'indexes' || ! isset($this->stored[$id])) {
                    return false;
                }
                unset($this->stored[$id]);

                return true;
            }
        );
        $database->method('updateDocument')->willReturnCallback(
            function (string $collection, string $id, Document $update): Document {
                if ($this->beforeUpdate !== null) {
                    ($this->beforeUpdate)();
                }
                if ($update->isSet('status')) {
                    $this->statusWrites[] = $update->getAttribute('status');
                }
                if ($collection !== 'indexes' || ! isset($this->stored[$id])) {
                    return new Document();
                }
                $this->stored[$id] = new Document([...$this->stored[$id]->getArrayCopy(), ...$update->getArrayCopy()]);

                return $this->stored[$id];
            }
        );

        return $database;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $collection
     */
    private function process(string $type, array $document, array $collection, Database $dbForProject, Database $dbForDatabases): void
    {
        $dbForPlatform = $this->createStub(Database::class);
        $dbForPlatform->method('getDocument')->willReturn(new Document(['$id' => 'proj1']));

        $worker = new class ($this->recordTrigger(...)) extends Databases {
            public function __construct(private readonly \Closure $record)
            {
                parent::__construct();
            }

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
                ($this->record)($event, $index ?? $attribute);
            }
        };

        $message = new Message([
            'pid' => 'pid',
            'queue' => 'v1-databases',
            'timestamp' => \time(),
            'payload' => [
                'type' => $type,
                'database' => ['$id' => 'db1', '$sequence' => '30360'],
                'collection' => ['$id' => 'proof', '$sequence' => '1', ...$collection],
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

    private function recordTrigger(string $event, ?Document $payload): void
    {
        $this->triggers[] = ['event' => $event, 'payload' => $payload?->getArrayCopy()];
    }
}
