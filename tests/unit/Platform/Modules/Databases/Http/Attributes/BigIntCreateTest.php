<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Http\Attributes;

use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Database as DatabasePublisher;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\BigInt\Create as AttributeCreate;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\BigInt\Create as ColumnCreate;
use Appwrite\Utopia\Database\Attribute as AttributeDefinition;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Adapter;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Schema\ColumnType;

require_once __DIR__ . '/../../../../../../../app/init.php';
require_once __DIR__ . '/../../../../../../../src/Appwrite/Platform/Modules/Databases/Constants.php';

/**
 * The dedicated bigint endpoint and the inline definition on create
 * collection/table persist one and the same type, so a row from either path is
 * found by the same type filter and accepted by the same update endpoint.
 */
final class BigIntCreateTest extends TestCase
{
    private const string DATABASE_ID = 'library';
    private const string COLLECTION_ID = 'books';
    private const string KEY = 'pages';

    /**
     * @return \Iterator<string, array{class-string<AttributeCreate>}>
     */
    public static function endpoints(): \Iterator
    {
        yield 'databases' => [AttributeCreate::class];
        yield 'tablesDB' => [ColumnCreate::class];
    }

    /**
     * @param class-string<AttributeCreate> $endpoint
     */
    #[DataProvider('endpoints')]
    public function testTheDedicatedEndpointPersistsTheSameTypeAsAnInlineDefinition(string $endpoint): void
    {
        $inline = AttributeDefinition::resolve(['key' => self::KEY, 'type' => ColumnType::BigInteger->value]);

        $this->assertSame('bigint', $inline['type']);
        $this->assertSame('bigint', $this->create(new $endpoint())->getAttribute('type'));
    }

    private function create(AttributeCreate $endpoint): Document
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
        ];

        $adapter = $this->createStub(Adapter::class);
        $adapter->method('hasFeature')->willReturn(true);
        $adapter->method('supports')->willReturn(true);

        $stored = null;
        $dbForProject = $this->createStub(Database::class);
        $dbForProject->method('getAdapter')->willReturn($adapter);
        $dbForProject->method('getDocument')->willReturnCallback(
            static fn (string $collection, string $id): Document => $documents[$collection . '/' . $id] ?? new Document()
        );
        $dbForProject->method('createDocument')->willReturnCallback(
            static function (string $collection, Document $document) use (&$stored): Document {
                $stored = $document;

                return $document;
            }
        );

        $queueForEvents = $this->createStub(Event::class);
        $queueForEvents->method('setContext')->willReturnSelf();
        $queueForEvents->method('setParam')->willReturnSelf();

        $authorization = $this->createStub(Authorization::class);
        $authorization->method('skip')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        $response = $this->createStub(Response::class);
        $response->method('setStatusCode')->willReturnSelf();

        $endpoint->action(
            databaseId: self::DATABASE_ID,
            collectionId: self::COLLECTION_ID,
            key: self::KEY,
            required: false,
            min: 10,
            max: 5000,
            default: null,
            array: false,
            response: $response,
            dbForProject: $dbForProject,
            publisherForDatabase: $this->createStub(DatabasePublisher::class),
            queueForEvents: $queueForEvents,
            authorization: $authorization,
        );

        $this->assertInstanceOf(Document::class, $stored);

        return $stored;
    }
}
