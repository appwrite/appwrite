<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\TablesDB;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Database;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;

final class RowsCustomServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testCreateAndListRelationshipsExceedingQueryLimit(): void
    {
        if (!$this->getSupportForRelationships()) {
            $this->expectNotToPerformAssertions();

            return;
        }

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $databaseId = ID::unique();
        $restaurants = ID::unique();
        $items = ID::unique();
        $restaurantId = ID::unique();
        $database = $this->client->call(Client::METHOD_POST, '/tablesdb', $headers, [
            'databaseId' => $databaseId,
            'name' => 'Relationship query limit',
        ]);
        $this->assertSame(201, $database['headers']['status-code']);

        try {
            foreach ([$restaurants, $items] as $tableId) {
                $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables', $headers, [
                    'tableId' => $tableId,
                    'name' => $tableId,
                ]);
                $this->assertSame(201, $table['headers']['status-code']);
            }

            $columnUrl = '/tablesdb/' . $databaseId . '/tables/' . $restaurants . '/columns';
            $relationship = $this->client->call(Client::METHOD_POST, $columnUrl . '/relationship', $headers, [
                'relatedTableId' => $items,
                'type' => Database::RELATION_MANY_TO_MANY,
                'twoWay' => true,
                'key' => 'items',
                'twoWayKey' => 'restaurants',
                'onDelete' => Database::RELATION_MUTATE_SET_NULL,
            ]);
            $this->assertSame(202, $relationship['headers']['status-code']);
            $this->assertEventually(function () use ($columnUrl, $headers) {
                $column = $this->client->call(Client::METHOD_GET, $columnUrl . '/items', $headers);
                $this->assertSame(200, $column['headers']['status-code']);
                $this->assertSame('available', $column['body']['status']);
            }, 30_000, 100);

            $ids = array_map(fn (int $index) => 'r' . $index, range(1, APP_DATABASE_QUERY_MAX_VALUES + 1));
            $row = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $databaseId . '/tables/' . $restaurants . '/rows', $headers, [
                'rowId' => $restaurantId,
                'data' => ['items' => array_map(fn (string $id) => ['$id' => $id], $ids)],
            ]);
            $this->assertSame(201, $row['headers']['status-code']);

            // Test for SUCCESS: a small page can expand more than 500 related rows.
            foreach ([
                ['/tablesdb/' . $databaseId . '/tables/' . $restaurants . '/rows', 'rows'],
                ['/databases/' . $databaseId . '/collections/' . $restaurants . '/documents', 'documents'],
            ] as [$url, $resource]) {
                $response = $this->client->call(Client::METHOD_GET, $url, $headers, [
                    'queries' => [
                        Query::select(['*', 'items.*'])->toString(),
                        Query::limit(25)->toString(),
                    ],
                ]);
                $this->assertSame(200, $response['headers']['status-code']);
                $this->assertSame(1, $response['body']['total']);
                $this->assertCount(1, $response['body'][$resource]);
                $this->assertSame($restaurantId, $response['body'][$resource][0]['$id']);
                $this->assertEqualsCanonicalizing($ids, array_column($response['body'][$resource][0]['items'], '$id'));

                // Test for FAILURE: explicit user queries keep the same value limit.
                $response = $this->client->call(Client::METHOD_GET, $url, $headers, [
                    'queries' => [Query::equal('$id', $ids)->toString()],
                ]);
                $this->assertSame(400, $response['headers']['status-code']);
                $this->assertSame(Exception::GENERAL_QUERY_INVALID, $response['body']['type']);
                $this->assertSame('Invalid query: Query on attribute has greater than ' . APP_DATABASE_QUERY_MAX_VALUES . ' values: $id', $response['body']['message']);
            }
        } finally {
            $response = $this->client->call(Client::METHOD_DELETE, '/tablesdb/' . $databaseId, $headers);
            $this->assertSame(204, $response['headers']['status-code']);
        }
    }
}
