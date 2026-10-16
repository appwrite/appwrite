<?php

namespace Tests\E2E\Services\Databases\Queries;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;

trait RejectedValues
{
    public function testMalformedGeometryIsRejected(): void
    {
        if (!$this->getSupportForSpatials()) {
            $this->markTestSkipped('Spatial attributes are not supported by this database adapter');
        }

        $databaseId = $this->setupDatabase()['databaseId'];
        $collectionId = $this->createRejectedValuesCollection($databaseId, 'malformedGeometry');

        $created = $this->createAttribute($databaseId, $collectionId, 'point', [
            'key' => 'location',
            'required' => true,
        ]);
        $this->assertSame(202, $created['headers']['status-code'], $created['body']['message'] ?? '');
        $this->waitForAttribute($databaseId, $collectionId, 'location');

        $this->createRejectedValuesRecord($databaseId, $collectionId, ['location' => [10.0, 10.0]]);

        $geometries = [
            'point missing its y' => '{"method":"intersects","attribute":"location","values":[[[1]]]}',
            'polygon point missing its y' => '{"method":"intersects","attribute":"location","values":[[[[0,0],[1],[1,1],[0,0]]]]}',
            'non-numeric coordinates' => '{"method":"intersects","attribute":"location","values":[["east","north"]]}',
            'distance from a point missing its y' => '{"method":"distanceLessThan","attribute":"location","values":[[[1],10,false]]}',
            'distance with a non-boolean meters flag' => '{"method":"distanceLessThan","attribute":"location","values":[[[1,1],10,[true]]]}',
        ];

        foreach ($geometries as $name => $query) {
            $response = $this->listRejectedValues($databaseId, $collectionId, [$query]);

            $this->assertSame(400, $response['headers']['status-code'], "{$name}: " . ($response['body']['message'] ?? ''));
            $this->assertSame(Exception::GENERAL_QUERY_INVALID, $response['body']['type'], $name);
        }

        $matched = $this->listRejectedValues($databaseId, $collectionId, [
            Query::intersects('location', [[[0.0, 0.0], [20.0, 0.0], [20.0, 20.0], [0.0, 20.0], [0.0, 0.0]]])->toString(),
        ]);
        $this->assertSame(200, $matched['headers']['status-code'], $matched['body']['message'] ?? '');
        $this->assertSame(1, $matched['body']['total']);
    }

    public function testAggregateOverflowIsRejected(): void
    {
        if (!$this->getSupportForAggregations()) {
            $this->markTestSkipped('Adapter does not support aggregation queries');
        }

        $databaseId = $this->setupDatabase()['databaseId'];
        $collectionId = $this->createRejectedValuesCollection($databaseId, 'aggregateOverflow');

        $created = $this->createAttribute($databaseId, $collectionId, 'float', [
            'key' => 'score',
            'required' => true,
        ]);
        $this->assertSame(202, $created['headers']['status-code'], $created['body']['message'] ?? '');
        $this->waitForAttribute($databaseId, $collectionId, 'score');

        $this->createRejectedValuesRecord($databaseId, $collectionId, ['score' => 1e308]);
        $this->createRejectedValuesRecord($databaseId, $collectionId, ['score' => 1e308]);

        $sum = $this->listRejectedValues($databaseId, $collectionId, [Query::sum('score', 'total')->toString()]);
        $this->assertSame(400, $sum['headers']['status-code'], 'A sum past the range of a double: ' . ($sum['body']['message'] ?? ''));
        $this->assertSame(Exception::DATABASE_QUERY_OUT_OF_RANGE, $sum['body']['type']);

        $spread = $this->listRejectedValues($databaseId, $collectionId, [Query::stddev('score', 'spread')->toString()]);
        $this->assertContains($spread['headers']['status-code'], [200, 400], 'A deviation of two equal values is 0, or out of range where the engine squares them: ' . ($spread['body']['message'] ?? ''));
        if ($spread['headers']['status-code'] === 400) {
            $this->assertSame(Exception::DATABASE_QUERY_OUT_OF_RANGE, $spread['body']['type']);
        } else {
            $this->assertEqualsWithDelta(0.0, $spread['body'][$this->getRecordResource()][0]['spread'], 0.0001);
        }
    }

    public function testIntegerSumPastInt64IsRejected(): void
    {
        if (!$this->getSupportForAttributes()) {
            $this->markTestSkipped('Attributes are not supported by this database adapter');
        }

        $databaseId = $this->setupDatabase()['databaseId'];
        $collectionId = $this->createRejectedValuesCollection($databaseId, 'integerSumOverflow');
        $this->addRejectedValuesAttribute($databaseId, $collectionId, 'string', ['key' => 'series', 'size' => 16, 'required' => true]);
        $this->addRejectedValuesAttribute($databaseId, $collectionId, 'bigint', ['key' => 'amount', 'required' => true]);

        foreach ([['above', PHP_INT_MAX], ['above', PHP_INT_MAX], ['top', PHP_INT_MAX - 1], ['top', 1]] as [$series, $amount]) {
            $this->createRejectedValuesRecord($databaseId, $collectionId, ['series' => $series, 'amount' => $amount]);
        }

        $above = $this->listRejectedValues($databaseId, $collectionId, [Query::equal('series', ['above'])->toString(), Query::sum('amount', 'total')->toString()]);
        $top = $this->listRejectedValues($databaseId, $collectionId, [Query::equal('series', ['top'])->toString(), Query::sum('amount', 'total')->toString()]);

        if (!$this->getSupportForAggregations()) {
            $this->assertQueryRejected($above, 'A sum where aggregations are unsupported');
            $this->assertQueryRejected($top, 'A sum where aggregations are unsupported');

            return;
        }

        $this->assertSame(400, $above['headers']['status-code'], 'A sum of two INT64_MAX: ' . \json_encode($above['body']));
        $this->assertSame(Exception::DATABASE_QUERY_OUT_OF_RANGE, $above['body']['type']);

        $this->assertSame(200, $top['headers']['status-code'], 'A sum of INT64_MAX - 1 and 1: ' . \json_encode($top['body']));
        $this->assertSame(PHP_INT_MAX, $top['body'][$this->getRecordResource()][0]['total']);
    }

    public function testPermissionsMinAndMaxAreRejected(): void
    {
        if (!$this->getSupportForAttributes()) {
            $this->markTestSkipped('Attributes are not supported by this database adapter');
        }

        $databaseId = $this->setupDatabase()['databaseId'];
        $collectionId = $this->createRejectedValuesCollection($databaseId, 'permissionsMinMax');
        $this->addRejectedValuesAttribute($databaseId, $collectionId, 'string', ['key' => 'label', 'size' => 16, 'required' => true]);
        $this->createRejectedValuesRecord($databaseId, $collectionId, ['label' => 'a']);

        foreach ([Query::max('$permissions', 'result'), Query::min('$permissions', 'result')] as $query) {
            $method = $query->getMethod()->value;
            $response = $this->listRejectedValues($databaseId, $collectionId, [$query->toString()]);

            $this->assertQueryRejected($response, "{$method}(\$permissions)");
            if ($this->getSupportForAggregations()) {
                $this->assertStringContainsString("Aggregate {$method} requires an attribute whose values are ordered", $response['body']['message']);
            }
        }
    }

    public function testOutOfRangeSpatialFilterIsRejected(): void
    {
        if (!$this->getSupportForSpatials()) {
            $this->markTestSkipped('Spatial attributes are not supported by this database adapter');
        }

        $databaseId = $this->setupDatabase()['databaseId'];
        $collectionId = $this->createRejectedValuesCollection($databaseId, 'outOfRangeSpatialFilter');
        $this->addRejectedValuesAttribute($databaseId, $collectionId, 'point', ['key' => 'pt', 'required' => true]);
        $this->createRejectedValuesRecord($databaseId, $collectionId, ['pt' => [10.0, 10.0]]);

        $filters = [
            'intersects a point at longitude 200 and latitude 100' => '{"method":"intersects","attribute":"pt","values":[[200,100]]}',
            'intersects a point at latitude -91' => '{"method":"intersects","attribute":"pt","values":[[0,-91]]}',
            'intersects a polygon reaching longitude 181' => '{"method":"intersects","attribute":"pt","values":[[[[0,0],[181,0],[181,10],[0,10],[0,0]]]]}',
            'distance from a point at latitude 100' => '{"method":"distanceLessThan","attribute":"pt","values":[[[0,100],10,false]]}',
        ];

        foreach ($filters as $name => $filter) {
            $this->assertQueryRejected($this->listRejectedValues($databaseId, $collectionId, [$filter]), $name);
        }

        $inRange = $this->listRejectedValues($databaseId, $collectionId, ['{"method":"intersects","attribute":"pt","values":[[10,10]]}']);
        $this->assertSame(200, $inRange['headers']['status-code'], \json_encode($inRange['body']) ?: '');
        $this->assertSame(1, $inRange['body']['total']);
    }

    public function testMalformedWktIsRejectedOnWrite(): void
    {
        if (!$this->getSupportForSpatials()) {
            $this->markTestSkipped('Spatial attributes are not supported by this database adapter');
        }

        $databaseId = $this->setupDatabase()['databaseId'];
        $collectionId = $this->createRejectedValuesCollection($databaseId, 'malformedWkt');
        $this->addRejectedValuesAttribute($databaseId, $collectionId, 'point', ['key' => 'pt', 'required' => false]);

        $created = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $collectionId), $this->getRejectedValuesKeyHeaders(), [
            $this->getRecordIdParam() => Id::unique(),
            'data' => ['pt' => 'POINT(1 2'],
        ]);
        $this->assertSame(400, $created['headers']['status-code'], 'Create with POINT(1 2: ' . \json_encode($created['body']));
        $this->assertSame($this->getStructureExceptionType(), $created['body']['type']);

        $recordId = $this->createRejectedValuesRecord($databaseId, $collectionId, ['pt' => [1.0, 2.0]]);

        $updated = $this->client->call(Client::METHOD_PATCH, $this->getRecordUrl($databaseId, $collectionId, $recordId), $this->getRejectedValuesKeyHeaders(), [
            'data' => ['pt' => 'POINT(1 2'],
        ]);
        $this->assertSame(400, $updated['headers']['status-code'], 'Update with POINT(1 2: ' . \json_encode($updated['body']));
        $this->assertSame($this->getStructureExceptionType(), $updated['body']['type']);

        $stored = $this->listRejectedValues($databaseId, $collectionId, []);
        $this->assertSame(200, $stored['headers']['status-code']);
        $this->assertSame(1, $stored['body']['total']);
        $this->assertEquals([1, 2], $stored['body'][$this->getRecordResource()][0]['pt']);
    }

    public function testInvalidRegexIsRejected(): void
    {
        if (!$this->getSupportForAttributes()) {
            $this->markTestSkipped('Attributes are not supported by this database adapter');
        }

        $databaseId = $this->setupDatabase()['databaseId'];
        $collectionId = $this->createRejectedValuesCollection($databaseId, 'invalidRegex');
        $this->addRejectedValuesAttribute($databaseId, $collectionId, 'string', ['key' => 's', 'size' => 32, 'required' => true]);
        $this->createRejectedValuesRecord($databaseId, $collectionId, ['s' => 'abc']);

        $invalid = $this->listRejectedValues($databaseId, $collectionId, [Query::regex('s', '((')->toString()]);
        $this->assertQueryRejected($invalid, 'regex((()');
        $this->assertStringContainsString('Invalid regular expression', $invalid['body']['message']);

        $valid = $this->listRejectedValues($databaseId, $collectionId, [Query::regex('s', '^ab')->toString()]);
        $this->assertSame(200, $valid['headers']['status-code'], \json_encode($valid['body']) ?: '');
        $this->assertSame(1, $valid['body']['total']);
    }

    public function testFilterValuesThatAreNotAListAreRejected(): void
    {
        if (!$this->getSupportForAttributes()) {
            $this->markTestSkipped('Attributes are not supported by this database adapter');
        }

        $databaseId = $this->setupDatabase()['databaseId'];
        $collectionId = $this->createRejectedValuesCollection($databaseId, 'filterValuesObject');
        $this->addRejectedValuesAttribute($databaseId, $collectionId, 'string', ['key' => 's', 'size' => 32, 'required' => true]);
        $this->createRejectedValuesRecord($databaseId, $collectionId, ['s' => 'abc']);

        foreach (['equal', 'notEqual', 'contains'] as $method) {
            $response = $this->listRejectedValues($databaseId, $collectionId, ['{"method":"' . $method . '","attribute":"s","values":{"a":1}}']);

            $this->assertQueryRejected($response, "{$method} with object values");
            $this->assertStringContainsString(\ucfirst($method) . ' query values must be a list', $response['body']['message']);
        }
    }

    public function testCursorAlongAnUncomparableOrderIsRejected(): void
    {
        if (!$this->getSupportForAttributes()) {
            $this->markTestSkipped('Attributes are not supported by this database adapter');
        }

        $spatial = $this->getSupportForSpatials();
        $databaseId = $this->setupDatabase()['databaseId'];
        $collectionId = $this->createRejectedValuesCollection($databaseId, 'uncomparableCursor');
        $this->addRejectedValuesAttribute($databaseId, $collectionId, 'string', ['key' => 'sa', 'size' => 16, 'required' => false, 'array' => true]);
        if ($spatial) {
            $this->addRejectedValuesAttribute($databaseId, $collectionId, 'point', ['key' => 'pt', 'required' => false]);
        }

        $recordIds = [];
        foreach ([[['a', 'b'], [1.0, 1.0]], [['c'], [2.0, 2.0]]] as [$strings, $point]) {
            $recordIds[] = $this->createRejectedValuesRecord($databaseId, $collectionId, $spatial ? ['sa' => $strings, 'pt' => $point] : ['sa' => $strings]);
        }

        foreach ($spatial ? ['sa', 'pt'] : ['sa'] as $attribute) {
            $ordered = $this->listRejectedValues($databaseId, $collectionId, [Query::orderAsc($attribute)->toString()]);
            $this->assertSame(200, $ordered['headers']['status-code'], "orderAsc({$attribute}): " . \json_encode($ordered['body']));
            $this->assertSame(2, $ordered['body']['total']);

            $paged = $this->listRejectedValues($databaseId, $collectionId, [
                Query::orderAsc($attribute)->toString(),
                Query::cursorAfter(new Document(['$id' => $recordIds[0]]))->toString(),
            ]);
            $this->assertQueryRejected($paged, "orderAsc({$attribute}) with cursorAfter");
        }
    }

    /**
     * @param array<string, mixed> $response
     */
    private function assertQueryRejected(array $response, string $name): void
    {
        $this->assertSame(400, $response['headers']['status-code'], "{$name}: " . \json_encode($response['body']));
        $this->assertSame(Exception::GENERAL_QUERY_INVALID, $response['body']['type'], $name);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function addRejectedValuesAttribute(string $databaseId, string $collectionId, string $type, array $payload): void
    {
        $created = $this->createAttribute($databaseId, $collectionId, $type, $payload);
        $this->assertSame(202, $created['headers']['status-code'], $created['body']['message'] ?? '');
        $this->waitForAttribute($databaseId, $collectionId, $payload['key']);
    }

    private function createRejectedValuesCollection(string $databaseId, string $name): string
    {
        $collection = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $this->getRejectedValuesKeyHeaders(), [
            $this->getContainerIdParam() => Id::unique(),
            'name' => $name,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $this->assertSame(201, $collection['headers']['status-code'], $collection['body']['message'] ?? '');

        return $collection['body']['$id'];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createRejectedValuesRecord(string $databaseId, string $collectionId, array $data): string
    {
        $record = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $collectionId), $this->getRejectedValuesKeyHeaders(), [
            $this->getRecordIdParam() => Id::unique(),
            'data' => $data,
        ]);
        $this->assertSame(201, $record['headers']['status-code'], $record['body']['message'] ?? '');

        return $record['body']['$id'];
    }

    /**
     * @param list<string> $queries
     * @return array<string, mixed>
     */
    private function listRejectedValues(string $databaseId, string $collectionId, array $queries): array
    {
        return $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $collectionId), \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => $queries,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function getRejectedValuesKeyHeaders(): array
    {
        return [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
    }
}
