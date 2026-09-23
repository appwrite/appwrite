<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Http\Documents;

use Appwrite\Databases\TransactionState;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\XList;
use Appwrite\Usage\Context;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use PDO;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Hook\Permissions;
use Utopia\Database\PermissionType;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Schema\ColumnType;

require_once __DIR__ . '/../../../../../../../app/init.php';
require_once __DIR__ . '/../../../../../../../src/Appwrite/Platform/Modules/Databases/Constants.php';

/**
 * A cursor only marks where the next page starts, so its document is looked up with authorization skipped,
 * whether or not the query joins another collection: a caller who may list a collection must be able to page
 * past a document it cannot read. The lookup never carries the request's selects or joins. An order on a joined
 * attribute takes its value from the joined rows the caller can read, read as listing that collection directly
 * reads them, so a page boundary never depends on a row the list itself hides.
 */
final class CursorLookupTest extends TestCase
{
    private const string DATABASE_ID = 'blog';
    private const string CURSOR_ID = 'post2';
    private const string CUSTOMERS = 'database_1_collection_3';
    private const string ORDERS = 'database_1_collection_4';
    private const string PRODUCTS = 'database_1_collection_5';

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

    /**
     * @var list<array{collection: string, authorized: bool}>
     */
    private array $reads = [];

    /**
     * @var list<Document>
     */
    private array $cursors = [];

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
                'customers' => self::perDocument('customers', '3'),
                'orders' => self::perDocument('orders', '4'),
                'products' => self::perDocument('products', '5'),
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

    public function testCursorWithJoinIsLookedUpWithoutSelectsOrJoins(): void
    {
        $this->list([
            Query::join('authors', 'authorId', '$id')->toString(),
            Query::select(['title'])->toString(),
            Query::orderAsc('title')->toString(),
            Query::cursorAfter(self::CURSOR_ID)->toString(),
        ], $this->documentsDatabase(cursorReadable: true));

        $this->assertCount(1, $this->lookups);
        $this->assertSame([], $this->lookups[0]['queries'], 'a select must not strip the order attributes from the cursor document, and a join must not read joined rows with authorization skipped');
        $this->assertSame(self::CURSOR_ID, $this->cursorValue()->getId());
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

    public function testCursorWithJoinOrderTakesItsValueFromAJoinedRowTheCallerCanRead(): void
    {
        $store = $this->store();
        $this->customer($store, 'alice', readable: true);
        $this->order($store, 'a-hidden', 'alice', 35, readable: false);
        $this->order($store, 'b-visible', 'alice', 10, readable: true);

        $cursor = $this->page($store, [Query::orderAsc('ord.amount')], Query::cursorAfter('alice'));

        $this->assertSame('alice', $cursor->getId());
        $this->assertSame(10, $this->orderValue($cursor, 'ord.amount'), 'the page boundary must come from the order the caller can read, not the one the list hides');
    }

    public function testCursorWithJoinOrderResolvesJoinedValuesOfADocumentTheCallerCannotRead(): void
    {
        $store = $this->store();
        $this->customer($store, 'bob', readable: false);
        $this->order($store, 'c-hidden', 'bob', 5, readable: false);
        $this->order($store, 'd-visible', 'bob', 20, readable: true);

        $cursor = $this->page($store, [Query::orderAsc('ord.amount')], Query::cursorAfter('bob'));

        $this->assertSame('bob', $cursor->getId(), 'a caller who cannot read the cursor document still pages past it');
        $this->assertSame(20, $this->orderValue($cursor, 'ord.amount'));
    }

    public function testCursorDocumentIsReadWithAuthorizationSkippedAndJoinedRowsWithTheCallersPermissions(): void
    {
        $store = $this->store();
        $this->customer($store, 'bob', readable: false);
        $this->order($store, 'd-visible', 'bob', 20, readable: true);

        $this->page($store, [Query::orderAsc('ord.amount')], Query::cursorAfter('bob'));

        $this->assertContains(['collection' => self::CUSTOMERS, 'authorized' => false], $this->reads, 'the cursor document itself is read with authorization skipped');
        $this->assertContains(['collection' => self::ORDERS, 'authorized' => true], $this->reads, 'joined rows are read with the caller\'s permissions');
        $this->assertNotContains(['collection' => self::ORDERS, 'authorized' => false], $this->reads, 'joined rows are never read with authorization skipped');
    }

    public function testCursorWithJoinOrderSkipsJoinedRowsTheListFiltersOut(): void
    {
        $store = $this->store();
        $this->customer($store, 'alice', readable: true);
        $this->order($store, 'a-open', 'alice', 50, readable: true, status: 'open');
        $this->order($store, 'b-paid', 'alice', 10, readable: true, status: 'paid');

        $cursor = $this->page($store, [
            Query::equal('ord.status', ['paid']),
            Query::orderAsc('ord.amount'),
        ], Query::cursorAfter('alice'));

        $this->assertSame(10, $this->orderValue($cursor, 'ord.amount'), 'the list only pairs the customer with its paid order');
    }

    public function testCursorAfterADocumentWithSeveralJoinedRowsStartsBehindItsLastRow(): void
    {
        $store = $this->store();
        $this->customer($store, 'alice', readable: true);
        $this->order($store, 'a-first', 'alice', 10, readable: true);
        $this->order($store, 'b-second', 'alice', 25, readable: true);

        $after = $this->page($store, [Query::orderAsc('ord.amount')], Query::cursorAfter('alice'));
        $this->assertSame(25, $this->orderValue($after, 'ord.amount'));

        $descending = $this->page($store, [Query::orderDesc('ord.amount')], Query::cursorAfter('alice'));
        $this->assertSame(10, $this->orderValue($descending, 'ord.amount'));

        $before = $this->page($store, [Query::orderAsc('ord.amount')], Query::cursorBefore('alice'));
        $this->assertSame(10, $this->orderValue($before, 'ord.amount'), 'the page before a document ends ahead of its first row');
    }

    public function testCursorWithJoinOrderComparesJoinedDatetimesInStoredForm(): void
    {
        $store = $this->store();
        $this->customer($store, 'alice', readable: true);
        $this->order($store, 'b-visible', 'alice', 10, readable: true, placedAt: '2024-05-01T10:00:00.000+00:00');

        $cursor = $this->page($store, [Query::orderAsc('ord.placedAt')], Query::cursorAfter('alice'));

        $this->assertSame('2024-05-01 10:00:00.000', $cursor->getAttribute('ord.placedAt'), 'the list compares the stored value, as it does for the cursor document\'s own attributes');
    }

    public function testCursorWithJoinOrderFollowsAChainOfJoins(): void
    {
        $store = $this->store();
        $this->customer($store, 'alice', readable: true);
        $this->order($store, 'b-visible', 'alice', 10, readable: true, productId: 'lamp');
        $store->getAuthorization()->skip(fn () => $store->createDocument(self::PRODUCTS, new Document([
            '$id' => 'lamp',
            'name' => 'Lamp',
            '$permissions' => [Permission::read(Role::user('reader'))],
        ])));

        $cursor = $this->page($store, [
            Query::join('products', 'ord.productId', '$id', '=', 'prod')->toString(),
            Query::orderAsc('prod.name'),
        ], Query::cursorAfter('alice'));

        $this->assertSame('Lamp', $this->orderValue($cursor, 'prod.name'));
    }

    public function testCursorWithoutAReadableJoinedRowHasNoJoinedOrderValue(): void
    {
        $store = $this->store();
        $this->customer($store, 'alice', readable: true);
        $this->order($store, 'a-hidden', 'alice', 35, readable: false);

        $cursor = $this->page($store, [Query::orderAsc('ord.amount')], Query::cursorAfter('alice'));

        $this->assertNull($this->orderValue($cursor, 'ord.amount'), 'without a row the caller can read the order value is null, as it is in the list');
    }

    /**
     * @param list<string> $queries
     */
    private function list(array $queries, ?Database $documents = null, string $collectionId = 'posts'): void
    {
        (new XList())->action(
            databaseId: self::DATABASE_ID,
            collectionId: $collectionId,
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

    /**
     * Lists customers joined to their orders and returns the cursor document the list query received.
     *
     * @param list<Query|string> $queries
     */
    private function page(Database $store, array $queries, Query $cursor): Document
    {
        $this->list([
            Query::join('orders', '$id', 'customerId', '=', 'ord')->toString(),
            ...\array_map(static fn (Query|string $query): string => $query instanceof Query ? $query->toString() : $query, $queries),
            $cursor->toString(),
        ], $store, 'customers');

        $this->assertNotSame([], $this->cursors, 'the page must be listed');

        return \array_pop($this->cursors);
    }

    /**
     * The value the list query compares for an order attribute: the qualified key, else the bare one.
     */
    private function orderValue(Document $cursor, string $order): mixed
    {
        return $cursor->getAttribute($order) ?? $cursor->getAttribute(\substr($order, (int) \strrpos($order, '.') + 1));
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

    /**
     * A real database whose collections hold per-document permissions only. Reads run in full; the list query
     * itself is recorded instead of run, so each test sees the cursor document the list would page from.
     */
    private function store(): Database
    {
        $record = function (string $collection, bool $authorized): void {
            $this->reads[] = ['collection' => $collection, 'authorized' => $authorized];
        };
        $page = function (Document $cursor): void {
            $this->cursors[] = $cursor;
        };

        $store = new class (new SQLite(new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])), new Cache(new None()), $record, $page) extends Database {
            /**
             * @param \Closure(string, bool): void $record
             * @param \Closure(Document): void $page
             */
            public function __construct(SQLite $adapter, Cache $cache, private readonly \Closure $record, private readonly \Closure $page)
            {
                parent::__construct($adapter, $cache);
            }

            #[\Override]
            public function getDocument(string $collection, string $id, array $queries = [], bool $forUpdate = false): Document
            {
                ($this->record)($collection, $this->getAuthorization()->getStatus());

                return parent::getDocument($collection, $id, $queries, $forUpdate);
            }

            #[\Override]
            public function find(string $collection, array $queries = [], PermissionType $forPermission = PermissionType::Read): array
            {
                $cursors = Query::getCursorQueries($queries, false);
                if ($cursors !== []) {
                    ($this->page)(\reset($cursors)->getValue());

                    return [];
                }

                ($this->record)($collection, $this->getAuthorization()->getStatus());

                return parent::find($collection, $queries, $forPermission);
            }
        };

        $store
            ->setAuthorization($this->authorization)
            ->setDatabase('cursorLookup')
            ->setNamespace('cursor_lookup_' . \uniqid());
        $store->addHook(new Permissions());
        $store->create();

        $store->createCollection(self::perDocumentCollection(self::CUSTOMERS, [
            new Attribute('name', ColumnType::String, size: 64),
        ]));
        $store->createCollection(self::perDocumentCollection(self::ORDERS, [
            new Attribute('customerId', ColumnType::String, size: 64),
            new Attribute('productId', ColumnType::String, size: 64),
            new Attribute('status', ColumnType::String, size: 16),
            new Attribute('amount', ColumnType::Integer),
            new Attribute('placedAt', ColumnType::Datetime, filters: ['datetime']),
        ]));
        $store->createCollection(self::perDocumentCollection(self::PRODUCTS, [
            new Attribute('name', ColumnType::String, size: 64),
        ]));

        return $store;
    }

    private function customer(Database $store, string $id, bool $readable): void
    {
        $this->authorization->skip(fn () => $store->createDocument(self::CUSTOMERS, new Document([
            '$id' => $id,
            'name' => \ucfirst($id),
            '$permissions' => [Permission::read(Role::user($readable ? 'reader' : 'other'))],
        ])));
    }

    private function order(
        Database $store,
        string $id,
        string $customerId,
        int $amount,
        bool $readable,
        string $status = 'paid',
        ?string $placedAt = null,
        ?string $productId = null,
    ): void {
        $this->authorization->skip(fn () => $store->createDocument(self::ORDERS, new Document([
            '$id' => $id,
            'customerId' => $customerId,
            'productId' => $productId,
            'status' => $status,
            'amount' => $amount,
            'placedAt' => $placedAt,
            '$permissions' => [Permission::read(Role::user($readable ? 'reader' : 'other'))],
        ])));
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

    /**
     * @param list<Attribute> $attributes
     */
    private static function perDocumentCollection(string $id, array $attributes): Collection
    {
        return new Collection(
            id: $id,
            attributes: $attributes,
            permissions: [Permission::create(Role::any())],
            documentSecurity: true,
        );
    }

    private static function perDocument(string $id, string $sequence): Document
    {
        return new Document([
            '$id' => $id,
            '$sequence' => $sequence,
            '$permissions' => [Permission::create(Role::any())],
            'enabled' => true,
            'documentSecurity' => true,
            'attributes' => [],
        ]);
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
