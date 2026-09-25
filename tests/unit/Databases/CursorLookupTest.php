<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Appwrite\Databases\CursorLookup;
use PDO;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Hook\Permissions;
use Utopia\Database\PermissionType;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Schema\ColumnType;

/**
 * A cursor only marks where the next page starts, so its document is looked up with authorization skipped,
 * whether or not the query joins another collection: a caller who may list a collection must be able to page
 * past a document it cannot read. The lookup never carries the request's selects or joins. An order on a joined
 * attribute takes its value from the joined rows the caller can read, read as listing that collection directly
 * reads them, so a page boundary never depends on a row the list itself hides.
 *
 * The queries reach the lookup as the list route hands them over: parsed, with each join resolved to the table
 * of the collection it names.
 */
final class CursorLookupTest extends TestCase
{
    private const string CURSOR_ID = 'post2';
    private const string POSTS = 'database_1_collection_1';
    private const string AUTHORS = 'database_1_collection_2';
    private const string CUSTOMERS = 'database_1_collection_3';
    private const string ORDERS = 'database_1_collection_4';
    private const string PRODUCTS = 'database_1_collection_5';

    private Authorization $authorization;

    /**
     * @var list<array{authorized: bool, queries: array<Query>}>
     */
    private array $lookups = [];

    /**
     * @var list<array{collection: string, authorized: bool}>
     */
    private array $reads = [];

    protected function setUp(): void
    {
        $this->authorization = new Authorization();
        $this->authorization->addRole(Role::user('reader')->toString());
        $this->authorization->addRole(Role::users()->toString());
    }

    public function testCursorWithJoinResolvesADocumentTheCallerCannotRead(): void
    {
        $cursor = $this->lookup([
            Query::join(self::AUTHORS, 'authorId', '$id')->toString(),
            Query::orderAsc('title')->toString(),
            Query::cursorAfter(self::CURSOR_ID)->toString(),
        ]);

        $this->assertCount(1, $this->lookups);
        $this->assertFalse($this->lookups[0]['authorized'], 'the cursor lookup must skip authorization, as it does without a join');
        $this->assertSame(self::CURSOR_ID, $cursor->getId(), 'the page must start after the cursor document');
    }

    public function testCursorWithJoinIsLookedUpWithoutSelectsOrJoins(): void
    {
        $cursor = $this->lookup([
            Query::join(self::AUTHORS, 'authorId', '$id')->toString(),
            Query::select(['title'])->toString(),
            Query::orderAsc('title')->toString(),
            Query::cursorAfter(self::CURSOR_ID)->toString(),
        ], $this->documentsDatabase(cursorReadable: true));

        $this->assertCount(1, $this->lookups);
        $this->assertSame([], $this->lookups[0]['queries'], 'a select must not strip the order attributes from the cursor document, and a join must not read joined rows with authorization skipped');
        $this->assertSame(self::CURSOR_ID, $cursor->getId());
    }

    public function testCursorWithoutJoinIsLookedUpWithoutQueries(): void
    {
        $cursor = $this->lookup([
            Query::select(['title'])->toString(),
            Query::cursorAfter(self::CURSOR_ID)->toString(),
        ]);

        $this->assertCount(1, $this->lookups);
        $this->assertFalse($this->lookups[0]['authorized']);
        $this->assertSame([], $this->lookups[0]['queries']);
        $this->assertSame(self::CURSOR_ID, $cursor->getId());
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

    public function testCursorWithJoinOrderPassesJoinedDatetimesForTheLibraryToEncode(): void
    {
        $store = $this->store();
        $this->customer($store, 'alice', readable: true);
        $this->order($store, 'b-visible', 'alice', 10, readable: true, placedAt: '2024-05-01T10:00:00.000+00:00');

        $cursor = $this->page($store, [Query::orderAsc('ord.placedAt')], Query::cursorAfter('alice'));

        $this->assertSame('2024-05-01T10:00:00.000+00:00', $cursor->getAttribute('ord.placedAt'), 'the library encodes joined cursor values, as it does the cursor document\'s own attributes');
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
            Query::join(self::PRODUCTS, 'ord.productId', '$id', '=', 'prod')->toString(),
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
    private function lookup(array $queries, ?Database $store = null, string $collection = self::POSTS): Document
    {
        $parsed = Query::parseQueries($queries);
        $cursors = Query::getCursorQueries($parsed, false);
        $this->assertCount(1, $cursors, 'the list must carry the cursor the lookup resolves');

        return (new CursorLookup($store ?? $this->documentsDatabase(), $this->authorization))
            ->resolve($collection, \reset($cursors), $parsed);
    }

    /**
     * Resolves the cursor of a list of customers joined to their orders.
     *
     * @param list<Query|string> $queries
     */
    private function page(Database $store, array $queries, Query $cursor): Document
    {
        return $this->lookup([
            Query::join(self::ORDERS, '$id', 'customerId', '=', 'ord')->toString(),
            ...\array_map(static fn (Query|string $query): string => $query instanceof Query ? $query->toString() : $query, $queries),
            $cursor->toString(),
        ], $store, self::CUSTOMERS);
    }

    /**
     * The value the list query compares for an order attribute: the qualified key, else the bare one.
     */
    private function orderValue(Document $cursor, string $order): mixed
    {
        return $cursor->getAttribute($order) ?? $cursor->getAttribute(\substr($order, (int) \strrpos($order, '.') + 1));
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
        $database->method('find')->willReturn([]);
        $database->method('skipRelationships')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        return $database;
    }

    /**
     * A real database whose collections hold per-document permissions only, recording which reads
     * the lookup runs with the caller's permissions and which it runs with authorization skipped.
     */
    private function store(): Database
    {
        $record = function (string $collection, bool $authorized): void {
            $this->reads[] = ['collection' => $collection, 'authorized' => $authorized];
        };

        $store = new class (new SQLite(new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])), new Cache(new None()), $record) extends Database {
            /**
             * @param \Closure(string, bool): void $record
             */
            public function __construct(SQLite $adapter, Cache $cache, private readonly \Closure $record)
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
}
