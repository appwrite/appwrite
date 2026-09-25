<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Http\Attributes;

use Appwrite\Event\Event;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\BigInt\Update as AttributeUpdate;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\BigInt\Update as ColumnUpdate;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Adapter;
use Utopia\Database\Attribute;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Schema\ColumnType;

require_once __DIR__ . '/../../../../../../../app/init.php';
require_once __DIR__ . '/../../../../../../../src/Appwrite/Platform/Modules/Databases/Constants.php';

/**
 * A bigint is updatable whatever spelling its stored type carries: the
 * persisted one every creation path writes, and the enum one rows created
 * before the spelling was unified still hold.
 */
final class BigIntUpdateTest extends TestCase
{
    private const string DATABASE_ID = 'library';
    private const string COLLECTION_ID = 'books';
    private const string KEY = 'pages';
    private const string ATTRIBUTE_ID = '1_1_pages';

    /**
     * @return \Iterator<string, array{class-string<AttributeUpdate>, string}>
     */
    public static function endpoints(): \Iterator
    {
        $persisted = Attribute::persistedType(ColumnType::BigInteger);

        yield 'databases, persisted spelling' => [AttributeUpdate::class, $persisted];
        yield 'databases, enum spelling' => [AttributeUpdate::class, ColumnType::BigInteger->value];
        yield 'tablesDB, persisted spelling' => [ColumnUpdate::class, $persisted];
        yield 'tablesDB, enum spelling' => [ColumnUpdate::class, ColumnType::BigInteger->value];
    }

    /**
     * @param class-string<AttributeUpdate> $endpoint
     */
    #[DataProvider('endpoints')]
    public function testEveryStoredSpellingIsUpdatable(string $endpoint, string $storedType): void
    {
        $output = $this->update(new $endpoint(), $storedType);

        $this->assertSame($storedType, $output->getAttribute('type'));
        $this->assertSame(10, $output->getAttribute('min'));
        $this->assertSame(5000, $output->getAttribute('max'));
    }

    private function update(AttributeUpdate $endpoint, string $storedType): Document
    {
        $documents = [
            'databases/' . self::DATABASE_ID => new Document([
                '$id' => self::DATABASE_ID,
                '$sequence' => '1',
                'type' => DATABASE_TYPE_LEGACY,
            ]),
            'database_1/' . self::COLLECTION_ID => new Document([
                '$id' => self::COLLECTION_ID,
                '$sequence' => '1',
                'indexes' => [],
            ]),
            'attributes/' . self::ATTRIBUTE_ID => new Document([
                '$id' => self::ATTRIBUTE_ID,
                'key' => self::KEY,
                'type' => $storedType,
                'status' => 'available',
                'size' => 8,
                'required' => false,
                'array' => false,
                'format' => APP_DATABASE_ATTRIBUTE_BIGINT_RANGE,
                'formatOptions' => ['min' => \PHP_INT_MIN, 'max' => \PHP_INT_MAX],
            ]),
        ];

        $adapter = $this->createStub(Adapter::class);
        $adapter->method('hasFeature')->willReturn(true);

        $dbForProject = $this->createStub(Database::class);
        $dbForProject->method('getAdapter')->willReturn($adapter);
        $dbForProject->method('getDocument')->willReturnCallback(
            static fn (string $collection, string $id): Document => $documents[$collection . '/' . $id] ?? new Document()
        );
        $dbForProject->method('updateAttribute')->willReturn(new Document([
            '$id' => self::KEY,
            'default' => null,
        ]));
        $dbForProject->method('updateDocument')->willReturnCallback(
            static fn (string $collection, string $id, Document $document): Document => $document
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
            collectionId: self::COLLECTION_ID,
            key: self::KEY,
            required: false,
            min: 10,
            max: 5000,
            default: null,
            newKey: null,
            response: $response,
            dbForProject: $dbForProject,
            queueForEvents: $queueForEvents,
            authorization: $authorization,
        );

        $this->assertInstanceOf(Document::class, $output);

        return $output;
    }
}
