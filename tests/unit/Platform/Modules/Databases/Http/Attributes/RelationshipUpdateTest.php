<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Http\Attributes;

use Appwrite\Event\Event;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Relationship\Update as AttributeUpdate;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Relationship\Update as ColumnUpdate;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Adapter;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\RelationSide;
use Utopia\Database\RelationType;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Schema\ColumnType;
use Utopia\Query\Schema\ForeignKeyAction;

require_once __DIR__ . '/../../../../../../../app/init.php';
require_once __DIR__ . '/../../../../../../../src/Appwrite/Platform/Modules/Databases/Constants.php';

/**
 * onDelete is optional on both relationship update endpoints, and leaving it
 * out means "keep the stored action", as it did before the enum migration.
 */
final class RelationshipUpdateTest extends TestCase
{
    private const string DATABASE_ID = 'library';
    private const string BOOKS_ID = 'books';
    private const string AUTHORS_ID = 'authors';
    private const string AUTHOR_ATTRIBUTE_ID = '1_1_author';
    private const string BOOK_ATTRIBUTE_ID = '1_2_book';

    /**
     * @var list<array{newKey: ?string, onDelete: ?ForeignKeyAction}>
     */
    private array $relationshipUpdates = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $storedOptions = [];

    /**
     * @return \Iterator<string, array{class-string<AttributeUpdate>}>
     */
    public static function endpoints(): \Iterator
    {
        yield 'databases' => [AttributeUpdate::class];
        yield 'tablesDB' => [ColumnUpdate::class];
    }

    /**
     * @param class-string<AttributeUpdate> $endpoint
     */
    #[DataProvider('endpoints')]
    public function testRenameWithoutOnDeleteKeepsTheStoredAction(string $endpoint): void
    {
        $output = $this->update(new $endpoint(), onDelete: null, newKey: 'writer');

        $this->assertSame([['newKey' => 'writer', 'onDelete' => null]], $this->relationshipUpdates);
        $this->assertSame(ForeignKeyAction::Cascade->value, $this->storedOptions[self::AUTHOR_ATTRIBUTE_ID]['onDelete']);
        $this->assertSame(ForeignKeyAction::Cascade->value, $this->storedOptions[self::BOOK_ATTRIBUTE_ID]['onDelete']);
        $this->assertSame('writer', $this->storedOptions[self::BOOK_ATTRIBUTE_ID]['twoWayKey']);
        $this->assertSame('writer', $output->getAttribute('key'));
        $this->assertSame(ForeignKeyAction::Cascade->value, $output->getAttribute('onDelete'));
    }

    /**
     * @param class-string<AttributeUpdate> $endpoint
     */
    #[DataProvider('endpoints')]
    public function testEmptyUpdateLeavesTheRelationshipUnchanged(string $endpoint): void
    {
        $output = $this->update(new $endpoint(), onDelete: null, newKey: null);

        $this->assertSame([['newKey' => null, 'onDelete' => null]], $this->relationshipUpdates);
        $this->assertSame(self::options(self::AUTHORS_ID, 'book', RelationSide::Parent), $this->storedOptions[self::AUTHOR_ATTRIBUTE_ID]);
        $this->assertSame(self::options(self::BOOKS_ID, 'author', RelationSide::Child), $this->storedOptions[self::BOOK_ATTRIBUTE_ID]);
        $this->assertSame(ForeignKeyAction::Cascade->value, $output->getAttribute('onDelete'));
    }

    /**
     * @param class-string<AttributeUpdate> $endpoint
     */
    #[DataProvider('endpoints')]
    public function testOnDeleteIsAppliedToBothSides(string $endpoint): void
    {
        $output = $this->update(new $endpoint(), onDelete: ForeignKeyAction::SetNull->value, newKey: null);

        $this->assertSame([['newKey' => null, 'onDelete' => ForeignKeyAction::SetNull]], $this->relationshipUpdates);
        $this->assertSame(ForeignKeyAction::SetNull->value, $this->storedOptions[self::AUTHOR_ATTRIBUTE_ID]['onDelete']);
        $this->assertSame(ForeignKeyAction::SetNull->value, $this->storedOptions[self::BOOK_ATTRIBUTE_ID]['onDelete']);
        $this->assertSame(ForeignKeyAction::SetNull->value, $output->getAttribute('onDelete'));
    }

    private function update(AttributeUpdate $endpoint, ?string $onDelete, ?string $newKey): Document
    {
        $documents = [
            'databases/' . self::DATABASE_ID => new Document([
                '$id' => self::DATABASE_ID,
                '$sequence' => '1',
                'type' => DATABASE_TYPE_LEGACY,
            ]),
            'database_1/' . self::BOOKS_ID => new Document([
                '$id' => self::BOOKS_ID,
                '$sequence' => '1',
                'indexes' => [],
            ]),
            'database_1/' . self::AUTHORS_ID => new Document([
                '$id' => self::AUTHORS_ID,
                '$sequence' => '2',
                'indexes' => [],
            ]),
            'attributes/' . self::AUTHOR_ATTRIBUTE_ID => self::relationship(
                self::AUTHOR_ATTRIBUTE_ID,
                'author',
                self::options(self::AUTHORS_ID, 'book', RelationSide::Parent),
            ),
            'attributes/' . self::BOOK_ATTRIBUTE_ID => self::relationship(
                self::BOOK_ATTRIBUTE_ID,
                'book',
                self::options(self::BOOKS_ID, 'author', RelationSide::Child),
            ),
        ];

        $adapter = $this->createStub(Adapter::class);
        $adapter->method('hasFeature')->willReturn(true);

        $dbForProject = $this->createStub(Database::class);
        $dbForProject->method('getAdapter')->willReturn($adapter);
        $dbForProject->method('getDocument')->willReturnCallback(
            static fn (string $collection, string $id): Document => $documents[$collection . '/' . $id] ?? new Document()
        );
        $dbForProject->method('updateRelationship')->willReturnCallback(
            function (string $collection, string $id, ?string $newKey = null, ?string $newTwoWayKey = null, ?bool $twoWay = null, ?ForeignKeyAction $onDelete = null): bool {
                $this->relationshipUpdates[] = ['newKey' => $newKey, 'onDelete' => $onDelete];

                return true;
            }
        );
        $dbForProject->method('updateDocument')->willReturnCallback(
            function (string $collection, string $id, Document $document): Document {
                $this->storedOptions[$id] = $document->getAttribute('options');

                return $document;
            }
        );

        $queueForEvents = $this->createStub(Event::class);
        $queueForEvents->method('setContext')->willReturnSelf();
        $queueForEvents->method('setParam')->willReturnSelf();

        $authorization = $this->createStub(Authorization::class);
        $authorization->method('skip')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        $output = null;
        $response = $this->createStub(Response::class);
        $response->method('setStatusCode')->willReturnSelf();
        $response->method('dynamic')->willReturnCallback(static function (Document $document) use (&$output): void {
            $output = $document;
        });

        $endpoint->action(
            databaseId: self::DATABASE_ID,
            collectionId: self::BOOKS_ID,
            key: 'author',
            onDelete: $onDelete,
            newKey: $newKey,
            response: $response,
            dbForProject: $dbForProject,
            queueForEvents: $queueForEvents,
            authorization: $authorization,
        );

        $this->assertInstanceOf(Document::class, $output);

        return $output;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function relationship(string $id, string $key, array $options): Document
    {
        return new Document([
            '$id' => $id,
            'key' => $key,
            'type' => ColumnType::Relationship->value,
            'status' => 'available',
            'required' => false,
            'array' => false,
            'options' => $options,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function options(string $relatedCollection, string $twoWayKey, RelationSide $side): array
    {
        return [
            'relatedCollection' => $relatedCollection,
            'relationType' => RelationType::OneToOne->value,
            'twoWay' => true,
            'twoWayKey' => $twoWayKey,
            'onDelete' => ForeignKeyAction::Cascade->value,
            'side' => $side->value,
        ];
    }
}
