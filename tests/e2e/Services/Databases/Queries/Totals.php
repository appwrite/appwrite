<?php

namespace Tests\E2E\Services\Databases\Queries;

use Tests\E2E\Client;
use Tests\E2E\Services\Databases\Queries\Oracle\Join;
use Tests\E2E\Services\Databases\Queries\Oracle\Pair;
use Tests\E2E\Services\Databases\Queries\Oracle\Summary;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;

trait Totals
{
    private const int TOTALS_PAGE_SIZE = 2;

    public function testTotalCountsGroups(): void
    {
        if (!$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support aggregation queries');
        }

        $fixture = $this->setupRestrictedOrdersFixture();
        $groups = \count(\array_unique($this->seedVisibleToCaller()->labels()));
        $queries = [
            Query::groupBy(['label'])->toString(),
            Query::count('*', 'rowCount')->toString(),
        ];

        $this->assertTotalMatchesRows($fixture->databaseId, $fixture->ordersId, $queries, $groups, 'Groups by label');

        $paged = [...$queries, Query::limit(1)->toString(), Query::offset(1)->toString()];

        $cached = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($fixture->databaseId, $fixture->ordersId), $this->callerHeaders(), [
            'queries' => $paged,
            'ttl' => 60,
        ]);
        $this->assertSame(200, $cached['headers']['status-code']);
        $this->assertSame($groups, $cached['body']['total'], 'Total of a cached grouped list');

        $posted = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($fixture->databaseId, $fixture->ordersId) . '/query', $this->callerHeaders(), [
            'queries' => $paged,
        ]);
        $this->assertSame(200, $posted['headers']['status-code']);
        $this->assertSame($groups, $posted['body']['total'], 'Total of a grouped query');
    }

    public function testTotalCountsDistinctRows(): void
    {
        if (!$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support aggregation queries');
        }

        $fixture = $this->setupRestrictedOrdersFixture();

        $this->assertTotalMatchesRows($fixture->databaseId, $fixture->ordersId, [
            Query::select(['label'])->toString(),
            Query::distinct()->toString(),
        ], \count(\array_unique($this->seedVisibleToCaller()->labels())), 'Distinct labels');
    }

    public function testTotalOfAnUngroupedAggregateIsOne(): void
    {
        if (!$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support aggregation queries');
        }

        $fixture = $this->setupRestrictedOrdersFixture();

        $this->assertTotalMatchesRows($fixture->databaseId, $fixture->ordersId, [
            Query::count('*', 'rowCount')->toString(),
            Query::sum('amount', 'amountSum')->toString(),
        ], 1, 'Ungrouped aggregate');
    }

    public function testTotalCountsLeftJoinGroups(): void
    {
        if (!$this->getSupportForJoins() || !$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support join or aggregation queries');
        }

        $fixture = $this->setupRestrictedOrdersFixture();
        $seed = $this->seedVisibleToCaller();

        $this->assertTotalMatchesRows($fixture->databaseId, $fixture->customersId, [
            Join::Left->query($fixture->ordersId, 'ord')->toString(),
            Query::groupBy(['ord.label'])->toString(),
            Query::count('*', 'rowCount')->toString(),
        ], \count(Summary::byLabel(Join::Left->pairs($seed->customers, $seed->orders))), 'Groups of the left join by label');
    }

    public function testTotalCountsFanOutJoinRows(): void
    {
        if (!$this->getSupportForJoins() || !$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support join or aggregation queries');
        }

        $fixture = $this->setupRestrictedOrdersFixture();
        $seed = $this->seedVisibleToCaller();
        $pairs = Join::Inner->pairs($seed->customers, $seed->orders);
        $join = Join::Inner->query($fixture->ordersId, 'ord')->toString();

        $this->assertGreaterThan(\count(\array_unique(\array_map(static fn (Pair $pair): string => $pair->customer->id, $pairs))), \count($pairs), 'The join fans a customer out to several orders');

        $rows = $this->assertTotalMatchesRows($fixture->databaseId, $fixture->customersId, [
            $join,
            Query::select(['name', 'ord.amount'])->toString(),
        ], \count($pairs), 'Rows of the fan-out join');
        $this->assertPairRows($pairs, $rows, 'Rows of the fan-out join');

        $names = \array_unique(\array_map(static fn (Pair $pair): string => $pair->customer->name, $pairs));
        $distinct = $this->assertTotalMatchesRows($fixture->databaseId, $fixture->customersId, [
            $join,
            Query::select(['name'])->toString(),
            Query::distinct()->toString(),
        ], \count($names), 'Distinct customers of the fan-out join');
        $this->assertSame($this->sortedStrings($names), $this->sortedStrings(\array_column($distinct, 'name')));
    }

    public function testTotalOfAnUnlimitedReadCountsPastTheDefaultPage(): void
    {
        if (!$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support aggregation queries');
        }

        $databaseId = $this->setupDatabase()['databaseId'];
        $labelsId = $this->createSeedContainer($databaseId, 'totalLabels' . Id::unique(), false, [
            Permission::read(Role::any()),
            Permission::create(Role::any()),
        ]);

        if ($this->getSupportForAttributes()) {
            $created = $this->createAttribute($databaseId, $labelsId, 'string', ['key' => 'label', 'size' => 32, 'required' => true]);
            $this->assertSame(202, $created['headers']['status-code']);
            $this->waitForAttribute($databaseId, $labelsId, 'label');
        }

        $labels = APP_LIMIT_LIST_DEFAULT + 3;
        $created = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $labelsId), $this->apiKeyHeaders(), [
            $this->getRecordResource() => \array_map(
                static fn (int $index): array => ['$id' => "label{$index}", 'label' => "label{$index}"],
                \range(1, $labels),
            ),
        ]);
        $this->assertSame(201, $created['headers']['status-code']);

        $reads = [
            'Groups by label' => [Query::groupBy(['label'])->toString(), Query::count('*', 'rowCount')->toString()],
            'Distinct labels' => [Query::select(['label'])->toString(), Query::distinct()->toString()],
        ];

        foreach ($reads as $message => $queries) {
            $unlimited = $this->queryRecords($databaseId, $labelsId, $queries);
            $this->assertSame(200, $unlimited['headers']['status-code'], $message);
            $this->assertCount(APP_LIMIT_LIST_DEFAULT, $unlimited['body'][$this->getRecordResource()], "{$message}: an unlimited read returns the default page");
            $this->assertSame($labels, $unlimited['body']['total'], "{$message}: total of a full default page");
        }
    }

    /**
     * @param list<string> $queries
     * @return list<array<string, mixed>> The rows of the whole read
     */
    private function assertTotalMatchesRows(string $databaseId, string $containerId, array $queries, int $expected, string $message): array
    {
        $whole = $this->queryRecords($databaseId, $containerId, [...$queries, Query::limit(100)->toString()]);
        $this->assertSame(200, $whole['headers']['status-code'], $message);
        $rows = $whole['body'][$this->getRecordResource()];
        $this->assertCount($expected, $rows, "{$message}: rows");
        $this->assertSame($expected, $whole['body']['total'], "{$message}: total of the whole read");

        $unlimited = $this->queryRecords($databaseId, $containerId, $queries);
        $this->assertSame(200, $unlimited['headers']['status-code'], $message);
        $this->assertCount(\min($expected, APP_LIMIT_LIST_DEFAULT), $unlimited['body'][$this->getRecordResource()], "{$message}: rows of the unlimited read");
        $this->assertSame($expected, $unlimited['body']['total'], "{$message}: total of the unlimited read");

        $paged = 0;
        for ($offset = 0; $offset < $expected + self::TOTALS_PAGE_SIZE; $offset += self::TOTALS_PAGE_SIZE) {
            $page = $this->queryRecords($databaseId, $containerId, [
                ...$queries,
                Query::limit(self::TOTALS_PAGE_SIZE)->toString(),
                Query::offset($offset)->toString(),
            ]);
            $this->assertSame(200, $page['headers']['status-code'], $message);
            $this->assertSame($expected, $page['body']['total'], "{$message}: total of the page at offset {$offset}");
            $paged += \count($page['body'][$this->getRecordResource()]);
        }
        $this->assertSame($expected, $paged, "{$message}: rows across pages");

        return $rows;
    }
}
