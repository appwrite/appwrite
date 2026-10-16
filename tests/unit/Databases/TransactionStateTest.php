<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Appwrite\Databases\TransactionState;
use Appwrite\Utopia\Database\Documents\User;
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
use Utopia\Database\Filter;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;

final class TransactionStateTest extends TestCase
{
    private const string MOVIES = 'database_1_collection_1';

    private const string CUSTOMERS = 'database_1_collection_2';

    private const string ORDERS = 'database_1_collection_3';

    private Database $store;

    private TransactionState $state;

    protected function setUp(): void
    {
        $authorization = new Authorization();
        $authorization->addRole(Role::any()->toString());

        $this->store = new Database(new SQLite(new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])), new Cache(new None()));
        $this->store
            ->setAuthorization($authorization)
            ->setDatabase('transactionState')
            ->setNamespace('transaction_state_' . \uniqid());
        $this->store->create();
        $this->store->createCollection(Collection::create(
            id: self::MOVIES,
            attributes: [Attribute::string('genre', size: 32), Attribute::integer('year')],
            permissions: [Permission::read(Role::any()), Permission::create(Role::any())],
        ));
        foreach ([['drama', 2000], ['drama', 2002], ['comedy', 1990]] as [$genre, $year]) {
            $this->store->createDocument(self::MOVIES, new Document(['genre' => $genre, 'year' => $year]));
        }

        $store = $this->store;
        $this->state = new TransactionState($store, $authorization, fn (): Database => $store, new User());
    }

    public function testListOutsideATransactionReturnsDocuments(): void
    {
        $documents = $this->state->listDocuments(new Document(), self::MOVIES, null, [Query::equal('genre', ['drama'])]);

        $this->assertCount(2, $documents);
        $this->assertContainsOnlyInstancesOf(Document::class, $documents);
        $this->assertSame(['drama', 'drama'], \array_map(static fn (Document $document): mixed => $document->getAttribute('genre'), $documents));
    }

    public function testAggregatesInsideATransactionAreRejected(): void
    {
        $this->expectException(QueryException::class);

        $this->state->listDocuments(new Document(), self::MOVIES, 'transaction', [Query::count('*', 'movies')]);
    }

    public function testAggregateCountInsideATransactionIsRejected(): void
    {
        $this->expectException(QueryException::class);

        $this->state->countDocuments(new Document(), self::MOVIES, 'transaction', [Query::groupBy(['genre'])]);
    }

    public function testOneToManyJoinInATransactionReturnsEveryJoinedRow(): void
    {
        $transaction = $this->shop();

        $rows = $this->state->listDocuments(new Document(), self::CUSTOMERS, $transaction->getId(), $this->ordersOfCustomers());

        $this->assertSame([['ada', 'Ada', 10], ['bob', 'Bob', 15], ['ada', 'Ada', 20]], $this->project($rows), 'a customer with two orders reads as one row per order, in the order the join returns them');
        $this->assertSame(\count($rows), $this->state->countDocuments(new Document(), self::CUSTOMERS, $transaction->getId(), $this->ordersOfCustomers()));
    }

    public function testStagedUpdateReachesEveryJoinedRow(): void
    {
        $transaction = $this->shop();
        $this->stage($transaction, 'update', 'ada', ['name' => 'Ada Lovelace']);

        $rows = $this->state->listDocuments(new Document(), self::CUSTOMERS, $transaction->getId(), $this->ordersOfCustomers());

        $this->assertSame([['ada', 'Ada Lovelace', 10], ['bob', 'Bob', 15], ['ada', 'Ada Lovelace', 20]], $this->project($rows), 'each joined row of the customer reads the staged name and keeps its own order');
        $this->assertSame(\count($rows), $this->state->countDocuments(new Document(), self::CUSTOMERS, $transaction->getId(), $this->ordersOfCustomers()));
    }

    public function testStagedDeleteRemovesEveryJoinedRow(): void
    {
        $transaction = $this->shop();
        $this->stage($transaction, 'delete', 'ada', []);

        $rows = $this->state->listDocuments(new Document(), self::CUSTOMERS, $transaction->getId(), $this->ordersOfCustomers());

        $this->assertSame([['bob', 'Bob', 15]], $this->project($rows), 'deleting the customer removes every row it was joined into');
        $this->assertSame(1, $this->state->countDocuments(new Document(), self::CUSTOMERS, $transaction->getId(), $this->ordersOfCustomers()));
    }

    public function testUnjoinedReadInATransactionOrdersStagedWritesWithStoredRows(): void
    {
        $transaction = $this->shop();
        $this->stage($transaction, 'create', 'cy', ['$id' => 'cy', 'name' => 'Cy']);
        $this->stage($transaction, 'update', 'ada', ['name' => 'Ada Lovelace']);
        $queries = [Query::select(['name']), Query::orderDesc('name')];

        $rows = $this->state->listDocuments(new Document(), self::CUSTOMERS, $transaction->getId(), $queries);

        $this->assertSame([['cy', 'Cy', null], ['bob', 'Bob', null], ['ada', 'Ada Lovelace', null]], $this->project($rows), 'staged creates and updates take their place in the order');
        $this->assertSame(3, $this->state->countDocuments(new Document(), self::CUSTOMERS, $transaction->getId(), $queries));
    }

    /**
     * @return list<Query>
     */
    private function ordersOfCustomers(): array
    {
        return [
            Query::join(self::ORDERS, 'ord', [Query::on('$id', 'customerId')]),
            Query::select(['name', 'ord.total']),
            Query::orderAsc('ord.total'),
        ];
    }

    /**
     * @param array<Document> $rows
     * @return list<array{string, mixed, mixed}>
     */
    private function project(array $rows): array
    {
        return \array_values(\array_map(
            static fn (Document $row): array => [$row->getId(), $row->getAttribute('name'), $row->getAttribute('ord.total')],
            $rows,
        ));
    }

    private function shop(): Document
    {
        $this->store->createCollection(Collection::create(
            id: self::CUSTOMERS,
            attributes: [Attribute::string('name', size: 64)],
            permissions: [Permission::read(Role::any()), Permission::create(Role::any())],
        ));
        $this->store->createCollection(Collection::create(
            id: self::ORDERS,
            attributes: [Attribute::string('customerId', size: 64), Attribute::integer('total')],
            permissions: [Permission::read(Role::any()), Permission::create(Role::any())],
        ));
        foreach (['ada' => 'Ada', 'bob' => 'Bob'] as $id => $name) {
            $this->store->createDocument(self::CUSTOMERS, new Document(['$id' => $id, 'name' => $name]));
        }
        foreach ([['ada', 10], ['bob', 15], ['ada', 20]] as [$customerId, $total]) {
            $this->store->createDocument(self::ORDERS, new Document(['customerId' => $customerId, 'total' => $total]));
        }

        $this->store->createCollection(Collection::create(
            id: 'transactions',
            attributes: [Attribute::string('status', size: 16)],
            permissions: [Permission::create(Role::any())],
        ));
        $this->store->createCollection(Collection::create(
            id: 'transactionLogs',
            attributes: [
                Attribute::string('transactionInternalId', required: true),
                Attribute::string('databaseInternalId', required: true),
                Attribute::string('collectionInternalId', required: true),
                Attribute::string('documentId'),
                Attribute::string('action', size: 32, required: true),
                Attribute::string('data', size: 1_000_000, required: true, filters: [Filter::Json]),
            ],
            permissions: [Permission::create(Role::any())],
        ));

        return $this->store->createDocument('transactions', new Document([
            'status' => 'pending',
            '$permissions' => [Permission::read(Role::any())],
        ]));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function stage(Document $transaction, string $action, string $customerId, array $data): void
    {
        $this->store->createDocument('transactionLogs', new Document([
            'transactionInternalId' => (string) $transaction->getSequence(),
            'databaseInternalId' => '1',
            'collectionInternalId' => '2',
            'documentId' => $customerId,
            'action' => $action,
            'data' => $data,
        ]));
    }

    public function testStagedCreateThatMissesTheFilterIsLeftOut(): void
    {
        $transaction = $this->shop();
        $this->stage($transaction, 'create', 'cy', ['$id' => 'cy', 'name' => 'Cy']);
        $queries = [Query::equal('name', ['Bob'])];

        $this->assertSame([['bob', 'Bob', null]], $this->project($this->state->listDocuments(new Document(), self::CUSTOMERS, $transaction->getId(), $queries)));
        $this->assertSame(1, $this->state->countDocuments(new Document(), self::CUSTOMERS, $transaction->getId(), $queries));
    }

    public function testStagedUpdateIsMatchedAgainstTheFilterWithItsNewValues(): void
    {
        $transaction = $this->shop();
        $this->stage($transaction, 'update', 'ada', ['name' => 'Zed']);

        $old = [Query::equal('name', ['Ada'])];
        $this->assertSame([], $this->project($this->state->listDocuments(new Document(), self::CUSTOMERS, $transaction->getId(), $old)), 'the row no longer matches once its staged update is applied');
        $this->assertSame(0, $this->state->countDocuments(new Document(), self::CUSTOMERS, $transaction->getId(), $old));

        $new = [Query::equal('name', ['Zed'])];
        $this->assertSame([['ada', 'Zed', null]], $this->project($this->state->listDocuments(new Document(), self::CUSTOMERS, $transaction->getId(), $new)), 'the row matches on its staged value');
        $this->assertSame(1, $this->state->countDocuments(new Document(), self::CUSTOMERS, $transaction->getId(), $new));
    }

    public function testOrderAndLimitApplyToTheMergedSet(): void
    {
        $transaction = $this->shopWithStagedCreates();

        $descending = $this->state->listDocuments(new Document(), self::CUSTOMERS, $transaction->getId(), [Query::orderDesc('name'), Query::limit(2)]);
        $this->assertSame([['cy', 'Cy', null], ['bob', 'Bob', null]], $this->project($descending));

        $ascending = $this->state->listDocuments(new Document(), self::CUSTOMERS, $transaction->getId(), [Query::orderAsc('name'), Query::limit(2)]);
        $this->assertSame([['dee', 'Abe', null], ['ada', 'Ada', null]], $this->project($ascending));

        $this->assertSame(4, $this->state->countDocuments(new Document(), self::CUSTOMERS, $transaction->getId(), [Query::orderAsc('name'), Query::limit(2)]), 'total counts the merged set, not the page');
    }

    public function testDefaultOrderPutsStagedCreatesAfterStoredRows(): void
    {
        $transaction = $this->shopWithStagedCreates();
        $this->stage($transaction, 'delete', 'ada', []);

        $rows = $this->state->listDocuments(new Document(), self::CUSTOMERS, $transaction->getId(), [Query::limit(2)]);
        $this->assertSame([['bob', 'Bob', null], ['cy', 'Cy', null]], $this->project($rows), 'stored rows by sequence, then staged creates in staging order');
        $this->assertSame(3, $this->state->countDocuments(new Document(), self::CUSTOMERS, $transaction->getId(), []));
    }

    public function testOffsetAndCursorPageThroughTheMergedSet(): void
    {
        $transaction = $this->shopWithStagedCreates();
        $byName = Query::orderAsc('name');
        $bob = $this->store->getDocument(self::CUSTOMERS, 'bob');

        $page = fn (array $queries): array => $this->project($this->state->listDocuments(new Document(), self::CUSTOMERS, $transaction->getId(), [$byName, ...$queries]));

        $this->assertSame([['ada', 'Ada', null], ['bob', 'Bob', null]], $page([Query::offset(1), Query::limit(2)]));
        $this->assertSame([['cy', 'Cy', null]], $page([Query::cursorAfter($bob)]), 'after a stored row');
        $this->assertSame([['dee', 'Abe', null], ['ada', 'Ada', null]], $page([Query::cursorBefore($bob)]), 'before a stored row');
        $this->assertSame([['ada', 'Ada', null], ['bob', 'Bob', null], ['cy', 'Cy', null]], $page([Query::cursorAfter(new Document(['$id' => 'dee', 'name' => 'Abe']))]), 'after a staged row');
        $this->assertSame([['ada', 'Ada', null], ['bob', 'Bob', null]], $page([Query::cursorBefore(new Document(['$id' => 'cy', 'name' => 'Cy'])), Query::limit(2)]), 'the rows nearest before a staged row');
    }

    private function shopWithStagedCreates(): Document
    {
        $transaction = $this->shop();
        $this->stage($transaction, 'create', 'cy', ['$id' => 'cy', 'name' => 'Cy']);
        $this->stage($transaction, 'create', 'dee', ['$id' => 'dee', 'name' => 'Abe']);

        return $transaction;
    }
}
