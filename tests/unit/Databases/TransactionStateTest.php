<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Appwrite\Databases\TransactionState;
use Appwrite\Utopia\Database\Documents\User;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Event;
use Utopia\Database\Event\Document\Found;
use Utopia\Database\Event\Domain;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Filter;
use Utopia\Database\Hook\Lifecycle;
use Utopia\Database\Hook\Selective;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;

final class TransactionStateTest extends TestCase
{
    private const string MOVIES = 'database_1_collection_1';

    private const string CUSTOMERS = 'database_1_collection_2';

    private const string ORDERS = 'database_1_collection_3';

    private const string CUSTOMERS_INTERNAL_ID = '2';

    private const string ORDERS_INTERNAL_ID = '3';

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

    public function testDocumentAnEarlierOperationOfTheSameRequestWritesExists(): void
    {
        $transaction = $this->shop();
        $pending = [
            $this->pending($transaction, 'bulkCreate', null, [['$id' => 'cy', 'name' => 'Cy']]),
            $this->pending($transaction, 'bulkUpsert', null, [['$id' => 'dee', 'name' => 'Dee']]),
            $this->pending($transaction, 'upsert', 'eve', ['name' => 'Eve']),
            $this->pending($transaction, 'create', 'fay', ['name' => 'Fay']),
        ];

        foreach (['cy' => 'Cy', 'dee' => 'Dee', 'eve' => 'Eve', 'fay' => 'Fay'] as $id => $name) {
            $document = $this->state->getDocument(new Document(), self::CUSTOMERS, $id, $transaction->getId(), pending: $pending);
            $this->assertSame($name, $document->getAttribute('name'), "{$id} is written earlier in the same request");
        }

        $this->assertTrue($this->state->getDocument(new Document(), self::CUSTOMERS, 'cy', $transaction->getId())->isEmpty(), 'operations of a request are not stored until it succeeds');
    }

    public function testDocumentAnEarlierOperationOfTheSameRequestDeletesIsMissing(): void
    {
        $transaction = $this->shop();
        $pending = [
            $this->pending($transaction, 'bulkCreate', null, [['$id' => 'cy', 'name' => 'Cy']]),
            $this->pending($transaction, 'delete', 'cy', []),
            $this->pending($transaction, 'delete', 'ada', []),
        ];

        $this->assertTrue($this->state->getDocument(new Document(), self::CUSTOMERS, 'cy', $transaction->getId(), pending: $pending)->isEmpty());
        $this->assertTrue($this->state->getDocument(new Document(), self::CUSTOMERS, 'ada', $transaction->getId(), pending: $pending)->isEmpty());
        $this->assertSame('Bob', $this->state->getDocument(new Document(), self::CUSTOMERS, 'bob', $transaction->getId(), pending: $pending)->getAttribute('name'));
    }

    public function testARequestReadsTheStoredOperationsAndItsDatabaseOnce(): void
    {
        $transaction = $this->shop();
        foreach (['cy', 'dee', 'eve'] as $id) {
            $this->stage($transaction, 'create', $id, ['$id' => $id, 'name' => \ucfirst($id)]);
        }

        $reads = new class () implements Lifecycle, Selective {
            public int $count = 0;

            public function handles(Event $event): bool
            {
                return $event === Event::DocumentFind;
            }

            public function handle(Domain $event): void
            {
                if ($event instanceof Found && $event->collection === 'transactionLogs') {
                    $this->count++;
                }
            }
        };
        $this->store->addHook($reads);

        $store = $this->store;
        $databases = 0;
        $state = new TransactionState($store, $store->getAuthorization(), function () use ($store, &$databases): Database {
            $databases++;
            return $store;
        }, new User());

        $pending = [];
        foreach (['ada', 'bob', 'cy', 'dee', 'eve', 'fay'] as $id) {
            $document = $state->getDocument(new Document(), self::CUSTOMERS, $id, $transaction->getId(), pending: $pending);
            $state->stagedView(new Document(), $transaction, $pending)(self::CUSTOMERS, $id);
            $this->assertSame($id === 'fay', $document->isEmpty(), "{$id} reads as staged and stored");
            $pending[] = $this->pending($transaction, 'update', $id, ['name' => "{$id} again"]);
        }

        $this->assertSame('ada again', $state->getDocument(new Document(), self::CUSTOMERS, 'ada', $transaction->getId(), pending: $pending)->getAttribute('name'), 'an operation staged earlier in the request is seen by a later read');
        $this->assertSame(1, $reads->count, 'the stored operations are read once per request, not once per operation');
        $this->assertSame(1, $databases, 'the database is built once per request, not once per operation');
    }

    /**
     * @param array<mixed> $data
     */
    private function pending(Document $transaction, string $action, ?string $documentId, array $data, string $collectionInternalId = self::CUSTOMERS_INTERNAL_ID): Document
    {
        return new Document([
            'transactionInternalId' => (string) $transaction->getSequence(),
            'databaseInternalId' => '1',
            'collectionInternalId' => $collectionInternalId,
            'documentId' => $documentId,
            'action' => $action,
            'data' => $data,
        ]);
    }

    public function testStagedCreateInADistinctReadCarriesOnlyTheSelectedAttributes(): void
    {
        $transaction = $this->shop();
        $this->stage($transaction, 'create', 'cy', ['$id' => 'cy', 'name' => 'Cy']);

        $rows = $this->state->listDocuments(new Document(), self::CUSTOMERS, $transaction->getId(), [Query::distinct(), Query::select(['name'])]);

        $staged = \array_values(\array_filter($rows, static fn (Document $row): bool => $row->getAttribute('name') === 'Cy'));
        $this->assertCount(1, $staged);
        $this->assertSame(['name' => 'Cy'], $staged[0]->getArrayCopy());
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
    private function stage(Document $transaction, string $action, ?string $documentId, array $data, string $collectionInternalId = self::CUSTOMERS_INTERNAL_ID): void
    {
        $this->store->createDocument('transactionLogs', new Document([
            'transactionInternalId' => (string) $transaction->getSequence(),
            'databaseInternalId' => '1',
            'collectionInternalId' => $collectionInternalId,
            'documentId' => $documentId,
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

    public function testOrderOnAnAttributeTheReadSelectsAwayStillOrdersStoredRows(): void
    {
        $transaction = $this->shop();
        $this->store->createDocument(self::CUSTOMERS, new Document(['$id' => 'abe', 'name' => 'Abe']));
        $this->stage($transaction, 'create', 'cy', ['$id' => 'cy', 'name' => 'Cy']);

        $rows = $this->state->listDocuments(new Document(), self::CUSTOMERS, $transaction->getId(), [Query::select(['$id']), Query::orderAsc('name')]);

        $this->assertSame(['abe', 'ada', 'bob', 'cy'], \array_map(static fn (Document $row): string => $row->getId(), $rows));
        $this->assertNull($rows[0]->getAttribute('name'), 'the order attribute is selected for the merge only');
    }

    public function testExistsFilterSeesAStagedUpdateThatSetsTheAttribute(): void
    {
        $transaction = $this->shop();
        $this->store->createDocument(self::CUSTOMERS, new Document(['$id' => 'nameless']));
        $this->stage($transaction, 'update', 'nameless', ['name' => 'Named']);

        $rows = $this->state->listDocuments(new Document(), self::CUSTOMERS, $transaction->getId(), [Query::exists(['name']), Query::equal('$id', ['nameless'])]);
        $this->assertSame([['nameless', 'Named', null]], $this->project($rows));
    }

    public function testStagedWriteIsNotMatchedByAFilterOnlyTheEngineEvaluates(): void
    {
        $transaction = $this->shop();
        $this->stage($transaction, 'create', 'cy', ['$id' => 'cy', 'name' => 'Cy']);
        $this->stage($transaction, 'bulkDelete', null, ['queries' => [Query::regex('name', '^B')->toString()]]);

        $this->assertSame('Cy', $this->state->getDocument(new Document(), self::CUSTOMERS, 'cy', $transaction->getId())->getAttribute('name'), 'a staged bulk delete by a pattern the matcher cannot evaluate leaves a staged create alone');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function incrementKeys(): array
    {
        return [
            'TablesDB column' => ['column'],
            'legacy attribute' => ['attribute'],
        ];
    }

    #[DataProvider('incrementKeys')]
    public function testStagedIncrementBuildsOnTheStoredValue(string $key): void
    {
        $transaction = $this->shop();
        $order = $this->order(15);
        $this->stage($transaction, 'increment', $order, [$key => 'total', 'value' => 5], self::ORDERS_INTERNAL_ID);

        $this->assertSame(20, $this->state->getDocument(new Document(), self::ORDERS, $order, $transaction->getId())->getAttribute('total'), 'the staged increment is added to the stored total, not read as the total');
        $this->assertSame(20, $this->state->stagedView(new Document(), $transaction)(self::ORDERS, $order)?->getAttribute('total'), 'the permission pre-check sees the incremented total');
    }

    public function testConsecutiveStagedIncrementsAndDecrementsSum(): void
    {
        $transaction = $this->shop();
        $order = $this->order(15);
        $this->stage($transaction, 'increment', $order, ['column' => 'total', 'value' => 5], self::ORDERS_INTERNAL_ID);
        $this->stage($transaction, 'decrement', $order, ['column' => 'total', 'value' => 2], self::ORDERS_INTERNAL_ID);
        $this->stage($transaction, 'increment', $order, ['column' => 'total'], self::ORDERS_INTERNAL_ID);

        $this->assertSame(19, $this->state->getDocument(new Document(), self::ORDERS, $order, $transaction->getId())->getAttribute('total'));
    }

    public function testIncrementAfterAStagedUpdateOfTheAttributeBuildsOnTheStagedValue(): void
    {
        $transaction = $this->shop();
        $order = $this->order(15);
        $this->stage($transaction, 'increment', $order, ['column' => 'total', 'value' => 5], self::ORDERS_INTERNAL_ID);
        $this->stage($transaction, 'update', $order, ['total' => 30], self::ORDERS_INTERNAL_ID);
        $this->stage($transaction, 'increment', $order, ['column' => 'total', 'value' => 2], self::ORDERS_INTERNAL_ID);

        $this->assertSame(32, $this->state->getDocument(new Document(), self::ORDERS, $order, $transaction->getId())->getAttribute('total'), 'the update replaces the earlier increment and the later one builds on it');
    }

    #[DataProvider('incrementKeys')]
    public function testIncrementAfterAStagedUpdateOfAnotherAttributeBuildsOnTheStoredValue(string $key): void
    {
        $transaction = $this->shop();
        $order = $this->order(15);
        $this->stage($transaction, 'update', $order, ['customerId' => 'ada'], self::ORDERS_INTERNAL_ID);
        $this->stage($transaction, 'increment', $order, [$key => 'total', 'value' => 5], self::ORDERS_INTERNAL_ID);

        $document = $this->state->getDocument(new Document(), self::ORDERS, $order, $transaction->getId());
        $this->assertSame(20, $document->getAttribute('total'));
        $this->assertSame('ada', $document->getAttribute('customerId'));
    }

    public function testIncrementOfAStagedCreateBuildsOnTheStagedValue(): void
    {
        $transaction = $this->shop();
        $this->stage($transaction, 'create', 'new', ['$id' => 'new', 'customerId' => 'bob', 'total' => 7], self::ORDERS_INTERNAL_ID);
        $this->stage($transaction, 'increment', 'new', ['column' => 'total', 'value' => 3], self::ORDERS_INTERNAL_ID);

        $this->assertSame(10, $this->state->getDocument(new Document(), self::ORDERS, 'new', $transaction->getId())->getAttribute('total'));
    }

    public function testListAndCountMatchAndOrderAStagedIncrementByItsNewValue(): void
    {
        $transaction = $this->shop();
        $bob = $this->order(15);
        $ada = $this->order(10);
        $this->stage($transaction, 'increment', $bob, ['column' => 'total', 'value' => 10], self::ORDERS_INTERNAL_ID);
        $this->stage($transaction, 'increment', $ada, ['column' => 'total', 'value' => 50], self::ORDERS_INTERNAL_ID);
        $this->stage($transaction, 'delete', $ada, [], self::ORDERS_INTERNAL_ID);
        $queries = [Query::greaterThan('total', 18), Query::orderDesc('total')];

        $rows = $this->state->listDocuments(new Document(), self::ORDERS, $transaction->getId(), $queries);

        $this->assertSame([['bob', 25], ['ada', 20]], \array_map(static fn (Document $row): array => [$row->getAttribute('customerId'), $row->getAttribute('total')], $rows), 'the incremented order matches and sorts by its new total, and the deleted one drops its increment');
        $this->assertSame(2, $this->state->countDocuments(new Document(), self::ORDERS, $transaction->getId(), $queries));
    }

    public function testIncrementAnEarlierOperationOfTheSameRequestStagesIsVisible(): void
    {
        $transaction = $this->shop();
        $order = $this->order(15);
        $pending = [$this->pending($transaction, 'increment', $order, ['column' => 'total', 'value' => 5], self::ORDERS_INTERNAL_ID)];

        $this->assertSame(20, $this->state->getDocument(new Document(), self::ORDERS, $order, $transaction->getId(), pending: $pending)->getAttribute('total'));
    }

    private function order(int $total): string
    {
        return $this->store->findOne(self::ORDERS, [Query::equal('total', [$total])])->getId();
    }

    private function shopWithStagedCreates(): Document
    {
        $transaction = $this->shop();
        $this->stage($transaction, 'create', 'cy', ['$id' => 'cy', 'name' => 'Cy']);
        $this->stage($transaction, 'create', 'dee', ['$id' => 'dee', 'name' => 'Abe']);

        return $transaction;
    }
}
