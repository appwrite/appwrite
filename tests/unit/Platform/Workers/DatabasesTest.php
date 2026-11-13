<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Realtime;
use Appwrite\Platform\Modules\Databases\Workers\Databases;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Relationship;
use Utopia\Database\RelationshipDeleteAction;
use Utopia\Database\RelationshipSide;
use Utopia\Database\RelationshipType;
use Utopia\Query\Schema\ColumnType;
use Utopia\Queue\Message;

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
            'status' => 'processing',
            'size' => 64,
            'required' => false,
            'default' => null,
        ]);

        $dbForProject = $this->createMock(Database::class);
        $dbForProject->method('getDocument')->willReturnCallback(
            fn (string $collection, string $id) => $collection === 'attributes' ? $attribute : new Document()
        );
        $dbForProject->method('updateDocument')->willReturnArgument(2);
        $dbForProject->expects($this->never())->method('createAttribute');

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
            'status' => 'processing',
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

    public function testCreateRelationshipRedeliveryMarksTheExistingRelationshipAvailable(): void
    {
        $updates = [];

        $this->processRelationshipCreation(
            $this->duplicateBacking('Attribute already exists', $this->existingRelationship('author', RelationshipDeleteAction::SetNull)),
            $updates,
        );

        \ksort($updates);
        $this->assertSame([
            'attributes/30360_2_books' => ['status' => 'available'],
            'attributes/attr1' => ['status' => 'available'],
        ], $updates);
    }

    public function testCreateRelationshipConflictMarksBothSidesFailed(): void
    {
        $updates = [];

        try {
            $this->processRelationshipCreation(
                $this->duplicateBacking('Related attribute already exists', $this->existingRelationship('writer', RelationshipDeleteAction::SetNull)),
                $updates,
            );
            $this->fail('A conflicting relationship must propagate');
        } catch (DuplicateException $exception) {
            $this->assertSame('Related attribute already exists', $exception->getMessage());
        }

        \ksort($updates);
        $this->assertSame([
            'attributes/30360_2_books' => ['status' => 'failed', 'error' => 'Related attribute already exists'],
            'attributes/attr1' => ['status' => 'failed', 'error' => 'Related attribute already exists'],
        ], $updates);
    }

    public function testCreateRelationshipRedeliveryWithDifferentOptionsMarksFailed(): void
    {
        $updates = [];

        try {
            $this->processRelationshipCreation(
                $this->duplicateBacking('Attribute already exists', $this->existingRelationship('author', RelationshipDeleteAction::Cascade)),
                $updates,
            );
            $this->fail('A relationship with different options must propagate');
        } catch (DuplicateException $exception) {
            $this->assertSame('Attribute already exists', $exception->getMessage());
        }

        \ksort($updates);
        $this->assertSame([
            'attributes/30360_2_books' => ['status' => 'failed', 'error' => 'Attribute already exists'],
            'attributes/attr1' => ['status' => 'failed', 'error' => 'Attribute already exists'],
        ], $updates);
    }

    public function testCreateRelationshipDuplicateOfChildSideMarksBothSidesFailed(): void
    {
        $updates = [];

        try {
            $this->processRelationshipCreation(
                $this->duplicateBacking('Attribute already exists', $this->existingRelationship('author', RelationshipDeleteAction::SetNull, RelationshipSide::Child)),
                $updates,
            );
            $this->fail('A duplicate of the child side must propagate');
        } catch (DuplicateException $exception) {
            $this->assertSame('Attribute already exists', $exception->getMessage());
        }

        \ksort($updates);
        $this->assertSame([
            'attributes/30360_2_books' => ['status' => 'failed', 'error' => 'Attribute already exists'],
            'attributes/attr1' => ['status' => 'failed', 'error' => 'Attribute already exists'],
        ], $updates);
    }

    public function testCreateOneWayRelationshipRedeliveryLeavesTheTwinUntouched(): void
    {
        $updates = [];
        $fetched = [];

        $this->processRelationshipCreation(
            $this->duplicateBacking('Attribute already exists', $this->existingRelationship('author', RelationshipDeleteAction::SetNull, twoWay: false)),
            $updates,
            $fetched,
            twoWay: false,
        );

        $this->assertNotContains('attributes/30360_2_books', $fetched);
        $this->assertSame(['attributes/attr1' => ['status' => 'available']], $updates);
    }

    #[DataProvider('staleAttributes')]
    public function testCreateAttributeSkipsAttributeThatIsNoLongerProcessing(?Document $stored): void
    {
        $dbForProject = $this->createMock(Database::class);
        $dbForProject->method('getDocument')->willReturnCallback(
            static fn (string $collection, string $id): Document => match ([$collection, $id]) {
                ['attributes', 'attr1'] => $stored ?? new Document(),
                ['attributes', '30360_2_books'] => new Document(['$id' => '30360_2_books', 'key' => 'books', 'status' => 'processing']),
                ['database_30360', 'authors'] => new Document(['$id' => 'authors', '$sequence' => '2']),
                default => new Document(),
            }
        );
        $dbForProject->expects($this->never())->method('updateDocument');

        $dbForDatabases = $this->createMock(Database::class);
        $dbForDatabases->expects($this->never())->method('createRelationship');
        $dbForDatabases->expects($this->never())->method('createAttribute');

        $this->process(DATABASE_TYPE_CREATE_ATTRIBUTE, ['$id' => 'attr1'], [], $dbForProject, $dbForDatabases);
    }

    /**
     * @return \Iterator<string, array{?Document}>
     */
    public static function staleAttributes(): \Iterator
    {
        $column = new Document([
            '$id' => 'attr1',
            'key' => 'note',
            'type' => ColumnType::String->value,
            'size' => 64,
        ]);
        yield 'relationship available' => [self::relationshipRow('available', true)];
        yield 'relationship deleting' => [self::relationshipRow('deleting', true)];
        yield 'relationship stuck' => [self::relationshipRow('stuck', true)];
        yield 'column available' => [(clone $column)->setAttribute('status', 'available')];
        yield 'column deleting' => [(clone $column)->setAttribute('status', 'deleting')];
        yield 'deleted row' => [null];
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

    private function existingRelationship(
        string $key,
        RelationshipDeleteAction $onDelete,
        RelationshipSide $side = RelationshipSide::Parent,
        bool $twoWay = true,
    ): Attribute {
        return Attribute::relationship($key, Relationship::fromArray([
            'relatedCollection' => 'database_30360_collection_2',
            'relationType' => RelationshipType::ManyToOne->value,
            'twoWay' => $twoWay,
            'key' => $key,
            'twoWayKey' => 'books',
            'onDelete' => $onDelete->value,
        ]), $side);
    }

    private function duplicateBacking(string $message, Attribute $existing): Database
    {
        $dbForDatabases = $this->createStub(Database::class);
        $dbForDatabases->method('createRelationship')->willThrowException(new DuplicateException($message));
        $dbForDatabases->method('findCollection')->willReturnCallback(
            static fn (string $id): ?Collection => $id === self::PHYSICAL_COLLECTION
                ? Collection::create(self::PHYSICAL_COLLECTION, attributes: [$existing])
                : null
        );

        return $dbForDatabases;
    }

    private static function relationshipRow(string $status, bool $twoWay): Document
    {
        return new Document([
            '$id' => 'attr1',
            'key' => 'author',
            'type' => ColumnType::Relationship->value,
            'status' => $status,
            'options' => [
                'relatedCollection' => 'authors',
                'relationType' => RelationshipType::ManyToOne->value,
                'twoWay' => $twoWay,
                'twoWayKey' => 'books',
                'onDelete' => RelationshipDeleteAction::SetNull->value,
            ],
        ]);
    }

    /**
     * @param array<string, array<string, mixed>> $updates
     * @param list<string> $fetched
     */
    private function processRelationshipCreation(Database $dbForDatabases, array &$updates, array &$fetched = [], bool $twoWay = true): void
    {
        $attribute = self::relationshipRow('processing', $twoWay);
        $relatedAttribute = new Document(['$id' => '30360_2_books', 'key' => 'books', 'status' => 'processing']);

        $dbForProject = $this->createStub(Database::class);
        $dbForProject->method('getDocument')->willReturnCallback(
            static function (string $collection, string $id) use ($attribute, $relatedAttribute, &$fetched): Document {
                $fetched[] = $collection . '/' . $id;

                return match ([$collection, $id]) {
                    ['attributes', 'attr1'] => $attribute,
                    ['attributes', '30360_2_books'] => $relatedAttribute,
                    ['database_30360', 'authors'] => new Document(['$id' => 'authors', '$sequence' => '2']),
                    default => new Document(),
                };
            }
        );
        $dbForProject->method('updateDocument')->willReturnCallback(
            static function (string $collection, string $id, Document $update) use (&$updates): Document {
                $updates[$collection . '/' . $id] = $update->getArrayCopy();

                return $update;
            }
        );

        $this->process(DATABASE_TYPE_CREATE_ATTRIBUTE, ['$id' => 'attr1'], [], $dbForProject, $dbForDatabases);
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $collection
     */
    private function process(string $type, array $document, array $collection, Database $dbForProject, Database $dbForDatabases): void
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
}
