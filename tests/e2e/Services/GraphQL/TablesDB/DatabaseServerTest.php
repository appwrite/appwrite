<?php

namespace Tests\E2E\Services\GraphQL\TablesDB;

use Exception;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Services\GraphQL\Base;
use Utopia\Database\Database;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

class DatabaseServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use Base;

    private static array $cachedDatabase = [];
    private static array $cachedTableData = [];
    private static array $cachedStringColumnData = [];
    private static array $cachedIntegerColumnData = [];
    private static array $cachedBooleanColumnData = [];
    private static array $cachedFloatColumnData = [];
    private static array $cachedEmailColumnData = [];
    private static array $cachedEnumColumnData = [];
    private static array $cachedDatetimeColumnData = [];
    private static array $cachedRelationshipColumnData = [];
    private static array $cachedIPColumnData = [];
    private static array $cachedURLColumnData = [];
    private static array $cachedIndexData = [];
    private static array $cachedRowData = [];
    private static array $cachedBulkData = [];

    protected function setupDatabase(): array
    {
        if (!empty(static::$cachedDatabase)) {
            return static::$cachedDatabase;
        }

        $projectId = $this->getProject()['$id'];
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

        $query = $this->getQuery(self::TABLESDB_CREATE_DATABASE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => 'actors',
                'name' => 'Actors',
            ]
        ];

        $database = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        // Handle 409 conflict - database already exists from parallel test
        if (isset($database['body']['errors'])) {
            $errorMessage = $database['body']['errors'][0]['message'] ?? '';
            if (strpos($errorMessage, 'already exists') !== false || strpos($errorMessage, 'Document with the requested ID already exists') !== false) {
                // Fetch the existing database
                $getQuery = $this->getQuery(self::TABLESDB_GET_DATABASE);
                $gqlPayload = [
                    'query' => $getQuery,
                    'variables' => [
                        'databaseId' => 'actors',
                    ]
                ];
                $database = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);
                $this->assertArrayNotHasKey('errors', $database['body']);
                static::$cachedDatabase = $database['body']['data']['tablesDBGet'];
                return static::$cachedDatabase;
            }
        }

        static::$cachedDatabase = $database['body']['data']['tablesDBCreate'];
        return static::$cachedDatabase;
    }

    protected function setupTable(): array
    {
        if (!empty(static::$cachedTableData)) {
            return static::$cachedTableData;
        }

        $database = $this->setupDatabase();
        $projectId = $this->getProject()['$id'];
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

        // Create or get 'actors' table
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

        $table = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        // Handle 409 conflict - table already exists
        if (isset($table['body']['errors'])) {
            $errorMessage = $table['body']['errors'][0]['message'] ?? '';
            if (strpos($errorMessage, 'already exists') !== false || strpos($errorMessage, 'Document with the requested ID already exists') !== false) {
                $getQuery = $this->getQuery(self::GET_TABLE);
                $gqlPayload = [
                    'query' => $getQuery,
                    'variables' => [
                        'databaseId' => $database['_id'],
                        'tableId' => 'actors',
                    ]
                ];
                $table = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);
                $this->assertArrayNotHasKey('errors', $table['body']);
                $table = $table['body']['data']['tablesDBGetTable'];
            }
        } else {
            $table = $table['body']['data']['tablesDBCreateTable'];
        }

        // Create or get 'movies' table
        $query = $this->getQuery(self::CREATE_TABLE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $database['_id'],
                'tableId' => 'movies',
                'name' => 'Movies',
                'rowSecurity' => false,
                'permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::users()),
                    Permission::update(Role::users()),
                    Permission::delete(Role::users()),
                ],
            ]
        ];

        $table2 = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);

        // Handle 409 conflict
        if (isset($table2['body']['errors'])) {
            $errorMessage = $table2['body']['errors'][0]['message'] ?? '';
            if (strpos($errorMessage, 'already exists') !== false || strpos($errorMessage, 'Document with the requested ID already exists') !== false) {
                $getQuery = $this->getQuery(self::GET_TABLE);
                $gqlPayload = [
                    'query' => $getQuery,
                    'variables' => [
                        'databaseId' => $database['_id'],
                        'tableId' => 'movies',
                    ]
                ];
                $table2 = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $gqlPayload);
                $this->assertArrayNotHasKey('errors', $table2['body']);
                $table2 = $table2['body']['data']['tablesDBGetTable'];
            }
        } else {
            $table2 = $table2['body']['data']['tablesDBCreateTable'];
        }

        static::$cachedTableData = [
            'database' => $database,
            'table' => $table,
            'table2' => $table2,
        ];

        return static::$cachedTableData;
    }

    protected function setupStringColumn(): array
    {
        if (!empty(static::$cachedStringColumnData)) {
            return static::$cachedStringColumnData;
        }

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

        $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        static::$cachedStringColumnData = $data;
        return static::$cachedStringColumnData;
    }

    protected function setupUpdatedStringColumn(): array
    {
        $data = $this->setupStringColumn();

        // Check if already updated by looking for default value
        $projectId = $this->getProject()['$id'];

        // Wait for columns to be available
        sleep(1);

        $query = $this->getQuery(self::UPDATE_STRING_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'name',
                'required' => false,
                'default' => 'Default Value',
            ]
        ];

        $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        return $data;
    }

    protected function setupIntegerColumn(): array
    {
        if (!empty(static::$cachedIntegerColumnData)) {
            return static::$cachedIntegerColumnData;
        }

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

        $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        static::$cachedIntegerColumnData = $data;
        return static::$cachedIntegerColumnData;
    }

    protected function setupUpdatedIntegerColumn(): array
    {
        $data = $this->setupIntegerColumn();
        $projectId = $this->getProject()['$id'];

        // Wait for columns to be available
        sleep(1);

        $query = $this->getQuery(self::UPDATE_INTEGER_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'age',
                'required' => false,
                'min' => 12,
                'max' => 160,
                'default' => 50
            ]
        ];

        $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        return $data;
    }

    protected function setupBooleanColumn(): array
    {
        if (!empty(static::$cachedBooleanColumnData)) {
            return static::$cachedBooleanColumnData;
        }

        $data = $this->setupTable();
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_BOOLEAN_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'alive',
                'required' => true,
            ]
        ];

        $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        static::$cachedBooleanColumnData = $data;
        return static::$cachedBooleanColumnData;
    }

    protected function setupUpdatedBooleanColumn(): array
    {
        $data = $this->setupBooleanColumn();
        $projectId = $this->getProject()['$id'];

        // Wait for columns to be available
        sleep(1);

        $query = $this->getQuery(self::UPDATE_BOOLEAN_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'alive',
                'required' => false,
                'default' => true
            ]
        ];

        $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        return $data;
    }

    protected function setupFloatColumn(): array
    {
        if (!empty(static::$cachedFloatColumnData)) {
            return static::$cachedFloatColumnData;
        }

        $data = $this->setupTable();
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_FLOAT_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'salary',
                'min' => 1000.0,
                'max' => 999999.99,
                'default' => 1000.0,
                'required' => false,
            ]
        ];

        $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        static::$cachedFloatColumnData = $data;
        return static::$cachedFloatColumnData;
    }

    protected function setupUpdatedFloatColumn(): array
    {
        $data = $this->setupFloatColumn();
        $projectId = $this->getProject()['$id'];

        // Wait for columns to be available
        sleep(1);

        $query = $this->getQuery(self::UPDATE_FLOAT_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'salary',
                'required' => false,
                'min' => 100.0,
                'max' => 1000000.0,
                'default' => 2500.0
            ]
        ];

        $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        return $data;
    }

    protected function setupEmailColumn(): array
    {
        if (!empty(static::$cachedEmailColumnData)) {
            return static::$cachedEmailColumnData;
        }

        $data = $this->setupTable();
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_EMAIL_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'email',
                'required' => true,
            ]
        ];

        $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        static::$cachedEmailColumnData = $data;
        return static::$cachedEmailColumnData;
    }

    protected function setupUpdatedEmailColumn(): array
    {
        $data = $this->setupEmailColumn();
        $projectId = $this->getProject()['$id'];

        // Wait for columns to be available
        sleep(1);

        $query = $this->getQuery(self::UPDATE_EMAIL_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'email',
                'required' => false,
                'default' => 'torsten@appwrite.io',
            ]
        ];

        $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        return $data;
    }

    protected function setupEnumColumn(): array
    {
        if (!empty(static::$cachedEnumColumnData)) {
            return static::$cachedEnumColumnData;
        }

        $data = $this->setupTable();
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_ENUM_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'role',
                'elements' => [
                    'crew',
                    'actor',
                    'guest',
                ],
                'required' => true,
            ]
        ];

        $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        static::$cachedEnumColumnData = $data;
        return static::$cachedEnumColumnData;
    }

    protected function setupUpdatedEnumColumn(): array
    {
        $data = $this->setupEnumColumn();
        $projectId = $this->getProject()['$id'];

        // Wait for columns to be available
        sleep(1);

        $query = $this->getQuery(self::UPDATE_ENUM_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'role',
                'required' => false,
                'elements' => [
                    'crew',
                    'tech',
                    'actor'
                ],
                'default' => 'tech'
            ]
        ];

        $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        return $data;
    }

    protected function setupDatetimeColumn(): array
    {
        if (!empty(static::$cachedDatetimeColumnData)) {
            return static::$cachedDatetimeColumnData;
        }

        $data = $this->setupTable();
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_DATETIME_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'dob',
                'required' => true,
            ]
        ];

        $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        static::$cachedDatetimeColumnData = $data;
        return static::$cachedDatetimeColumnData;
    }

    protected function setupUpdatedDatetimeColumn(): array
    {
        $data = $this->setupDatetimeColumn();
        $projectId = $this->getProject()['$id'];

        // Wait for columns to be available
        sleep(1);

        $query = $this->getQuery(self::UPDATE_DATETIME_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'dob',
                'required' => false,
                'default' => '2000-01-01T00:00:00Z'
            ]
        ];

        $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        return $data;
    }

    protected function setupRelationshipColumn(): array
    {
        if (!empty(static::$cachedRelationshipColumnData)) {
            return static::$cachedRelationshipColumnData;
        }

        $data = $this->setupTable();
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_RELATIONSHIP_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table2']['_id'],          // Movies
                'relatedTableId' => $data['table']['_id'],    // Actors
                'type' => Database::RELATION_ONE_TO_MANY,
                'twoWay' => true,
                'key' => 'actors',
                'twoWayKey' => 'movie'
            ]
        ];

        $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        static::$cachedRelationshipColumnData = $data;
        return static::$cachedRelationshipColumnData;
    }

    protected function setupUpdatedRelationshipColumn(): array
    {
        $data = $this->setupRelationshipColumn();
        $projectId = $this->getProject()['$id'];

        sleep(1);

        $query = $this->getQuery(self::UPDATE_RELATIONSHIP_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table2']['_id'],
                'key' => 'actors',
                'onDelete' => Database::RELATION_MUTATE_CASCADE,
            ]
        ];

        $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        return $data;
    }

    protected function setupIPColumn(): array
    {
        if (!empty(static::$cachedIPColumnData)) {
            return static::$cachedIPColumnData;
        }

        $data = $this->setupTable();
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_IP_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'ip',
                'required' => false,
                'default' => '::1',
            ]
        ];

        $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        static::$cachedIPColumnData = $data;
        return static::$cachedIPColumnData;
    }

    protected function setupUpdatedIPColumn(): array
    {
        $data = $this->setupIPColumn();
        $projectId = $this->getProject()['$id'];

        // Wait for columns to be available
        sleep(3);

        $query = $this->getQuery(self::UPDATE_IP_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'ip',
                'required' => false,
                'default' => '127.0.0.1'
            ]
        ];

        $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        return $data;
    }

    protected function setupURLColumn(): array
    {
        if (!empty(static::$cachedURLColumnData)) {
            return static::$cachedURLColumnData;
        }

        $data = $this->setupTable();
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_URL_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'url',
                'required' => false,
                'default' => 'https://appwrite.io',
            ]
        ];

        $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        static::$cachedURLColumnData = $data;
        return static::$cachedURLColumnData;
    }

    protected function setupUpdatedURLColumn(): array
    {
        $data = $this->setupURLColumn();
        $projectId = $this->getProject()['$id'];

        // Wait for columns to be available
        sleep(3);

        $query = $this->getQuery(self::UPDATE_URL_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'url',
                'required' => false,
                'default' => 'https://cloud.appwrite.io'
            ]
        ];

        $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        return $data;
    }

    protected function setupIndex(): array
    {
        if (!empty(static::$cachedIndexData)) {
            return static::$cachedIndexData;
        }

        // Need updated string and integer columns first
        $this->setupUpdatedStringColumn();
        $data = $this->setupUpdatedIntegerColumn();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_COLUMN_INDEX);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'index',
                'type' => 'key',
                'columns' => [
                    'name',
                    'age',
                ],
            ]
        ];

        $index = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        static::$cachedIndexData = [
            'database' => $data['database'],
            'table' => $data['table'],
            'index' => $index['body']['data']['tablesDBCreateIndex'],
        ];

        return static::$cachedIndexData;
    }

    protected function setupRow(): array
    {
        if (!empty(static::$cachedRowData)) {
            return static::$cachedRowData;
        }

        // Need updated string, integer, boolean, and enum columns
        $this->setupUpdatedStringColumn();
        $this->setupUpdatedIntegerColumn();
        $this->setupUpdatedBooleanColumn();
        $data = $this->setupUpdatedEnumColumn();

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
                    'email' => 'example@appwrite.io',
                    'age' => 30,
                    'alive' => true,
                    'salary' => 9999.9,
                    'role' => 'crew',
                    'dob' => '2000-01-01T00:00:00Z',
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

        $row = $row['body']['data']['tablesDBCreateRow'];

        static::$cachedRowData = [
            'database' => $data['database'],
            'table' => $data['table'],
            'row' => $row,
        ];

        return static::$cachedRowData;
    }

    protected function setupBulkData(): array
    {
        if (!empty(static::$cachedBulkData)) {
            return static::$cachedBulkData;
        }

        $project = $this->getProject();
        $projectId = $project['$id'];
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

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

        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $payload);
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

        $this->client->call(Client::METHOD_POST, '/graphql', $headers, $payload);

        static::$cachedBulkData = compact('databaseId', 'tableId', 'projectId');

        return static::$cachedBulkData;
    }

    public function testCreateDatabase(): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::TABLESDB_CREATE_DATABASE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => 'actors',
                'name' => 'Actors',
            ]
        ];

        $database = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($database['body']['data']);
        $this->assertArrayNotHasKey('errors', $database['body']);
        $database = $database['body']['data']['tablesDBCreate'];
        $this->assertEquals('Actors', $database['name']);

        // Store for caching
        static::$cachedDatabase = $database;
    }

    /**
     * @throws Exception
     */
    public function testCreateTable(): void
    {
        $database = $this->setupDatabase();
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

        $table = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($table['body']['data']);
        $this->assertArrayNotHasKey('errors', $table['body']);
        $table = $table['body']['data']['tablesDBCreateTable'];
        $this->assertEquals('Actors', $table['name']);

        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $database['_id'],
                'tableId' => 'movies',
                'name' => 'Movies',
                'rowSecurity' => false,
                'permissions' => [
                    Permission::read(Role::any()),
                    Permission::create(Role::users()),
                    Permission::update(Role::users()),
                    Permission::delete(Role::users()),
                ],
            ]
        ];

        $table2 = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($table2['body']['data']);
        $this->assertArrayNotHasKey('errors', $table2['body']);
        $table2 = $table2['body']['data']['tablesDBCreateTable'];
        $this->assertEquals('Movies', $table2['name']);

        // Store for caching
        static::$cachedTableData = [
            'database' => $database,
            'table' => $table,
            'table2' => $table2,
        ];
    }

    /**
     * @throws Exception
     */
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

        $column = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        // TODO: @itznotabug - check for `encrypt` attribute in string column's response body as well!
        $this->assertArrayNotHasKey('errors', $column['body']);
        $this->assertIsArray($column['body']['data']);
        $this->assertIsArray($column['body']['data']['tablesDBCreateStringColumn']);

        // Store for caching
        static::$cachedStringColumnData = $data;
    }

    /**
     * @throws Exception
     */
    public function testUpdateStringColumn(): void
    {
        $data = $this->setupStringColumn();

        // Wait for columns to be available
        sleep(1);

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::UPDATE_STRING_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'name',
                'required' => false,
                'default' => 'Default Value',
            ]
        ];

        $column = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($column['body']['data']);
        $this->assertIsArray($column['body']['data']['tablesDBUpdateStringColumn']);
        $this->assertFalse($column['body']['data']['tablesDBUpdateStringColumn']['required']);
        $this->assertEquals('Default Value', $column['body']['data']['tablesDBUpdateStringColumn']['default']);
        $this->assertEquals(200, $column['headers']['status-code']);
    }

    /**
     * @throws Exception
     */
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

        $column = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $column['body']);
        $this->assertIsArray($column['body']['data']);
        $this->assertIsArray($column['body']['data']['tablesDBCreateIntegerColumn']);

        // Store for caching
        static::$cachedIntegerColumnData = $data;
    }

    /**
     * @throws Exception
     */
    public function testUpdateIntegerColumn(): void
    {
        $data = $this->setupIntegerColumn();

        // Wait for columns to be available
        sleep(1);

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::UPDATE_INTEGER_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'age',
                'required' => false,
                'min' => 12,
                'max' => 160,
                'default' => 50
            ]
        ];

        $column = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($column['body']['data']);
        $this->assertIsArray($column['body']['data']['tablesDBUpdateIntegerColumn']);
        $this->assertFalse($column['body']['data']['tablesDBUpdateIntegerColumn']['required']);
        $this->assertEquals(12, $column['body']['data']['tablesDBUpdateIntegerColumn']['min']);
        $this->assertEquals(160, $column['body']['data']['tablesDBUpdateIntegerColumn']['max']);
        $this->assertEquals(50, $column['body']['data']['tablesDBUpdateIntegerColumn']['default']);
        $this->assertEquals(200, $column['headers']['status-code']);
    }

    /**
     * @throws Exception
     */
    public function testCreateBooleanColumn(): void
    {
        $data = $this->setupTable();
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_BOOLEAN_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'alive',
                'required' => true,
            ]
        ];

        $column = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $column['body']);
        $this->assertIsArray($column['body']['data']);
        $this->assertIsArray($column['body']['data']['tablesDBCreateBooleanColumn']);

        // Store for caching
        static::$cachedBooleanColumnData = $data;
    }

    /**
     * @throws Exception
     */
    public function testUpdateBooleanColumn(): void
    {
        $data = $this->setupBooleanColumn();

        // Wait for columns to be available
        sleep(1);

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::UPDATE_BOOLEAN_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'alive',
                'required' => false,
                'default' => true
            ]
        ];

        $column = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($column['body']['data']);
        $this->assertIsArray($column['body']['data']['tablesDBUpdateBooleanColumn']);
        $this->assertFalse($column['body']['data']['tablesDBUpdateBooleanColumn']['required']);
        $this->assertTrue($column['body']['data']['tablesDBUpdateBooleanColumn']['default']);
        $this->assertEquals(200, $column['headers']['status-code']);
    }

    /**
     * @throws Exception
     */
    public function testCreateFloatColumn(): void
    {
        $data = $this->setupTable();
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_FLOAT_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'salary',
                'min' => 1000.0,
                'max' => 999999.99,
                'default' => 1000.0,
                'required' => false,
            ]
        ];

        $column = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $column['body']);
        $this->assertIsArray($column['body']['data']);
        $this->assertIsArray($column['body']['data']['tablesDBCreateFloatColumn']);

        // Store for caching
        static::$cachedFloatColumnData = $data;
    }

    /**
     * @throws Exception
     */
    public function testUpdateFloatColumn(): void
    {
        $data = $this->setupFloatColumn();

        // Wait for columns to be available
        sleep(1);

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::UPDATE_FLOAT_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'salary',
                'required' => false,
                'min' => 100.0,
                'max' => 1000000.0,
                'default' => 2500.0
            ]
        ];

        $column = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($column['body']['data']);
        $this->assertIsArray($column['body']['data']['tablesDBUpdateFloatColumn']);
        $this->assertFalse($column['body']['data']['tablesDBUpdateFloatColumn']['required']);
        $this->assertEquals(100.0, $column['body']['data']['tablesDBUpdateFloatColumn']['min']);
        $this->assertEquals(1000000.0, $column['body']['data']['tablesDBUpdateFloatColumn']['max']);
        $this->assertEquals(2500.0, $column['body']['data']['tablesDBUpdateFloatColumn']['default']);
        $this->assertEquals(200, $column['headers']['status-code']);
    }

    /**
     * @throws Exception
     */
    public function testCreateEmailColumn(): void
    {
        $data = $this->setupTable();
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_EMAIL_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'email',
                'required' => true,
            ]
        ];

        $column = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $column['body']);
        $this->assertIsArray($column['body']['data']);
        $this->assertIsArray($column['body']['data']['tablesDBCreateEmailColumn']);

        // Store for caching
        static::$cachedEmailColumnData = $data;
    }

    /**
     * @throws Exception
     */
    public function testUpdateEmailColumn(): void
    {
        $data = $this->setupEmailColumn();

        // Wait for columns to be available
        sleep(1);

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::UPDATE_EMAIL_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'email',
                'required' => false,
                'default' => 'torsten@appwrite.io',
            ]
        ];

        $column = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($column['body']['data']);
        $this->assertIsArray($column['body']['data']['tablesDBUpdateEmailColumn']);
        $this->assertFalse($column['body']['data']['tablesDBUpdateEmailColumn']['required']);
        $this->assertEquals('torsten@appwrite.io', $column['body']['data']['tablesDBUpdateEmailColumn']['default']);
        $this->assertEquals(200, $column['headers']['status-code']);
    }

    /**
     * @throws Exception
     */
    public function testCreateEnumColumn(): void
    {
        $data = $this->setupTable();
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_ENUM_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'role',
                'elements' => [
                    'crew',
                    'actor',
                    'guest',
                ],
                'required' => true,
            ]
        ];

        $column = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $column['body']);
        $this->assertIsArray($column['body']['data']);
        $this->assertIsArray($column['body']['data']['tablesDBCreateEnumColumn']);

        // Store for caching
        static::$cachedEnumColumnData = $data;
    }


    /**
     * @throws Exception
     */
    public function testUpdateEnumColumn(): void
    {
        $data = $this->setupEnumColumn();

        // Wait for columns to be available
        sleep(1);

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::UPDATE_ENUM_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'role',
                'required' => false,
                'elements' => [
                    'crew',
                    'tech',
                    'actor'
                ],
                'default' => 'tech'
            ]
        ];

        $column = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($column['body']['data']);
        $this->assertIsArray($column['body']['data']['tablesDBUpdateEnumColumn']);
        $this->assertFalse($column['body']['data']['tablesDBUpdateEnumColumn']['required']);
        $this->assertEquals('tech', $column['body']['data']['tablesDBUpdateEnumColumn']['default']);
        $this->assertContains('tech', $column['body']['data']['tablesDBUpdateEnumColumn']['elements']);
        $this->assertNotContains('guest', $column['body']['data']['tablesDBUpdateEnumColumn']['elements']);
        $this->assertEquals(200, $column['headers']['status-code']);
    }

    /**
     * @throws Exception
     */
    public function testCreateDatetimeColumn(): void
    {
        $data = $this->setupTable();
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_DATETIME_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'dob',
                'required' => true,
            ]
        ];

        $column = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $column['body']);
        $this->assertIsArray($column['body']['data']);
        $this->assertIsArray($column['body']['data']['tablesDBCreateDatetimeColumn']);

        // Store for caching
        static::$cachedDatetimeColumnData = $data;
    }

    /**
     * @throws Exception
     */
    public function testUpdateDatetimeColumn(): void
    {
        $data = $this->setupDatetimeColumn();

        // Wait for columns to be available
        sleep(1);

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::UPDATE_DATETIME_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'dob',
                'required' => false,
                'default' => '2000-01-01T00:00:00Z'
            ]
        ];

        $column = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($column['body']['data']);
        $this->assertIsArray($column['body']['data']['tablesDBUpdateDatetimeColumn']);
        $this->assertFalse($column['body']['data']['tablesDBUpdateDatetimeColumn']['required']);
        $this->assertEquals('2000-01-01T00:00:00Z', $column['body']['data']['tablesDBUpdateDatetimeColumn']['default']);
        $this->assertEquals(200, $column['headers']['status-code']);
    }

    public function testCreateRelationshipColumn(): void
    {
        $data = $this->setupTable();
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_RELATIONSHIP_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table2']['_id'],          // Movies
                'relatedTableId' => $data['table']['_id'],    // Actors
                'type' => Database::RELATION_ONE_TO_MANY,
                'twoWay' => true,
                'key' => 'actors',
                'twoWayKey' => 'movie'
            ]
        ];

        $column = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $column['body']);
        $this->assertIsArray($column['body']['data']);
        $this->assertIsArray($column['body']['data']['tablesDBCreateRelationshipColumn']);

        // Store for caching
        static::$cachedRelationshipColumnData = $data;
    }

    public function testUpdateRelationshipColumn(): void
    {
        $data = $this->setupRelationshipColumn();

        sleep(1);

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::UPDATE_RELATIONSHIP_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table2']['_id'],
                'key' => 'actors',
                'onDelete' => Database::RELATION_MUTATE_CASCADE,
            ]
        ];

        $column = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $column['body']);
        $this->assertIsArray($column['body']['data']);
        $this->assertIsArray($column['body']['data']['tablesDBUpdateRelationshipColumn']);
    }

    /**
     * @throws Exception
     */
    public function testCreateIPColumn(): void
    {
        $data = $this->setupTable();
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_IP_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'ip',
                'required' => false,
                'default' => '::1',
            ]
        ];

        $column = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $column['body']);
        $this->assertIsArray($column['body']['data']);
        $this->assertIsArray($column['body']['data']['tablesDBCreateIpColumn']);

        // Store for caching
        static::$cachedIPColumnData = $data;
    }

    /**
     * @throws Exception
     */
    public function testUpdateIPColumn(): void
    {
        $data = $this->setupIPColumn();

        // Wait for columns to be available
        sleep(3);

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::UPDATE_IP_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'ip',
                'required' => false,
                'default' => '127.0.0.1'
            ]
        ];

        $column = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($column['body']['data']);
        $this->assertIsArray($column['body']['data']['tablesDBUpdateIpColumn']);
        $this->assertFalse($column['body']['data']['tablesDBUpdateIpColumn']['required']);
        $this->assertEquals('127.0.0.1', $column['body']['data']['tablesDBUpdateIpColumn']['default']);
        $this->assertEquals(200, $column['headers']['status-code']);
    }

    /**
     * @throws Exception
     */
    public function testCreateURLColumn(): void
    {
        $data = $this->setupTable();
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_URL_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'url',
                'required' => false,
                'default' => 'https://appwrite.io',
            ]
        ];

        $column = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $column['body']);
        $this->assertIsArray($column['body']['data']);
        $this->assertIsArray($column['body']['data']['tablesDBCreateUrlColumn']);

        // Store for caching
        static::$cachedURLColumnData = $data;
    }

    /**
     * @throws Exception
     */
    public function testUpdateURLColumn(): void
    {
        $data = $this->setupURLColumn();

        // Wait for columns to be available
        sleep(3);

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::UPDATE_URL_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'url',
                'required' => false,
                'default' => 'https://cloud.appwrite.io'
            ]
        ];

        $column = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsArray($column['body']['data']);
        $this->assertIsArray($column['body']['data']['tablesDBUpdateUrlColumn']);
        $this->assertFalse($column['body']['data']['tablesDBUpdateUrlColumn']['required']);
        $this->assertEquals('https://cloud.appwrite.io', $column['body']['data']['tablesDBUpdateUrlColumn']['default']);
        $this->assertEquals(200, $column['headers']['status-code']);
    }

    /**
     * @throws Exception
     */
    public function testCreateIndex(): void
    {
        // Need updated string and integer columns first
        $this->setupUpdatedStringColumn();
        $data = $this->setupUpdatedIntegerColumn();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_COLUMN_INDEX);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'index',
                'type' => 'key',
                'columns' => [
                    'name',
                    'age',
                ],
            ]
        ];

        $index = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $index['body']);
        $this->assertIsArray($index['body']['data']);
        $this->assertIsArray($index['body']['data']['tablesDBCreateIndex']);

        // Store for caching
        static::$cachedIndexData = [
            'database' => $data['database'],
            'table' => $data['table'],
            'index' => $index['body']['data']['tablesDBCreateIndex'],
        ];
    }

    /**
     * @throws Exception
     */
    public function testCreateRow(): void
    {
        // Need updated string, integer, boolean, and enum columns
        $this->setupUpdatedStringColumn();
        $this->setupUpdatedIntegerColumn();
        $this->setupUpdatedBooleanColumn();
        $data = $this->setupUpdatedEnumColumn();

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
                    'email' => 'example@appwrite.io',
                    'age' => 30,
                    'alive' => true,
                    'salary' => 9999.9,
                    'role' => 'crew',
                    'dob' => '2000-01-01T00:00:00Z',
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

        // Store for caching
        static::$cachedRowData = [
            'database' => $data['database'],
            'table' => $data['table'],
            'row' => $row,
        ];
    }

    //    /**
    //     * @depends testCreateStringColumn
    //     * @depends testCreateIntegerColumn
    //     * @depends testCreateBooleanColumn
    //     * @depends testCreateFloatColumn
    //     * @depends testCreateEmailColumn
    //     * @depends testCreateEnumColumn
    //     * @depends testCreateDatetimeColumn
    //     * @throws Exception
    //     */
    //    public function testCreateCustomEntity(): array
    //    {
    //        $projectId = $this->getProject()['$id'];
    //        $query = $this->getQuery(self::CREATE_CUSTOM_ENTITY);
    //        $gqlPayload = [
    //            'query' => $query,
    //            'variables' => [
    //                'name' => 'John Doe',
    //                'age' => 35,
    //                'alive' => true,
    //                'salary' => 9999.9,
    //                'email' => 'johndoe@appwrite.io',
    //                'role' => 'crew',
    //                'dob' => '2000-01-01T00:00:00Z',
    //            ]
    //        ];
    //
    //        $actor = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
    //            'content-type' => 'application/json',
    //            'x-appwrite-project' => $projectId,
    //        ], $this->getHeaders()), $gqlPayload);
    //
    //        $this->assertArrayNotHasKey('errors', $actor['body']);
    //        $this->assertIsArray($actor['body']['data']);
    //        $actor = $actor['body']['data']['actorsCreate'];
    //        $this->assertIsArray($actor);
    //
    //        return $actor;
    //    }

    public function testGetDatabases(): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::TABLESDB_GET_DATABASES);
        $gqlPayload = [
            'query' => $query,
        ];

        $databases = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $databases['body']);
        $this->assertIsArray($databases['body']['data']);
        $this->assertIsArray($databases['body']['data']['tablesDBList']);
    }

    /**
     * @throws Exception
     */
    public function testGetDatabase(): void
    {
        $database = $this->setupDatabase();
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::TABLESDB_GET_DATABASE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $database['_id'],
            ]
        ];

        $database = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $database['body']);
        $this->assertIsArray($database['body']['data']);
        $this->assertIsArray($database['body']['data']['tablesDBGet']);
    }

    /**
     * @throws Exception
     */
    public function testGetTables(): void
    {
        $data = $this->setupTable();
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_TABLES);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
            ]
        ];

        $tables = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);



        $this->assertArrayNotHasKey('errors', $tables['body']);
        $this->assertIsArray($tables['body']['data']);
        $this->assertIsArray($tables['body']['data']['tablesDBListTables']);
    }

    /**
     * @throws Exception
     */
    public function testGetTable(): void
    {
        $data = $this->setupTable();
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_TABLE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
            ]
        ];

        $table = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $table['body']);
        $this->assertIsArray($table['body']['data']);
        $this->assertIsArray($table['body']['data']['tablesDBGetTable']);
    }

    /**
     * @throws Exception
     */
    public function testGetColumns(): void
    {
        $this->setupUpdatedStringColumn();
        $data = $this->setupUpdatedIntegerColumn();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_COLUMNS);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
            ]
        ];

        $columns = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $columns['body']);
        $this->assertIsArray($columns['body']['data']);
        $this->assertIsArray($columns['body']['data']['tablesDBListColumns']);
    }

    /**
     * @throws Exception
     */
    public function testGetColumn(): void
    {
        $data = $this->setupTable();
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'name',
            ]
        ];

        $column = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $column['body']);
        $this->assertIsArray($column['body']['data']);
        $this->assertIsArray($column['body']['data']['tablesDBGetColumn']);
    }

    /**
     * @throws Exception
     */
    public function testGetIndexes(): void
    {
        $data = $this->setupIndex();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_COLUMN_INDEXES);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
            ]
        ];

        $indices = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $indices['body']);
        $this->assertIsArray($indices['body']['data']);
        $this->assertIsArray($indices['body']['data']['tablesDBListIndexes']);
    }

    /**
     * @throws Exception
     */
    public function testGetIndex(): void
    {
        $data = $this->setupIndex();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::GET_COLUMN_INDEX);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => $data['index']['key'],
            ]
        ];

        $index = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $index['body']);
        $this->assertIsArray($index['body']['data']);
        $this->assertIsArray($index['body']['data']['tablesDBGetIndex']);
    }

    /**
     * @throws Exception
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
     * @throws Exception
     */
    public function testGetRow(): void
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

    //    /**
    //     * @depends testCreateCustomEntity
    //     * @throws Exception
    //     */
    //    public function testGetCustomEntities($data)
    //    {
    //        $projectId = $this->getProject()['$id'];
    //        $query = $this->getQuery(self::GET_CUSTOM_ENTITIES);
    //        $gqlPayload = [
    //            'query' => $query,
    //        ];
    //
    //        $customEntities = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
    //            'content-type' => 'application/json',
    //            'x-appwrite-project' => $projectId,
    //        ], $this->getHeaders()), $gqlPayload);
    //
    //        $this->assertArrayNotHasKey('errors', $customEntities['body']);
    //        $this->assertIsArray($customEntities['body']['data']);
    //        $this->assertIsArray($customEntities['body']['data']['actorsList']);
    //    }
    //
    //    /**
    //     * @depends testCreateCustomEntity
    //     * @throws Exception
    //     */
    //    public function testGetCustomEntity($data)
    //    {
    //        $projectId = $this->getProject()['$id'];
    //        $query = $this->getQuery(self::GET_CUSTOM_ENTITY);
    //        $gqlPayload = [
    //            'query' => $query,
    //            'variables' => [
    //                'id' => $data['id'],
    //            ]
    //        ];
    //
    //        $entity = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
    //            'content-type' => 'application/json',
    //            'x-appwrite-project' => $projectId,
    //        ], $this->getHeaders()), $gqlPayload);
    //
    //        $this->assertArrayNotHasKey('errors', $entity['body']);
    //        $this->assertIsArray($entity['body']['data']);
    //        $this->assertIsArray($entity['body']['data']['actorsGet']);
    //    }

    /**
     * @throws Exception
     */
    public function testUpdateDatabase(): void
    {
        $database = $this->setupDatabase();
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::TABLESDB_UPDATE_DATABASE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $database['_id'],
                'name' => 'New Database Name',
            ]
        ];

        $database = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $database['body']);
        $this->assertIsArray($database['body']['data']);
        $this->assertIsArray($database['body']['data']['tablesDBUpdate']);
    }

    /**
     * @throws Exception
     */
    public function testUpdateTable(): void
    {
        $data = $this->setupTable();
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::UPDATE_TABLE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'name' => 'New Table Name',
                'rowSecurity' => false,
            ]
        ];

        $table = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $table['body']);
        $this->assertIsArray($table['body']['data']);
        $this->assertIsArray($table['body']['data']['tablesDBUpdateTable']);
    }

    /**
     * @throws Exception
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

    //    /**
    //     * @depends testCreateCustomEntity
    //     * @throws Exception
    //     */
    //    public function testUpdateCustomEntity(array $data)
    //    {
    //        $projectId = $this->getProject()['$id'];
    //        $query = $this->getQuery(self::UPDATE_CUSTOM_ENTITY);
    //        $gqlPayload = [
    //            'query' => $query,
    //            'variables' => [
    //                'id' => $data['id'],
    //                'name' => 'New Custom Entity Name',
    //            ]
    //        ];
    //
    //        $entity = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
    //            'content-type' => 'application/json',
    //            'x-appwrite-project' => $projectId,
    //        ], $this->getHeaders()), $gqlPayload);
    //
    //        $this->assertArrayNotHasKey('errors', $entity['body']);
    //        $this->assertIsArray($entity['body']['data']);
    //        $entity = $entity['body']['data']['actorsUpdate'];
    //        $this->assertIsArray($entity);
    //        $this->assertStringContainsString('New Custom Entity Name', $entity['name']);
    //    }

    /**
     * @throws Exception
     */
    public function testDeleteRow(): void
    {
        $data = $this->setupRow();

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

    //    /**
    //     * @depends testCreateCustomEntity
    //     * @throws Exception
    //     */
    //    public function testDeleteCustomEntity(array $data)
    //    {
    //        $projectId = $this->getProject()['$id'];
    //        $query = $this->getQuery(self::DELETE_CUSTOM_ENTITY);
    //        $gqlPayload = [
    //            'query' => $query,
    //            'variables' => [
    //                'id' => $data['id'],
    //            ]
    //        ];
    //
    //        $entity = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
    //            'content-type' => 'application/json',
    //            'x-appwrite-project' => $projectId,
    //        ], $this->getHeaders()), $gqlPayload);
    //
    //        $this->assertIsNotArray($entity['body']);
    //        $this->assertEquals(204, $entity['headers']['status-code']);
    //    }

    /**
     * @throws Exception
     */
    public function testDeleteColumn(): void
    {
        $data = $this->setupUpdatedStringColumn();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::DELETE_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
                'key' => 'name',
            ]
        ];

        $column = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsNotArray($column['body']);
        $this->assertEquals(204, $column['headers']['status-code']);
    }

    /**
     * @throws Exception
     */
    public function testDeleteTable(): void
    {
        $data = $this->setupTable();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::DELETE_TABLE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['database']['_id'],
                'tableId' => $data['table']['_id'],
            ]
        ];

        $table = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsNotArray($table['body']);
        $this->assertEquals(204, $table['headers']['status-code']);
    }

    /**
     * @throws Exception
     */
    public function testDeleteDatabase(): void
    {
        $database = $this->setupDatabase();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::TABLESDB_DELETE_DATABASE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $database['_id'],
            ]
        ];

        $database = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertIsNotArray($database['body']);
        $this->assertEquals(204, $database['headers']['status-code']);
    }

    /**
     * @throws Exception
     */
    public function testBulkCreate(): void
    {
        $project = $this->getProject();
        $projectId = $project['$id'];
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

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

        // Store for caching
        static::$cachedBulkData = compact('databaseId', 'tableId', 'projectId');
    }

    public function testBulkUpdate(): void
    {
        $data = $this->setupBulkData();

        $userId = $this->getUser()['$id'];
        $permissions = [
            Permission::read(Role::user($userId)),
            Permission::update(Role::user($userId)),
            Permission::delete(Role::user($userId)),
        ];

        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
        ], $this->getHeaders());

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
    }

    public function testBulkUpsert(): void
    {
        $data = $this->setupBulkData();

        $userId = $this->getUser()['$id'];
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
        ], $this->getHeaders());

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
    }

    public function testBulkDelete(): void
    {
        $data = $this->setupBulkData();

        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
        ], $this->getHeaders());

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
    }
}
