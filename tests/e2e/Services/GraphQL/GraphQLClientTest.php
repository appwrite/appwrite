<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;


class GraphQLClientTest extends Scope
{
    use ProjectCustom;
    use SideClient;
    use GraphQLBase;

    public function testCreateAccounts(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_ACCOUNT);
        $email = 'test' . \rand() . '@test.com';
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => 'unique()',
                'name' => 'Tester',
                'email' => $email,
                'password' => 'password',
            ],
        ];
        $account1 = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $graphQLPayload);

        $this->assertIsArray($account1['body']['data']);
        $account1 = $account1['body']['data']['accountCreate'];
        $this->assertEquals('Tester', $account1['name']);
        $this->assertEquals($email, $account1['email']);

        // Create First Account Session
        $query = $this->getQuery(self::$CREATE_ACCOUNT_SESSION);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'email' => $email,
                'password' => 'password',
            ]
        ];
        $session1 = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $graphQLPayload);

        $this->assertIsArray($session1['body']['data']);
        $this->assertIsArray($session1['body']['data']['accountCreateSession']);

        $session1Cookie = $this->client->parseCookie((string)$session1['headers']['set-cookie'])['a_session_' . $this->getProject()['$id']];

        /* 
        * Create Second Account
        */
        $query = $this->getQuery(self::$CREATE_ACCOUNT);
        $email = 'test' . \rand() . '@test.com';
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'userId' => 'unique()',
                'email' => $email,
                'password' => 'password',
                'name' => 'Tester2',
            ],
        ];
        $account2 = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $graphQLPayload);

        $this->assertIsArray($account2['body']['data']);
        $this->assertIsArray($account2['body']['data']['accountCreate']);
        $this->assertArrayNotHasKey('errors', $account2['body']);

        $account2 = $account2['body']['data']['accountCreate'];
        $this->assertEquals('Tester2', $account2['name']);
        $this->assertEquals($email, $account2['email']);

        /* 
        * Create Second Account Session
        */
        $query = $this->getQuery(self::$CREATE_ACCOUNT_SESSION);
        $graphQLPayload = [
            'query' => $query,
            'variables' => [
                'email' => $email,
                'password' => 'password',
            ],
        ];
        $session2 = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $graphQLPayload);

        $this->assertIsArray($session2['body']['data']);
        $this->assertIsArray($session2['body']['data']['accountCreateSession']);
        $this->assertArrayNotHasKey('errors', $session2['body']);
        $session2Cookie = $this->client->parseCookie((string)$session2['headers']['set-cookie'])['a_session_' . $this->getProject()['$id']];

        return [
            'session1Cookie' => $session1Cookie,
            'session2Cookie' => $session2Cookie,
            'user1Id' => $session1['body']['data']['accountCreateSession']['userId'],
            'user2Id' => $session2['body']['data']['accountCreateSession']['userId'],
        ];
    }

    /**
     * @depends testCreateCollection
     * @depends testCreateAccounts
     */
    public function testWildCardPermissions(array $data, array $accounts)
    {
        $projectId = $this->getProject()['$id'];

        /*
        * Account 1 Creates a document with wildcard permissions
        */
        $query = $this->getQuery(self::$CREATE_DOCUMENT_REST);

        $docVariables = [
            'documentId' => 'unique()',
            'collectionId' => $data['collectionId'],
            'data' => [
                'name' => 'Robert',
                'age' => 100,
                'alive' => true,
            ],
            'read' => ['role:all'],
            'write' => ['role:all'],
        ];
        $graphQLPayload = [
            'query' => $query,
            'variables' => $docVariables
        ];
        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $accounts['session1Cookie'],
        ], $graphQLPayload);

        $this->assertNull($document['body']['errors']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['databaseCreateDocument']);

        $doc = $document['body']['data']['databaseCreateDocument'];
        $this->assertArrayHasKey('$id', $doc);
        $this->assertEquals($data['collectionId'], $doc['$collection']);
        $this->assertEquals('Robert', $doc['name']);
        $this->assertEquals(100, $doc['age']);

        $this->assertEquals($docVariables['read'], $doc['read']);
        $this->assertEquals($docVariables['write'], $doc['write']);

        /*
        * Account 1 tries to access it 
        */
        $query = $this->getQuery(self::$GET_DOCUMENT);
        $getDocumentVariables = [
            'collectionId' => $data['collectionId'],
            'documentId' => $doc['$id']
        ];
        $graphQLPayload = [
            'query' => $query,
            'variables' => $getDocumentVariables
        ];
        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $accounts['session1Cookie'],
        ], $graphQLPayload);

        $this->assertNull($document['body']['errors']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['databaseGetDocument']);

        $doc = $document['body']['data']['databaseGetDocument'];
        $this->assertArrayHasKey('$id', $doc);
        $this->assertEquals($data['collectionId'], $doc['$collection']);
        $this->assertEquals('Robert', $doc['name']);
        $this->assertEquals(100, $doc['age']);
        $this->assertEquals($docVariables['read'], $doc['read']);
        $this->assertEquals($docVariables['write'], $doc['write']);

        /*
        * Account 2 tries to access it 
        */
        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $accounts['session2Cookie'],
        ], $graphQLPayload);

        $this->assertNull($document['body']['errors']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['databaseGetDocument']);

        $doc = $document['body']['data']['databaseGetDocument'];
        $this->assertArrayHasKey('$id', $doc);
        $this->assertEquals($data['collectionId'], $doc['$collection']);
        $this->assertEquals('Robert', $doc['name']);
        $this->assertEquals(100, $doc['age']);
        $this->assertEquals($docVariables['read'], $doc['read']);
        $this->assertEquals($docVariables['write'], $doc['write']);
    }

    /**
     * @depends testCreateCollection
     * @depends testCreateAccounts
     * @throws \Exception
     */
    public function testUserRole(array $data, array $accounts)
    {
        $projectId = $this->getProject()['$id'];

        /*
        * Account 1 Creates a document with user permissions
        */
        $query = $this->getQuery(self::$CREATE_DOCUMENT_REST);
        $createDocumentVariables = [
            'collectionId' => $data['collectionId'],
            'data' => [
                'name' => 'Robert',
                'age' => '100',
                'alive' => true,
            ],
            'read' => ["user:{$accounts['user1Id']}"],
            'write' => ["user:{$accounts['user1Id']}"],
        ];

        $graphQLPayload = [
            'query' => $query,
            'variables' => $createDocumentVariables
        ];

        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $accounts['session1Cookie'],
        ], $graphQLPayload);

        $this->assertNull($document['body']['errors']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['databaseCreateDocument']);

        $doc = $document['body']['data']['databaseCreateDocument'];
        $this->assertArrayHasKey('$id', $doc);
        $this->assertEquals($data['collectionId'], $doc['$collection']);
        $this->assertEquals($createDocumentVariables['data']['name'], $doc['name']);
        $this->assertEquals($createDocumentVariables['data']['age'], $doc['age']);
        $this->assertEquals($createDocumentVariables['read'], $doc['read']);
        $this->assertEquals($createDocumentVariables['write'], $doc['write']);

        /*
        * Account 1 tries to access it 
        */
        $query = $this->getQuery(self::$GET_DOCUMENT);
        $getDocumentVariables = [
            'collectionId' => $data['collectionId'],
            'documentId' => $doc['$id']
        ];
        $graphQLPayload = [
            'query' => $query,
            'variables' => $getDocumentVariables
        ];
        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $accounts['session1Cookie'],
        ], $graphQLPayload);

        $this->assertNull($document['body']['errors']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['databaseGetDocument']);

        $doc = $document['body']['data']['databaseGetDocument'];
        $this->assertArrayHasKey('$id', $doc);
        $this->assertEquals($data['collectionId'], $doc['$collection']);
        $this->assertEquals($createDocumentVariables['data']['name'], $doc['name']);
        $this->assertEquals($createDocumentVariables['data']['age'], $doc['age']);
        $this->assertEquals($createDocumentVariables['read'], $doc['read']);
        $this->assertEquals($createDocumentVariables['write'], $doc['write']);

        /*
        * Account 2 tries to access it 
        */
        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $accounts['session2Cookie'],
        ], $graphQLPayload);

        $this->assertEquals('No document found', $document['body']['errors'][0]['message']);

        /*
        * Account 1 Updates the document permissions
        */
        $query = $this->getQuery(self::$UPDATE_DOCUMENT);
        $updateDocumentVariables = [
            'collectionId' => $data['collectionId'],
            'documentId' => $doc['$id'],
            'data' => [],
            'read' => ['role:all'],
            'write' => ['role:all']
        ];
        $graphQLPayload = [
            'query' => $query,
            'variables' => $updateDocumentVariables
        ];
        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $accounts['session1Cookie'],
        ], $graphQLPayload);

        $this->assertNull($document['body']['errors']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['databaseUpdateDocument']);

        $doc = $document['body']['data']['database_updateDocument'];
        $this->assertArrayHasKey('$id', $doc);
        $this->assertEquals($data['collectionId'], $doc['$collection']);
        $this->assertEquals($createDocumentVariables['data']['name'], $doc['name']);
        $this->assertEquals($createDocumentVariables['data']['age'], $doc['age']);
        $this->assertEquals($updateDocumentVariables['read'], $doc['read']);
        $this->assertEquals($updateDocumentVariables['write'], $doc['write']);

        /*
        * Account 2 tries to access it 
        */
        $query = $this->getQuery(self::$GET_DOCUMENT);
        $getDocumentVariables = [
            'collectionId' => $data['collectionId'],
            'documentId' => $doc['$id']
        ];
        $graphQLPayload = [
            'query' => $query,
            'variables' => $getDocumentVariables
        ];
        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $accounts['session2Cookie'],
        ], $graphQLPayload);

        $this->assertNull($document['body']['errors']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['databaseGetDocument']);

        $doc = $document['body']['data']['databaseGetDocument'];
        $this->assertArrayHasKey('$id', $doc);
        $this->assertEquals($data['collectionId'], $doc['$collection']);
        $this->assertEquals($createDocumentVariables['data']['name'], $doc['name']);
        $this->assertEquals($createDocumentVariables['data']['age'], $doc['age']);
        $this->assertEquals($updateDocumentVariables['read'], $doc['read']);
        $this->assertEquals($updateDocumentVariables['write'], $doc['write']);
    }

    /**
     * @depends testCreateCollection
     * @depends testCreateAccounts
     * @throws \Exception
     */
    public function testTeamRole(array $data, array $accounts)
    {
        $projectId = $this->getProject()['$id'];
        /**
         * Account 1 creates a team
         */
        $query = $this->getQuery(self::$CREATE_TEAM);
        $createTeamVariables = [
            'name' => 'Test Team'
        ];
        $graphQLPayload = [
            'query' => $query,
            'variables' => $createTeamVariables
        ];
        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $accounts['session1Cookie'],
        ], $graphQLPayload);

        $this->assertNull($document['body']['errors']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['teams_create']);

        $team = $document['body']['data']['teams_create'];
        $this->assertArrayHasKey('id', $team);
        $this->assertEquals($createTeamVariables['name'], $team['name']);

        /*
        * Account 1 Creates a document with team permissions
        */
        $query = $this->getQuery(self::$CREATE_DOCUMENT_REST);
        $createDocumentVariables = [
            'collectionId' => $data['collectionId'],
            'data' => [
                'name' => 'Robert',
                'age' => 100
            ],
            'read' => ["team:{$team['id']}"],
            'write' => ["team:{$team['id']}"],
        ];
        $graphQLPayload = [
            'query' => $query,
            'variables' => $createDocumentVariables
        ];
        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $accounts['session1Cookie'],
        ], $graphQLPayload);

        $this->assertNull($document['body']['errors']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['databaseCreateDocument']);

        $doc = $document['body']['data']['databaseCreateDocument'];
        $this->assertArrayHasKey('$id', $doc);
        $this->assertEquals($data['collectionId'], $doc['$collection']);
        $this->assertEquals($createDocumentVariables['data']['name'], $doc['name']);
        $this->assertEquals($createDocumentVariables['data']['age'], $doc['age']);
        $this->assertEquals($createDocumentVariables['read'], $doc['read']);
        $this->assertEquals($createDocumentVariables['write'], $doc['write']);

        /*
        * Account 1 tries to access it 
        */
        $query = $this->getQuery(self::$GET_DOCUMENT);
        $getDocumentVariables = [
            'collectionId' => $data['collectionId'],
            'documentId' => $doc['$id']
        ];
        $graphQLPayload = [
            'query' => $query,
            'variables' => $getDocumentVariables
        ];
        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $accounts['session1Cookie'],
        ], $graphQLPayload);

        $this->assertNull($document['body']['errors']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['databaseGetDocument']);

        $doc = $document['body']['data']['databaseGetDocument'];
        $this->assertArrayHasKey('$id', $doc);
        $this->assertEquals($data['collectionId'], $doc['$collection']);
        $this->assertEquals($createDocumentVariables['data']['name'], $doc['name']);
        $this->assertEquals($createDocumentVariables['data']['age'], $doc['age']);
        $this->assertEquals($createDocumentVariables['read'], $doc['read']);
        $this->assertEquals($createDocumentVariables['write'], $doc['write']);

        /*
        * Create a membership 
        */
        $email = \rand() . 'friend@localhost.test';
        $query = $this->getQuery(self::$CREATE_TEAM_MEMBERSHIP);
        $createMembershipVariable = [
            'teamId' => $team['id'],
            'name' => 'Friend User',
            'email' => $email,
            'roles' => ['owner'],
            'url' => 'http://localhost:5000/join-us#title'
        ];
        $graphQLPayload = [
            'query' => $query,
            'variables' => $createMembershipVariable
        ];
        $membership = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $accounts['session1Cookie'],
        ], $graphQLPayload);

        $this->assertNull($membership['body']['errors']);
        $this->assertIsArray($membership['body']['data']);
        $this->assertIsArray($membership['body']['data']['teamsCreateMembership']);

        $membership = $membership['body']['data']['teamsCreateMembership'];
        $this->assertNotEmpty($membership['id']);
        $this->assertNotEmpty($membership['userId']);
        $this->assertNotEmpty($membership['teamId']);
        $this->assertCount(1, $membership['roles']);
        $this->assertIsInt($membership['joined']);
        $this->assertEquals(false, $membership['confirm']);

        $lastEmail = $this->getLastEmail();

        $this->assertEquals($email, $lastEmail['to'][0]['address']);
        $this->assertEquals('Friend User', $lastEmail['to'][0]['name']);
        $this->assertEquals('Invitation to ' . $createTeamVariables['name'] . ' Team at ' . $this->getProject()['name'], $lastEmail['subject']);

        $secret = substr($lastEmail['text'], strpos($lastEmail['text'], '&secret=', 0) + 8, 256);
        $inviteUid = substr($lastEmail['text'], strpos($lastEmail['text'], '?inviteId=', 0) + 10, 13);
        $userUid = substr($lastEmail['text'], strpos($lastEmail['text'], '&userId=', 0) + 8, 13);

        /** Update membership status  */
        $query = $this->getQuery(self::$UPDATE_MEMBERSHIP_STATUS);
        $updateMembershipStatus = [
            'teamId' => $team['id'],
            'inviteId' => $inviteUid,
            'userId' => $userUid,
            'secret' => $secret,
        ];
        $graphQLPayload = [
            'query' => $query,
            'variables' => $updateMembershipStatus
        ];
        $updatedMembership = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $graphQLPayload);

        $this->assertNull($updatedMembership['body']['errors']);
        $this->assertIsArray($updatedMembership['body']['data']);
        $this->assertIsArray($updatedMembership['body']['data']['teamsUpdateMembershipStatus']);

        $updatedMembership = $updatedMembership['body']['data']['teamsUpdateMembershipStatus'];
        $this->assertNotEmpty($membership['id'], $updatedMembership['id']);
        $this->assertEquals($membership['userId'], $updatedMembership['userId']);
        $this->assertEquals($membership['teamId'], $updatedMembership['teamId']);
        $this->assertCount(1, $updatedMembership['roles']);
        $this->assertIsInt($updatedMembership['joined']);
        $this->assertEquals(true, $updatedMembership['confirm']);

    }

}