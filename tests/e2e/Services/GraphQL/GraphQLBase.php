<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;

trait GraphQLBase
{
    // Collections
    public static string $GET_COLLECTION = 'get_collection';
    public static string $LIST_COLLECTIONS = 'list_collections';
    public static string $CREATE_COLLECTION = 'create_collection';
    public static string $UPDATE_COLLECTION = 'update_collection';
    public static string $DELETE_COLLECTION = 'delete_collection';
    // Attributes
    public static string $CREATE_STRING_ATTRIBUTE = 'create_string_attribute';
    public static string $CREATE_INTEGER_ATTRIBUTE = 'create_integer_attribute';
    public static string $CREATE_FLOAT_ATTRIBUTE = 'create_float_attribute';
    public static string $CREATE_BOOLEAN_ATTRIBUTE = 'create_boolean_attribute';
    public static string $CREATE_URL_ATTRIBUTE = 'create_string_attribute';
    public static string $CREATE_EMAIL_ATTRIBUTE = 'create_string_attribute';
    public static string $CREATE_IP_ATTRIBUTE = 'create_string_attribute';
    public static string $CREATE_ENUM_ATTRIBUTE = 'create_string_attribute';
    // Documents
    public static string $GET_DOCUMENT = 'get_document';
    public static string $LIST_DOCUMENTS = 'list_documents';
    public static string $CREATE_DOCUMENT_REST = 'create_document_rest';
    public static string $CREATE_CUSTOM_ENTITY = 'create_document_hooks';
    public static string $UPDATE_DOCUMENT = 'update_document';
    public static string $DELETE_DOCUMENT = 'delete_document';

    // Locales
    public static string $LIST_COUNTRIES = 'list_countries';

    // Projects
    public static string $CREATE_API_KEY = 'create_key';

    // Account
    public static string $CREATE_ACCOUNT = 'create_account';
    public static string $CREATE_ACCOUNT_SESSION = 'create_account_session';
    public static string $CREATE_ANONYMOUS_SESSION = 'create_anonymous_session';
    public static string $CREATE_ACCOUNT_JWT = 'create_account_jwt';
    public static string $CREATE_MAGIC_URL = 'create_magic_url';
    public static string $CREATE_PASSWORD_RECOVERY = 'create_password_recovery';
    public static string $CREATE_EMAIL_VERIFICATION = 'create_email_verification';
    public static string $CREATE_PHONE_VERIFICATION = 'create_phone_verification';
    public static string $GET_ACCOUNT = 'get_account';
    public static string $GET_ACCOUNT_SESSION = 'get_account_session';
    public static string $GET_ACCOUNT_SESSIONS = 'get_account_sessions';
    public static string $GET_ACCOUNT_PREFS = 'get_account_preferences';
    public static string $GET_ACCOUNT_LOGS = 'get_account_logs';
    public static string $UPDATE_ACCOUNT_NAME = 'update_account_name';
    public static string $UPDATE_ACCOUNT_EMAIL = 'update_account_email';
    public static string $UPDATE_ACCOUNT_PASSWORD = 'update_account_password';
    public static string $UPDATE_ACCOUNT_PREFS = 'update_account_prefs';
    public static string $UPDATE_ACCOUNT_PHONE = 'update_account_phone';
    public static string $UPDATE_ACCOUNT_STATUS = 'update_account_status';
    public static string $UPDATE_MAGIC_URL = 'confirm_magic_url';
    public static string $UPDATE_PASSWORD_RECOVERY = 'confirm_password_recovery';
    public static string $UPDATE_EMAIL_VERIFICATION = 'confirm_email_verification';
    public static string $UPDATE_PHONE_VERIFICATION = 'confirm_phone_verification';
    public static string $DELETE_ACCOUNT_SESSION = 'delete_account_session';
    public static string $DELETE_ACCOUNT_SESSIONS = 'delete_account_sessions';
    public static string $DELETE_ACCOUNT = 'delete_account';
    // Users
    public static string $CREATE_USER = 'create_user';
    public static string $GET_USER = 'get_user';
    public static string $GET_USERS = 'list_user';
    public static string $GET_USER_PREFERENCES = 'get_user_preferences';
    public static string $GET_USER_SESSIONS = 'get_user_sessions';
    public static string $GET_USER_MEMBERSHIPS = 'get_user_memberships';
    public static string $GET_USER_LOGS = 'get_user_logs';
    public static string $UPDATE_USER_STATUS = 'update_user_status';
    public static string $UPDATE_USER_NAME = 'update_user_name';
    public static string $UPDATE_USER_EMAIL = 'update_user_email';
    public static string $UPDATE_USER_EMAIL_VERIFICATION = 'update_email_verification';
    public static string $UPDATE_USER_PHONE_VERIFICATION = 'update_email_verification';
    public static string $UPDATE_USER_PASSWORD = 'update_user_password';
    public static string $UPDATE_USER_PHONE = 'update_user_phone';
    public static string $UPDATE_USER_PREFS = 'update_user_prefs';
    public static string $DELETE_USER_SESSIONS = 'delete_user_sessions';
    public static string $DELETE_USER_SESSION = 'delete_user_session';
    public static string $DELETE_USER = 'delete_user';
    // Teams
    public static string $GET_TEAM = 'get_team';
    public static string $GET_TEAMS = 'list_teams';
    public static string $CREATE_TEAM = 'create_team';
    public static string $UPDATE_TEAM = 'update_team';
    public static string $DELETE_TEAM = 'delete_team';
    public static string $GET_TEAM_MEMBERSHIP = 'get_team_membership';
    public static string $GET_TEAM_MEMBERSHIPS = 'list_team_memberships';
    public static string $CREATE_TEAM_MEMBERSHIP = 'create_team_membership';
    public static string $UPDATE_TEAM_MEMBERSHIP_ROLES = 'update_team_membership_roles';
    public static string $UPDATE_TEAM_MEMBERSHIP_STATUS = 'update_membership_status';
    public static string $DELETE_TEAM_MEMBERSHIP = 'delete_team_membership';

    // Functions
    public static string $GET_FUNCTION = 'get_function';
    public static string $LIST_FUNCTIONS = 'list_functions';
    public static string $CREATE_FUNCTION = 'create_function';
    public static string $UPDATE_FUNCTION = 'update_function';
    public static string $DELETE_FUNCTION = 'delete_function';
    // Deployments
    public static string $LIST_DEPLOYMENTS = 'list_deployments';
    public static string $GET_DEPLOYMENT = 'get_deployment';
    public static string $CREATE_DEPLOYMENT = 'create_deployment';
    public static string $DELETE_DEPLOYMENT = 'delete_deployment';
    // Executions
    public static string $LIST_EXECUTIONS = 'list_executions';
    public static string $GET_EXECUTION = 'get_execution';
    public static string $CREATE_EXECUTION = 'create_execution';
    public static string $DELETE_EXECUTION = 'delete_execution';
    public static string $RETRY_BUILD = 'retry_build';

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
                return 'mutation createCollection($collectionId: String!, $name: String!, $permission: String!, $read: [String!]!, $write: [String!]!) {
                    databaseCreateCollection (collectionId: $collectionId, name: $name, permission: $permission, read: $read, write: $write) {
                        _id
                        _read
                        _write
                        name
                        permission
                    }
                }';
            case self::$UPDATE_COLLECTION:
                return 'mutation updateCollection($collectionId: String!, $name: String!, $permission: String!, $read: [String!]!, $write: [String!]!, $enabled: Boolean){
                    databaseUpdateCollection (collectionId: $collectionId, name: $name, permission: $permission, read: $read, write: $write, enabled: $enabled) {
                        _id
                        _read
                        _write
                        name
                        permission
                    }
                }';
            case self::$DELETE_COLLECTION:
                return 'mutation deleteCollection($collectionId: String!){
                    databaseDeleteCollection (collectionId: $collectionId)
                }';
            case self::$CREATE_STRING_ATTRIBUTE:
                return 'mutation createStringAttribute($collectionId: String!, $key: String!, $size: Int!, $required: Boolean!, $default: String, $array: Boolean){
                    databaseCreateStringAttribute (collectionId: $collectionId, key: $key, size: $size, required: $required, default: $default, array: $array) {
                        key
                        required
                        default
                        array
                    }
                }';
            case self::$CREATE_INTEGER_ATTRIBUTE:
                return 'mutation createIntegerAttribute($collectionId: String!, $key: String!, $required: Boolean!, $min: Int, $max: Int, $default: Int, $array: Boolean){
                    databaseCreateIntegerAttribute (collectionId: $collectionId, key: $key, min: $min, max: $max, required: $required, default: $default, array: $array) {
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
                        key
                        required
                        default
                        array
                    }
                }';
            case self::$CREATE_URL_ATTRIBUTE:
                return 'mutation createUrlAttribute($collectionId: String!, $key: String!, $required: Boolean!, $default: String, $array: Boolean){
                    databaseCreateUrlAttribute (collectionId: $collectionId, key: $key, required: $required, default: $default, array: $array) {
                        key
                        required
                        default
                        array
                    }
                }';
            case self::$CREATE_EMAIL_ATTRIBUTE:
                return 'mutation createEmailAttribute($collectionId: String!, $key: String!, $required: Boolean!, $default: String, $array: Boolean){
                    databaseCreateEmailAttribute (collectionId: $collectionId, key: $key, required: $required, default: $default, array: $array) {
                        key
                        required
                        default
                        array
                    }
                }';
            case self::$CREATE_IP_ATTRIBUTE:
                return 'mutation createIpAttribute($collectionId: String!, $key: String!, $required: Boolean!, $default: String, $array: Boolean){
                    databaseCreateIpAttribute (collectionId: $collectionId, key: $key, required: $required, default: $default, array: $array) {
                        key
                        required
                        default
                        array
                    }
                }';
            case self::$CREATE_ENUM_ATTRIBUTE:
                return 'mutation createEnumAttribute($collectionId: String!, $key: String!, $elements: [String!]!, $required: Boolean!, $default: String, $array: Boolean){
                    databaseCreateEnumAttribute (collectionId: $collectionId, key: $key, elements: $elements, required: $required, default: $default, array: $array) {
                        key
                        elements
                        required
                        default
                        array
                    }
                }';
            case self::$GET_DOCUMENT:
                return 'query getDocument($collectionId: String!, $documentId: String!){
                    databaseGetDocument (collectionId: $collectionId, documentId: $documentId) {
                        _id
                        _collection
                        _read
                        _write
                        data
                    }
                }';
            case self::$LIST_DOCUMENTS:
                return 'query listDocuments($collectionId: String, $filters: [Json]){
                    databaseListDocuments (collectionId: $collectionId, filters: $filters) {
                        total
                        documents {
                            _id
                            collectionId
                            data
                        }
                    }   
                }';
            case self::$CREATE_DOCUMENT_REST:
                return 'mutation createDocument($collectionId: String!, $documentId: String!, $data: Json!, $read: [String!]!, $write: [String!]!){
                    databaseCreateDocument (collectionId: $collectionId, documentId: $documentId, data: $data, read: $read, write: $write) {
                        _id
                        _collection
                        _read
                        _write
                    }
                }';
            case self::$CREATE_CUSTOM_ENTITY:
                return 'mutation createActor($name: String!, $age: Int!, $alive: Boolean!, $salary: Float) {
                    actorsCreate(name: $name, age: $age, alive: $alive, salary: $salary) {
                        _id
                        name
                        age
                        alive
                    }
                }';
            case self::$UPDATE_DOCUMENT:
                return 'mutation updateDocument($collectionId: String!, $documentId: String!, $data: Json!, $read: [String!]!, $write: [String!]!){
                    databaseUpdateDocument (collectionId: $collectionId, documentId: $documentId, data: $data, read: $read, write: $write) {
                        _id
                        _collection
                    }
                }';
            case self::$DELETE_DOCUMENT:
                return 'mutation deleteDocument($collectionId: String!, $documentId: String!){
                    databaseDeleteDocument (collectionId: $collectionId, documentId: $documentId)
                }';

            case self::$GET_USER:
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
            case self::$GET_USER_PREFERENCES:
                return 'query getUserPreferences ($userId : String!) {
                    usersGetPrefs(userId : $userId) {
                        data
                    }
                }';
            case self::$GET_USER_SESSIONS:
                return 'query getUserSessions ($userId : String!) {
                    usersGetSessions(userId : $userId) {
                        total 
                        sessions {
                            _id
                            userId
                        }
                    }
                }';
            case self::$GET_USER_MEMBERSHIPS:
                return 'query getUserMemberships ($userId : String!) {
                    usersGetMemberships(userId : $userId) {
                        total
                        memberships {
                            _id
                            userId
                            teamId
                        }
                    }
                }';
            case self::$GET_USER_LOGS:
                return 'query getUserLogs ($userId : String!) {
                    usersGetLogs(userId : $userId) {
                        total
                        logs {
                            event
                            userId
                        }
                    }
                }';
            case self::$GET_USERS:
                return 'query listUsers($search: String, $limit: Int, $offset: Int, $cursor: String, $cursorDirection: String, $orderType: String) {
                    usersList (search: $search, limit: $limit, offset: $offset, cursor: $cursor, cursorDirection: $cursorDirection, orderType: $orderType) {
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
            case self::$CREATE_USER:
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
                return 'mutation updateUserStatus($userId: String!, $status: Boolean!){
                    usersUpdateStatus (userId: $userId, status: $status) {
                        _id
                        name
                        email
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
            case self::$UPDATE_USER_PHONE:
                return 'mutation updateUserPhone($userId: String!, number: String!){
                    usersUpdatePhone (userId: $userId, number: number) {
                        name
                        email
                    }
                }';
            case self::$UPDATE_USER_PREFS:
                return 'mutation updateUserPrefs($userId: String!, $prefs: Json!){
                    usersUpdatePrefs (userId: $userId, prefs: $prefs) {
                        data
                    }
                }';
            case self::$UPDATE_USER_EMAIL_VERIFICATION:
                return 'mutation updateUserEmailVerification($userId: String!, $emailVerification: Boolean!){
                    usersUpdateVerification (userId: $userId, emailVerification: $emailVerification) {
                        name
                        email
                    }
                }';
            case self::$UPDATE_USER_PHONE_VERIFICATION:
                return 'mutation updateUserPhoneVerification($userId: String!, $phoneVerification: Boolean!){
                    usersUpdatePhoneVerification (userId: $userId, phoneVerification: $phoneVerification) {
                        name
                        email
                    }
                }';
            case self::$DELETE_USER_SESSIONS:
                return 'mutation deleteUserSessions($userId: String!){
                    usersDeleteSessions (userId: $userId)
                }';
            case self::$DELETE_USER_SESSION:
                return 'mutation deleteUserSession($userId: String!, $sessionId: String!){
                    usersDeleteSession (userId: $userId, sessionId: $sessionId)
                }';
            case self::$DELETE_USER:
                return 'mutation deleteUser($userId: String!) {
                    usersDelete(userId: $userId)
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
            case self::$CREATE_API_KEY:
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
                        _id
                        name
                        email
                        status
                        registration
                        emailVerification
                    }
                }';
            case self::$CREATE_ACCOUNT:
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
            case self::$UPDATE_ACCOUNT_NAME:
                return 'mutation updateAccountName($name: String!){
                    accountUpdateName (name: $name) {
                        _id
                        name
                        status
                        email
                    }
                }';
            case self::$UPDATE_ACCOUNT_EMAIL:
                return 'mutation updateAccountEmail($email: String!, $password: String!){
                    accountUpdateEmail (email: $email, password: $password) {
                        _id
                        name
                        status
                        email
                    }
                }';
            case self::$UPDATE_ACCOUNT_PASSWORD:
                return 'mutation updateAccountPassword($password: String!, $oldPassword: String!){
                    accountUpdatePassword (password: $password, oldPassword: $oldPassword) {
                        _id
                        name
                        status
                        email
                    }
                }';
            case self::$UPDATE_ACCOUNT_PHONE:
                return 'mutation updateAccountPhone($phone: String!, $password: String!){
                    accountUpdatePhone (phone: $phone, password: $password) {
                        _id
                        name
                        status
                        email
                    }
                }';
            case self::$UPDATE_ACCOUNT_PREFS:
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
            case self::$UPDATE_ACCOUNT_STATUS:
                return 'mutation updateAccountStatus{
                    accountUpdateStatus {
                        status
                        email
                    }
                }';
            case self::$GET_ACCOUNT_SESSION:
                return 'query getAccountSession($sessionId: String!) {
                    accountGetSession(sessionId: $sessionId) {
                        _id
                        userId
                    }
                }';
            case self::$CREATE_ACCOUNT_SESSION:
                return 'mutation createAccountSession($email: String!, $password: String!){
                    accountCreateSession (email: $email, password: $password) {
                        _id
                        userId
                        expire
                        ip
                        current
                    }
                }';
            case self::$DELETE_ACCOUNT_SESSION:
                return 'mutation deleteAccountSession($sessionId: String!){
                    accountDeleteSession (sessionId: $sessionId)
                }';
            case self::$DELETE_ACCOUNT_SESSIONS:
                return 'mutation deleteAccountSessions {
                    accountDeleteSessions
                }';
            case self::$CREATE_MAGIC_URL:
                return 'mutation createMagicURL($userId: String!, $email: String!){
                    accountCreateMagicURLSession (userId: $userId, email: $email) {
                        userId
                        expire
                    }
                }';
            case self::$UPDATE_MAGIC_URL:
                return 'mutation confirmMagicURL($userId: String!, $secret: String!){
                    accountUpdateMagicURLSession (userId: $userId, secret: $secret) {
                        userId
                        expire
                        provider
                        ip
                    }
                }';
            case self::$CREATE_ANONYMOUS_SESSION:
                return 'mutation createAnonymousSession {
                    accountCreateAnonymousSession {
                        _id
                        userId
                    }
                }';
            case self::$CREATE_ACCOUNT_JWT:
                return 'mutation createJWT{
                    accountCreateJWT {
                        jwt
                    }
                }';
            case self::$GET_ACCOUNT_PREFS:
                return 'query getAccountPreferences {
                    accountGetPrefs { 
                        data 
                    }
                }';
            case self::$GET_ACCOUNT_SESSIONS:
                return 'query getAccountSessions {
                    accountGetSessions {
                        total
                        sessions {
                            _id
                            userId
                            expire
                        }
                    }
                }';
            case self::$GET_ACCOUNT_LOGS:
                return 'query getAccountLogs {
                    accountGetLogs {
                        total
                        logs {
                            event
                            userId
                            ip
                            countryName
                        }
                    }
                }';
            case self::$CREATE_PASSWORD_RECOVERY:
                return 'mutation createPasswordRecovery($email: String!, $url: String!){
                    accountCreateRecovery (email: $email, url: $url) {
                        userId
                        secret
                        expire
                    }
                }';
            case self::$UPDATE_PASSWORD_RECOVERY:
                return 'mutation confirmPasswordRecovery($userId: String!, $secret: String!, $password: String!, $passwordAgain: String!) {
                    accountUpdateRecovery (userId: $userId, secret: $secret, password: $password, passwordAgain: $passwordAgain) {
                        userId
                        secret
                        expire
                    }
                }';
            case self::$CREATE_EMAIL_VERIFICATION:
                return 'mutation createVerification($url: String!){
                    accountCreateVerification (url: $url) {
                        userId
                        secret
                        expire
                    }
                }';
            case self::$UPDATE_EMAIL_VERIFICATION:
                return 'mutation confirmVerification($userId: String!, $secret: String!) {
                    accountUpdateVerification (userId: $userId, secret: $secret) {
                        userId
                        secret
                        expire
                    }
                }';
            case self::$CREATE_PHONE_VERIFICATION:
                return 'mutation createPhoneVerification {
                    accountCreatePhoneVerification {
                        userId
                        secret
                        expire
                    }
                }';
            case self::$UPDATE_PHONE_VERIFICATION:
                return 'mutation confirmPhoneVerification($userId: String!, $secret: String!) {
                    accountUpdatePhoneVerification (userId: $userId, secret: $secret) {
                        userId
                        secret
                        expire
                    }
                }';
            case self::$GET_TEAM:
                return 'query getTeam($teamId: String!){
                    teamsGet (teamId: $teamId) {
                        _id
                        name
                        total
                    }
                }';
            case self::$GET_TEAMS:
                return 'query listTeams {
                    teamsList {
                        total
                        teams {
                            name
                            total
                        }
                    }
                }';
            case self::$CREATE_TEAM:
                return 'mutation createTeam($teamId: String!, $name: String!, $roles: [String]){
                    teamsCreate(teamId: $teamId, name : $name, roles: $roles) {
                        _id
                        name
                        total
                    }
                }';
            case self::$UPDATE_TEAM:
                return 'mutation updateTeam($teamId: String!, $name: String!){
                    teamsUpdate(teamId: $teamId, name : $name) {
                        _id
                        name
                        total
                    }
                }';
            case self::$DELETE_TEAM:
                return 'mutation deleteTeam($teamId: String!){
                    teamsDelete(teamId: $teamId)
                }';
            case self::$GET_TEAM_MEMBERSHIP:
                return 'query getTeamMembership($teamId: String!, $membershipId: String!){
                    teamsGetMembership (teamId: $teamId, membershipId: $membershipId) {
                        total
                        memberships {
                            _id
                            teamId
                            userName
                            userEmail
                        }
                    }
                }';
            case self::$GET_TEAM_MEMBERSHIPS:
                return 'query getTeamMemberships($teamId: String!){
                    teamsGetMemberships (teamId: $teamId) {
                        total
                        memberships {
                            _id
                            teamId
                            userName
                            userEmail
                        }
                    }
                }';
            case self::$CREATE_TEAM_MEMBERSHIP:
                return 'mutation createTeamMembership($teamId: String!, $email: String!, $name: String, $roles: [String!]!, $url: String!){
                    teamsCreateMembership(teamId: $teamId, email: $email, name : $name, roles: $roles, url: $url) {
                        _id
                        userId
                        teamId
                        userName 
                        userEmail
                        invited 
                        joined 
                        confirm
                        roles
                    }
                }';
            case self::$UPDATE_TEAM_MEMBERSHIP_ROLES:
                return 'mutation updateTeamMembershipRoles($teamId: String!, $membershipId: String!, $roles: [String!]!){
                    teamsUpdateMembershipRoles(teamId: $teamId, membershipId: $membershipId, roles: $roles) {
                        _id
                        userId
                        teamId
                        userName 
                        userEmail
                        invited 
                        joined 
                        confirm
                        roles
                    }
                }';
            case self::$UPDATE_TEAM_MEMBERSHIP_STATUS:
                return 'mutation updateTeamMembership($teamId: String!, $membershipId: String!, $userId: String!, $secret: String!){
                    teamsUpdateMembershipStatus(teamId: $teamId, membershipId: $membershipId, userId: $userId, secret: $secret ) {
                        _id
                        userId
                        teamId
                        userName 
                        userEmail
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
}
