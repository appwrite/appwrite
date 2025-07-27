<?php

namespace Tests\E2E\Services\GraphQL\Grids;

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

    public function testCreateDatabase(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GRIDS_CREATE_DATABASE);
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
        $database = $database['body']['data']['gridsCreateDatabase'];
        $this->assertEquals('Actors', $database['name']);

        return $database;
    }

    /**
     * @depends testCreateDatabase
     */
    public function testCreateTable($database): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_TABLE);
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
        $table = $table['body']['data']['gridsCreateTable'];
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
        $table2 = $table2['body']['data']['gridsCreateTable'];
        $this->assertEquals('Movies', $table2['name']);

        return [
            'database' => $database,
            'table' => $table,
            'table2' => $table2,
        ];
    }

    /**
     * @depends testCreateTable
     * @throws Exception
     */
    public function testCreateStringColumn($data): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_STRING_COLUMN);
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
        $this->assertIsArray($column['body']['data']['gridsCreateStringColumn']);

        return $data;
    }

    /**
     * @depends testCreateStringColumn
     * @throws Exception
     */
    public function testUpdateStringColumn($data): array
    {
        // Wait for columns to be available
        sleep(1);

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_STRING_COLUMN);
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
        $this->assertIsArray($column['body']['data']['gridsUpdateStringColumn']);
        $this->assertFalse($column['body']['data']['gridsUpdateStringColumn']['required']);
        $this->assertEquals('Default Value', $column['body']['data']['gridsUpdateStringColumn']['default']);
        $this->assertEquals(200, $column['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateTable
     * @throws Exception
     */
    public function testCreateIntegerColumn($data): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_INTEGER_COLUMN);
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
        $this->assertIsArray($column['body']['data']['gridsCreateIntegerColumn']);

        return $data;
    }

    /**
     * @depends testCreateIntegerColumn
     * @throws Exception
     */
    public function testUpdateIntegerColumn($data): array
    {
        // Wait for columns to be available
        sleep(1);

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_INTEGER_COLUMN);
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
        $this->assertIsArray($column['body']['data']['gridsUpdateIntegerColumn']);
        $this->assertFalse($column['body']['data']['gridsUpdateIntegerColumn']['required']);
        $this->assertEquals(12, $column['body']['data']['gridsUpdateIntegerColumn']['min']);
        $this->assertEquals(160, $column['body']['data']['gridsUpdateIntegerColumn']['max']);
        $this->assertEquals(50, $column['body']['data']['gridsUpdateIntegerColumn']['default']);
        $this->assertEquals(200, $column['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateTable
     * @throws Exception
     */
    public function testCreateBooleanColumn($data): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_BOOLEAN_COLUMN);
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
        $this->assertIsArray($column['body']['data']['gridsCreateBooleanColumn']);

        return $data;
    }

    /**
     * @depends testCreateBooleanColumn
     * @throws Exception
     */
    public function testUpdateBooleanColumn($data): array
    {
        // Wait for columns to be available
        sleep(1);

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_BOOLEAN_COLUMN);
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
        $this->assertIsArray($column['body']['data']['gridsUpdateBooleanColumn']);
        $this->assertFalse($column['body']['data']['gridsUpdateBooleanColumn']['required']);
        $this->assertTrue($column['body']['data']['gridsUpdateBooleanColumn']['default']);
        $this->assertEquals(200, $column['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateTable
     * @throws Exception
     */
    public function testCreateFloatColumn($data): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_FLOAT_COLUMN);
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
        $this->assertIsArray($column['body']['data']['gridsCreateFloatColumn']);

        return $data;
    }

    /**
     * @depends testCreateFloatColumn
     * @throws Exception
     */
    public function testUpdateFloatColumn($data): array
    {
        // Wait for columns to be available
        sleep(1);

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_FLOAT_COLUMN);
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
        $this->assertIsArray($column['body']['data']['gridsUpdateFloatColumn']);
        $this->assertFalse($column['body']['data']['gridsUpdateFloatColumn']['required']);
        $this->assertEquals(100.0, $column['body']['data']['gridsUpdateFloatColumn']['min']);
        $this->assertEquals(1000000.0, $column['body']['data']['gridsUpdateFloatColumn']['max']);
        $this->assertEquals(2500.0, $column['body']['data']['gridsUpdateFloatColumn']['default']);
        $this->assertEquals(200, $column['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateTable
     * @throws Exception
     */
    public function testCreateEmailColumn($data): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_EMAIL_COLUMN);
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
        $this->assertIsArray($column['body']['data']['gridsCreateEmailColumn']);

        return $data;
    }

    /**
     * @depends testCreateEmailColumn
     * @throws Exception
     */
    public function testUpdateEmailColumn($data): array
    {
        // Wait for columns to be available
        sleep(1);

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_EMAIL_COLUMN);
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
        $this->assertIsArray($column['body']['data']['gridsUpdateEmailColumn']);
        $this->assertFalse($column['body']['data']['gridsUpdateEmailColumn']['required']);
        $this->assertEquals('torsten@appwrite.io', $column['body']['data']['gridsUpdateEmailColumn']['default']);
        $this->assertEquals(200, $column['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateTable
     * @throws Exception
     */
    public function testCreateEnumColumn($data): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_ENUM_COLUMN);
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
        $this->assertIsArray($column['body']['data']['gridsCreateEnumColumn']);

        return $data;
    }


    /**
     * @depends testCreateEnumColumn
     * @throws Exception
     */
    public function testUpdateEnumColumn($data): array
    {
        // Wait for columns to be available
        sleep(1);

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_ENUM_COLUMN);
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
        $this->assertIsArray($column['body']['data']['gridsUpdateEnumColumn']);
        $this->assertFalse($column['body']['data']['gridsUpdateEnumColumn']['required']);
        $this->assertEquals('tech', $column['body']['data']['gridsUpdateEnumColumn']['default']);
        $this->assertContains('tech', $column['body']['data']['gridsUpdateEnumColumn']['elements']);
        $this->assertNotContains('guest', $column['body']['data']['gridsUpdateEnumColumn']['elements']);
        $this->assertEquals(200, $column['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateTable
     * @throws Exception
     */
    public function testCreateDatetimeColumn($data): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_DATETIME_COLUMN);
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
        $this->assertIsArray($column['body']['data']['gridsCreateDatetimeColumn']);

        return $data;
    }

    /**
     * @depends testCreateDatetimeColumn
     * @throws Exception
     */
    public function testUpdateDatetimeColumn($data): array
    {
        // Wait for columns to be available
        sleep(1);

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_DATETIME_COLUMN);
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
        $this->assertIsArray($column['body']['data']['gridsUpdateDatetimeColumn']);
        $this->assertFalse($column['body']['data']['gridsUpdateDatetimeColumn']['required']);
        $this->assertEquals('2000-01-01T00:00:00Z', $column['body']['data']['gridsUpdateDatetimeColumn']['default']);
        $this->assertEquals(200, $column['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateTable
     */
    public function testCreateRelationshipColumn(array $data): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_RELATIONSHIP_COLUMN);
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
        $this->assertIsArray($column['body']['data']['gridsCreateRelationshipColumn']);

        return $data;
    }

    /**
     * @depends testCreateRelationshipColumn
     */
    public function testUpdateRelationshipColumn(array $data): array
    {
        sleep(1);

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_RELATIONSHIP_COLUMN);
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
        $this->assertIsArray($column['body']['data']['gridsUpdateRelationshipColumn']);

        return $data;
    }

    /**
     * @depends testCreateTable
     * @throws Exception
     */
    public function testCreateIPColumn($data): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_IP_COLUMN);
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
        $this->assertIsArray($column['body']['data']['gridsCreateIpColumn']);

        return $data;
    }

    /**
     * @depends testCreateIPColumn
     * @throws Exception
     */
    public function testUpdateIPColumn($data): array
    {
        // Wait for columns to be available
        sleep(3);

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_IP_COLUMN);
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
        $this->assertIsArray($column['body']['data']['gridsUpdateIpColumn']);
        $this->assertFalse($column['body']['data']['gridsUpdateIpColumn']['required']);
        $this->assertEquals('127.0.0.1', $column['body']['data']['gridsUpdateIpColumn']['default']);
        $this->assertEquals(200, $column['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateTable
     * @throws Exception
     */
    public function testCreateURLColumn($data): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_URL_COLUMN);
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
        $this->assertIsArray($column['body']['data']['gridsCreateUrlColumn']);

        return $data;
    }

    /**
     * @depends testCreateURLColumn
     * @throws Exception
     */
    public function testUpdateURLColumn($data): void
    {
        // Wait for columns to be available
        sleep(3);

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_URL_COLUMN);
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
        $this->assertIsArray($column['body']['data']['gridsUpdateUrlColumn']);
        $this->assertFalse($column['body']['data']['gridsUpdateUrlColumn']['required']);
        $this->assertEquals('https://cloud.appwrite.io', $column['body']['data']['gridsUpdateUrlColumn']['default']);
        $this->assertEquals(200, $column['headers']['status-code']);
    }

    /**
     * @depends testUpdateStringColumn
     * @depends testUpdateIntegerColumn
     * @throws Exception
     */
    public function testCreateIndex($data): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_COLUMN_INDEX);
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
        $this->assertIsArray($index['body']['data']['gridsCreateIndex']);

        return [
            'database' => $data['database'],
            'table' => $data['table'],
            'index' => $index['body']['data']['gridsCreateIndex'],
        ];
    }

    /**
     * @depends testUpdateStringColumn
     * @depends testUpdateIntegerColumn
     * @depends testUpdateBooleanColumn
     * @depends testUpdateEnumColumn
     * @throws Exception
     */
    public function testCreateRow($data): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_ROW);
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

        $row = $row['body']['data']['gridsCreateRow'];
        $this->assertIsArray($row);

        return [
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
    //        $query = $this->getQuery(self::$CREATE_CUSTOM_ENTITY);
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
        $query = $this->getQuery(self::$GRIDS_GET_DATABASES);
        $gqlPayload = [
            'query' => $query,
        ];

        $databases = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $gqlPayload);

        $this->assertArrayNotHasKey('errors', $databases['body']);
        $this->assertIsArray($databases['body']['data']);
        $this->assertIsArray($databases['body']['data']['gridsListDatabases']);
    }

    /**
     * @depends testCreateDatabase
     * @throws Exception
     */
    public function testGetDatabase($database): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GRIDS_GET_DATABASE);
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
        $this->assertIsArray($database['body']['data']['gridsGetDatabase']);
    }

    /**
     * @depends testCreateTable
     * @throws Exception
     */
    public function testGetTables($data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_TABLES);
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
        $this->assertIsArray($tables['body']['data']['gridsListTables']);
    }

    /**
     * @depends testCreateTable
     * @throws Exception
     */
    public function testGetTable($data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_TABLE);
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
        $this->assertIsArray($table['body']['data']['gridsGetTable']);
    }

    /**
     * @depends testUpdateStringColumn
     * @depends testUpdateIntegerColumn
     * @throws Exception
     */
    public function testGetColumns($data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_COLUMNS);
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
        $this->assertIsArray($columns['body']['data']['gridsListColumns']);
    }

    /**
     * @depends testCreateTable
     * @throws Exception
     */
    public function testGetColumn($data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_COLUMN);
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
        $this->assertIsArray($column['body']['data']['gridsGetColumn']);
    }

    /**
     * @depends testCreateIndex
     * @throws Exception
     */
    public function testGetIndexes($data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_COLUMN_INDEXES);
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
        $this->assertIsArray($indices['body']['data']['gridsListIndexes']);
    }

    /**
     * @depends testCreateIndex
     * @throws Exception
     */
    public function testGetIndex($data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_COLUMN_INDEX);
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
        $this->assertIsArray($index['body']['data']['gridsGetIndex']);
    }

    /**
     * @depends testCreateTable
     * @throws Exception
     */
    public function testGetRows($data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_ROWS);
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
        $this->assertIsArray($rows['body']['data']['gridsListRows']);
    }

    /**
     * @depends testCreateRow
     * @throws Exception
     */
    public function testGetRow($data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_ROW);
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
        $this->assertIsArray($row['body']['data']['gridsGetRow']);
    }

    //    /**
    //     * @depends testCreateCustomEntity
    //     * @throws Exception
    //     */
    //    public function testGetCustomEntities($data)
    //    {
    //        $projectId = $this->getProject()['$id'];
    //        $query = $this->getQuery(self::$GET_CUSTOM_ENTITIES);
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
    //        $query = $this->getQuery(self::$GET_CUSTOM_ENTITY);
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
     * @depends testCreateDatabase
     * @throws Exception
     */
    public function testUpdateDatabase($database)
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GRIDS_UPDATE_DATABASE);
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
        $this->assertIsArray($database['body']['data']['gridsUpdateDatabase']);
    }

    /**
     * @depends testCreateTable
     * @throws Exception
     */
    public function testUpdateTable($data)
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_TABLE);
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
        $this->assertIsArray($table['body']['data']['gridsUpdateTable']);
    }

    /**
     * @depends testCreateRow
     * @throws Exception
     */
    public function testUpdateRow($data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$UPDATE_ROW);
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
        $row = $row['body']['data']['gridsUpdateRow'];
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
    //        $query = $this->getQuery(self::$UPDATE_CUSTOM_ENTITY);
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
     * @depends testCreateRow
     * @throws Exception
     */
    public function testDeleteRow($data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$DELETE_ROW);
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
    //        $query = $this->getQuery(self::$DELETE_CUSTOM_ENTITY);
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
     * @depends testUpdateStringColumn
     * @throws Exception
     */
    public function testDeleteColumn($data): void
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$DELETE_COLUMN);
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
     * @depends testCreateTable
     * @throws Exception
     */
    public function testDeleteTable($data)
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$DELETE_TABLE);
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
     * @depends testCreateDatabase
     * @throws Exception
     */
    public function testDeleteDatabase($database)
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GRIDS_DELETE_DATABASE);
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
    public function testBulkCreate(): array
    {
        $project = $this->getProject();
        $projectId = $project['$id'];
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders());

        // Step 1: Create database
        $query = $this->getQuery(self::$GRIDS_CREATE_DATABASE);
        $payload = [
            'query' => $query,
            'variables' => [
                'databaseId' => 'bulk',
                'name' => 'Bulk',
            ],
        ];

        $res = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $payload);
        $this->assertArrayNotHasKey('errors', $res['body']);
        $databaseId = $res['body']['data']['gridsCreateDatabase']['_id'];

        // Step 2: Create table
        $query = $this->getQuery(self::$CREATE_TABLE);
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
        $tableId = $res['body']['data']['gridsCreateTable']['_id'];

        // Step 3: Create column
        $query = $this->getQuery(self::$CREATE_STRING_COLUMN);
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
        $query = $this->getQuery(self::$CREATE_ROWS);
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
        $this->assertCount(10, $res['body']['data']['gridsCreateRows']['rows']);

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

        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
        ], $this->getHeaders());

        // Step 1: Bulk update rows
        $query = $this->getQuery(self::$UPDATE_ROWS);
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
        $this->assertCount(10, $res['body']['data']['gridsUpdateRows']['rows']);

        // Step 2: Fetch and validate updated rows
        $query = $this->getQuery(self::$GET_ROWS);
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

        $fetched = $res['body']['data']['gridsListRows'];
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
        $query = $this->getQuery(self::$UPSERT_ROWS);
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

        $rows = $response['body']['data']['gridsUpsertRows']['rows'];
        $this->assertCount(2, $rows);

        $rowMap = [];
        foreach ($rows as $row) {
            $decoded = json_decode($row['data'], true);
            $rowMap[$decoded['name']] = $decoded;
        }

        $this->assertArrayHasKey('Row #1000', $rowMap);
        $this->assertArrayHasKey('Row #11', $rowMap);

        // Step 2: Fetch all rows and confirm count is now 11
        $query = $this->getQuery(self::$GET_ROWS);
        $fetchPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['databaseId'],
                'tableId' => $data['tableId'],
            ],
        ];

        $res = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $fetchPayload);
        $this->assertEquals(200, $res['headers']['status-code']);

        $fetched = $res['body']['data']['gridsListRows'];
        $this->assertEquals(11, $fetched['total']);

        // Step 3: Upsert row with new permissions using `tablesUpsertRow`
        $query = $this->getQuery(self::$UPSERT_ROW);
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

        $updated = $res['body']['data']['gridsUpsertRow'];
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
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectId'],
        ], $this->getHeaders());

        // Step 1: Perform bulk delete
        $query = $this->getQuery(self::$DELETE_ROWS);
        $payload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['databaseId'],
                'tableId' => $data['tableId'],
            ],
        ];

        $res = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $payload);
        $this->assertArrayNotHasKey('errors', $res['body']);

        $deleted = $res['body']['data']['gridsDeleteRows']['rows'];
        $this->assertIsArray($deleted);
        $this->assertCount(11, $deleted);

        // Step 2: Confirm deletion via refetch
        $query = $this->getQuery(self::$GET_ROWS);
        $payload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $data['databaseId'],
                'tableId' => $data['tableId'],
            ],
        ];

        $res = $this->client->call(Client::METHOD_POST, '/graphql', $headers, $payload);
        $this->assertEquals(200, $res['headers']['status-code']);
        $this->assertEquals(0, $res['body']['data']['gridsListRows']['total']);

        return $data;
    }
}
