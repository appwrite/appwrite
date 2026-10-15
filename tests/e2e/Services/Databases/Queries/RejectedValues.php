<?php

namespace Tests\E2E\Services\Databases\Queries;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;
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

        $overflows = $this->getConsoleVariables()['_APP_DB_ADAPTER'] === 'postgresql';

        foreach ([Query::sum('score', 'total'), Query::stddev('score', 'spread')] as $aggregate) {
            $response = $this->listRejectedValues($databaseId, $collectionId, [$aggregate->toString()]);
            $method = $aggregate->getMethod()->value;

            $this->assertContains($response['headers']['status-code'], [200, 400], "{$method}: " . ($response['body']['message'] ?? ''));
            if ($overflows) {
                $this->assertSame(400, $response['headers']['status-code'], "{$method} overflows a double on this database");
            }
            if ($response['headers']['status-code'] === 400) {
                $this->assertSame(Exception::DATABASE_QUERY_OUT_OF_RANGE, $response['body']['type'], $method);
            }
        }
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
    private function createRejectedValuesRecord(string $databaseId, string $collectionId, array $data): void
    {
        $record = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $collectionId), $this->getRejectedValuesKeyHeaders(), [
            $this->getRecordIdParam() => Id::unique(),
            'data' => $data,
        ]);
        $this->assertSame(201, $record['headers']['status-code'], $record['body']['message'] ?? '');
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
