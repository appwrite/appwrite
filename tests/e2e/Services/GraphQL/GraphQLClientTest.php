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

    /**
     * @depends testCreateCollection
     * @depends testCreateStringAttribute
     * @depends testCreateIntegerAttribute
     * @depends testCreateBooleanAttribute
     * @depends testCreateAccounts
     */
    public function testWildCardPermissions(array $data, $str, $int, $bool, array $accounts)
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

        $this->assertArrayNotHasKey('errors', $document['body']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['databaseCreateDocument']);

        $doc = $document['body']['data']['databaseCreateDocument'];
        $this->assertArrayHasKey('_id', $doc);
        $this->assertEquals($data['collectionId'], $doc['_collection']);
        $this->assertEquals($docVariables['read'], $doc['_read']);
        $this->assertEquals($docVariables['write'], $doc['_write']);

        /*
        * Account 1 tries to access it 
        */
        $query = $this->getQuery(self::$GET_DOCUMENT);
        $getDocumentVariables = [
            'collectionId' => $data['collectionId'],
            'documentId' => $doc['_id']
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

        $this->assertArrayNotHasKey('errors', $document['body']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['databaseGetDocument']);

        $doc = $document['body']['data']['databaseGetDocument'];
        $this->assertArrayHasKey('_id', $doc);
        $this->assertEquals($data['collectionId'], $doc['_collection']);
        $this->assertEquals('Robert', $doc['name']);
        $this->assertEquals(100, $doc['age']);
        $this->assertEquals($docVariables['read'], $doc['_read']);
        $this->assertEquals($docVariables['write'], $doc['write']);

        /*
        * Account 2 tries to access it 
        */
        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $accounts['session2Cookie'],
        ], $graphQLPayload);

        $this->assertArrayNotHasKey('errors', $document['body']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['databaseGetDocument']);

        $doc = $document['body']['data']['databaseGetDocument'];
        $this->assertArrayHasKey('_id', $doc);
        $this->assertEquals($data['collectionId'], $doc['_collection']);
        $this->assertEquals('Robert', $doc['name']);
        $this->assertEquals(100, $doc['age']);
        $this->assertEquals($docVariables['read'], $doc['_read']);
        $this->assertEquals($docVariables['write'], $doc['write']);
    }

    /**
     * @depends testCreateCollection
     * @depends testCreateStringAttribute
     * @depends testCreateIntegerAttribute
     * @depends testCreateBooleanAttribute
     * @depends testCreateAccounts
     * @throws \Exception
     */
    public function testUserRole(array $data, $str, $int, $bool, array $accounts)
    {
        $projectId = $this->getProject()['$id'];

        /*
        * Account 1 Creates a document with user permissions
        */
        $query = $this->getQuery(self::$CREATE_DOCUMENT_REST);
        $createDocumentVariables = [
            'collectionId' => $data['collectionId'],
            'documentId' => 'unique()',
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

        $this->assertArrayNotHasKey('errors', $document['body']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['databaseCreateDocument']);

        $doc = $document['body']['data']['databaseCreateDocument'];
        $this->assertArrayHasKey('_id', $doc);
        $this->assertEquals($data['collectionId'], $doc['_collection']);
        $this->assertEquals($createDocumentVariables['read'], $doc['_read']);
        $this->assertEquals($createDocumentVariables['write'], $doc['_write']);

        /*
        * Account 1 tries to access it 
        */
        $query = $this->getQuery(self::$GET_DOCUMENT);
        $getDocumentVariables = [
            'collectionId' => $data['collectionId'],
            'documentId' => $doc['_id']
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

        $this->assertArrayNotHasKey('errors', $document['body']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['databaseGetDocument']);

        $doc = $document['body']['data']['databaseGetDocument'];
        $this->assertArrayHasKey('_id', $doc);
        $this->assertEquals($data['collectionId'], $doc['_collection']);
        $this->assertEquals($createDocumentVariables['data']['name'], $doc['name']);
        $this->assertEquals($createDocumentVariables['data']['age'], $doc['age']);
        $this->assertEquals($createDocumentVariables['read'], $doc['_read']);
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

        $this->assertArrayNotHasKey('errors', $document['body']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['databaseUpdateDocument']);

        $doc = $document['body']['data']['database_updateDocument'];
        $this->assertArrayHasKey('_id', $doc);
        $this->assertEquals($data['collectionId'], $doc['_collection']);
        $this->assertEquals($updateDocumentVariables['read'], $doc['_read']);
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

        $this->assertArrayNotHasKey('errors', $document['body']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['databaseGetDocument']);

        $doc = $document['body']['data']['databaseGetDocument'];
        $this->assertArrayHasKey('_id', $doc);
        $this->assertEquals($data['collectionId'], $doc['_collection']);
        $this->assertEquals($updateDocumentVariables['read'], $doc['_read']);
        $this->assertEquals($updateDocumentVariables['write'], $doc['write']);
    }

    /**
     * @depends testCreateCollection
     * @depends testCreateStringAttribute
     * @depends testCreateIntegerAttribute
     * @depends testCreateBooleanAttribute
     * @depends testCreateAccounts
     * @throws \Exception
     */
    public function testTeamRole(array $data, $str, $int, $bool, array $accounts)
    {
        $projectId = $this->getProject()['$id'];
        /**
         * Account 1 creates a team
         */
        $query = $this->getQuery(self::$CREATE_TEAM);
        $createTeamVariables = [
            'teamId' => 'unique()',
            'name' => 'Test Team',
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

        $this->assertArrayNotHasKey('errors', $document['body']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['teamsCreate']);

        $team = $document['body']['data']['teamsCreate'];
        $this->assertArrayHasKey('_id', $team);
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
            'read' => ["team:{$team['_id']}"],
            'write' => ["team:{$team['_id']}"],
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

        $this->assertArrayNotHasKey('errors', $document['body']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['databaseCreateDocument']);

        $doc = $document['body']['data']['databaseCreateDocument'];
        $this->assertArrayHasKey('_id', $doc);
        $this->assertEquals($data['collectionId'], $doc['_collection']);
        $this->assertEquals($createDocumentVariables['read'], $doc['_read']);
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

        $this->assertArrayNotHasKey('errors', $document['body']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['databaseGetDocument']);

        $doc = $document['body']['data']['databaseGetDocument'];
        $this->assertArrayHasKey('_id', $doc);
        $this->assertEquals($data['collectionId'], $doc['_collection']);
        $this->assertEquals($createDocumentVariables['read'], $doc['_read']);
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
        $query = $this->getQuery(self::$UPDATE_TEAM_MEMBERSHIP_STATUS);
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