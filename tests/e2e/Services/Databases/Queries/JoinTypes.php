<?php

namespace Tests\E2E\Services\Databases\Queries;

use Tests\E2E\Client;
use Tests\E2E\Services\Databases\Queries\Oracle\Assertions;
use Tests\E2E\Services\Databases\Queries\Oracle\Customer;
use Tests\E2E\Services\Databases\Queries\Oracle\Fixture;
use Tests\E2E\Services\Databases\Queries\Oracle\Join;
use Tests\E2E\Services\Databases\Queries\Oracle\Order;
use Tests\E2E\Services\Databases\Queries\Oracle\Seed;
use Tests\E2E\Services\Databases\Queries\Oracle\Summary;
use Utopia\Database\Database;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

trait JoinTypes
{
    use Assertions;

    /**
     * @var array<string, Fixture>
     */
    private static array $restrictedOrdersFixtures = [];

    public function testRightJoinMatchedRows(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];
        $customersId = $data['customersId'];
        $ordersId = $data['ordersId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $customersId), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::rightJoin($ordersId, '$id', 'customerId')->toString(),
                Query::select(['name'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $rows = $result['body'][$this->getRecordResource()];
        $this->assertCount(4, $rows);
        $this->assertSame(4, $result['body']['total']);
    }

    public function testCrossJoinCartesianCount(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];
        $customersId = $data['customersId'];
        $ordersId = $data['ordersId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $customersId), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::crossJoin($ordersId)->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $rows = $result['body'][$this->getRecordResource()];
        $this->assertCount(12, $rows);
        $this->assertSame(12, $result['body']['total']);
        $this->assertJoinedValuesStayAliased($rows, ['customerId', 'amount', 'status']);
    }

    public function testFullOuterJoinMatchedAndUnmatched(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];
        $customersId = $data['customersId'];
        $ordersId = $data['ordersId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $customersId), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::fullOuterJoin($ordersId, '$id', 'customerId')->toString(),
                Query::select(['name'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $rows = $result['body'][$this->getRecordResource()];
        $this->assertCount(5, $rows);
        $this->assertSame(5, $result['body']['total']);
    }

    public function testNaturalJoinRejected(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];
        $customersId = $data['customersId'];
        $ordersId = $data['ordersId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $customersId), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::naturalJoin($ordersId)->toString(),
            ],
        ]);

        $this->assertSame(400, $result['headers']['status-code']);
        $this->assertSame('general_query_invalid', $result['body']['type']);
    }

    public function testGetRowInnerJoinMatched(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];
        $customersId = $data['customersId'];
        $ordersId = $data['ordersId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $customersId, 'alice'), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::join($ordersId, '$id', 'customerId')->toString(),
                Query::select(['name'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $this->assertSame('alice', $result['body']['$id']);
        $this->assertSame('Alice', $result['body']['name']);
    }

    public function testGetRowLeftJoinUnmatched(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];
        $customersId = $data['customersId'];
        $ordersId = $data['ordersId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $customersId, 'carol'), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::leftJoin($ordersId, '$id', 'customerId', '=', 'ord')->toString(),
                Query::select(['name', 'ord.amount'])->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $this->assertSame('carol', $result['body']['$id']);
        $this->assertSame('Carol', $result['body']['name']);
        $this->assertArrayHasKey('ord.amount', $result['body']);
        $this->assertNull($result['body']['ord.amount'], 'An unmatched left-joined amount must be null, not 0');
        $this->assertArrayNotHasKey('amount', $result['body']);
    }

    public function testGetRowInnerJoinUnmatchedNotFound(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];
        $customersId = $data['customersId'];
        $ordersId = $data['ordersId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $customersId, 'carol'), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::join($ordersId, '$id', 'customerId')->toString(),
            ],
        ]);

        $this->assertSame(404, $result['headers']['status-code']);
    }

    public function testGetRowRightJoinUnmatchedNotFound(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];
        $customersId = $data['customersId'];
        $ordersId = $data['ordersId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $customersId, 'carol'), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::rightJoin($ordersId, '$id', 'customerId')->toString(),
            ],
        ]);

        $this->assertSame(404, $result['headers']['status-code']);
    }

    public function testGetRowOneToManyReturnsFirst(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];
        $customersId = $data['customersId'];
        $ordersId = $data['ordersId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $customersId, 'alice'), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::join($ordersId, '$id', 'customerId', '=', 'ord')->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $this->assertSame('alice', $result['body']['$id']);
        $this->assertArrayHasKey('ord.amount', $result['body']);
        $this->assertContains($result['body']['ord.amount'], [100, 50]);
        $this->assertJoinedValuesStayAliased([$result['body']], ['customerId', 'amount', 'status']);
    }

    public function testGetRowFullOuterJoinExistingIdLikeLeft(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];
        $customersId = $data['customersId'];
        $ordersId = $data['ordersId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $customersId, 'carol'), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::fullOuterJoin($ordersId, '$id', 'customerId', '=', 'ord')->toString(),
            ],
        ]);

        $this->assertSame(200, $result['headers']['status-code']);
        $this->assertSame('carol', $result['body']['$id']);
        $this->assertArrayHasKey('ord.amount', $result['body']);
        $this->assertNull($result['body']['ord.amount'], 'An unmatched full-outer-joined amount must be null, not 0');
    }

    public function testGetRowRejectsCount(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $data = $this->setupAnalyticsFixture();
        $databaseId = $data['databaseId'];
        $customersId = $data['customersId'];

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $customersId, 'alice'), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::count()->toString(),
            ],
        ]);

        $this->assertSame(400, $result['headers']['status-code']);
        $this->assertSame('general_query_invalid', $result['body']['type']);
    }

    public function testInnerJoinRowsTotalAndAggregatesMatchReadableRows(): void
    {
        $this->assertJoinMatchesReadableRows(Join::Inner);
    }

    public function testLeftJoinRowsTotalAndAggregatesMatchReadableRows(): void
    {
        $this->assertJoinMatchesReadableRows(Join::Left);
    }

    public function testRightJoinRowsTotalAndAggregatesMatchReadableRows(): void
    {
        $this->assertJoinMatchesReadableRows(Join::Right);
    }

    public function testCrossJoinRowsTotalAndAggregatesMatchReadableRows(): void
    {
        $this->assertJoinMatchesReadableRows(Join::Cross);
    }

    public function testFullOuterJoinRowsTotalAndAggregatesMatchReadableRows(): void
    {
        $this->assertJoinMatchesReadableRows(Join::FullOuter);
    }

    public function testJoinLimitAcceptsEightJoinsAndRejectsNine(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $fixture = $this->setupRestrictedOrdersFixture();
        $readable = \count($this->seedVisibleToCaller()->customers);

        $eight = $this->queryRecords($fixture->databaseId, $fixture->customersId, [
            ...$this->selfJoins($fixture->customersId, 8),
            Query::select(['name'])->toString(),
            Query::limit(100)->toString(),
        ]);

        $this->assertSame(200, $eight['headers']['status-code'], 'Eight joins are within the limit');
        $this->assertCount($readable, $eight['body'][$this->getRecordResource()]);
        $this->assertSame($readable, $eight['body']['total']);

        $nine = $this->queryRecords($fixture->databaseId, $fixture->customersId, [
            ...$this->selfJoins($fixture->customersId, 9),
            Query::select(['name'])->toString(),
        ]);

        $this->assertSame(400, $nine['headers']['status-code'], 'A ninth join exceeds the limit');
        $this->assertSame('general_query_invalid', $nine['body']['type']);
    }

    public function testBulkUpdateWithJoinQueryRejected(): void
    {
        $databaseId = $this->setupDatabase()['databaseId'];
        $notesId = $this->createBulkNotes($databaseId);
        $peersId = $this->createSeedContainer($databaseId, 'bulkPeers' . ID::unique(), false, [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
        ]);

        foreach ([$peersId, Database::METADATA] as $joined) {
            $updated = $this->client->call(Client::METHOD_PATCH, $this->getRecordUrl($databaseId, $notesId), $this->apiKeyHeaders(), [
                'data' => ['note' => 'rewritten'],
                'queries' => [Query::leftJoin($joined, '$id', '$id', '=', 'peer')->toString()],
            ]);

            $this->assertSame(400, $updated['headers']['status-code'], "A bulk update joining '{$joined}' must be rejected");
            $this->assertSame('general_query_invalid', $updated['body']['type']);
        }

        $this->assertBulkNotesUntouched($databaseId, $notesId);
    }

    public function testBulkDeleteWithJoinQueryRejected(): void
    {
        $databaseId = $this->setupDatabase()['databaseId'];
        $notesId = $this->createBulkNotes($databaseId);
        $peersId = $this->createSeedContainer($databaseId, 'bulkPeers' . ID::unique(), false, [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
        ]);

        foreach ([$peersId, Database::METADATA] as $joined) {
            $deleted = $this->client->call(Client::METHOD_DELETE, $this->getRecordUrl($databaseId, $notesId), $this->apiKeyHeaders(), [
                'queries' => [Query::leftJoin($joined, '$id', '$id', '=', 'peer')->toString()],
            ]);

            $this->assertSame(400, $deleted['headers']['status-code'], "A bulk delete joining '{$joined}' must be rejected");
            $this->assertSame('general_query_invalid', $deleted['body']['type']);
        }

        $this->assertBulkNotesUntouched($databaseId, $notesId);
    }

    public function testJoinQueriesRejectedWhereAdapterLacksJoins(): void
    {
        if ($this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter supports join queries');
        }

        $databaseId = $this->setupDatabase()['databaseId'];
        $probesId = $this->createProbeContainer($databaseId);
        $peersId = $this->createSeedContainer($databaseId, 'probePeers' . ID::unique(), false, [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
        ]);
        $join = Query::leftJoin($peersId, '$id', '$id', '=', 'peer')->toString();

        $plain = $this->queryRecords($databaseId, $probesId, []);
        $this->assertSame(200, $plain['headers']['status-code']);
        $this->assertSame(['probe'], \array_column($plain['body'][$this->getRecordResource()], '$id'));

        $listed = $this->queryRecords($databaseId, $probesId, [$join]);
        $this->assertSame(400, $listed['headers']['status-code']);
        $this->assertSame('general_query_invalid', $listed['body']['type']);

        $got = $this->fetchRecord($databaseId, $probesId, 'probe', [$join]);
        $this->assertSame(400, $got['headers']['status-code']);
        $this->assertSame('general_query_invalid', $got['body']['type']);
    }

    protected function setupRestrictedOrdersFixture(): Fixture
    {
        $cacheKey = $this->getCacheKey();
        if (isset(self::$restrictedOrdersFixtures[$cacheKey])) {
            return self::$restrictedOrdersFixtures[$cacheKey];
        }

        $databaseId = $this->setupDatabase()['databaseId'];
        $suffix = ID::unique();
        $documentLevel = [Permission::create(Role::any())];

        $fixture = new Fixture(
            databaseId: $databaseId,
            customersId: $this->createSeedContainer($databaseId, 'roCustomers' . $suffix, true, $documentLevel),
            ordersId: $this->createSeedContainer($databaseId, 'roOrders' . $suffix, true, $documentLevel),
            paymentsId: $this->createSeedContainer($databaseId, 'roPayments' . $suffix, false, [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
            ]),
        );

        $attributes = [
            [$fixture->customersId, 'string', ['key' => 'name', 'size' => 64, 'required' => true]],
            [$fixture->ordersId, 'string', ['key' => 'customerId', 'size' => 36, 'required' => false]],
            [$fixture->ordersId, 'integer', ['key' => 'amount', 'required' => true]],
            [$fixture->ordersId, 'string', ['key' => 'label', 'size' => 32, 'required' => true]],
            [$fixture->ordersId, 'integer', ['key' => 'flags', 'required' => true]],
            [$fixture->ordersId, 'integer', ['key' => 'scores', 'required' => false, 'array' => true]],
            [$fixture->paymentsId, 'string', ['key' => 'orderId', 'size' => 36, 'required' => true]],
            [$fixture->paymentsId, 'integer', ['key' => 'amount', 'required' => true]],
        ];

        foreach ($attributes as [$containerId, $type, $payload]) {
            $created = $this->createAttribute($databaseId, $containerId, $type, $payload);
            $this->assertSame(202, $created['headers']['status-code']);
        }

        foreach ($attributes as [$containerId, , $payload]) {
            $this->waitForAttribute($databaseId, $containerId, $payload['key']);
        }

        $seed = Seed::create();

        foreach ($seed->customers as $customer) {
            $this->createSeedRecord($databaseId, $fixture->customersId, $customer->id, [
                'name' => $customer->name,
            ], $this->seedPermissions($customer->hidden));
        }

        foreach ($seed->orders as $order) {
            $this->createSeedRecord($databaseId, $fixture->ordersId, $order->id, [
                'customerId' => $order->customerId,
                'amount' => $order->amount,
                'label' => $order->label,
                'flags' => $order->flags,
                'scores' => [$order->flags, $order->amount],
            ], $this->seedPermissions($order->hidden));
        }

        foreach ($seed->payments as $payment) {
            $this->createSeedRecord($databaseId, $fixture->paymentsId, $payment->id, [
                'orderId' => $payment->orderId,
                'amount' => $payment->amount,
            ], $this->seedPermissions(false));
        }

        return self::$restrictedOrdersFixtures[$cacheKey] = $fixture;
    }

    protected function seedVisibleToCaller(): Seed
    {
        return Seed::create()->visibleTo($this->getSide() === 'client');
    }

    /**
     * @param list<string> $queries
     * @return array<string, mixed>
     */
    protected function queryRecords(string $databaseId, string $containerId, array $queries): array
    {
        return $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $containerId), $this->callerHeaders(), [
            'queries' => $queries,
        ]);
    }

    /**
     * @param list<string> $queries
     * @return array<string, mixed>
     */
    protected function fetchRecord(string $databaseId, string $containerId, string $recordId, array $queries): array
    {
        return $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $containerId, $recordId), $this->callerHeaders(), [
            'queries' => $queries,
        ]);
    }

    private function assertJoinMatchesReadableRows(Join $join): void
    {
        if (!$this->getSupportForJoins() || !$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support join or aggregation queries');
        }

        $fixture = $this->setupRestrictedOrdersFixture();
        $seed = $this->seedVisibleToCaller();
        $this->assertDirectReadsMatchSeed($fixture, $seed);

        $pairs = $join->pairs($seed->customers, $seed->orders);
        $joinQuery = $join->query($fixture->ordersId, 'ord')->toString();

        $listed = $this->queryRecords($fixture->databaseId, $fixture->customersId, [
            $joinQuery,
            Query::select(['name', 'ord.amount'])->toString(),
            Query::limit(100)->toString(),
        ]);

        $this->assertSame(200, $listed['headers']['status-code']);
        $this->assertPairRows($pairs, $listed['body'][$this->getRecordResource()], "Rows of the {$join->name} join");
        $this->assertSame(\count($pairs), $listed['body']['total'], "Total of the {$join->name} join");

        $aggregated = $this->queryRecords($fixture->databaseId, $fixture->customersId, [
            $joinQuery,
            Query::count('*', 'rowCount')->toString(),
            Query::count('ord.$id', 'orderCount')->toString(),
            Query::sum('ord.amount', 'amountSum')->toString(),
            Query::min('ord.amount', 'amountMinimum')->toString(),
            Query::max('ord.amount', 'amountMaximum')->toString(),
            Query::avg('ord.amount', 'amountAverage')->toString(),
        ]);

        $this->assertSame(200, $aggregated['headers']['status-code']);
        $this->assertCount(1, $aggregated['body'][$this->getRecordResource()]);
        $this->assertSummaryRow(Summary::of($pairs), $aggregated['body'][$this->getRecordResource()][0], "Aggregates of the {$join->name} join");

        $grouped = $this->queryRecords($fixture->databaseId, $fixture->customersId, [
            $joinQuery,
            Query::groupBy(['ord.label'])->toString(),
            Query::count('*', 'rowCount')->toString(),
            Query::count('ord.$id', 'orderCount')->toString(),
            Query::sum('ord.amount', 'amountSum')->toString(),
        ]);

        $this->assertSame(200, $grouped['headers']['status-code']);
        $this->assertGroupRows($pairs, $grouped['body'][$this->getRecordResource()], "Groups of the {$join->name} join");

        $filtered = $this->queryRecords($fixture->databaseId, $fixture->customersId, [
            $joinQuery,
            Query::groupBy(['ord.label'])->toString(),
            Query::sum('ord.amount', 'amountSum')->toString(),
            Query::having([Query::greaterThan('amountSum', Seed::GROUP_THRESHOLD)])->toString(),
        ]);

        $this->assertSame(200, $filtered['headers']['status-code']);
        $this->assertGroupLabels(Summary::labelsAbove($pairs, Seed::GROUP_THRESHOLD), $filtered['body'][$this->getRecordResource()], "Groups of the {$join->name} join above the having threshold");
    }

    private function assertDirectReadsMatchSeed(Fixture $fixture, Seed $seed): void
    {
        $customers = $this->queryRecords($fixture->databaseId, $fixture->customersId, [Query::limit(100)->toString()]);
        $this->assertSame(200, $customers['headers']['status-code']);
        $this->assertSame(
            $this->sortedStrings(\array_map(static fn (Customer $customer): string => $customer->id, $seed->customers)),
            $this->sortedStrings(\array_column($customers['body'][$this->getRecordResource()], '$id')),
            'Customers a direct read returns',
        );

        $orders = $this->queryRecords($fixture->databaseId, $fixture->ordersId, [Query::limit(100)->toString()]);
        $this->assertSame(200, $orders['headers']['status-code']);
        $this->assertSame(
            $this->sortedStrings(\array_map(static fn (Order $order): string => $order->id, $seed->orders)),
            $this->sortedStrings(\array_column($orders['body'][$this->getRecordResource()], '$id')),
            'Orders a direct read returns',
        );
    }

    /**
     * @return list<string>
     */
    private function selfJoins(string $collectionId, int $count): array
    {
        return \array_map(
            static fn (int $index): string => Query::join($collectionId, '$id', '$id', '=', 'peer' . $index)->toString(),
            \range(1, $count),
        );
    }

    private function createBulkNotes(string $databaseId): string
    {
        $notesId = $this->createSeedContainer($databaseId, 'bulkNotes' . ID::unique(), false, [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ]);

        if ($this->getSupportForAttributes()) {
            $created = $this->createAttribute($databaseId, $notesId, 'string', ['key' => 'note', 'size' => 32, 'required' => true]);
            $this->assertSame(202, $created['headers']['status-code']);
            $this->waitForAttribute($databaseId, $notesId, 'note');
        }

        foreach (['n1', 'n2'] as $noteId) {
            $this->createSeedRecord($databaseId, $notesId, $noteId, ['note' => 'original'], [Permission::read(Role::any())]);
        }

        return $notesId;
    }

    private function assertBulkNotesUntouched(string $databaseId, string $notesId): void
    {
        $listed = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $notesId), $this->apiKeyHeaders(), [
            'queries' => [Query::limit(100)->toString()],
        ]);

        $this->assertSame(200, $listed['headers']['status-code']);
        $notes = \array_column($listed['body'][$this->getRecordResource()], 'note', '$id');
        \ksort($notes);
        $this->assertSame(['n1' => 'original', 'n2' => 'original'], $notes);
    }

    protected function createProbeContainer(string $databaseId): string
    {
        $probesId = $this->createSeedContainer($databaseId, 'probes' . ID::unique(), false, [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
        ]);
        $this->createSeedRecord($databaseId, $probesId, 'probe', new \stdClass(), [Permission::read(Role::any())]);

        return $probesId;
    }

    /**
     * @param list<string> $permissions
     */
    private function createSeedContainer(string $databaseId, string $name, bool $documentSecurity, array $permissions): string
    {
        $created = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $this->apiKeyHeaders(), [
            $this->getContainerIdParam() => ID::unique(),
            'name' => $name,
            $this->getSecurityParam() => $documentSecurity,
            'permissions' => $permissions,
        ]);
        $this->assertSame(201, $created['headers']['status-code']);

        return $created['body']['$id'];
    }

    /**
     * @param array<string, mixed>|\stdClass $data
     * @param list<string> $permissions
     */
    private function createSeedRecord(string $databaseId, string $containerId, string $recordId, array|\stdClass $data, array $permissions): void
    {
        $created = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $containerId), $this->apiKeyHeaders(), [
            $this->getRecordIdParam() => $recordId,
            'data' => $data,
            'permissions' => $permissions,
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
    }

    /**
     * @return list<string>
     */
    private function seedPermissions(bool $hidden): array
    {
        return $hidden
            ? [Permission::read(Role::user(Seed::HIDDEN_USER))]
            : [Permission::read(Role::any())];
    }

    /**
     * @return array<string, string>
     */
    private function callerHeaders(): array
    {
        return \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());
    }

    /**
     * @return array<string, string>
     */
    private function apiKeyHeaders(): array
    {
        return [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
    }
}
