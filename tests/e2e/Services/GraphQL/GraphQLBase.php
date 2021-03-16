<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;

trait GraphQLBase
{

    static $CREATE_COLLECTION = "create_collection";
    static $CREATE_DOCUMENT = "create_document";
    static $LIST_DOCUMENTS = "list_documents";
    static $GET_DOCUMENT = "get_document";
    static $UPDATE_DOCUMENT = "update_document";
    static $CREATE_USER = "create_user";
    static $LIST_COUNTRIES = "list_countries";
    static $CREATE_KEY = "create_key";
    static $CREATE_ACCOUNT = "create_account";
    static $CREATE_ACCOUNT_SESSION = "create_account_session";

    /**
     * @var array
     */
    protected static $project = [];

    /**
     * @return array
     */
    public function getProject(): array
    {
        if (!empty(self::$project)) {
            return self::$project;
        }

        $team = $this->client->call(Client::METHOD_POST, '/teams', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'name' => 'Demo Project Team',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);
        $this->assertEquals('Demo Project Team', $team['body']['name']);
        $this->assertNotEmpty($team['body']['$id']);

        $project = $this->client->call(Client::METHOD_POST, '/projects', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'name' => 'Demo Project',
            'teamId' => $team['body']['$id'],
            'description' => 'Demo Project Description',
            'logo' => '',
            'url' => 'https://appwrite.io',
            'legalName' => '',
            'legalCountry' => '',
            'legalState' => '',
            'legalCity' => '',
            'legalAddress' => '',
            'legalTaxId' => '',
        ]);

        $this->assertEquals(201, $project['headers']['status-code']);
        $this->assertNotEmpty($project['body']);

        self::$project = [
            '$id' => $project['body']['$id'],
            'name' => $project['body']['name']
        ];

        return self::$project;
    }

    public function testCreateCollection(): array {
        $projectId = $this->getProject()['$id'];
        $key = '';
        $query = $this->getQuery(self::$CREATE_COLLECTION);
        
        $actorsVariables = [
            'name' => 'Actors',
            'read' => ['*'],
            'write' => ['role:member', 'role:admin'],
            'rules' => [
                [
                    'label' => 'First Name',
                    'key' => 'firstName',
                    'type' => 'text',
                    'default' => '',
                    'required' => true,
                    'array' => false
                ],
                [
                    'label' => 'Last Name',
                    'key' => 'lastName',
                    'type' => 'text',
                    'default' => '',
                    'required' => true,
                    'array' => false
                ],
            ],
        ];

        $graphQLPayload = [
            "query" => $query,
            "variables" => $actorsVariables
        ];
        
        $actors = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ]), $graphQLPayload);

        $errorMessage = "User (role: guest) missing scope (collections.write)";
        $this->assertEquals($actors['headers']['status-code'], 401);
        $this->assertEquals($actors['body']['errors'][0]['message'], $errorMessage);
        $this->assertIsArray($actors['body']['data']);
        $this->assertNull($actors['body']['data']['database_createCollection']);

        $key = $this->createKey('test', ['collections.write']);
        $actors = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ]), $graphQLPayload);

        $this->assertEquals($actors['headers']['status-code'], 201);
        $this->assertNull($actors['body']['errors']);
        $this->assertIsArray($actors['body']['data']);
        $this->assertIsArray($actors['body']['data']['database_createCollection']);

        $data = $actors['body']['data']['database_createCollection'];
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('permissions', $data);
        $this->assertEquals('Actors', $data['name']);
        $this->assertArrayHasKey('dateCreated', $data);
        $this->assertArrayHasKey('dateUpdated', $data);
        $this->assertArrayHasKey('rules', $data);
       
        $permissions = $data['permissions'];
        $this->assertIsArray($permissions);
        $this->assertArrayHasKey('read', $permissions);
        $this->assertArrayHasKey('write', $permissions);
        $read = $permissions['read'];
        $this->assertContains('*', $read);
        $write = $permissions['write'];
        $this->assertContains('role:member', $write);
        $this->assertContains('role:admin', $write);
        
        $rules = $data['rules'];
        $this->assertIsArray($rules);
        $this->assertCount(2, $rules);
        $firstRule = $rules[0];
        $this->assertArrayHasKey('id', $firstRule);
        $this->assertEquals('rules', $firstRule['collection']);
        $this->assertEquals('text', $firstRule['type']);
        $this->assertEquals('firstName', $firstRule['key']);
        $this->assertEquals('First Name', $firstRule['label']);
        $this->assertEquals('', $firstRule['default']);
        $this->assertEquals(false, $firstRule['array']);
        $this->assertEquals(true, $firstRule['required']);
        $this->assertEquals([], $firstRule['list']);
        $secondRule = $rules[1];
        $this->assertArrayHasKey('id', $secondRule);
        $this->assertEquals('rules', $secondRule['collection']);
        $this->assertEquals('text', $secondRule['type']);
        $this->assertEquals('lastName', $secondRule['key']);
        $this->assertEquals('Last Name', $secondRule['label']);
        $this->assertEquals('', $secondRule['default']);
        $this->assertEquals(false, $secondRule['array']);
        $this->assertEquals(true, $secondRule['required']);
        $this->assertEquals([], $secondRule['list']);

        // $moviesVariables = [
        //     'name' => 'Movies',
        //     'read' => ['*'],
        //     'write' => ['role:member', 'role:admin'],
        //     'rules' => [
        //         [
        //             'label' => 'Name',
        //             'key' => 'name',
        //             'type' => 'text',
        //             'default' => '',
        //             'required' => true,
        //             'array' => false
        //         ],
        //         [
        //             'label' => 'Release Year',
        //             'key' => 'releaseYear',
        //             'type' => 'numeric',
        //             'default' => 0,
        //             'required' => false,
        //             'array' => false
        //         ],
        //         [
        //             'label' => 'Actors',
        //             'key' => 'actors',
        //             'type' => 'document',
        //             'default' => [],
        //             'required' => false,
        //             'array' => true,
        //             'list' => [$data['id']],
        //         ],
        //     ],
        // ];
        
        // $graphQLPayload = [
        //     "query" => $query,
        //     "variables" => $moviesVariables
        // ];
        
        // $movies = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
        //     'origin' => 'http://localhost',
        //     'content-type' => 'application/json',
        //     'x-appwrite-project' => $projectId,
        //     'x-appwrite-key' => $key
        // ]), $graphQLPayload);

        // $this->assertEquals($movies['headers']['status-code'], 201);
        // $this->assertNull($movies['body']['errors']);
        // $this->assertIsArray($movies['body']['data']);
        // $this->assertIsArray($movies['body']['data']['database_createCollection']);

        // $data = $movies['body']['data']['database_createCollection'];
        // $this->assertArrayHasKey('id', $data);
        // $this->assertArrayHasKey('permissions', $data);
        // $this->assertEquals('Movies', $data['name']);
        // $this->assertArrayHasKey('dateCreated', $data);
        // $this->assertArrayHasKey('dateUpdated', $data);
        // $this->assertArrayHasKey('rules', $data);
       
        // $permissions = $data['permissions'];
        // $this->assertIsArray($permissions);
        // $this->assertArrayHasKey('read', $permissions);
        // $this->assertArrayHasKey('write', $permissions);
        // $read = $permissions['read'];
        // $this->assertContains('*', $read);
        // $write = $permissions['write'];
        // $this->assertContains('role:member', $write);
        // $this->assertContains('role:admin', $write);
        
        // $rules = $data['rules'];
        // $this->assertIsArray($rules);
        // $this->assertCount(3, $rules);
        // $firstRule = $rules[0];
        // $this->assertArrayHasKey('id', $firstRule);
        // $this->assertEquals('rules', $firstRule['collection']);
        // $this->assertEquals('text', $firstRule['type']);
        // $this->assertEquals('name', $firstRule['key']);
        // $this->assertEquals('Name', $firstRule['label']);
        // $this->assertEquals('', $firstRule['default']);
        // $this->assertEquals(false, $firstRule['array']);
        // $this->assertEquals(true, $firstRule['required']);
        // $this->assertEquals([], $firstRule['list']);
        // $secondRule = $rules[1];
        // $this->assertArrayHasKey('id', $secondRule);
        // $this->assertEquals('rules', $secondRule['collection']);
        // $this->assertEquals('numeric', $secondRule['type']);
        // $this->assertEquals('releaseYear', $secondRule['key']);
        // $this->assertEquals('Release Year', $secondRule['label']);
        // $this->assertEquals(0, $secondRule['default']);
        // $this->assertEquals(false, $secondRule['array']);
        // $this->assertEquals(false, $secondRule['required']);
        // $this->assertEquals([], $secondRule['list']);
        // $thirdRule = $rules[2];
        // $this->assertArrayHasKey('id', $thirdRule);
        // $this->assertEquals('rules', $thirdRule['collection']);
        // $this->assertEquals('document', $thirdRule['type']);
        // $this->assertEquals('actors', $thirdRule['key']);
        // $this->assertEquals('Actors', $thirdRule['label']);
        // $this->assertEquals([], $thirdRule['default']);
        // $this->assertEquals(true, $thirdRule['array']);
        // $this->assertEquals(false, $thirdRule['required']);
        // $this->assertEquals([$actors['body']['data']['$id']], $thirdRule['list']);

        // return $data;

        return ['actorsId' => $data['id']];
    }


    public function createKey(string $name, array $scopes): string {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$CREATE_KEY);
        
        $variables = [
            "projectId" => $projectId,
            "name" => $name,
            "scopes" => $scopes
        ];

        $graphQLPayload = [
            "query" => $query,
            "variables" => $variables
        ];

        $key = $this->client->call(Client::METHOD_POST, '/graphql', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console'
        ], $graphQLPayload);

        $this->assertEquals($key['headers']['status-code'], 201);
        $this->assertNull($key['body']['errors']);
        $this->assertIsArray($key['body']['data']);
        $this->assertIsArray($key['body']['data']['projects_createKey']);

        return $key['body']['data']['projects_createKey']['secret'];
    }

    public function getQuery(string $name): String{
        switch($name) {
            case self::$CREATE_COLLECTION :
                return  "mutation createCollection(\$name: String!, \$read: [Json]!, \$write: [Json]!, \$rules: [Json]!){
                    database_createCollection (name: \$name, read : \$read, write: \$write, rules: \$rules) {
                        id
                        permissions {
                            read
                            write
                        }
                        name
                        dateCreated
                        dateUpdated
                        rules {
                            id
                            collection
                            type
                            key
                            label
                            default
                            array
                            required
                            list
                        }
                    }
                }";
            case self::$CREATE_DOCUMENT : 
                return "mutation createDocument(\$collectionId: String!, \$data: Json!, \$read: [Json]!, \$write: [Json]!){
                    database_createDocument (collectionId: \$collectionId, data: \$data, read: \$read, write: \$write)
                }";

            case self::$LIST_DOCUMENTS :
                return "query listDocuments(\$collectionId: String, \$filters: [Json]){
                    database_listDocuments (collectionId: \$collectionId, filters: \$filters) {
                        sum
                        documents 
                    }   
                }";

            case self::$GET_DOCUMENT :
                return "query getDocument(\$collectionId: String!, \$documentId: String!){
                    database_getDocument (collectionId: \$collectionId, documentId: \$documentId)
                }";
            
            case self::$UPDATE_DOCUMENT:
                return "mutation updateDocument(\$collectionId: String!, \$documentId: String!, \$data: Json!, \$read: [Json]!, \$write: [Json]!){
                    database_updateDocument (collectionId: \$collectionId, documentId: \$documentId,data: \$data, read: \$read, write: \$write)
                }";

            case self::$CREATE_USER :
                return "mutation createUser(\$email: String!, \$password: String!, \$name: String){
                    users_create (email: \$email, password: \$password, name: \$name) {
                        id
                        name
                        registration
                        status
                        email
                        emailVerification
                        prefs
                    }
                }"; 

            case self::$LIST_COUNTRIES:
                return "query listCountries {
                    locale_getCountries{
                        sum
                        countries {
                            name
                            code
                        }
                    }
                }";

            case self::$CREATE_KEY : 
                return "mutation createKey(\$projectId: String!, \$name: String!, \$scopes: [Json]!){
                    projects_createKey (projectId: \$projectId, name: \$name, scopes: \$scopes) {
                        id
                        name
                        scopes
                        secret
                    }
                }";
            
            case self::$CREATE_ACCOUNT :
                return "mutation createAccount(\$email: String!, \$password: String!, \$name: String){
                    account_create (email: \$email, password: \$password, name: \$name) {
                        id
                        name
                        registration
                        status
                        email
                        emailVerification
                        prefs
                    }
                }";

            case self::$CREATE_ACCOUNT_SESSION :
                return "mutation createAccountSession(\$email: String!, \$password: String!){
                    account_createSession (email: \$email, password: \$password) {
                        id
                        userId
                        expire
                        ip
                        current
                    }
                }";
        }
    }
    
}