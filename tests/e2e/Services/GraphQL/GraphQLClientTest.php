<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Scopes\SideServer;


class GraphQLClientTest extends Scope 
{
    use SideClient;
    use GraphQLBase;

    public function testCreateAccounts(): array{
        $projectId = $this->getProject()['$id'];
        
        /* 
        * Create First Account
        */ 
        $query = $this->getQuery(self::$CREATE_ACCOUNT);
        $variables = [
            "email" => "test1@test.com",
            "password" => "testtest",
            "name" => "test1"
        ];
        $graphQLPayload = [
            "query" => $query,
            "variables" => $variables
        ];
        $account1 = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $graphQLPayload);

        $this->assertEquals($account1['headers']['status-code'], 201);
        $this->assertNull($account1['body']['errors']);
        $this->assertIsArray($account1['body']['data']);
        $this->assertIsArray($account1['body']['data']['account_create']);
        $account1 = $account1['body']['data']['account_create'];
        $this->assertEquals($variables['name'], $account1['name']);
        $this->assertEquals($variables['email'], $account1['email']);

        /* 
        * Create First Account Session
        */ 
        $query = $this->getQuery(self::$CREATE_ACCOUNT_SESSION);
        $graphQLPayload = [
            "query" => $query,
            "variables" => $variables
        ];
        $session1 = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $graphQLPayload);
        $this->assertEquals($session1['headers']['status-code'], 201);
        $this->assertNull($session1['body']['errors']);
        $this->assertIsArray($session1['body']['data']);
        $this->assertIsArray($session1['body']['data']['account_createSession']);
        $session1Cookie = $this->client->parseCookie((string)$session1['headers']['set-cookie'])['a_session_'.$this->getProject()['$id']];
    
        /* 
        * Create Second Account
        */ 
        $query = $this->getQuery(self::$CREATE_ACCOUNT);
        $variables = [
            "email" => "test2@test.com",
            "password" => "testtest",
            "name" => "test2"
        ];
        $graphQLPayload = [
            "query" => $query,
            "variables" => $variables
        ];
        $account2 = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $graphQLPayload);

        $this->assertEquals($account2['headers']['status-code'], 201);
        $this->assertNull($account2['body']['errors']);
        $this->assertIsArray($account2['body']['data']);
        $this->assertIsArray($account2['body']['data']['account_create']);
        $account2 = $account2['body']['data']['account_create'];
        $this->assertEquals($variables['name'], $account2['name']);
        $this->assertEquals($variables['email'], $account2['email']);

        /* 
        * Create Second Account Session
        */ 
        $query = $this->getQuery(self::$CREATE_ACCOUNT_SESSION);
        $graphQLPayload = [
            "query" => $query,
            "variables" => $variables
        ];
        $session2 = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $graphQLPayload);
        $this->assertEquals($session2['headers']['status-code'], 201);
        $this->assertNull($session2['body']['errors']);
        $this->assertIsArray($session2['body']['data']);
        $this->assertIsArray($session2['body']['data']['account_createSession']);
        $session2Cookie = $this->client->parseCookie((string)$session2['headers']['set-cookie'])['a_session_'.$this->getProject()['$id']];
    
        return [
            "session1Cookie" => $session1Cookie,
            "user1Id" => $session1['body']['data']['account_createSession']['userId'],
            "session2Cookie" => $session2Cookie,
            "user2Id" => $session2['body']['data']['account_createSession']['userId'],
        ];
    }

    /**
    * @depends testCreateCollection
    * @depends testCreateAccounts
    */
    public function testWildCardPermissions(array $collections, array $accounts) {
        $projectId = $this->getProject()['$id'];
        /*
        * Account 1 Creates a document with wildcard permissions
        */
        $query = $this->getQuery(self::$CREATE_DOCUMENT);
        $createDocumentVariables = [
            'collectionId' => $collections['actorsId'],
            'data' => [
                'firstName' => 'Robert',
                'lastName' => "Downey"
            ],
            'read' => ['*'],
            'write' => ['*'],
        ];
        $graphQLPayload = [
            "query" => $query,
            "variables" => $createDocumentVariables
        ];
        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $accounts['session1Cookie'],
        ], $graphQLPayload);

        $this->assertEquals($document['headers']['status-code'], 201);
        $this->assertNull($document['body']['errors']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['database_createDocument']);
        $doc = $document['body']['data']['database_createDocument'];
        $this->assertArrayHasKey('$id', $doc);
        $this->assertEquals($collections['actorsId'], $doc['$collection']);
        $this->assertEquals('Robert', $doc['firstName']);
        $this->assertEquals('Downey', $doc['lastName']);
        $permissions = $doc['$permissions'];
        $this->assertEquals($createDocumentVariables['read'], $permissions['read']);
        $this->assertEquals($createDocumentVariables['write'], $permissions['write']);

        /*
        * Account 1 tries to access it 
        */
        $query = $this->getQuery(self::$GET_DOCUMENT);
        $getDocumentVariables = [
            'collectionId' => $collections['actorsId'],
            'documentId' => $doc['$id']
        ];
        $graphQLPayload = [
            "query" => $query,
            "variables" => $getDocumentVariables
        ];
        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $accounts['session1Cookie'],
        ], $graphQLPayload);

        $this->assertEquals($document['headers']['status-code'], 200);
        $this->assertNull($document['body']['errors']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['database_getDocument']);
        $doc = $document['body']['data']['database_getDocument'];
        $this->assertArrayHasKey('$id', $doc);
        $this->assertEquals($collections['actorsId'], $doc['$collection']);
        $this->assertEquals('Robert', $doc['firstName']);
        $this->assertEquals('Downey', $doc['lastName']);
        $permissions = $doc['$permissions'];
        $this->assertEquals($createDocumentVariables['read'], $permissions['read']);
        $this->assertEquals($createDocumentVariables['write'], $permissions['write']);

        /*
        * Account 2 tries to access it 
        */
        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $accounts['session2Cookie'],
        ], $graphQLPayload);
        $this->assertEquals($document['headers']['status-code'], 200);
        $this->assertNull($document['body']['errors']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['database_getDocument']);
        $doc = $document['body']['data']['database_getDocument'];
        $this->assertArrayHasKey('$id', $doc);
        $this->assertEquals($collections['actorsId'], $doc['$collection']);
        $this->assertEquals('Robert', $doc['firstName']);
        $this->assertEquals('Downey', $doc['lastName']);
        $permissions = $doc['$permissions'];
        $this->assertEquals($createDocumentVariables['read'], $permissions['read']);
        $this->assertEquals($createDocumentVariables['write'], $permissions['write']);
    }


    /**
    * @depends testCreateCollection
    * @depends testCreateAccounts
    */
    public function testUserRole(array $collections, array $accounts) {
        $projectId = $this->getProject()['$id'];
        /*
        * Account 1 Creates a document with user permissions
        */
        $query = $this->getQuery(self::$CREATE_DOCUMENT);
        $createDocumentVariables = [
            'collectionId' => $collections['actorsId'],
            'data' => [
                'firstName' => 'Robert',
                'lastName' => "Downey"
            ],
            'read' => ["user:{$accounts['user1Id']}"],
            'write' => ["user:{$accounts['user1Id']}"],
        ];
        $graphQLPayload = [
            "query" => $query,
            "variables" => $createDocumentVariables
        ];
        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $accounts['session1Cookie'],
        ], $graphQLPayload);

        $this->assertEquals($document['headers']['status-code'], 201);
        $this->assertNull($document['body']['errors']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['database_createDocument']);
        $doc = $document['body']['data']['database_createDocument'];
        $this->assertArrayHasKey('$id', $doc);
        $this->assertEquals($collections['actorsId'], $doc['$collection']);
        $this->assertEquals($createDocumentVariables['data']['firstName'], $doc['firstName']);
        $this->assertEquals($createDocumentVariables['data']['lastName'], $doc['lastName']);
        $permissions = $doc['$permissions'];
        $this->assertEquals($createDocumentVariables['read'], $permissions['read']);
        $this->assertEquals($createDocumentVariables['write'], $permissions['write']);

        /*
        * Account 1 tries to access it 
        */
        $query = $this->getQuery(self::$GET_DOCUMENT);
        $getDocumentVariables = [
            'collectionId' => $collections['actorsId'],
            'documentId' => $doc['$id']
        ];
        $graphQLPayload = [
            "query" => $query,
            "variables" => $getDocumentVariables
        ];
        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $accounts['session1Cookie'],
        ], $graphQLPayload);

        $this->assertEquals($document['headers']['status-code'], 200);
        $this->assertNull($document['body']['errors']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['database_getDocument']);
        $doc = $document['body']['data']['database_getDocument'];
        $this->assertArrayHasKey('$id', $doc);
        $this->assertEquals($collections['actorsId'], $doc['$collection']);
        $this->assertEquals($createDocumentVariables['data']['firstName'], $doc['firstName']);
        $this->assertEquals($createDocumentVariables['data']['lastName'], $doc['lastName']);
        $permissions = $doc['$permissions'];
        $this->assertEquals($createDocumentVariables['read'], $permissions['read']);
        $this->assertEquals($createDocumentVariables['write'], $permissions['write']);

        /*
        * Account 2 tries to access it 
        */
        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $accounts['session2Cookie'],
        ], $graphQLPayload);

        $this->assertEquals($document['headers']['status-code'], 404);
        $this->assertEquals($document['body']['errors'][0]['message'], "No document found");

        /*
        * Account 1 Updates the document permissions
        */
        $query = $this->getQuery(self::$UPDATE_DOCUMENT);
        $updateDocumentVariables = [
            'collectionId' => $collections['actorsId'],
            'documentId' => $doc['$id'],
            'data' => [],
            'read' => ['*'],
            'write' => ['*']
        ];
        $graphQLPayload = [
            "query" => $query,
            "variables" => $updateDocumentVariables
        ];
        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $accounts['session1Cookie'],
        ], $graphQLPayload);

        $this->assertEquals($document['headers']['status-code'], 200);
        $this->assertNull($document['body']['errors']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['database_updateDocument']);
        $doc = $document['body']['data']['database_updateDocument'];
        $this->assertArrayHasKey('$id', $doc);
        $this->assertEquals($collections['actorsId'], $doc['$collection']);
        $this->assertEquals($createDocumentVariables['data']['firstName'], $doc['firstName']);
        $this->assertEquals($createDocumentVariables['data']['lastName'], $doc['lastName']);
        $permissions = $doc['$permissions'];
        $this->assertEquals($updateDocumentVariables['read'], $permissions['read']);
        $this->assertEquals($updateDocumentVariables['write'], $permissions['write']);

        /*
        * Account 2 tries to access it 
        */
        $query = $this->getQuery(self::$GET_DOCUMENT);
        $getDocumentVariables = [
            'collectionId' => $collections['actorsId'],
            'documentId' => $doc['$id']
        ];
        $graphQLPayload = [
            "query" => $query,
            "variables" => $getDocumentVariables
        ];
        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $accounts['session2Cookie'],
        ], $graphQLPayload);

        $this->assertEquals($document['headers']['status-code'], 200);
        $this->assertNull($document['body']['errors']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['database_getDocument']);
        $doc = $document['body']['data']['database_getDocument'];
        $this->assertArrayHasKey('$id', $doc);
        $this->assertEquals($collections['actorsId'], $doc['$collection']);
        $this->assertEquals($createDocumentVariables['data']['firstName'], $doc['firstName']);
        $this->assertEquals($createDocumentVariables['data']['lastName'], $doc['lastName']);
        $permissions = $doc['$permissions'];
        $this->assertEquals($updateDocumentVariables['read'], $permissions['read']);
        $this->assertEquals($updateDocumentVariables['write'], $permissions['write']);
    }

    /**
    * @depends testCreateCollection
    * @depends testCreateAccounts
    */
    public function testTeamRole(array $collections, array $accounts) {
        $projectId = $this->getProject()['$id'];
        /**
         * Account 1 creates a team
         */
        $query = $this->getQuery(self::$CREATE_TEAM);
        $createTeamVariables = [
            'name' => 'Test Team'
        ];
        $graphQLPayload = [
            "query" => $query,
            "variables" => $createTeamVariables
        ];
        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $accounts['session1Cookie'],
        ], $graphQLPayload);

        $this->assertEquals($document['headers']['status-code'], 201);
        $this->assertNull($document['body']['errors']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['teams_create']);
        $team = $document['body']['data']['teams_create'];
        $this->assertArrayHasKey('id', $team);
        $this->assertEquals($createTeamVariables['name'], $team['name'] );

        /*
        * Account 1 Creates a document with team permissions
        */
        $query = $this->getQuery(self::$CREATE_DOCUMENT);
        $createDocumentVariables = [
            'collectionId' => $collections['actorsId'],
            'data' => [
                'firstName' => 'Robert',
                'lastName' => "Downey"
            ],
            'read' => ["team:{$team['id']}"],
            'write' => ["team:{$team['id']}"],
        ];
        $graphQLPayload = [
            "query" => $query,
            "variables" => $createDocumentVariables
        ];
        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $accounts['session1Cookie'],
        ], $graphQLPayload);

        $this->assertEquals($document['headers']['status-code'], 201);
        $this->assertNull($document['body']['errors']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['database_createDocument']);
        $doc = $document['body']['data']['database_createDocument'];
        $this->assertArrayHasKey('$id', $doc);
        $this->assertEquals($collections['actorsId'], $doc['$collection']);
        $this->assertEquals($createDocumentVariables['data']['firstName'], $doc['firstName']);
        $this->assertEquals($createDocumentVariables['data']['lastName'], $doc['lastName']);
        $permissions = $doc['$permissions'];
        $this->assertEquals($createDocumentVariables['read'], $permissions['read']);
        $this->assertEquals($createDocumentVariables['write'], $permissions['write']);

        /*
        * Account 1 tries to access it 
        */
        $query = $this->getQuery(self::$GET_DOCUMENT);
        $getDocumentVariables = [
            'collectionId' => $collections['actorsId'],
            'documentId' => $doc['$id']
        ];
        $graphQLPayload = [
            "query" => $query,
            "variables" => $getDocumentVariables
        ];
        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $accounts['session1Cookie'],
        ], $graphQLPayload);

        $this->assertEquals($document['headers']['status-code'], 200);
        $this->assertNull($document['body']['errors']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['database_getDocument']);
        $doc = $document['body']['data']['database_getDocument'];
        $this->assertArrayHasKey('$id', $doc);
        $this->assertEquals($collections['actorsId'], $doc['$collection']);
        $this->assertEquals($createDocumentVariables['data']['firstName'], $doc['firstName']);
        $this->assertEquals($createDocumentVariables['data']['lastName'], $doc['lastName']);
        $permissions = $doc['$permissions'];
        $this->assertEquals($createDocumentVariables['read'], $permissions['read']);
        $this->assertEquals($createDocumentVariables['write'], $permissions['write']);

        /*
        * Create a membership 
        */
        $email = uniqid().'friend@localhost.test';
        $query = $this->getQuery(self::$CREATE_TEAM_MEMBERSHIP);
        $createMembershipVariable = [
            'teamId' => $team['id'] ,
            'name' => 'Friend User',
            'email' => $email,
            'roles' => ['owner'],
            'url' => 'http://localhost:5000/join-us#title'
        ];
        $graphQLPayload = [
            "query" => $query,
            "variables" => $createMembershipVariable
        ];
        $membership = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_'.$this->getProject()['$id'].'=' . $accounts['session1Cookie'],
        ], $graphQLPayload);

        $this->assertEquals($membership['headers']['status-code'], 201);
        $this->assertNull($membership['body']['errors']);
        $this->assertIsArray($membership['body']['data']);
        $this->assertIsArray($membership['body']['data']['teams_createMembership']);
        $membership = $membership['body']['data']['teams_createMembership'];
        $this->assertNotEmpty($membership['id']);
        $this->assertNotEmpty($membership['userId']);
        $this->assertNotEmpty($membership['teamId']);
        $this->assertCount(1, $membership['roles']);
        $this->assertIsInt($membership['joined']);
        $this->assertEquals(false, $membership['confirm']);


        $lastEmail = $this->getLastEmail();

        $this->assertEquals($email, $lastEmail['to'][0]['address']);
        $this->assertEquals('Friend User', $lastEmail['to'][0]['name']);
        $this->assertEquals('Invitation to '.$createTeamVariables['name'].' Team at '.$this->getProject()['name'], $lastEmail['subject']);

        $secret = substr($lastEmail['text'], strpos($lastEmail['text'], '&secret=', 0) + 8, 256);
        $inviteUid = substr($lastEmail['text'], strpos($lastEmail['text'], '?inviteId=', 0) + 10, 13);
        $userUid = substr($lastEmail['text'], strpos($lastEmail['text'], '&userId=', 0) + 8, 13);

        /** Update membership status  */
        $query = $this->getQuery(self::$UPDATE_MEMBERSHIP_STATUS);
        $updateMembershipStatus = [
            'teamId' => $team['id'] ,
            'inviteId' => $inviteUid,
            'userId' => $userUid,
            'secret' => $secret,
        ];
        $graphQLPayload = [
            "query" => $query,
            "variables" => $updateMembershipStatus
        ];
        $updatedMembership = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $graphQLPayload);

        $this->assertEquals($updatedMembership['headers']['status-code'], 200);
        $this->assertNull($updatedMembership['body']['errors']);
        $this->assertIsArray($updatedMembership['body']['data']);
        $this->assertIsArray($updatedMembership['body']['data']['teams_updateMembershipStatus']);
        $updatedMembership = $updatedMembership['body']['data']['teams_updateMembershipStatus'];
        $this->assertNotEmpty($membership['id'], $updatedMembership['id']);
        $this->assertEquals($membership['userId'],$updatedMembership['userId']);
        $this->assertEquals($membership['teamId'], $updatedMembership['teamId']);
        $this->assertCount(1, $updatedMembership['roles']);
        $this->assertIsInt($updatedMembership['joined']);
        $this->assertEquals(true, $updatedMembership['confirm']);

    }

}