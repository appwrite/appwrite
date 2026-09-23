<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Http\Documents;

use Appwrite\Databases\TransactionState;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\XList;
use Appwrite\Usage\Context;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Method;
use Utopia\Query\Schema\ColumnType;

require_once __DIR__ . '/../../../../../../../app/init.php';
require_once __DIR__ . '/../../../../../../../src/Appwrite/Platform/Modules/Databases/Constants.php';

/**
 * A cursor only marks where the next page starts, so its document is looked up with authorization skipped,
 * whether or not the query joins another collection: a caller who may list a collection must be able to page
 * past a document it cannot read. The lookup never carries the request's selects, because the cursor needs
 * every order attribute, but it keeps the joins so an order on a joined attribute still has its value.
 */
final class CursorLookupTest extends TestCase
{
    private const string DATABASE_ID = 'blog';
    private const string CURSOR_ID = 'post2';

    private Authorization $authorization;

    /**
     * @var array<string, array<string, Document>>
     */
    private array $metadata;

    /**
     * @var list<array{authorized: bool, queries: array<Query>}>
     */
    private array $lookups = [];

    /**
     * @var list<array<Query>>
     */
    private array $searches = [];

    protected function setUp(): void
    {
        $this->authorization = new Authorization();
        $this->authorization->addRole(Role::user('reader')->toString());
        $this->authorization->addRole(Role::users()->toString());

        $this->metadata = [
            'databases' => [
                self::DATABASE_ID => new Document([
                    '$id' => self::DATABASE_ID,
                    '$sequence' => '1',
                    'type' => DATABASE_TYPE_LEGACY,
                    'enabled' => true,
                ]),
            ],
            'database_1' => [
                'posts' => new Document([
                    '$id' => 'posts',
                    '$sequence' => '1',
                    'enabled' => true,
                    'documentSecurity' => true,
                    'attributes' => [self::attribute('title'), self::attribute('authorId')],
                ]),
                'authors' => new Document([
                    '$id' => 'authors',
                    '$sequence' => '2',
                    '$permissions' => [Permission::read(Role::users())],
                    'enabled' => true,
                    'documentSecurity' => false,
                    'attributes' => [self::attribute('name')],
                ]),
            ],
        ];
    }

    public function testCursorWithJoinResolvesADocumentTheCallerCannotRead(): void
    {
        $this->list([
            Query::join('authors', 'authorId', '$id')->toString(),
            Query::orderAsc('title')->toString(),
            Query::cursorAfter(self::CURSOR_ID)->toString(),
        ]);

        $this->assertCount(1, $this->lookups);
        $this->assertFalse($this->lookups[0]['authorized'], 'the cursor lookup must skip authorization, as it does without a join');
        $this->assertSame(self::CURSOR_ID, $this->cursorValue()->getId(), 'the page must start after the cursor document');
    }

    public function testCursorWithJoinIsLookedUpWithoutSelects(): void
    {
        $this->list([
            Query::join('authors', 'authorId', '$id')->toString(),
            Query::select(['title'])->toString(),
            Query::orderAsc('title')->toString(),
            Query::cursorAfter(self::CURSOR_ID)->toString(),
        ], $this->documentsDatabase(cursorReadable: true));

        $methods = \array_map(static fn (Query $query): Method => $query->getMethod(), $this->lookups[0]['queries']);
        $this->assertNotContains(Method::Select, $methods, 'a select must not strip the order attributes from the cursor document');
        $this->assertSame([Method::Join], $methods, 'the joins stay so an order on a joined attribute has its value');
        $this->assertSame('database_1_collection_2', $this->lookups[0]['queries'][0]->getAttribute(), 'the join is resolved before the lookup');
    }

    public function testCursorWithoutJoinIsLookedUpWithoutQueries(): void
    {
        $this->list([
            Query::select(['title'])->toString(),
            Query::cursorAfter(self::CURSOR_ID)->toString(),
        ]);

        $this->assertCount(1, $this->lookups);
        $this->assertFalse($this->lookups[0]['authorized']);
        $this->assertSame([], $this->lookups[0]['queries']);
        $this->assertSame(self::CURSOR_ID, $this->cursorValue()->getId());
    }

    public function testRejectedCursorLookupIsAnInvalidQuery(): void
    {
        $documents = $this->createStub(Database::class);
        $documents->method('getDocument')->willThrowException(new QueryException('Invalid query: Join alias collides'));

        try {
            $this->list([
                Query::join('authors', 'authorId', '$id')->toString(),
                Query::cursorAfter(self::CURSOR_ID)->toString(),
            ], $documents);
        } catch (Exception $error) {
            $this->assertSame(Exception::GENERAL_QUERY_INVALID, $error->getType());
            $this->assertSame(400, $error->getCode());
            $this->assertSame('Invalid query: Join alias collides', $error->getMessage());

            return;
        }

        $this->fail('A query the library rejects while resolving the cursor must be a 400 ' . Exception::GENERAL_QUERY_INVALID);
    }

    /**
     * @param list<string> $queries
     */
    private function list(array $queries, ?Database $documents = null): void
    {
        (new XList())->action(
            databaseId: self::DATABASE_ID,
            collectionId: 'posts',
            queries: $queries,
            transactionId: null,
            includeTotal: false,
            ttl: 0,
            response: $this->createStub(Response::class),
            dbForProject: $this->projectDatabase(),
            user: new User(['$id' => 'reader']),
            getDatabasesDB: fn (): Database => $documents ?? $this->documentsDatabase(),
            usage: new Context(),
            transactionState: $this->createStub(TransactionState::class),
            authorization: $this->authorization,
        );
    }

    private function projectDatabase(): Database
    {
        $database = $this->createStub(Database::class);
        $database->method('getDocument')->willReturnCallback(
            fn (string $collection, string $id): Document => $this->metadata[$collection][$id] ?? new Document()
        );

        return $database;
    }

    /**
     * Unless the cursor is readable, the caller holds no read permission on the cursor document,
     * so only a lookup that skips authorization finds it.
     */
    private function documentsDatabase(bool $cursorReadable = false): Database
    {
        $database = $this->createStub(Database::class);
        $database->method('getDocument')->willReturnCallback(
            function (string $collection, string $id, array $queries = []) use ($cursorReadable): Document {
                $authorized = $this->authorization->getStatus();
                $this->lookups[] = ['authorized' => $authorized, 'queries' => $queries];

                return $authorized && !$cursorReadable ? new Document() : new Document(['$id' => $id, 'title' => 'Second']);
            }
        );
        $database->method('find')->willReturnCallback(function (string $collection, array $queries = []): array {
            $this->searches[] = $queries;

            return [];
        });
        $database->method('skipRelationships')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        return $database;
    }

    private function cursorValue(): Document
    {
        $this->assertCount(1, $this->searches, 'the page must be listed');
        $cursors = Query::getCursorQueries($this->searches[0], false);
        $this->assertCount(1, $cursors);
        $cursor = \reset($cursors)->getValue();
        $this->assertInstanceOf(Document::class, $cursor);

        return $cursor;
    }

    private static function attribute(string $key): Document
    {
        return new Document([
            '$id' => $key,
            'key' => $key,
            'type' => ColumnType::String->value,
            'size' => 128,
            'required' => false,
            'array' => false,
        ]);
    }
}
