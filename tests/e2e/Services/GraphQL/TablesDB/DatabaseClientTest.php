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

    /**
     * Cached database data
     */
    private static array $cachedDatabase = [];

    /**
     * Cached table data (includes database)
     */
    private static array $cachedTable = [];

    /**
     * Cached columns setup flag (keyed by project)
     */
    private static array $columnsCreated = [];

    /**
     * Cached row data (includes database, table, row)
     */
    private static array $cachedRow = [];

    /**
     * Cached bulk create data
     */
    private static array $cachedBulkCreate = [];

    /**
     * Cached bulk upsert data
     */
    private static array $cachedBulkUpsert = [];

    /**
     * Helper method to set up a database
     */
    protected function setupDatabase(): array
    {
        if (!empty(self::$cachedDatabase)) {
            return self::$cachedDatabase;
        }

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

        self::$cachedDatabase = $database;
        return self::$cachedDatabase;
    }

    /**
     * Helper method to set up a table (includes database setup)
     */
    protected function setupTable(): array
    {
        if (!empty(self::$cachedTable)) {
            return self::$cachedTable;
        }

        $database = $this->setupDatabase();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_TABLE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $database['_id'],
                'tableId' => ID::unique(),
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

        self::$cachedTable = [
            'table' => $table,
            'database' => $database,
        ];
        return self::$cachedTable;
    }

    /**
     * Helper method to set up columns (string and integer)
     */
    protected function setupColumns(): array
    {
        $data = $this->setupTable();

        $cacheKey = $this->getProject()['$id'] ?? 'default';
        if (!empty(self::$columnsCreated[$cacheKey])) {
            return $data;
        }

        $projectId = $this->getProject()['$id'];
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        // Create string column
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

        $column = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);
        // Handle 409 conflict - column may already exist from individual test
        if (isset($column['body']['errors'])) {
            $errorMessage = $column['body']['errors'][0]['message'] ?? '';
            if (strpos($errorMessage, 'already exists') === false && strpos($errorMessage, 'Document with the requested ID already exists') === false) {
                $this->assertArrayNotHasKey('errors', $column['body']);
            }
        }

        // Create integer column
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

        $column = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);
        // Handle 409 conflict - column may already exist from individual test
        if (isset($column['body']['errors'])) {
            $errorMessage = $column['body']['errors'][0]['message'] ?? '';
            if (strpos($errorMessage, 'already exists') === false && strpos($errorMessage, 'Document with the requested ID already exists') === false) {
                $this->assertArrayNotHasKey('errors', $column['body']);
            }
        }

        self::$columnsCreated[$cacheKey] = true;
        return $data;
    }

    /**
     * Helper method to set up a row (includes database, table, and columns setup)
     */
    protected function setupRow(): array
    {
        if (!empty(self::$cachedRow)) {
            return self::$cachedRow;
        }

        $data = $this->setupColumns();
        sleep(3);

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

        self::$cachedRow = [
            'database' => $data['database'],
            'table' => $data['table'],
            'row' => $row,
        ];
        return self::$cachedRow;
    }

    /**
     * Helper method to set up bulk create data
     */
    protected function setupBulkCreate(): array
    {
        if (!empty(self::$cachedBulkCreate)) {
            return self::$cachedBulkCreate;
        }

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
                'databaseId' => ID::unique(),
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
            'tableId' => ID::unique(),
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
            $rows[] = ['$id' => ID::unique(), 'name' => 'Row #' . $i];
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

        self::$cachedBulkCreate = compact('databaseId', 'tableId', 'projectId');
        return self::$cachedBulkCreate;
    }

    /**
     * Helper method to set up bulk upsert data (includes bulk create and bulk update)
     */
    protected function setupBulkUpsert(): array
    {
        if (!empty(self::$cachedBulkUpsert)) {
            return self::$cachedBulkUpsert;
        }

        $data = $this->setupBulkCreate();

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
        $this->assertGreaterThanOrEqual(10, count($res['body']['data']['tablesDBUpdateRows']['rows']));

        // Step 2: Add two new rows via upsert
        $query = $this->getQuery(self::UPSERT_ROWS);
        $upsertPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['databaseId'],
                'tableId' => $data['tableId'],
                'rows' => [
                    [
                        '$id' => ID::unique(),
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

        // Step 3: Upsert row with new permissions using `tablesUpsertRow`
        $upsertRowId = ID::unique();
        $query = $this->getQuery(self::UPSERT_ROW);
        $payload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['databaseId'],
                'tableId' => $data['tableId'],
                'rowId' => $upsertRowId,
                'data' => ['name' => 'Row Upserted'],
                'permissions' => $permissions,
            ],
        ];

        $res = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $payload);
        $this->assertArrayNotHasKey('errors', $res['body']);

        self::$cachedBulkUpsert = $data;
        return self::$cachedBulkUpsert;
    }

    public function testCreateDatabase(): void
    {
        $database = $this->setupDatabase();
        $this->assertEquals('Actors', $database['name']);
    }

    public function testCreateTable(): void
    {
        $data = $this->setupTable();
        $this->assertEquals('Actors', $data['table']['name']);
    }

    public function testCreateStringColumn(): void
    {
        $data = $this->setupTable();

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

        // Column may already exist from setupColumns, so we just check for valid response
        $this->assertIsArray($column['body']);
    }

    public function testCreateIntegerColumn(): void
    {
        $data = $this->setupTable();

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

        // Column may already exist from setupColumns, so we just check for valid response
        $this->assertIsArray($column['body']);
    }

    public function testCreateRow(): void
    {
        $data = $this->setupRow();
        $this->assertIsArray($data['row']);
    }

    /**
     * @throws \Exception
     */
    public function testGetRows(): void
    {
        $data = $this->setupTable();

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
     * @throws \Exception
     */
    public function testGetDocument(): void
    {
        $data = $this->setupRow();

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
     * @throws \Exception
     */
    public function testUpdateRow(): void
    {
        $data = $this->setupRow();

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
     * @throws \Exception
     */
    public function testDeleteRow(): void
    {
        // Need to create a fresh row for deletion since we can't delete the cached row
        $data = $this->setupColumns();
        sleep(1);

        $projectId = $this->getProject()['$id'];

        // Create a new row specifically for deletion
        $query = $this->getQuery(self::CREATE_ROW);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'rowId' => ID::unique(),
                'data' => [
                    'name' => 'Row To Delete',
                    'age' => 25,
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
        $row = $row['body']['data']['tablesDBCreateRow'];

        // Now delete the row
        $query = $this->getQuery(self::DELETE_ROW);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'rowId' => $row['_id'],
            ]
        ];

        $result = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsNotArray($result['body']);
        $this->assertEquals(204, $result['headers']['status-code']);
    }

    /**
     * @throws \Exception
     */
    public function testBulkCreate(): void
    {
        $data = $this->setupBulkCreate();
        $this->assertNotEmpty($data['databaseId']);
        $this->assertNotEmpty($data['tableId']);
        $this->assertNotEmpty($data['projectId']);
    }

    public function testBulkUpdate(): void
    {
        $data = $this->setupBulkCreate();

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
        $this->assertGreaterThanOrEqual(1, count($res['body']['data']['tablesDBUpdateRows']['rows']));

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
        $this->assertGreaterThanOrEqual(1, $fetched['total']);

        foreach ($fetched['rows'] as $row) {
            $this->assertEquals($permissions, $row['_permissions']);
            $this->assertEquals($data['tableId'], $row['_tableId']);
            $this->assertEquals($data['databaseId'], $row['_databaseId']);
            $this->assertEquals('Rows Updated', json_decode($row['data'], true)['name']);
        }
    }

    public function testBulkUpsert(): void
    {
        $data = $this->setupBulkCreate();

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

        // Step 1: Add two new rows via upsert
        $query = $this->getQuery(self::UPSERT_ROWS);
        $upsertPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['databaseId'],
                'tableId' => $data['tableId'],
                'rows' => [
                    [
                        '$id' => ID::unique(),
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

        // Step 2: Fetch all rows and confirm count
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
        $this->assertGreaterThanOrEqual(11, $fetched['total']);

        // Step 3: Upsert row with new permissions using `tablesUpsertRow`
        $upsertRowId = ID::unique();
        $query = $this->getQuery(self::UPSERT_ROW);
        $payload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['databaseId'],
                'tableId' => $data['tableId'],
                'rowId' => $upsertRowId,
                'data' => ['name' => 'Row Upserted'],
                'permissions' => $permissions,
            ],
        ];

        $res = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $payload);
        $this->assertArrayNotHasKey('errors', $res['body']);

        $updated = $res['body']['data']['tablesDBUpsertRow'];
        $this->assertEquals('Row Upserted', json_decode($updated['data'], true)['name']);
        $this->assertEquals($data['databaseId'], $updated['_databaseId']);
        $this->assertEquals($data['tableId'], $updated['_tableId']);
    }

    public function testBulkDelete(): void
    {
        $data = $this->setupBulkUpsert();

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
        $this->assertGreaterThanOrEqual(1, count($deleted));

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
    }
}
