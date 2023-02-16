<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Helpers\Permission;

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
    private array $collection;

    public function setUp(): void
    {
        parent::setUp();

        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_ACCOUNT);

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

        $account2 = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $graphQLPayload);

        // Create session 1
        $query = $this->getQuery(self::$CREATE_ACCOUNT_SESSION);
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

        $this->token1 = $this->client->parseCookie(
            (string)$session1['headers']['set-cookie']
        )['a_session_' . $projectId];

        // Create session 2
        $graphQLPayload['variables']['email'] = $email2;

        $session2 = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $graphQLPayload);

        $this->token2 = $this->client->parseCookie(
            (string)$session2['headers']['set-cookie']
        )['a_session_' . $projectId];

        // Create database
        $query = $this->getQuery(self::$CREATE_DATABASE);
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

        // Create collection
        $query = $this->getQuery(self::$CREATE_COLLECTION);
        $userId = $this->account1['body']['data']['accountCreate']['_id'];
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $this->database['body']['data']['databasesCreate']['_id'],
                'collectionId' => ID::unique(),
                'name' => 'Actors',
                'documentSecurity' => true,
                'permissions' => [
                    Permission::create(Role::user($userId))
                ]
            ]
        ];
        $this->collection = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], $gqlPayload);

        // Create string attribute
        $query = $this->getQuery(self::$CREATE_STRING_ATTRIBUTE);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $this->database['body']['data']['databasesCreate']['_id'],
                'collectionId' => $this->collection['body']['data']['databasesCreateCollection']['_id'],
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

        // Create document as account 1
        $query = $this->getQuery(self::$CREATE_DOCUMENT);
        $userId = $this->account1['body']['data']['accountCreate']['_id'];
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $this->database['body']['data']['databasesCreate']['_id'],
                'collectionId' => $this->collection['body']['data']['databasesCreateCollection']['_id'],
                'documentId' => ID::unique(),
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
        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $this->token1,
        ], $gqlPayload);

        // Try to read as account 1
        $query = $this->getQuery(self::$GET_DOCUMENT);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $this->database['body']['data']['databasesCreate']['_id'],
                'collectionId' => $this->collection['body']['data']['databasesCreateCollection']['_id'],
                'documentId' => $document['body']['data']['databasesCreateDocument']['_id'],
            ]
        ];
        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $this->token1,
        ], $gqlPayload);

        var_dump($document);
        $this->assertIsArray($document['body']['data']['databasesGetDocument']);
        $this->assertArrayNotHasKey('errors', $document['body']);

        // Try to read as account 2
        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $this->token2,
        ], $gqlPayload);

        $this->assertArrayHasKey('errors', $document['body']);
        $this->assertEquals('Document with the requested ID could not be found.', $document['body']['errors'][0]['message']);
    }

    public function testValidAuth()
    {
        $projectId = $this->getProject()['$id'];

        // Create document as account 1
        $query = $this->getQuery(self::$CREATE_DOCUMENT);
        $userId = $this->account1['body']['data']['accountCreate']['_id'];
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $this->database['body']['data']['databasesCreate']['_id'],
                'collectionId' => $this->collection['body']['data']['databasesCreateCollection']['_id'],
                'documentId' => ID::unique(),
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
        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $this->token1,
        ], $gqlPayload);

        // Try to delete as account 1
        $query = $this->getQuery(self::$DELETE_DOCUMENT);
        $gqlPayload = [
            'query' => $query,
            'variables' => [
                'databaseId' => $this->database['body']['data']['databasesCreate']['_id'],
                'collectionId' => $this->collection['body']['data']['databasesCreateCollection']['_id'],
                'documentId' => $document['body']['data']['databasesCreateDocument']['_id'],
            ]
        ];
        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $this->token1,
        ], $gqlPayload);

        $this->assertIsNotArray($document['body']);
        $this->assertEquals(204, $document['headers']['status-code']);
    }
}
