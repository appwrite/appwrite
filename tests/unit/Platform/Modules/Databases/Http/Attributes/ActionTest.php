<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Http\Attributes;

use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Database as DatabasePublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Action;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Relationship\Create as CollectionsCreate;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Relationship\Create as TablesCreate;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Throwable;
use Utopia\Database\Adapter;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\RelationshipDeleteAction;
use Utopia\Database\RelationshipType;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Schema\ColumnType;

require_once __DIR__ . '/../../../../../../../app/init.php';
require_once __DIR__ . '/../../../../../../../src/Appwrite/Platform/Modules/Databases/Constants.php';

final class ActionTest extends TestCase
{
    private const string DATABASE_ID = 'library';
    private const string DATABASE_SEQUENCE = '1';
    private const string COLLECTION_ID = 'books';
    private const string COLLECTION_SEQUENCE = '1';
    private const string RELATED_COLLECTION_ID = 'authors';
    private const string RELATED_COLLECTION_SEQUENCE = '2';
    private const string KEY = 'author';
    private const string TWO_WAY_KEY = 'books';
    private const string PARENT_ROW_ID = self::DATABASE_SEQUENCE . '_' . self::COLLECTION_SEQUENCE . '_' . self::KEY;
    private const string TWIN_ROW_ID = self::DATABASE_SEQUENCE . '_' . self::RELATED_COLLECTION_SEQUENCE . '_' . self::TWO_WAY_KEY;

    /**
     * @return iterable<string, array{class-string<Action>, Throwable, string}>
     */
    public static function twinRowFailures(): iterable
    {
        $failures = [
            'duplicate' => [
                new DuplicateException('Document already exists'),
                Exception::ATTRIBUTE_ALREADY_EXISTS,
                Exception::COLUMN_ALREADY_EXISTS,
            ],
            'limit' => [
                new LimitException('Column limit reached. Cannot create new attribute.'),
                Exception::ATTRIBUTE_LIMIT_EXCEEDED,
                Exception::COLUMN_LIMIT_EXCEEDED,
            ],
            'structure' => [
                new StructureException('Invalid document structure'),
                Exception::DOCUMENT_INVALID_STRUCTURE,
                Exception::ROW_INVALID_STRUCTURE,
            ],
        ];

        foreach ($failures as $name => [$failure, $collectionsType, $tablesType]) {
            yield "collections {$name}" => [CollectionsCreate::class, $failure, $collectionsType];
            yield "tables {$name}" => [TablesCreate::class, $failure, $tablesType];
        }
    }

    /**
     * @param class-string<Action> $actionClass
     */
    #[DataProvider('twinRowFailures')]
    public function testTwinRowFailureDeletesParentRow(string $actionClass, Throwable $failure, string $expectedType): void
    {
        $dbForProject = $this->projectDatabase($failure);
        $dbForProject->expects($this->once())
            ->method('deleteDocument')
            ->with('attributes', self::PARENT_ROW_ID)
            ->willReturn(true);

        $thrown = $this->createTwoWayRelationship(new $actionClass(), $dbForProject, $this->databasesDatabase($failure));

        $this->assertInstanceOf(Exception::class, $thrown);
        $this->assertSame($expectedType, $thrown->getType());
    }

    /**
     * @param class-string<Action> $actionClass
     */
    #[DataProvider('twinRowFailures')]
    public function testTwinRowFailureSurvivesParentRowDeletionFailure(string $actionClass, Throwable $failure, string $expectedType): void
    {
        $dbForProject = $this->projectDatabase($failure);
        $dbForProject->expects($this->once())
            ->method('deleteDocument')
            ->with('attributes', self::PARENT_ROW_ID)
            ->willThrowException(new DatabaseException('Connection lost'));

        $thrown = $this->createTwoWayRelationship(new $actionClass(), $dbForProject, $this->databasesDatabase($failure));

        $this->assertInstanceOf(Exception::class, $thrown, 'a failed cleanup must not replace the mapped error');
        $this->assertSame($expectedType, $thrown->getType());
    }

    /**
     * @return iterable<string, array{class-string<CollectionsCreate>}>
     */
    public static function relationshipActions(): iterable
    {
        yield 'collections' => [CollectionsCreate::class];
        yield 'tables' => [TablesCreate::class];
    }

    /**
     * @param class-string<CollectionsCreate> $actionClass
     */
    #[DataProvider('relationshipActions')]
    public function testParentRowStoresCanonicalRelatedCollectionId(string $actionClass): void
    {
        $stored = [];
        $dbForProject = $this->caseInsensitiveProjectDatabase($stored);
        $authorization = $this->createStub(Authorization::class);
        $authorization->method('skip')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        (new $actionClass())->action(
            self::DATABASE_ID,
            self::COLLECTION_ID,
            \strtoupper(self::RELATED_COLLECTION_ID),
            RelationshipType::OneToMany->value,
            false,
            self::KEY,
            self::TWO_WAY_KEY,
            RelationshipDeleteAction::Restrict->value,
            '',
            $this->createStub(Response::class),
            $dbForProject,
            fn (Document $database): Database => $this->relationshipDatabasesDatabase(),
            $this->createStub(DatabasePublisher::class),
            $this->createStub(Event::class),
            $authorization,
        );

        $this->assertArrayHasKey(self::PARENT_ROW_ID, $stored);
        $this->assertSame(
            self::RELATED_COLLECTION_ID,
            $stored[self::PARENT_ROW_ID]->getAttribute('options')['relatedCollection'],
            'the parent row must store the resolved id so later conflict checks match it exactly',
        );
    }

    private function createTwoWayRelationship(Action $action, Database $dbForProject, Database $dbForDatabases): ?Throwable
    {
        $authorization = $this->createStub(Authorization::class);
        $authorization->method('skip')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        $attribute = new Document([
            'key' => self::KEY,
            'type' => ColumnType::Relationship->value,
            'size' => 0,
            'required' => false,
            'default' => null,
            'array' => false,
            'filters' => [],
            'options' => [
                'relatedCollection' => self::RELATED_COLLECTION_ID,
                'relationType' => RelationshipType::OneToMany->value,
                'twoWay' => true,
                'twoWayKey' => self::TWO_WAY_KEY,
                'onDelete' => RelationshipDeleteAction::Restrict->value,
            ],
        ]);

        $createAttribute = \Closure::bind(
            fn (mixed ...$arguments): Document => $this->createAttribute(...$arguments),
            $action,
            $action::class,
        );

        try {
            $createAttribute(
                self::DATABASE_ID,
                self::COLLECTION_ID,
                $attribute,
                $this->createStub(Response::class),
                $dbForProject,
                static fn (Document $database): Database => $dbForDatabases,
                $this->createStub(DatabasePublisher::class),
                $this->createStub(Event::class),
                $authorization,
            );
        } catch (Throwable $thrown) {
            return $thrown;
        }

        return null;
    }

    private function projectDatabase(Throwable $failure): Database&MockObject
    {
        $database = new Document([
            '$id' => self::DATABASE_ID,
            '$sequence' => self::DATABASE_SEQUENCE,
            'type' => DATABASE_TYPE_LEGACY,
        ]);
        $collection = new Document([
            '$id' => self::COLLECTION_ID,
            '$sequence' => self::COLLECTION_SEQUENCE,
        ]);
        $relatedCollection = new Document([
            '$id' => self::RELATED_COLLECTION_ID,
            '$sequence' => self::RELATED_COLLECTION_SEQUENCE,
        ]);

        $dbForProject = $this->createMock(Database::class);
        $dbForProject->method('getDocument')->willReturnCallback(
            static fn (string $collectionId, string $id): Document => match ([$collectionId, $id]) {
                ['databases', self::DATABASE_ID] => $database,
                ['database_' . self::DATABASE_SEQUENCE, self::COLLECTION_ID] => $collection,
                ['database_' . self::DATABASE_SEQUENCE, self::RELATED_COLLECTION_ID] => $relatedCollection,
                default => new Document(),
            }
        );
        $dbForProject->method('createDocument')->willReturnCallback(
            static fn (string $collectionId, Document $document): Document => match (true) {
                $document->getId() === self::TWIN_ROW_ID && !$failure instanceof LimitException => throw $failure,
                default => $document,
            }
        );

        return $dbForProject;
    }

    /**
     * @param array<string, Document> $stored
     */
    private function caseInsensitiveProjectDatabase(array &$stored): Database
    {
        $documents = [
            'databases/' . self::DATABASE_ID => new Document([
                '$id' => self::DATABASE_ID,
                '$sequence' => self::DATABASE_SEQUENCE,
                'type' => DATABASE_TYPE_LEGACY,
            ]),
            'database_' . self::DATABASE_SEQUENCE . '/' . self::COLLECTION_ID => new Document([
                '$id' => self::COLLECTION_ID,
                '$sequence' => self::COLLECTION_SEQUENCE,
            ]),
            'database_' . self::DATABASE_SEQUENCE . '/' . self::RELATED_COLLECTION_ID => new Document([
                '$id' => self::RELATED_COLLECTION_ID,
                '$sequence' => self::RELATED_COLLECTION_SEQUENCE,
            ]),
        ];

        $dbForProject = $this->createStub(Database::class);
        $dbForProject->method('getDocument')->willReturnCallback(
            static fn (string $collectionId, string $id): Document => $documents[$collectionId . '/' . \strtolower($id)] ?? new Document()
        );
        $dbForProject->method('createDocument')->willReturnCallback(
            static function (string $collectionId, Document $document) use (&$stored): Document {
                $stored[$document->getId()] = $document;

                return $document;
            }
        );

        return $dbForProject;
    }

    private function relationshipDatabasesDatabase(): Database
    {
        $adapter = $this->createStub(Adapter::class);
        $adapter->method('hasFeature')->willReturn(true);

        $dbForDatabases = $this->createStub(Database::class);
        $dbForDatabases->method('getAdapter')->willReturn($adapter);
        $dbForDatabases->method('checkAttribute')->willReturn(true);
        $dbForDatabases->method('findCollection')->willReturnCallback(
            static fn (string $collection): Collection => Collection::fromArray(['$id' => $collection])
        );

        return $dbForDatabases;
    }

    private function databasesDatabase(Throwable $failure): Database
    {
        $relatedCollection = 'database_' . self::DATABASE_SEQUENCE . '_collection_' . self::RELATED_COLLECTION_SEQUENCE;

        $dbForDatabases = $this->createStub(Database::class);
        $dbForDatabases->method('checkAttribute')->willReturnCallback(
            static fn (string $collection, Attribute $attribute): bool => match (true) {
                $collection === $relatedCollection && $failure instanceof LimitException => throw $failure,
                default => true,
            }
        );

        return $dbForDatabases;
    }
}
