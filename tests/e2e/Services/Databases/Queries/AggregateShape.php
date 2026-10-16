<?php

namespace Tests\E2E\Services\Databases\Queries;

use Tests\E2E\Client;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;

trait AggregateShape
{
    /**
     * @var array<string, array{databaseId: string, metricsId: string, groupsId: string}>
     */
    private static array $aggregateShapeFixtures = [];

    private const array STORED_KEYS = ['$id', '$sequence', '$createdAt', '$updatedAt', '$permissions', '$databaseId', '$tableId', '$collectionId'];

    public function testAggregateValuesAreTypedByMethodAndAttribute(): void
    {
        if (!$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support aggregation queries');
        }

        $fixture = $this->setupAggregateShapeFixture();

        $rows = $this->listAggregateShape($fixture['databaseId'], $fixture['metricsId'], [
            Query::count('*', 'rows')->toString(),
            Query::countDistinct('grp', 'groups')->toString(),
            Query::sum('n', 'total')->toString(),
            Query::sum('f', 'fractions')->toString(),
            Query::avg('n', 'mean')->toString(),
            Query::variance('n', 'spread')->toString(),
            Query::stddev('n', 'deviation')->toString(),
            Query::min('n', 'least')->toString(),
            Query::max('f', 'most')->toString(),
            Query::min('at', 'earliest')->toString(),
        ]);

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame(['deviation', 'earliest', 'fractions', 'groups', 'least', 'mean', 'most', 'rows', 'spread', 'total'], $this->sortedKeys($row));
        $this->assertSame(3, $row['rows']);
        $this->assertSame(2, $row['groups']);
        $this->assertSame(7, $row['total']);
        $this->assertSame(7.5, $row['fractions']);
        $this->assertIsFloat($row['mean']);
        $this->assertEqualsWithDelta(7 / 3, $row['mean'], 0.0001);
        $this->assertIsFloat($row['spread']);
        $this->assertIsFloat($row['deviation']);
        $this->assertSame(1, $row['least']);
        $this->assertSame(3.5, $row['most']);
        $this->assertSame('2023-06-01T00:00:00.000+00:00', $row['earliest']);
    }

    public function testGroupedValuesAreTypedAsRowValues(): void
    {
        if (!$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support aggregation queries');
        }

        $fixture = $this->setupAggregateShapeFixture();

        $rows = $this->listAggregateShape($fixture['databaseId'], $fixture['metricsId'], [
            Query::count('*', 'rows')->toString(),
            Query::groupBy(['n', 'f', 'flag', 'tags', 'at'])->toString(),
        ]);

        $this->assertCount(3, $rows);
        $byCount = [];
        foreach ($rows as $row) {
            $this->assertSame(['at', 'f', 'flag', 'n', 'rows', 'tags'], $this->sortedKeys($row));
            $this->assertSame(1, $row['rows']);
            $byCount[$row['n']] = $row;
        }
        \ksort($byCount);

        $this->assertSame([1, 2, 4], \array_keys($byCount));
        $this->assertSame([1.5, true, ['p', 'q'], '2023-06-01T00:00:00.000+00:00'], [$byCount[1]['f'], $byCount[1]['flag'], $byCount[1]['tags'], $byCount[1]['at']]);
        $this->assertSame([2.5, false, ['p'], '2023-06-02T00:00:00.000+00:00'], [$byCount[2]['f'], $byCount[2]['flag'], $byCount[2]['tags'], $byCount[2]['at']]);
        $this->assertSame([3.5, true, [], '2023-06-01T00:00:00.000+00:00'], [$byCount[4]['f'], $byCount[4]['flag'], $byCount[4]['tags'], $byCount[4]['at']]);
    }

    public function testGroupedSystemAttributesKeepTheirPublicNames(): void
    {
        if (!$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support aggregation queries');
        }

        $fixture = $this->setupAggregateShapeFixture();

        $stored = [];
        foreach ($this->listAggregateShape($fixture['databaseId'], $fixture['metricsId'], []) as $record) {
            $stored[$record['$id']] = $record;
        }
        $this->assertCount(3, $stored);

        $rows = $this->listAggregateShape($fixture['databaseId'], $fixture['metricsId'], [
            Query::count('*', 'rows')->toString(),
            Query::groupBy(['$id', '$sequence', '$createdAt', '$updatedAt', '$permissions'])->toString(),
        ]);

        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame(['$createdAt', '$id', '$permissions', '$sequence', '$updatedAt', 'rows'], $this->sortedKeys($row));
            $this->assertArrayHasKey($row['$id'], $stored);
            $record = $stored[$row['$id']];
            $this->assertSame($record['$sequence'], $row['$sequence']);
            $this->assertSame($record['$createdAt'], $row['$createdAt']);
            $this->assertSame($record['$updatedAt'], $row['$updatedAt']);
            $this->assertSame($this->sortedValues($record['$permissions']), $this->sortedValues($row['$permissions']));
        }
    }

    public function testGroupedJoinedAttributesAreQualifiedByTheirAlias(): void
    {
        if (!$this->getSupportForJoins() || !$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support join or aggregation queries');
        }

        $fixture = $this->setupAggregateShapeFixture();
        $join = Query::join($fixture['groupsId'], 'o', [Query::on('otherId', '$id')])->toString();

        $alone = $this->listAggregateShape($fixture['databaseId'], $fixture['metricsId'], [
            Query::count('*', 'rows')->toString(),
            $join,
            Query::groupBy(['o.grp'])->toString(),
        ]);

        $this->assertCount(2, $alone);
        $counts = [];
        foreach ($alone as $row) {
            $this->assertSame(['o.grp', 'rows'], $this->sortedKeys($row));
            $counts[$row['o.grp']] = $row['rows'];
        }
        \ksort($counts);
        $this->assertSame(['og1' => 2, 'og2' => 1], $counts);

        $beside = $this->listAggregateShape($fixture['databaseId'], $fixture['metricsId'], [
            Query::count('*', 'rows')->toString(),
            $join,
            Query::groupBy(['grp', 'o.grp'])->toString(),
        ]);

        $this->assertCount(3, $beside);
        $pairs = [];
        foreach ($beside as $row) {
            $this->assertSame(['grp', 'o.grp', 'rows'], $this->sortedKeys($row));
            $pairs[] = $row['grp'] . ':' . $row['o.grp'];
        }
        \sort($pairs);
        $this->assertSame(['a:og1', 'a:og2', 'b:og1'], $pairs);

        $identified = $this->listAggregateShape($fixture['databaseId'], $fixture['metricsId'], [
            Query::sum('o.rank', 'ranks')->toString(),
            $join,
            Query::groupBy(['o.$id'])->toString(),
        ]);

        $this->assertCount(2, $identified);
        $ranks = [];
        foreach ($identified as $row) {
            $this->assertSame(['o.$id', 'ranks'], $this->sortedKeys($row));
            $ranks[$row['o.$id']] = $row['ranks'];
        }
        \ksort($ranks);
        $this->assertSame(['g1' => 6, 'g2' => 4], $ranks);
    }

    public function testDistinctRowsCarryOnlyTheSelectedAttributes(): void
    {
        if (!$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support aggregation queries');
        }

        $fixture = $this->setupAggregateShapeFixture();

        $rows = $this->listAggregateShape($fixture['databaseId'], $fixture['metricsId'], [
            Query::select(['grp'])->toString(),
            Query::distinct()->toString(),
        ]);

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(['grp'], \array_keys($row));
        }
        $this->assertSame(['a', 'b'], $this->sortedValues(\array_column($rows, 'grp')));
    }

    public function testCachedDistinctRowsCarryOnlyTheSelectedAttributes(): void
    {
        if (!$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support aggregation queries');
        }

        $fixture = $this->setupAggregateShapeFixture();

        $selections = [
            'grp' => [['grp'], 2],
            '$id and grp' => [['$id', 'grp'], 3],
        ];

        foreach ($selections as $name => [$selected, $count]) {
            $queries = [
                Query::distinct()->toString(),
                Query::select($selected)->toString(),
            ];

            foreach (['miss', 'hit'] as $cache) {
                $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($fixture['databaseId'], $fixture['metricsId']), array_merge([
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                ], $this->getHeaders()), [
                    'queries' => $queries,
                    'ttl' => 60,
                ]);

                $this->assertSame(200, $result['headers']['status-code'], "{$name} ({$cache}): " . \json_encode($result['body']));
                $this->assertSame($cache, $result['headers']['x-appwrite-cache'] ?? null, $name);

                $rows = $result['body'][$this->getRecordResource()];
                $this->assertCount($count, $rows, "{$name} ({$cache})");
                $expected = $selected;
                \sort($expected);
                foreach ($rows as $row) {
                    $this->assertSame($expected, $this->sortedKeys($row), "{$name} ({$cache})");
                }
                $this->assertSame(['a', 'b'], \array_values(\array_unique($this->sortedValues(\array_column($rows, 'grp')))), "{$name} ({$cache})");
                if (\in_array('$id', $selected, true)) {
                    $this->assertSame(['m1', 'm2', 'm3'], $this->sortedValues(\array_column($rows, '$id')), "{$name} ({$cache})");
                }
            }
        }
    }

    public function testDistinctWithValuesIsRejected(): void
    {
        if (!$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support aggregation queries');
        }

        $fixture = $this->setupAggregateShapeFixture();

        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($fixture['databaseId'], $fixture['metricsId']), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => ['{"method":"distinct","values":["grp"]}'],
        ]);

        $this->assertSame(400, $result['headers']['status-code']);
        $this->assertSame('general_query_invalid', $result['body']['type']);
    }

    public function testUnmatchedRightJoinRowCarriesNoStoredAttributes(): void
    {
        if (!$this->getSupportForJoins()) {
            $this->markTestSkipped('Adapter does not support join queries');
        }

        $fixture = $this->setupAggregateShapeFixture();

        $rows = $this->listAggregateShape($fixture['databaseId'], $fixture['metricsId'], [
            Query::rightJoin($fixture['groupsId'], 'o', [Query::on('otherId', '$id')])->toString(),
            Query::select(['grp', 'o.grp'])->toString(),
        ]);

        $this->assertCount(4, $rows);
        $unmatched = [];
        foreach ($rows as $row) {
            if ($row['grp'] === null) {
                $unmatched[] = $row;
                continue;
            }

            $this->assertIsString($row['$id']);
            $this->assertNotSame('', $row['$id']);
            $this->assertIsString($row['$sequence']);
            $this->assertNotSame('', $row['$createdAt']);
        }

        $this->assertCount(1, $unmatched);
        $this->assertSame('og3', $unmatched[0]['o.grp']);
        foreach (self::STORED_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $unmatched[0]);
        }
    }

    /**
     * @param list<string> $queries
     * @return list<array<string, mixed>>
     */
    private function listAggregateShape(string $databaseId, string $containerId, array $queries): array
    {
        $result = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $containerId), array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => $queries,
        ]);

        $this->assertSame(200, $result['headers']['status-code'], \json_encode($result['body']) ?: '');

        return $result['body'][$this->getRecordResource()];
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function sortedKeys(array $row): array
    {
        $keys = \array_map(\strval(...), \array_keys($row));
        \sort($keys);

        return $keys;
    }

    /**
     * @param array<mixed> $values
     * @return list<mixed>
     */
    private function sortedValues(array $values): array
    {
        \sort($values);

        return \array_values($values);
    }

    /**
     * @return array{databaseId: string, metricsId: string, groupsId: string}
     */
    private function setupAggregateShapeFixture(): array
    {
        $cacheKey = $this->getCacheKey();
        if (isset(self::$aggregateShapeFixtures[$cacheKey])) {
            return self::$aggregateShapeFixtures[$cacheKey];
        }

        $serverHeaders = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        $database = $this->client->call(Client::METHOD_POST, $this->getApiBasePath(), $serverHeaders, [
            'databaseId' => Id::unique(),
            'name' => 'Aggregate Shape Database',
        ]);
        $this->assertSame(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $containers = [];
        foreach (['metrics', 'groups'] as $name) {
            $container = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $serverHeaders, [
                $this->getContainerIdParam() => Id::unique(),
                'name' => $name,
                'permissions' => [
                    Permission::read(Role::any()),
                ],
                $this->getSecurityParam() => false,
            ]);
            $this->assertSame(201, $container['headers']['status-code']);
            $containers[$name] = $container['body']['$id'];
        }

        $attributes = [
            [$containers['metrics'], 'string', ['key' => 'grp', 'size' => 16, 'required' => false]],
            [$containers['metrics'], 'integer', ['key' => 'n', 'required' => false]],
            [$containers['metrics'], 'float', ['key' => 'f', 'required' => false]],
            [$containers['metrics'], 'boolean', ['key' => 'flag', 'required' => false]],
            [$containers['metrics'], 'string', ['key' => 'tags', 'size' => 16, 'required' => false, 'array' => true]],
            [$containers['metrics'], 'datetime', ['key' => 'at', 'required' => false]],
            [$containers['metrics'], 'string', ['key' => 'otherId', 'size' => 36, 'required' => false]],
            [$containers['groups'], 'string', ['key' => 'grp', 'size' => 16, 'required' => false]],
            [$containers['groups'], 'integer', ['key' => 'rank', 'required' => false]],
        ];
        foreach ($attributes as [$containerId, $type, $payload]) {
            $attribute = $this->createAttribute($databaseId, $containerId, $type, $payload);
            $this->assertSame(202, $attribute['headers']['status-code'], \json_encode($attribute['body']) ?: '');
        }
        foreach ($attributes as [$containerId, , $payload]) {
            $this->waitForAttribute($databaseId, $containerId, $payload['key']);
        }

        $records = [
            [$containers['groups'], 'g1', ['grp' => 'og1', 'rank' => 3]],
            [$containers['groups'], 'g2', ['grp' => 'og2', 'rank' => 4]],
            [$containers['groups'], 'g3', ['grp' => 'og3', 'rank' => 5]],
            [$containers['metrics'], 'm1', ['grp' => 'a', 'n' => 1, 'f' => 1.5, 'flag' => true, 'tags' => ['p', 'q'], 'at' => '2023-06-01T00:00:00.000+00:00', 'otherId' => 'g1']],
            [$containers['metrics'], 'm2', ['grp' => 'a', 'n' => 2, 'f' => 2.5, 'flag' => false, 'tags' => ['p'], 'at' => '2023-06-02T00:00:00.000+00:00', 'otherId' => 'g2']],
            [$containers['metrics'], 'm3', ['grp' => 'b', 'n' => 4, 'f' => 3.5, 'flag' => true, 'tags' => [], 'at' => '2023-06-01T00:00:00.000+00:00', 'otherId' => 'g1']],
        ];
        foreach ($records as [$containerId, $recordId, $data]) {
            $record = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $containerId), $serverHeaders, [
                $this->getRecordIdParam() => $recordId,
                'data' => $data,
            ]);
            $this->assertSame(201, $record['headers']['status-code'], \json_encode($record['body']) ?: '');
        }

        return self::$aggregateShapeFixtures[$cacheKey] = [
            'databaseId' => $databaseId,
            'metricsId' => $containers['metrics'],
            'groupsId' => $containers['groups'],
        ];
    }
}
