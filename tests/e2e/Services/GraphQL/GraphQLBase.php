<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;

trait GraphQLBase
{
    // Collections
    static string $GET_COLLECTION = 'get_collection';
    static string $LIST_COLLECTIONS = 'list_collections';
    static string $CREATE_COLLECTION = 'create_collection';
    static string $UPDATE_COLLECTION = 'update_collection';
    static string $DELETE_COLLECTION = 'delete_collection';
    // Attributes
    static string $CREATE_STRING_ATTRIBUTE = 'create_string_attribute';
    static string $CREATE_INTEGER_ATTRIBUTE = 'create_integer_attribute';
    static string $CREATE_FLOAT_ATTRIBUTE = 'create_float_attribute';
    static string $CREATE_BOOLEAN_ATTRIBUTE = 'create_float_attribute';
    static string $CREATE_URL_ATTRIBUTE = 'create_string_attribute';
    static string $CREATE_EMAIL_ATTRIBUTE = 'create_string_attribute';
    static string $CREATE_IP_ATTRIBUTE = 'create_string_attribute';
    static string $CREATE_ENUM_ATTRIBUTE = 'create_string_attribute';
    // Documents
    static string $GET_DOCUMENT = 'get_document';
    static string $LIST_DOCUMENTS = 'list_documents';
    static string $CREATE_DOCUMENT_REST = 'create_document_rest';
    static string $CREATE_DOCUMENT_GQL_HOOKS = 'create_document_hooks';
    static string $UPDATE_DOCUMENT = 'update_document';
    static string $DELETE_DOCUMENT = 'delete_document';

    // Locales
    static string $LIST_COUNTRIES = 'list_countries';

    // Projects
    static string $CREATE_API_KEY = 'create_key';

    // Account
    static string $GET_ACCOUNT = 'get_account';
    static string $CREATE_ACCOUNT = 'create_account';
    static string $UPDATE_ACCOUNT_NAME = 'update_account_name';
    static string $UPDATE_ACCOUNT_EMAIL = 'update_account_email';
    static string $UPDATE_ACCOUNT_PASSWORD = 'update_account_password';
    static string $UPDATE_ACCOUNT_PREFS = 'update_account_prefs';
    static string $DELETE_ACCOUNT = 'delete_account';
    static string $GET_ACCOUNT_SESSION = 'get_account_session';
    static string $LIST_ACCOUNT_SESSIONS = 'list_account_sessions';
    static string $CREATE_ACCOUNT_SESSION = 'create_account_session';
    static string $DELETE_ACCOUNT_SESSION = 'delete_account_session';
    static string $DELETE_ACCOUNT_SESSIONS = 'delete_account_sessions';
    // Users
    static string $GET_USER = 'get_user';
    static string $LIST_USERS = 'list_user';
    static string $CREATE_USER = 'create_user';
    static string $UPDATE_USER_STATUS = 'update_user_status';
    static string $UPDATE_USER_NAME = 'update_user_name';
    static string $UPDATE_USER_EMAIL = 'update_user_email';
    static string $UPDATE_USER_PASSWORD = 'update_user_password';
    static string $UPDATE_USER_PREFS = 'update_user_prefs';
    static string $DELETE_USER = 'delete_user';
    // Teams
    static string $GET_TEAM = 'get_team';
    static string $LIST_TEAMS = 'list_teams';
    static string $CREATE_TEAM = 'create_team';
    static string $UPDATE_TEAM = 'update_team';
    static string $DELETE_TEAM = 'delete_team';
    static string $GET_TEAM_MEMBERSHIP = 'get_team_membership';
    static string $LIST_TEAM_MEMBERSHIPS = 'list_team_memberships';
    static string $CREATE_TEAM_MEMBERSHIP = 'create_team_membership';
    static string $UPDATE_MEMBERSHIP_STATUS = 'update_membership_status';
    static string $DELETE_TEAM_MEMBERSHIP = 'delete_team_membership';

    // Functions
    static string $GET_FUNCTION = 'get_function';
    static string $LIST_FUNCTIONS = 'list_functions';
    static string $CREATE_FUNCTION = 'create_function';
    static string $UPDATE_FUNCTION = 'update_function';
    static string $DELETE_FUNCTION = 'delete_function';
    // Deployments
    static string $LIST_DEPLOYMENTS = 'list_deployments';
    static string $GET_DEPLOYMENT = 'get_deployment';
    static string $CREATE_DEPLOYMENT = 'create_deployment';
    static string $DELETE_DEPLOYMENT = 'delete_deployment';
    // Executions
    static string $LIST_EXECUTIONS = 'list_executions';
    static string $GET_EXECUTION = 'get_execution';
    static string $CREATE_EXECUTION = 'create_execution';
    static string $DELETE_EXECUTION = 'delete_execution';
    static string $RETRY_BUILD = 'retry_build';

    public function getQuery(string $name): string
    {
        switch ($name) {
            case self::$GET_COLLECTION:
                return 'query getCollection($collectionId: String!) {
                    databaseGetCollection(collectionId: $collectionId) {
                        _id
                        name
                    }
                }';
            case self::$LIST_COLLECTIONS:
                return 'query listCollections {
                    databaseListCollections {
                        total
                        collections {
                            _id
                            name
                        }
                    }
                }';
            case self::$CREATE_COLLECTION:
                return 'mutation createCollection($id: String!, $name: String!, $permission: String!, $read: [String!]!, $write: [String!]!) {
                    databaseCreateCollection (id: $\id, name: $name, permission: $permission, read: $read, write: $write) {
                        _id
                        name
                        permission
                        read
                        write
                    }
                }';
            case self::$UPDATE_COLLECTION:
                return 'mutation updateCollection($collectionId: String!, $name: String!, $permission: String!, $read: [String!]!, $write: [String!]!, $enabled: Boolean){
                    databaseUpdateCollection (collectionId: $collectionId, name: $name, permission: $permission, read: $read, write: $write, enabled: $enabled) {
                        _id
                        name
                        permission
                        read
                        write
                    }
                }';
            case self::$DELETE_COLLECTION:
                return 'mutation deleteCollection($collectionId: String!){
                    databaseDeleteCollection (collectionId: $collectionId)
                }';
            case self::$CREATE_STRING_ATTRIBUTE:
                return 'mutation createStringAttribute($collectionId: String!, $key: String!, $size: Int!, $required: Boolean!, $default: String, $array: Boolean){
                    databaseCreateStringAttribute (collectionId: $collectionId, key: $key, size: $size, required: $required, default: $default, array: $array) {
                        _id
                        key
                        required
                        default
                        array
                    }
                }';
            case self::$CREATE_INTEGER_ATTRIBUTE:
                return 'mutation createIntegerAttribute($collectionId: String!, $key: String!, $required: Boolean!, $min: Int, $max: Int, $default: Int, $array: Boolean){
                    databaseCreateIntegerAttribute (collectionId: $collectionId, key: $key, min: $min, max: $max, required: $required, default: $default, array: $array) {
                        _id
                        key
                        required
                        min
                        max
                        default
                        array
                    }
                }';
            case self::$CREATE_FLOAT_ATTRIBUTE:
                return 'mutation createFloatAttribute($collectionId: String!, $key: String!, $required: Boolean!, $min: Float, $max: Float, $default: Float, $array: Boolean){
                    databaseCreateFloatAttribute (collectionId: $collectionId, key: $key, min: $min, max: $max, required: $required, default: $default, array: $array) {
                        _id
                        key
                        required
                        min
                        max
                        default
                        array
                    }
                }';
            case self::$CREATE_BOOLEAN_ATTRIBUTE:
                return 'mutation createBooleanAttribute($collectionId: String!, $key: String!, $required: Boolean!, $default: Boolean, $array: Boolean){
                    databaseCreateBooleanAttribute (collectionId: $collectionId, key: $key, required: $required, default: $default, array: $array) {
                        _id
                        key
                        required
                        default
                        array
                    }
                }';
            case self::$CREATE_URL_ATTRIBUTE:
                return 'mutation createUrlAttribute($collectionId: String!, $key: String!, $required: Boolean!, $default: String, $array: Boolean){
                    databaseCreateUrlAttribute (collectionId: $collectionId, key: $key, required: $required, default: $default, array: $array) {
                        _id
                        key
                        required
                        default
                        array
                    }
                }';
            case self::$CREATE_EMAIL_ATTRIBUTE:
                return 'mutation createEmailAttribute($collectionId: String!, $key: String!, $required: Boolean!, $default: String, $array: Boolean){
                    databaseCreateEmailAttribute (collectionId: $collectionId, key: $key, required: $required, default: $default, array: $array) {
                        _id
                        key
                        required
                        default
                        array
                    }
                }';
            case self::$CREATE_IP_ATTRIBUTE:
                return 'mutation createIpAttribute($collectionId: String!, $key: String!, $required: Boolean!, $default: String, $array: Boolean){
                    databaseCreateIpAttribute (collectionId: $collectionId, key: $key, required: $required, default: $default, array: $array) {
                        _id
                        key
                        required
                        default
                        array
                    }
                }';
            case self::$CREATE_ENUM_ATTRIBUTE:
                return 'mutation createEnumAttribute($collectionId: String!, $key: String!, $elements: [String!]!, $required: Boolean!, $default: String, $array: Boolean){
                    databaseCreateEnumAttribute (collectionId: $collectionId, key: $key, elements: $elements, required: $required, default: $default, array: $array) {
                        _id
                        key
                        elements
                        required
                        default
                        array
                    }
                }';
            case self::$GET_DOCUMENT :
                return 'query getDocument($collectionId: String!, $documentId: String!){
                    databaseGetDocument (collectionId: $collectionId, documentId: $documentId) {
                        _id
                        collectionId
                        data {
                            name
                            age
                            alive
                            salary
                        }
                    }
                }';
            case self::$LIST_DOCUMENTS :
                return 'query listDocuments($collectionId: String, $filters: [Json]){
                    databaseListDocuments (collectionId: $collectionId, filters: $filters) {
                        total
                        documents {
                            _id
                            collectionId
                        }
                    }   
                }';
            case self::$CREATE_DOCUMENT_REST :
                return 'mutation createDocument($collectionId: String!, $documentId: String!, $data: Json!, $read: [String!]!, $write: [String!]!){
                    databaseCreateDocument (collectionId: $collectionId, documentId: $documentId, data: $data, read: $read, write: $write) {
                        _id
                        documentId
                        data {
                            name
                            age
                            alive
                            salary
                        }
                        read
                        write
                    }
                }';
            case self::$CREATE_DOCUMENT_GQL_HOOKS:
                return 'mutation createActor($name: String!, $age: Int!, $alive: Boolean!, $salary: Float) {
                    actorCreate(name: $name, age: $age, alive: $alive, salary: $salary) {
                        _id
                        name
                        age
                        alive
                    }
                }';
            case self::$UPDATE_DOCUMENT:
                return 'mutation updateDocument($collectionId: String!, $documentId: String!, $data: Json!, $read: [String!]!, $write: [String!]!){
                    databaseUpdateDocument (collectionId: $collectionId, documentId: $documentId,data: $data, read: $read, write: $write) {
                        _id
                        collectionId
                        data {
                            name
                            age
                            alive
                            salary
                        }
                    }
                }';
            case self::$DELETE_DOCUMENT:
                return 'mutation deleteDocument($collectionId: String!, $documentId: String!){
                    databaseDeleteDocument (collectionId: $collectionId, documentId: $documentId)
                }';

            case self::$GET_USER :
                return 'query getUser ($userId : String!) {
                    usersGet(userId : $userId) {
                        _id
                        name
                        registration
                        status
                        email
                        emailVerification
                    }
                }';
            case self::$LIST_USERS:
                return 'query listUsers($filters: [Json]){
                    usersList (filters: $filters) {
                        total
                        users {
                            _id
                            name
                            registration
                            status
                            email
                            emailVerification
                        }
                    }   
                }';
            case self::$CREATE_USER :
                return 'mutation createUser($userId: String!, $email: String!, $password: String!, $name: String){
                    usersCreate (userId: $userId, email: $email, password: $password, name: $name) {
                        _id
                        name
                        registration
                        status
                        email
                        emailVerification
                    }
                }';
            case self::$UPDATE_USER_STATUS:
                return 'mutation updateUserStatus($userId: String!, $status: String!){
                    usersUpdateStatus (userId: $userId, status: $status) {
                        _id
                        name
                        registration
                        status
                        email
                        emailVerification
                    }
                }';
            case self::$UPDATE_USER_NAME:
                return 'mutation updateUserName($userId: String!, $name: String!){
                    usersUpdateName (userId: $userId, name: $name) {
                        _id
                        name
                        registration
                        status
                        email
                        emailVerification
                    }
                }';
            case self::$UPDATE_USER_EMAIL:
                return 'mutation updateUserEmail($userId: String!, $email: String!){
                    usersUpdateEmail (userId: $userId, email: $email) {
                        _id
                        name
                        registration
                        status
                        email
                        emailVerification
                    }
                }';
            case self::$UPDATE_USER_PASSWORD:
                return 'mutation updateUserPassword($userId: String!, $password: String!){
                    usersUpdatePassword (userId: $userId, password: $password) {
                        _id
                        name
                        registration
                        status
                        email
                        emailVerification
                    }
                }';
            case self::$UPDATE_USER_PREFS:
                return 'mutation updateUserPrefs($userId: String!, $prefs: Json!){
                    usersUpdatePrefs (userId: $userId, prefs: $prefs) {
                        _id
                        name
                    }
                }';
            case self::$DELETE_USER :
                return 'mutation deleteUser($userId: String!) {
                    usersDeleteUser(userId : $userId)
                }';
            case self::$LIST_COUNTRIES:
                return 'query listCountries {
                    localeGetCountries{
                        total
                        countries {
                            name
                            code
                        }
                    }
                }';
            case self::$CREATE_API_KEY :
                return 'mutation createKey($projectId: String!, $name: String!, $scopes: [String!]!){
                    projectsCreateKey (projectId: $projectId, name: $name, scopes: $scopes) {
                        _id
                        name
                        scopes
                        secret
                    }
                }';
            case self::$GET_ACCOUNT:
                return 'query getAccount {
                    accountGet {
                        id
                        name
                        email
                        status
                        registration
                        emailVerification
                    }
                }';
            case self::$CREATE_ACCOUNT :
                return 'mutation createAccount($userId: String!, $email: String!, $password: String!, $name: String){
                    accountCreate (userId: $userId, email: $email, password: $password, name: $name) {
                        _id
                        name
                        registration
                        status
                        email
                        emailVerification
                    }
                }';
            case self::$UPDATE_ACCOUNT_NAME :
                return 'mutation updateAccountName($userId: String!, $name: String!){
                    accountUpdateName (userId: $userId, name: $name) {
                        _id
                        name
                        registration
                        status
                        email
                        emailVerification
                    }
                }';
            case self::$UPDATE_ACCOUNT_EMAIL :
                return 'mutation updateAccountEmail($userId: String!, $email: String!){
                    accountUpdateEmail (userId: $userId, email: $email) {
                        _id
                        name
                        registration
                        status
                        email
                        emailVerification
                    }
                }';
            case self::$UPDATE_ACCOUNT_PASSWORD :
                return 'mutation updateAccountPassword($userId: String!, $password: String!){
                    accountUpdatePassword (userId: $userId, password: $password) {
                        _id
                        name
                        registration
                        status
                        email
                        emailVerification
                    }
                }';
            case self::$UPDATE_ACCOUNT_PREFS :
                return 'mutation updateAccountPrefs($userId: String!, $prefs: Json!){
                    accountUpdatePrefs (userId: $userId, prefs: $prefs) {
                        _id
                        name
                        registration
                        status
                        email
                        emailVerification
                    }
                }';
            case self::$GET_ACCOUNT_SESSION:
                return 'query getAccountSession {
                    accountSessionGet {
                        _id
                        userId
                        projectId
                        scopes
                        expires
                    }
                }';
            case self::$LIST_ACCOUNT_SESSIONS:
                return 'query listAccountSessions {
                    accountSessionsList {
                        total
                        sessions {
                            _id
                            userId
                            expires
                        }
                    }
                }';
            case self::$CREATE_ACCOUNT_SESSION :
                return 'mutation createAccountSession($email: String!, $password: String!){
                    accountCreateSession (email: $email, password: $password) {
                        _id
                        userId
                        expire
                        ip
                        current
                    }
                }';
            case self::$DELETE_ACCOUNT_SESSION :
                return 'mutation deleteAccountSession($sessionId: String!){
                    accountDeleteSession (sessionId: $sessionId)
                }';
            case self::$DELETE_ACCOUNT_SESSIONS:
                return 'mutation deleteAccountSessions {
                    accountDeleteSessions
                }';
            case self::$GET_TEAM:
                return 'query getTeam($teamId: String!){
                    teamGet (teamId: $teamId) {
                        _id
                        name
                        total
                    }
                }';
            case self::$LIST_TEAMS:
                return 'query listTeams {
                    teamsList {
                        total
                        teams {
                            _id
                            name
                            total
                        }
                    }
                }';
            case self::$CREATE_TEAM:
                return 'mutation createTeam($teamId: String!, $name: String!, $roles: [Json]){
                    teamsCreate(teamId: $teamId, name : $name, roles: $roles) {
                        _id
                        name
                        dateCreated,
                        total
                    }
                }';
            case self::$UPDATE_TEAM:
                return 'mutation updateTeam($teamId: String!, $name: String!){
                    teamsUpdate(teamId: $teamId, name : $name) {
                        _id
                        name
                    }
                }';
            case self::$DELETE_TEAM:
                return 'mutation deleteTeam($teamId: String!){
                    teamsDelete(teamId: $teamId)
                }';
            case self::$GET_TEAM_MEMBERSHIP:
                return 'query getTeamMembership($teamId: String!, $userId: String!){
                    teamGetMembership (teamId: $teamId, userId: $userId) {
                        _id
                        name
                        email
                        invited
                    }
                }';
            case self::$LIST_TEAM_MEMBERSHIPS:
                return 'query listTeamMemberships($teamId: String!){
                    teamListMemberships (teamId: $teamId) {
                        total
                        memberships {
                            _id
                            name
                            email
                            invited
                        }
                    }
                }';
            case self::$CREATE_TEAM_MEMBERSHIP:
                return 'mutation createTeamMembership($teamId: String!, $email: String!, $name: String, $roles: [Json]!, $url: String!){
                    teamsCreateMembership(teamId: $teamId, email: $email, name : $name, roles: $roles, url: $url) {
                        _id
                        userId
                        teamId
                        name 
                        email
                        invited 
                        joined 
                        confirm
                        roles
                    }
                }';
            case self::$UPDATE_MEMBERSHIP_STATUS :
                return 'mutation updateTeamMembership($teamId: String!, $inviteId: String!, $userId: String!, $secret: String!){
                    teamsUpdateMembershipStatus(teamId: $teamId, inviteId: $inviteId, userId: $userId, secret: $secret ) {
                        _id
                        userId
                        teamId
                        name 
                        email
                        invited 
                        joined 
                        confirm
                        roles
                    }
                }';
            case self::$DELETE_TEAM_MEMBERSHIP:
                return 'mutation deleteTeamMembership($teamId: String!, $userId: String!){
                    teamsDeleteMembership(teamId: $teamId, userId: $userId)
                }';
            case self::$GET_FUNCTION:
                return 'query getFunction($functionId: String!) { 
                    functionsGet(functionId: $functionId) {
                        _id
                        name
                        runtime
                        execute
                    }
                }';
            case self::$LIST_FUNCTIONS:
                return 'query listFunctions($teamId: String!) {
                    functionsList(teamId: $teamId) {
                        total
                        functions {
                            _id
                            name
                            runtime
                            execute
                        }
                    }
                }';
            case self::$CREATE_FUNCTION:
                return 'mutation createFunction($functionId: String!, $name: String!, $execute: [String!]!, $runtime: String! $vars: Json, $events: [String!], $schedule: String, $timeout: Int) {
                    functionsCreate(functionId: $functionId, name: $name, execute: $execute, runtime: $runtime, vars: $vars, events: $events, schedule: $schedule, timeout: $timeout) {
                        _id
                        name
                        runtime
                        execute
                    }
                }';
            case self::$UPDATE_FUNCTION:
                return 'mutation updateFunction($functionId: String!, $name: String!, $execute: [String!]!, $runtime: String! $vars: Json, $events: [String!], $schedule: String, $timeout: Int) {
                    functionsUpdate(functionId: $functionId, name: $name, execute: $execute, runtime: $runtime, vars: $vars, events: $events, schedule: $schedule, timeout: $timeout) {
                        _id
                        name
                        runtime
                        execute
                    }
                }';
            case self::$DELETE_FUNCTION:
                return 'mutation deleteFunction($functionId: String!) {
                    functionsDelete(functionId: $functionId)
                }';
            case self::$GET_DEPLOYMENT:
                return 'query getDeployment($functionId: String!$deploymentId: , String!) {
                    functionsGetDeployments(functionId: $functionId, deploymentId: $deploymentId) {
                        _id
                        entrypoint
                        size
                        status
                        buildStdout
                        buildStderr
                    }
                }';
            case self::$LIST_DEPLOYMENTS:
                return 'query listDeployments($functionId: String!) {
                    functionsListDeployments(functionId: $functionId) {
                        total
                        deployments {
                            _id
                            entrypoint
                            size
                            status
                            buildStdout
                            buildStderr
                        }
                    }
                }';
            case self::$CREATE_DEPLOYMENT:
                return 'mutation createDeployment($functionId: String!, $entrypoint: String!, $code: String!, $activate: Boolean!) {
                    functionsCreateDeployment(functionId: $functionId, entrypoint: $entrypoint, size: $size, vars: $vars, events: $events, schedule: $schedule, timeout: $timeout) {
                        _id
                        entrypoint
                        size
                        status
                        buildStdout
                        buildStderr
                    }
                }';
            case self::$DELETE_DEPLOYMENT:
                return 'mutation deleteDeployment($functionId: String!, $deploymentId: String!) {
                    functionsDeleteDeployment(functionId: $functionId, deploymentId: $deploymentId)
                }';
            case self::$GET_EXECUTION:
                return 'query getExecution($functionId: String!$executionId: String!) {
                    functionsGetExecution(functionId: $functionId, executionId: $executionId) {
                        _id
                        status
                        stdout
                        stderr
                    }
                }';
            case self::$LIST_EXECUTIONS:
                return 'query listExecutions($functionId: String!) {
                    functionsListExecutions(functionId: $functionId) {
                        total
                        executions {
                            _id
                            status
                            stdout
                            stderr
                        }
                    }
                }';
            case self::$CREATE_EXECUTION:
                return 'mutation createExecution($functionId: String!) {
                    functionsCreateExecution(functionId: $functionId) {
                        _id
                        status
                        stdout
                        stderr
                    }
                }';
            case self::$DELETE_EXECUTION:
                return 'mutation deleteExecution($functionId: String!, $executionId: String!) {
                    functionsDeleteExecution(functionId: $functionId, executionId: $executionId)
                }';
            case self::$RETRY_BUILD:
                return 'mutation retryBuild($functionId: String!, $deploymentId: String!) {
                    functionsRetryBuild(functionId: $functionId, deploymentId: $deploymentId)
                }';
        }

        throw new \InvalidArgumentException('Invalid query type');
    }

    /**
     * @throws \Exception
     */
    public function testCreateCollection(): array
    {
        $projectId = $this->getProject()['$id'];
        $key = '';
        $query = $this->getQuery(self::$CREATE_COLLECTION);

        $collectionAttrs = [
            'collectionId' => 'actors',
            'name' => 'Actors',
            'permission' => 'collection',
            'read' => ['role:all'],
            'write' => ['role:member', 'role:admin'],
        ];

        $gqlPayload = [
            'query' => $query,
            'variables' => $collectionAttrs
        ];

        $actors = $this->client->call(Client::METHOD_POST, '/graphql', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ], $gqlPayload);

        $errorMessage = 'User (role: guest) missing scope (collections.write)';
        $this->assertEquals($actors['headers']['status-code'], 401);
        $this->assertEquals($actors['body']['errors'][0]['message'], $errorMessage);
        $this->assertIsArray($actors['body']['data']);
        $this->assertNull($actors['body']['data']['databaseCreateCollection']);

        $key = $this->createKey('test', ['collections.write']);

        $actors = $this->client->call(Client::METHOD_POST, '/graphql', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ], $gqlPayload);

        $this->assertEquals($actors['headers']['status-code'], 201);
        $this->assertNull($actors['body']['errors']);
        $this->assertIsArray($actors['body']['data']);

        $data = $actors['body']['data']['databaseCreateCollection'];
        $this->assertEquals('Actors', $data['name']);
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('permissions', $data);
        $this->assertContains('role:all', $data['read']);
        $this->assertContains('role:member', $data['write']);
        $this->assertContains('role:admin', $data['write']);

        return [
            'collectionId' => $data['id'],
            'key' => $key
        ];
    }

    /**
     * @depends testCreateCollection
     * @throws \Exception
     */
    public function testCreateStringAttribute(array $data)
    {
        $projectId = $this->getProject()['$id'];
        $key = $data['key'];
        $query = $this->getQuery(self::$CREATE_STRING_ATTRIBUTE);

        $attributeAttrs = [
            'collectionId' => $data['collectionId'],
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ];

        $gqlPayload = [
            'query' => $query,
            'variables' => $attributeAttrs
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ], $gqlPayload);

        $this->assertNull($attribute['body']['errors']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databaseCreateStringAttribute']);
    }

    /**
     * @depends testCreateCollection
     * @throws \Exception
     */
    public function testCreateIntegerAttribute(array $data)
    {
        $projectId = $this->getProject()['$id'];
        $key = $data['key'];
        $query = $this->getQuery(self::$CREATE_INTEGER_ATTRIBUTE);

        $attributeAttrs = [
            'collectionId' => $data['collectionId'],
            'key' => 'age',
            'min' => 18,
            'max' => 99,
            'required' => true,
        ];

        $gqlPayload = [
            'query' => $query,
            'variables' => $attributeAttrs
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ], $gqlPayload);

        $this->assertNull($attribute['body']['errors']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databaseCreateIntegerAttribute']);
    }

    /**
     * @depends testCreateCollection
     * @throws \Exception
     */
    public function testCreateBooleanAttribute(array $data)
    {
        $projectId = $this->getProject()['$id'];
        $key = $data['key'];
        $query = $this->getQuery(self::$CREATE_BOOLEAN_ATTRIBUTE);

        $attributeAttrs = [
            'collectionId' => $data['collectionId'],
            'key' => 'alive',
            'required' => true,
        ];

        $gqlPayload = [
            'query' => $query,
            'variables' => $attributeAttrs
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ], $gqlPayload);

        $this->assertNull($attribute['body']['errors']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databaseCreateBooleanAttribute']);
    }

    /**
     * @depends testCreateCollection
     * @throws \Exception
     */
    public function testCreateFloatAttribute(array $data)
    {
        $projectId = $this->getProject()['$id'];
        $key = $data['key'];
        $query = $this->getQuery(self::$CREATE_FLOAT_ATTRIBUTE);

        $attributeAttrs = [
            'collectionId' => $data['collectionId'],
            'key' => 'salary',
            'min' => 1000.0,
            'max' => 999999.99,
            'default' => 1000.0,
            'required' => false,
        ];

        $gqlPayload = [
            'query' => $query,
            'variables' => $attributeAttrs
        ];

        $attribute = $this->client->call(Client::METHOD_POST, '/graphql', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ], $gqlPayload);

        $this->assertNull($attribute['body']['errors']);
        $this->assertIsArray($attribute['body']['data']);
        $this->assertIsArray($attribute['body']['data']['databaseCreateFloatAttribute']);
    }

    /**
     * @depends testCreateCollection
     * @depends testCreateStringAttribute
     * @depends testCreateIntegerAttribute
     * @depends testCreateBooleanAttribute
     * @depends testCreateFloatAttribute
     * @throws \Exception
     */
    public function testCreateDocumentREST(array $data)
    {
        $projectId = $this->getProject()['$id'];
        $key = $data['key'];
        $query = $this->getQuery(self::$CREATE_DOCUMENT_REST);

        $documentAttrs = [
            'collectionId' => $data['collectionId'],
            'data' => [
                'name' => 'John Doe',
                'age' => 30,
                'alive' => true,
                'salary' => 9999.5
            ]
        ];

        $gqlPayload = [
            'query' => $query,
            'variables' => $documentAttrs
        ];

        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ], $gqlPayload);

        $this->assertNull($document['body']['errors']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['databaseCreateDocument']);
    }

    /**
     * @depends testCreateCollection
     * @depends testCreateStringAttribute
     * @depends testCreateIntegerAttribute
     * @depends testCreateBooleanAttribute
     * @depends testCreateFloatAttribute
     * @throws \Exception
     */
    public function testCreateDocumentGQL(array $data)
    {
        $projectId = $this->getProject()['$id'];
        $key = '';
        $query = $this->getQuery(self::$CREATE_DOCUMENT_GQL_HOOKS);

        $documentAttrs = [
            'name' => 'John Doe',
            'age' => 30,
            'alive' => true,
            'salary' => 9999.5,
        ];

        $gqlPayload = [
            'query' => $query,
            'variables' => $documentAttrs
        ];

        $document = $this->client->call(Client::METHOD_POST, '/graphql', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ], $gqlPayload);

        $this->assertNull($document['body']['errors']);
        $this->assertIsArray($document['body']['data']);
        $this->assertIsArray($document['body']['data']['actorCreate']);
    }

}