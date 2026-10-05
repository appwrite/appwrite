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
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Schema\ColumnType;

final class CursorLookupTest extends TestCase
{
    private const string CUSTOMERS = 'database_1_collection_3';
    private const string ORDERS = 'database_1_collection_4';
    private const string PRODUCTS = 'database_1_collection_5';

    private Authorization $authorization;

    protected function setUp(): void
    {
        $this->authorization = new Authorization();
        $this->authorization->addRole(Role::user('reader')->toString());
        $this->authorization->addRole(Role::users()->toString());
    }

    public function testCursorWithoutJoinPagesPastADocumentTheCallerCannotRead(): void
    {
        $store = $this->store();
        $this->customer($store, 'alice', readable: true);
        $this->customer($store, 'bob', readable: false);
        $this->customer($store, 'carol', readable: true);

        $ids = $this->listedIds($store, [Query::orderAsc('name')], Query::cursorAfter('bob'), join: false);

        $this->assertSame(['carol'], $ids, 'a caller who cannot read the cursor document still pages past it');
    }

    public function testCursorWithJoinPagesByAnOrderTheSelectLeavesOut(): void
    {
        $store = $this->store();
        $this->customer($store, 'alice', readable: true);
        $this->customer($store, 'bob', readable: true);
        $this->customer($store, 'carol', readable: true);
        $this->order($store, 'a-only', 'alice', 10, readable: true);
        $this->order($store, 'b-only', 'bob', 15, readable: true);
        $this->order($store, 'c-only', 'carol', 20, readable: true);

        $ids = $this->listedIds($store, [
            Query::select(['$id'])->toString(),
            Query::orderAsc('name'),
        ], Query::cursorAfter('bob'));

        $this->assertSame(['carol'], $ids, 'the select shapes the listed rows, never the cursor document the page starts after');
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
        $this->order($store, 'c-hidden', 'bob', 30, readable: false);
        $this->order($store, 'd-visible', 'bob', 20, readable: true);

        $cursor = $this->page($store, [Query::orderAsc('ord.amount')], Query::cursorAfter('bob'));

        $this->assertSame('bob', $cursor->getId(), 'a caller who cannot read the cursor document still pages past it');
        $this->assertSame(20, $this->orderValue($cursor, 'ord.amount'));
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

    public function testCursorOnAOneToManyJoinPagesBehindTheDocumentsLastJoinedRow(): void
    {
        $store = $this->store();
        $this->customer($store, 'alice', readable: true);
        $this->customer($store, 'bob', readable: true);
        $this->order($store, 'a-first', 'alice', 10, readable: true);
        $this->order($store, 'a-second', 'alice', 25, readable: true);
        $this->order($store, 'b-only', 'bob', 15, readable: true);

        $after = $this->pageRows($store, [], Query::cursorAfter('alice'));
        $this->assertSame([['bob', 'b-only']], $after, 'the page after a document starts behind every row the list pairs with it');

        $before = $this->pageRows($store, [], Query::cursorBefore('bob'));
        $this->assertSame([['alice', 'a-first'], ['alice', 'a-second']], $before, 'the page before a document ends ahead of its first row');
    }

    public function testCursorOrderedByAJoinedAttributeWithoutAReadableRowPagesAsTheListDoes(): void
    {
        $store = $this->store();
        $this->customer($store, 'alice', readable: true);
        $this->customer($store, 'bob', readable: true);
        $this->order($store, 'a-hidden', 'alice', 35, readable: false);
        $this->order($store, 'b-only', 'bob', 15, readable: true);

        $rows = $this->pageRows($store, [
            Query::leftJoin(self::ORDERS, '$id', 'customerId', '=', 'ord')->toString(),
            Query::orderAsc('ord.amount'),
        ], Query::cursorAfter('alice'), join: false);

        $this->assertSame([['bob', 'b-only']], $rows, 'a row the join did not match is a page boundary with null joined values');
    }

    public function testCursorOrderedByABareJoinedNamePagesAsTheListDoes(): void
    {
        $store = $this->store();
        $this->customer($store, 'alice', readable: true);
        $this->customer($store, 'bob', readable: true);
        $this->order($store, 'a-first', 'alice', 10, readable: true);
        $this->order($store, 'a-second', 'alice', 25, readable: true);
        $this->order($store, 'b-only', 'bob', 15, readable: true);

        $rows = $this->pageRows($store, [Query::orderAsc('amount')], Query::cursorAfter('bob'));

        $this->assertSame([['alice', 'a-second']], $rows, 'a bare order name only the joined collection declares reads the joined row');
    }

    public function testCursorOnAJoinOfTheJoinedIdNeedsNoJoinedRow(): void
    {
        $store = $this->store();
        $this->customer($store, 'alice', readable: true);
        $this->order($store, 'a-first', 'alice', 10, readable: true);
        $this->order($store, 'a-second', 'alice', 25, readable: true);

        $parsed = Query::parseQueries([
            Query::join(self::CUSTOMERS, 'customerId', '$id', '=', 'cus')->toString(),
            Query::cursorAfter('a-first')->toString(),
        ]);
        $cursor = Query::getCursorQueries($parsed, false)[0];
        $document = (new CursorLookup($store, $this->authorization))->resolve(self::ORDERS, $cursor, $parsed);

        $cursor->setValue($document);
        $this->assertSame(['a-second'], \array_map(
            static fn (Document $row): string => $row->getId(),
            $store->find(self::ORDERS, $parsed),
        ));
    }

    /**
     * @param list<Query|string> $queries
     */
    private function page(Database $store, array $queries, Query $cursor): Document
    {
        $parsed = $this->customerQueries($queries, $cursor, join: true);

        return (new CursorLookup($store, $this->authorization))->resolve(self::CUSTOMERS, Query::getCursorQueries($parsed, false)[0], $parsed);
    }

    /**
     * @param list<Query|string> $queries
     * @return list<array{0: string, 1: mixed}>
     */
    private function pageRows(Database $store, array $queries, Query $cursor, bool $join = true): array
    {
        return \array_map(
            static fn (Document $row): array => [$row->getId(), $row->getAttribute('ord.$id')],
            $this->listed($store, $queries, $cursor, $join),
        );
    }

    /**
     * @param list<Query|string> $queries
     * @return list<string>
     */
    private function listedIds(Database $store, array $queries, Query $cursor, bool $join = true): array
    {
        return \array_map(
            static fn (Document $row): string => $row->getId(),
            $this->listed($store, $queries, $cursor, $join),
        );
    }

    /**
     * @param list<Query|string> $queries
     * @return array<Document>
     */
    private function listed(Database $store, array $queries, Query $cursor, bool $join): array
    {
        $parsed = $this->customerQueries($queries, $cursor, $join);
        $resolved = Query::getCursorQueries($parsed, false)[0];
        $resolved->setValue((new CursorLookup($store, $this->authorization))->resolve(self::CUSTOMERS, $resolved, $parsed));

        return $store->find(self::CUSTOMERS, $parsed);
    }

    /**
     * @param list<Query|string> $queries
     * @return array<Query>
     */
    private function customerQueries(array $queries, Query $cursor, bool $join): array
    {
        return Query::parseQueries([
            ...($join ? [Query::join(self::ORDERS, '$id', 'customerId', '=', 'ord')->toString()] : []),
            ...\array_map(static fn (Query|string $query): string => $query instanceof Query ? $query->toString() : $query, $queries),
            $cursor->toString(),
        ]);
    }

    private function orderValue(Document $cursor, string $order): mixed
    {
        return $cursor->getAttribute($order) ?? $cursor->getAttribute(\substr($order, (int) \strrpos($order, '.') + 1));
    }

    private function store(): Database
    {
        $store = new Database(new SQLite(new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])), new Cache(new None()));
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
