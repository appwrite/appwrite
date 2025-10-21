<?php

namespace Tests\E2E\Services\GraphQL\TablesDB;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Services\GraphQL\Base;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

class DatabaseClientTest extends Scope
{
    use ProjectCustom;
    use SideClient;
    use Base;

    public function testCreateDatabase(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::TABLESDB_CREATE_DATABASE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => ID::unique(),
                'name' => 'Actors',
            ]
        ];

        $database = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $gqlPayload);

        $this->assertIsArray($database['body']['data']);
        $this->assertArrayNotHasKey('errors', $database['body']);
        $database = $database['body']['data']['tablesDBCreate'];
        $this->assertEquals('Actors', $database['name']);

        return $database;
    }

    /**
     * @depends testCreateDatabase
     */
    public function testCreateTable($database): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_TABLE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $database['_id'],
                'tableId' => 'actors',
                'name' => 'Actors',
                'rowSecurity' => false,
                'permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::users()),
                    Permission::update(Role::users()),
                    Permission::delete(Role::users()),
                ],
            ]
        ];

        $table = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $gqlPayload);

        $this->assertIsArray($table['body']['data']);
        $this->assertArrayNotHasKey('errors', $table['body']);
        $table = $table['body']['data']['tablesDBCreateTable'];
        $this->assertEquals('Actors', $table['name']);

        return [
            'table' => $table,
            'database' => $database,
        ];
    }

    /**
     * @depends testCreateTable
     */
    public function testCreateStringColumn($data): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_STRING_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'name',
                'size' => 256,
                'required' => true,
            ]
        ];

        $column = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $gqlPayload);

        $this->assertArrayNotHasKey('errors', $column['body']);
        $this->assertIsArray($column['body']['data']);
        $this->assertIsArray($column['body']['data']['tablesDBCreateStringColumn']);

        return $data;
    }

    /**
     * @depends testCreateTable
     */
    public function testCreateIntegerColumn($data): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_INTEGER_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'age',
                'min' => 18,
                'max' => 150,
                'required' => true,
            ]
        ];

        $column = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $gqlPayload);

        $this->assertArrayNotHasKey('errors', $column['body']);
        $this->assertIsArray($column['body']['data']);
        $this->assertIsArray($column['body']['data']['tablesDBCreateIntegerColumn']);

        return $data;
    }

    /**
     * @depends testCreateStringColumn
     * @depends testCreateIntegerColumn
     */
    public function testCreateRow($data): array
    {
        sleep(1);

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_ROW);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'rowId' => ID::unique(),
                'data' => [
                    'name' => 'John Doe',
                    'age' => 35,
                ],
                'permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
            ]
        ];

        $row = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $row['body']);
        $this->assertIsArray($row['body']['data']);

        $row = $row['body']['data']['tablesDBCreateRow'];
        $this->assertIsArray($row);

        return [
            'database' => $data['database'],
            'table' => $data['table'],
            'row' => $row,
        ];
    }

    /**
     * @depends testCreateTable
     * @throws \Exception
     */
    public function testGetRows($data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_ROWS);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
            ]
        ];

        $rows = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $rows['body']);
        $this->assertIsArray($rows['body']['data']);
        $this->assertIsArray($rows['body']['data']['tablesDBListRows']);
    }

    /**
     * @depends testCreateRow
     * @throws \Exception
     */
    public function testGetDocument($data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_ROW);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'rowId' => $data['row']['_id'],
            ]
        ];

        $row = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $row['body']);
        $this->assertIsArray($row['body']['data']);
        $this->assertIsArray($row['body']['data']['tablesDBGetRow']);
    }

    /**
     * @depends testCreateRow
     * @throws \Exception
     */
    public function testUpdateRow($data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::UPDATE_ROW);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'rowId' => $data['row']['_id'],
                'data' => [
                    'name' => 'New Row Name',
                ],
            ]
        ];

        $row = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $row['body']);
        $this->assertIsArray($row['body']['data']);
        $row = $row['body']['data']['tablesDBUpdateRow'];
        $this->assertIsArray($row);

        $this->assertStringContainsString('New Row Name', $row['data']);
    }

    /**
     * @depends testCreateRow
     * @throws \Exception
     */
    public function testDeleteRow($data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::DELETE_ROW);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'rowId' => $data['row']['_id'],
            ]
        ];

        $row = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsNotArray($row['body']);
        $this->assertEquals(204, $row['headers']['status-code']);
    }

    /**
     * @throws \Exception
     */
    public function testBulkCreate(): array
    {
        $project = $this->getProject();
        $projectId = $project['$id'];
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $project['apiKey'],
        ];

        // Step 1: Create database
        $query = $this->getQuery(self::TABLESDB_CREATE_DATABASE);
        $payload = [
            'query' => $query,
            'variables' => [
                'databaseId' => 'bulk',
                'name' => 'Bulk',
            ],
        ];

        $res = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $payload);
        $this->assertArrayNotHasKey('errors', $res['body']);
        $databaseId = $res['body']['data']['tablesDBCreate']['_id'];

        // Step 2: Create table
        $query = $this->getQuery(self::CREATE_TABLE);
        $payload['query'] = $query;
        $payload['variables'] = [
            'databaseId' => $databaseId,
            'tableId' => 'operations',
            'name' => 'Operations',
            'rowSecurity' => false,
            'permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ];

        $res = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $payload);
        $this->assertArrayNotHasKey('errors', $res['body']);
        $tableId = $res['body']['data']['tablesDBCreateTable']['_id'];

        // Step 3: Create column
        $query = $this->getQuery(self::CREATE_STRING_COLUMN);
        $payload['query'] = $query;
        $payload['variables'] = [
            'databaseId' => $databaseId,
            'tableId' => $tableId,
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ];

        $res = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $payload);
        $this->assertArrayNotHasKey('errors', $res['body']);
        sleep(1);

        // Step 4: Create rows
        $query = $this->getQuery(self::CREATE_ROWS);
        $rows = [];
        for ($i = 1; $i <= 10; $i++) {
            $rows[] = ['$id' => 'row' . $i, 'name' => 'Row #' . $i];
        }

        $payload['query'] = $query;
        $payload['variables'] = [
            'databaseId' => $databaseId,
            'tableId' => $tableId,
            'rows' => $rows,
        ];

        $res = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $payload);
        $this->assertArrayNotHasKey('errors', $res['body']);
        $this->assertCount(10, $res['body']['data']['tablesDBCreateRows']['rows']);

        return compact('databaseId', 'tableId', 'projectId');
    }

    /**
     * @depends testBulkCreate
     */
    public function testBulkUpdate(array $data): array
    {
        $userId = $this->getUser()['$id'];
        $permissions = [
            Permission::read(Role::user($userId)),
            Permission::update(Role::user($userId)),
            Permission::delete(Role::user($userId)),
        ];

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        // Step 1: Bulk update rows
        $query = $this->getQuery(self::UPDATE_ROWS);
        $payload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['databaseId'],
                'tableId' => $data['tableId'],
                'data' => [
                    'name' => 'Rows Updated',
                    '$permissions' => $permissions,
                ],
            ],
        ];

        $res = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $payload);
        $this->assertArrayNotHasKey('errors', $res['body']);
        $this->assertCount(10, $res['body']['data']['tablesDBUpdateRows']['rows']);

        // Step 2: Fetch and validate updated rows
        $query = $this->getQuery(self::GET_ROWS);
        $payload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['databaseId'],
                'tableId' => $data['tableId'],
                'queries' => [Query::equal('name', ['Rows Updated'])->toString()],
            ],
        ];

        $res = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $payload);
        $this->assertEquals(200, $res['headers']['status-code']);

        $fetched = $res['body']['data']['tablesDBListRows'];
        $this->assertEquals(10, $fetched['total']);

        foreach ($fetched['rows'] as $row) {
            $this->assertEquals($permissions, $row['_permissions']);
            $this->assertEquals($data['tableId'], $row['_tableId']);
            $this->assertEquals($data['databaseId'], $row['_databaseId']);
            $this->assertEquals('Rows Updated', json_decode($row['data'], true)['name']);
        }

        return $data;
    }

    /**
     * @depends testBulkCreate
     */
    public function testBulkUpsert(array $data): array
    {
        $userId = $this->getUser()['$id'];
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        $permissions = [
            Permission::read(Role::user($userId)),
            Permission::update(Role::user($userId)),
            Permission::delete(Role::user($userId)),
        ];

        // Step 1: Mutate row 10 and add row 11
        $query = $this->getQuery(self::UPSERT_ROWS);
        $upsertPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['databaseId'],
                'tableId' => $data['tableId'],
                'rows' => [
                    [
                        '$id' => 'row10',
                        'name' => 'Row #1000',
                    ],
                    [
                        'name' => 'Row #11',
                    ],
                ],
            ],
        ];

        $response = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $upsertPayload);
        $this->assertArrayNotHasKey('errors', $response['body']);

        $rows = $response['body']['data']['tablesDBUpsertRows']['rows'];
        $this->assertCount(2, $rows);

        $rowMap = [];
        foreach ($rows as $row) {
            $decoded = json_decode($row['data'], true);
            $rowMap[$decoded['name']] = $decoded;
        }

        $this->assertArrayHasKey('Row #1000', $rowMap);
        $this->assertArrayHasKey('Row #11', $rowMap);

        // Step 2: Fetch all rows and confirm count is now 11
        $query = $this->getQuery(self::GET_ROWS);
        $fetchPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['databaseId'],
                'tableId' => $data['tableId'],
            ],
        ];

        $res = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $fetchPayload);
        $this->assertEquals(200, $res['headers']['status-code']);

        $fetched = $res['body']['data']['tablesDBListRows'];
        $this->assertEquals(11, $fetched['total']);

        // Step 3: Upsert row with new permissions using `tablesUpsertRow`
        $query = $this->getQuery(self::UPSERT_ROW);
        $payload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['databaseId'],
                'tableId' => $data['tableId'],
                'rowId' => 'row10',
                'data' => ['name' => 'Row #10 Patched'],
                'permissions' => $permissions,
            ],
        ];

        $res = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $payload);
        $this->assertArrayNotHasKey('errors', $res['body']);

        $updated = $res['body']['data']['tablesDBUpsertRow'];
        $this->assertEquals('Row #10 Patched', json_decode($updated['data'], true)['name']);
        $this->assertEquals($data['databaseId'], $updated['_databaseId']);
        $this->assertEquals($data['tableId'], $updated['_tableId']);

        return $data;
    }

    /**
     * @depends testBulkUpsert
     */
    public function testBulkDelete(array $data): array
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        // Step 1: Perform bulk delete
        $query = $this->getQuery(self::DELETE_ROWS);
        $payload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['databaseId'],
                'tableId' => $data['tableId'],
            ],
        ];

        $res = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $payload);
        $this->assertArrayNotHasKey('errors', $res['body']);

        $deleted = $res['body']['data']['tablesDBDeleteRows']['rows'];
        $this->assertIsArray($deleted);
        $this->assertCount(11, $deleted);

        // Step 2: Confirm deletion via refetch
        $query = $this->getQuery(self::GET_ROWS);
        $payload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['databaseId'],
                'tableId' => $data['tableId'],
            ],
        ];

        $res = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $payload);
        $this->assertEquals(200, $res['headers']['status-code']);
        $this->assertEquals(0, $res['body']['data']['tablesDBListRows']['total']);

        return $data;
    }
}
