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

class AuthTest extends Scope
{
    use ProjectCustom;
    use SideClient;
    use Base;

    private array $account1;
    private array $account2;

    private string $token1;
    private string $token2;

    private array $database;
    private array $table;

    public function setUp(): void
    {
        parent::setUp();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::CREATE_ACCOUNT);

        $email1 = 'test' . \rand() . '@test.com';
        $email2 = 'test' . \rand() . '@test.com';

        // Create account 1
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => ID::unique(),
                'name' => 'User Name',
                'email' => $email1,
                'password' => 'password',
            ],
        ];
        $this->account1 = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $graphQLPayload);

        // Create account 2
        $graphQLPayload['variables']['userId'] = ID::unique();
        $graphQLPayload['variables']['email'] = $email2;

        $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $graphQLPayload);

        // Create session 1
        $query = $this->getQuery(self::CREATE_ACCOUNT_SESSION);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'email' => $email1,
                'password' => 'password',
            ]
        ];
        $session1 = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $graphQLPayload);

        $this->token1 = $session1['cookies']['a_session_' . $projectId];

        // Create session 2
        $graphQLPayload['variables']['email'] = $email2;

        $session2 = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $graphQLPayload);

        $this->token2 = $session2['cookies']['a_session_' . $projectId];

        // Create database
        $query = $this->getQuery(self::CREATE_DATABASE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => ID::unique(),
                'name' => 'Actors',
            ]
        ];
        $this->database = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $gqlPayload);

        // Create table
        $query = $this->getQuery(self::CREATE_TABLE);
        $userId = $this->account1['body']['data']['accountCreate']['_id'];
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $this->database['body']['data']['databasesCreate']['_id'],
                'tableId' => ID::unique(),
                'name' => 'Actors',
                'rowSecurity' => true,
                'permissions' => [
                    Permission::create(Role::user($userId))
                ]
            ]
        ];
        $this->table = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $gqlPayload);

        // Create string attribute
        $query = $this->getQuery(self::CREATE_STRING_COLUMN);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $this->database['body']['data']['databasesCreate']['_id'],
                'tableId' => $this->table['body']['data']['tablesDBCreateTable']['_id'],
                'key' => 'name',
                'size' => 256,
                'required' => true,
            ]
        ];
        $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $gqlPayload);

        sleep(1);
    }

    public function testInvalidAuth()
    {
        $projectId = $this->getProject()['$id'];

        // Create row as account 1
        $query = $this->getQuery(self::CREATE_ROW);
        $userId = $this->account1['body']['data']['accountCreate']['_id'];
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $this->database['body']['data']['databasesCreate']['_id'],
                'tableId' => $this->table['body']['data']['tablesDBCreateTable']['_id'],
                'rowId' => ID::unique(),
                'data' => [
                    'name' => 'John Doe',
                ],
                'permissions' => [
                    Permission::read(Role::user($userId)),
                    Permission::update(Role::user($userId)),
                    Permission::delete(Role::user($userId)),
                ]
            ]
        ];
        $row = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $this->token1,
        ], $gqlPayload);

        // Try to read as account 1
        $query = $this->getQuery(self::GET_ROW);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $this->database['body']['data']['databasesCreate']['_id'],
                'tableId' => $this->table['body']['data']['tablesDBCreateTable']['_id'],
                'rowId' => $row['body']['data']['tablesDBCreateRow']['_id'],
            ]
        ];
        $row = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $this->token1,
        ], $gqlPayload);

        $this->assertIsArray($row['body']['data']['tablesDBGetRow']);
        $this->assertArrayNotHasKey('errors', $row['body']);

        // Try to read as account 2
        $row = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $this->token2,
        ], $gqlPayload);

        $this->assertArrayHasKey('errors', $row['body']);
        $rowId = $gqlPayload['variables']['rowId'];
        $this->assertEquals("Row with the requested ID '$rowId' could not be found.", $row['body']['errors'][0]['message']);
    }

    public function testValidAuth()
    {
        $projectId = $this->getProject()['$id'];

        // Create row as account 1
        $query = $this->getQuery(self::CREATE_ROW);
        $userId = $this->account1['body']['data']['accountCreate']['_id'];
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $this->database['body']['data']['databasesCreate']['_id'],
                'tableId' => $this->table['body']['data']['tablesDBCreateTable']['_id'],
                'rowId' => ID::unique(),
                'data' => [
                    'name' => 'John Doe',
                ],
                'permissions' => [
                    Permission::read(Role::user($userId)),
                    Permission::update(Role::user($userId)),
                    Permission::delete(Role::user($userId)),
                ],
            ]
        ];
        $row = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $this->token1,
        ], $gqlPayload);

        // Try to delete as account 1
        $query = $this->getQuery(self::DELETE_ROW);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $this->database['body']['data']['databasesCreate']['_id'],
                'tableId' => $this->table['body']['data']['tablesDBCreateTable']['_id'],
                'rowId' => $row['body']['data']['tablesDBCreateRow']['_id'],
            ]
        ];
        $row = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $this->token1,
        ], $gqlPayload);

        $this->assertIsNotArray($row['body']);
        $this->assertEquals(204, $row['headers']['status-code']);
    }
}
