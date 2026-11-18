<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Realtime;
use Appwrite\Platform\Modules\Databases\Workers\Databases;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Attribute;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Relationship;
use Utopia\Database\RelationshipDeleteAction;
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
