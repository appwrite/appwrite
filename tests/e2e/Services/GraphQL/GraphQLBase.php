<?php

namespace Tests\E2E\Services\GraphQL;

trait GraphQLBase
{
    // Databases
    public static string $CREATE_DATABASE = 'create_database';
    public static string $GET_DATABASES = 'get_databases';
    public static string $GET_DATABASE = 'get_database';
    public static string $UPDATE_DATABASE = 'update_database';
    public static string $DELETE_DATABASE = 'delete_database';
    // Collections
    public static string $CREATE_COLLECTION = 'create_collection';
    public static string $GET_COLLECTION = 'get_collection';
    public static string $GET_COLLECTIONS = 'list_collections';
    public static string $UPDATE_COLLECTION = 'update_collection';
    public static string $DELETE_COLLECTION = 'delete_collection';
    // Attributes
    public static string $CREATE_STRING_ATTRIBUTE = 'create_string_attribute';
    public static string $CREATE_INTEGER_ATTRIBUTE = 'create_integer_attribute';
    public static string $CREATE_FLOAT_ATTRIBUTE = 'create_float_attribute';
    public static string $CREATE_BOOLEAN_ATTRIBUTE = 'create_boolean_attribute';
    public static string $CREATE_URL_ATTRIBUTE = 'create_url_attribute';
    public static string $CREATE_EMAIL_ATTRIBUTE = 'create_email_attribute';
    public static string $CREATE_IP_ATTRIBUTE = 'create_ip_attribute';
    public static string $CREATE_ENUM_ATTRIBUTE = 'create_enum_attribute';
    public static string $CREATE_DATETIME_ATTRIBUTE = 'create_datetime_attribute';
    public static string $GET_ATTRIBUTES = 'get_attributes';
    public static string $GET_ATTRIBUTE = 'get_attribute';
    public static string $DELETE_ATTRIBUTE = 'delete_attribute';
    // Indexes
    public static string $CREATE_INDEX = 'create_index';
    public static string $GET_INDEXES = 'get_indexes';
    public static string $GET_INDEX = 'get_index';
    public static string $DELETE_INDEX = 'delete_index';
    // Documents
    public static string $CREATE_DOCUMENT = 'create_document_rest';
    public static string $GET_DOCUMENTS = 'list_documents';
    public static string $GET_DOCUMENT = 'get_document';
    public static string $UPDATE_DOCUMENT = 'update_document';
    public static string $DELETE_DOCUMENT = 'delete_document';

    // Custom Entities
    public static string $CREATE_CUSTOM_ENTITY = 'create_custom_entity';
    public static string $GET_CUSTOM_ENTITIES = 'get_custom_entities';
    public static string $GET_CUSTOM_ENTITY = 'get_custom_entity';
    public static string $UPDATE_CUSTOM_ENTITY = 'update_custom_entity';
    public static string $DELETE_CUSTOM_ENTITY = 'delete_custom_entity';

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
    public static string $UPDATE_USER_PHONE_VERIFICATION = 'update_phone_verification';
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
    public static string $CREATE_FUNCTION = 'create_function';
    public static string $GET_FUNCTIONS = 'list_functions';
    public static string $GET_FUNCTION = 'get_function';
    public static string $GET_RUNTIMES = 'list_runtimes';
    public static string $UPDATE_FUNCTION = 'update_function';
    public static string $DELETE_FUNCTION = 'delete_function';
    //Deployments
    public static string $CREATE_DEPLOYMENT = 'create_deployment';
    public static string $GET_DEPLOYMENTS = 'list_deployments';
    public static string $GET_DEPLOYMENT = 'get_deployment';
    public static string $UPDATE_DEPLOYMENT = 'update_deployment';
    public static string $DELETE_DEPLOYMENT = 'delete_deployment';
    // Executions
    public static string $GET_EXECUTIONS = 'list_executions';
    public static string $GET_EXECUTION = 'get_execution';
    public static string $CREATE_EXECUTION = 'create_execution';
    public static string $DELETE_EXECUTION = 'delete_execution';
    public static string $RETRY_BUILD = 'retry_build';

    // Buckets
    public static string $CREATE_BUCKET = 'create_bucket';
    public static string $GET_BUCKETS = 'list_buckets';
    public static string $GET_BUCKET = 'get_bucket';
    public static string $UPDATE_BUCKET = 'update_bucket';
    public static string $DELETE_BUCKET = 'delete_bucket';
    // Files
    public static string $CREATE_FILE = 'create_file';
    public static string $GET_FILES = 'list_files';
    public static string $GET_FILE = 'get_file';
    public static string $GET_FILE_PREVIEW = 'get_file_preview';
    public static string $GET_FILE_DOWNLOAD = 'get_file_download';
    public static string $GET_FILE_VIEW = 'get_file_view';
    public static string $UPDATE_FILE = 'update_file';
    public static string $DELETE_FILE = 'delete_file';

    // Health
    public static string $GET_HTTP_HEALTH = 'get_http_health';
    public static string $GET_DB_HEALTH = 'get_db_health';
    public static string $GET_CACHE_HEALTH = 'get_cache_health';
    public static string $GET_TIME_HEALTH = 'get_time_health';
    public static string $GET_WEBHOOKS_QUEUE_HEALTH = 'get_webhooks_queue_health';
    public static string $GET_LOGS_QUEUE_HEALTH = 'get_logs_queue_health';
    public static string $GET_CERTIFICATES_QUEUE_HEALTH = 'get_certificates_queue_health';
    public static string $GET_FUNCTION_QUEUE_HEALTH = 'get_functions_queue_health';
    public static string $GET_LOCAL_STORAGE_HEALTH = 'get_local_storage_health';
    public static string $GET_ANITVIRUS_HEALTH = 'get_antivirus_health';

    // Localization
    public static string $GET_LOCALE = 'get_locale';
    public static string $LIST_COUNTRIES = 'list_countries';
    public static string $LIST_EU_COUNTRIES = 'list_eu_countries';
    public static string $LIST_COUNTRY_PHONE_CODES = 'list_country_phone_codes';
    public static string $LIST_CONTINENTS = 'list_continents';
    public static string $LIST_CURRENCIES = 'list_currencies';
    public static string $LIST_LANGUAGES = 'list_languages';

    // Avatars
    public static string $GET_CREDIT_CARD_ICON = 'get_credit_card_icon';
    public static string $GET_BROWSER_ICON = 'get_browser_icon';
    public static string $GET_COUNTRY_FLAG = 'get_country_flag';
    public static string $GET_IMAGE_FROM_URL = 'get_image_from_url';
    public static string $GET_FAVICON = 'get_favicon';
    public static string $GET_QRCODE = 'get_qrcode';
    public static string $GET_USER_INITIALS = 'get_user_initials';

    // Complex queries
    public static string $CREATE_DATABASE_STACK = 'complex_query';

    public function getQuery(string $name): string
    {
        switch ($name) {
            case self::$CREATE_DATABASE:
                return 'mutation createDatabase($databaseId: String!, $name: String!) {
                    databasesCreate(databaseId: $databaseId, name: $name) {
                        _id
                        name
                    }
                }';
            case self::$GET_DATABASES:
                return 'query listDatabases {
                    databasesList {
                        total
                        databases {
                            _id
                            name
                        }
                    }
                }';
            case self::$GET_DATABASE:
                return 'query getDatabase($databaseId: String!) {
                    databasesGet(databaseId: $databaseId) {
                        _id
                        name
                    }
                }';
            case self::$UPDATE_DATABASE:
                return 'mutation updateDatabase($databaseId: String!, $name: String!) {
                    databasesUpdate(databaseId: $databaseId, name: $name) {
                        _id
                        name
                    }
                }';
            case self::$DELETE_DATABASE:
                return 'mutation deleteDatabase($databaseId: String!) {
                    databasesDelete(databaseId: $databaseId)
                }';
            case self::$GET_COLLECTION:
                return 'query getCollection($databaseId: String!, $collectionId: String!) {
                    databasesGetCollection(databaseId: $databaseId, collectionId: $collectionId) {
                        _id
                        _permissions
                        documentSecurity
                        name
                    }
                }';
            case self::$GET_COLLECTIONS:
                return 'query listCollections($databaseId: String!) {
                    databasesListCollections(databaseId: $databaseId) {
                        total
                        collections {
                            _id
                            _permissions
                            documentSecurity
                            name
                        }
                    }
                }';
            case self::$CREATE_COLLECTION:
                return 'mutation createCollection($databaseId: String!, $collectionId: String!, $name: String!, $documentSecurity: Boolean!, $permissions: [String!]!) {
                    databasesCreateCollection(databaseId: $databaseId, collectionId: $collectionId, name: $name, documentSecurity: $documentSecurity, permissions: $permissions) {
                        _id
                        _permissions
                        documentSecurity
                        name
                    }
                }';
            case self::$UPDATE_COLLECTION:
                return 'mutation updateCollection($databaseId: String!, $collectionId: String!, $name: String!, $documentSecurity: Boolean!, $permissions: [String!], $enabled: Boolean){
                    databasesUpdateCollection(databaseId: $databaseId, collectionId: $collectionId, name: $name, documentSecurity: $documentSecurity, permissions: $permissions, enabled: $enabled) {
                        _id
                        _permissions
                        documentSecurity
                        name
                    }
                }';
            case self::$DELETE_COLLECTION:
                return 'mutation deleteCollection($databaseId: String!, $collectionId: String!){
                    databasesDeleteCollection(databaseId: $databaseId, collectionId: $collectionId)
                }';
            case self::$CREATE_STRING_ATTRIBUTE:
                return 'mutation createStringAttribute($databaseId: String!, $collectionId: String!, $key: String!, $size: Int!, $required: Boolean!, $default: String, $array: Boolean){
                    databasesCreateStringAttribute(databaseId: $databaseId, collectionId: $collectionId, key: $key, size: $size, required: $required, default: $default, array: $array) {
                        key
                        required
                        default
                        array
                    }
                }';
            case self::$CREATE_INTEGER_ATTRIBUTE:
                return 'mutation createIntegerAttribute($databaseId: String!, $collectionId: String!, $key: String!, $required: Boolean!, $min: Int, $max: Int, $default: Int, $array: Boolean){
                    databasesCreateIntegerAttribute(databaseId: $databaseId, collectionId: $collectionId, key: $key, min: $min, max: $max, required: $required, default: $default, array: $array) {
                        key
                        required
                        min
                        max
                        default
                        array
                    }
                }';
            case self::$CREATE_FLOAT_ATTRIBUTE:
                return 'mutation createFloatAttribute($databaseId: String!, $collectionId: String!, $key: String!, $required: Boolean!, $min: Float, $max: Float, $default: Float, $array: Boolean){
                    databasesCreateFloatAttribute(databaseId: $databaseId, collectionId: $collectionId, key: $key, min: $min, max: $max, required: $required, default: $default, array: $array) {
                        key
                        required
                        min
                        max
                        default
                        array
                    }
                }';
            case self::$CREATE_BOOLEAN_ATTRIBUTE:
                return 'mutation createBooleanAttribute($databaseId: String!, $collectionId: String!, $key: String!, $required: Boolean!, $default: Boolean, $array: Boolean){
                    databasesCreateBooleanAttribute(databaseId: $databaseId, collectionId: $collectionId, key: $key, required: $required, default: $default, array: $array) {
                        key
                        required
                        default
                        array
                    }
                }';
            case self::$CREATE_URL_ATTRIBUTE:
                return 'mutation createUrlAttribute($databaseId: String!, $collectionId: String!, $key: String!, $required: Boolean!, $default: String, $array: Boolean){
                    databasesCreateUrlAttribute(databaseId: $databaseId, collectionId: $collectionId, key: $key, required: $required, default: $default, array: $array) {
                        key
                        required
                        default
                        array
                    }
                }';
            case self::$CREATE_EMAIL_ATTRIBUTE:
                return 'mutation createEmailAttribute($databaseId: String!, $collectionId: String!, $key: String!, $required: Boolean!, $default: String, $array: Boolean){
                    databasesCreateEmailAttribute(databaseId: $databaseId, collectionId: $collectionId, key: $key, required: $required, default: $default, array: $array) {
                        key
                        required
                        default
                        array
                    }
                }';
            case self::$CREATE_IP_ATTRIBUTE:
                return 'mutation createIpAttribute($databaseId: String!, $collectionId: String!, $key: String!, $required: Boolean!, $default: String, $array: Boolean){
                    databasesCreateIpAttribute(databaseId: $databaseId, collectionId: $collectionId, key: $key, required: $required, default: $default, array: $array) {
                        key
                        required
                        default
                        array
                    }
                }';
            case self::$CREATE_ENUM_ATTRIBUTE:
                return 'mutation createEnumAttribute($databaseId: String!, $collectionId: String!, $key: String!, $elements: [String!]!, $required: Boolean!, $default: String, $array: Boolean){
                    databasesCreateEnumAttribute(databaseId: $databaseId, collectionId: $collectionId, key: $key, elements: $elements, required: $required, default: $default, array: $array) {
                        key
                        elements
                        required
                        default
                        array
                    }
                }';
            case self::$CREATE_DATETIME_ATTRIBUTE:
                return 'mutation createDatetimeAttribute($databaseId: String!, $collectionId: String!, $key: String!, $required: Boolean!, $default: String, $array: Boolean){
                    databasesCreateDatetimeAttribute(databaseId: $databaseId, collectionId: $collectionId, key: $key, required: $required, default: $default, array: $array) {
                        key
                        required
                        default
                        array
                    }
                }';
            case self::$CREATE_INDEX:
                return 'mutation createIndex($databaseId: String!, $collectionId: String!, $key: String!, $type: String!, $attributes: [String!]!, $orders: [String!]){
                    databasesCreateIndex(databaseId: $databaseId, collectionId: $collectionId, key: $key, type: $type, attributes: $attributes, orders: $orders) {
                        key
                        type
                        status
                    }
                }';
            case self::$GET_INDEXES:
                return 'query listIndexes($databaseId: String!, $collectionId: String!) {
                    databasesListIndexes(databaseId: $databaseId, collectionId: $collectionId) {
                        total
                        indexes {
                            key
                            type
                            status
                        }
                    }
                }';
            case self::$GET_INDEX:
                return 'query getIndex($databaseId: String!, $collectionId: String!, $key: String!) {
                    databasesGetIndex(databaseId: $databaseId, collectionId: $collectionId, key: $key) {
                        key
                        type
                        status
                    }
                }';
            case self::$DELETE_INDEX:
                return 'mutation deleteIndex($databaseId: String!, $collectionId: String!, $key: String!){
                    databasesDeleteIndex(databaseId: $databaseId, collectionId: $collectionId, key: $key)
                }';
            case self::$GET_ATTRIBUTES:
                return 'query listAttributes($databaseId: String!, $collectionId: String!) {
                    databasesListAttributes(databaseId: $databaseId, collectionId: $collectionId) {
                        total
                        attributes {
                            key
                            required
                            default
                            array
                            status
                        }
                    }
                }';
            case self::$GET_ATTRIBUTE:
                return 'query getAttribute($databaseId: String!, $collectionId: String!, $key: String!) {
                    databasesGetAttribute(databaseId: $databaseId, collectionId: $collectionId, key: $key) {
                        key
                        required
                        default
                        array
                    }
                }';
            case self::$DELETE_ATTRIBUTE:
                return 'mutation deleteAttribute($databaseId: String!, $collectionId: String!, $key: String!){
                    databasesDeleteAttribute(databaseId: $databaseId, collectionId: $collectionId, key: $key)
                }';
            case self::$GET_DOCUMENT:
                return 'query getDocument($databaseId: String!, $collectionId: String!, $documentId: String!){
                    databasesGetDocument(databaseId: $databaseId, collectionId: $collectionId, documentId: $documentId) {
                        _id
                        _collectionId
                        _permissions
                        data
                    }
                }';
            case self::$GET_DOCUMENTS:
                return 'query listDocuments($databaseId: String!, $collectionId: String!){
                    databasesListDocuments(databaseId: $databaseId, collectionId: $collectionId) {
                        total
                        documents {
                            _id
                            _collectionId
                            _permissions
                            data
                        }
                    }   
                }';
            case self::$CREATE_DOCUMENT:
                return 'mutation createDocument($databaseId: String!, $collectionId: String!, $documentId: String!, $data: Json!, $permissions: [String!]){
                    databasesCreateDocument(databaseId: $databaseId, collectionId: $collectionId, documentId: $documentId, data: $data, permissions: $permissions) {
                        _id
                        _collectionId
                        _permissions
                    }
                }';
            case self::$CREATE_CUSTOM_ENTITY:
                return 'mutation createActor($name: String!, $age: Int!, $alive: Boolean!, $salary: Float, $email: String!, $role: String!, $dob: String!, $ip: String, $url: String){
                    actorsCreate(name: $name, age: $age, alive: $alive, salary: $salary, email: $email, role: $role, dob: $dob, ip: $ip, url: $url) {
                        _id
                        name
                        age
                        alive
                        salary
                        email
                        role
                    }
                }';
            case self::$GET_CUSTOM_ENTITIES:
                return 'query getCustomEntities($name: String!){
                    actorsList(name: $name) {
                        total
                        actors {
                            name
                            age
                            alive
                            salary
                            email
                            role
                            dob
                            ip
                            url
                        }
                    }
                }';
            case self::$GET_CUSTOM_ENTITY:
                return 'query getCustomEntity($id: String!){
                    actorsGet(id: $id) {
                        name
                        age
                        alive
                        salary
                        email
                        role
                        dob
                        ip
                        url
                    }
                }';
            case self::$UPDATE_CUSTOM_ENTITY:
                return 'mutation updateCustomEntity($id: String!, $name: String, $age: Int, $alive: Boolean, $salary: Float, $email: String, $role: String, $dob: String, $ip: String, $url: String){
                    actorsUpdate(id: $id, name: $name, age: $age, alive: $alive, salary: $salary, email: $email, role: $role, dob: $dob, ip: $ip, url: $url) {
                        name
                        age
                        alive
                        salary
                        email
                        role
                        dob
                        ip
                        url
                    }
                }';
            case self::$DELETE_CUSTOM_ENTITY:
                return 'mutation deleteCustomEntity($id: String!){
                        actorsDelete(id: $id)
                    }';
            case self::$UPDATE_DOCUMENT:
                return 'mutation updateDocument($databaseId: String!, $collectionId: String!, $documentId: String!, $data: Json!, $permissions: [String!]){
                    databasesUpdateDocument(databaseId: $databaseId, collectionId: $collectionId, documentId: $documentId, data: $data, permissions: $permissions) {
                        _id
                        _collectionId
                        data
                    }
                }';
            case self::$DELETE_DOCUMENT:
                return 'mutation deleteDocument($databaseId: String!, $collectionId: String!, $documentId: String!){
                    databasesDeleteDocument(databaseId: $databaseId, collectionId: $collectionId, documentId: $documentId)
                }';

            case self::$GET_USER:
                return 'query getUser($userId : String!) {
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
                return 'query getUserPreferences($userId : String!) {
                    usersGetPrefs(userId : $userId) {
                        data
                    }
                }';
            case self::$GET_USER_SESSIONS:
                return 'query listUserSessions($userId : String!) {
                    usersListSessions(userId : $userId) {
                        total 
                        sessions {
                            _id
                            userId
                        }
                    }
                }';
            case self::$GET_USER_MEMBERSHIPS:
                return 'query listUserMemberships($userId : String!) {
                    usersListMemberships(userId : $userId) {
                        total
                        memberships {
                            _id
                            userId
                            teamId
                        }
                    }
                }';
            case self::$GET_USER_LOGS:
                return 'query listUserLogs($userId : String!) {
                    usersListLogs(userId : $userId) {
                        total
                        logs {
                            event
                            userId
                        }
                    }
                }';
            case self::$GET_USERS:
                return 'query listUsers($search: String, $queries: [String!]) {
                    usersList(search: $search, queries: $queries) {
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
                    usersCreate(userId: $userId, email: $email, password: $password, name: $name) {
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
                    usersUpdateStatus(userId: $userId, status: $status) {
                        _id
                        name
                        email
                    }
                }';
            case self::$UPDATE_USER_NAME:
                return 'mutation updateUserName($userId: String!, $name: String!){
                    usersUpdateName(userId: $userId, name: $name) {
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
                    usersUpdateEmail(userId: $userId, email: $email) {
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
                    usersUpdatePassword(userId: $userId, password: $password) {
                        _id
                        name
                        registration
                        status
                        email
                        emailVerification
                    }
                }';
            case self::$UPDATE_USER_PHONE:
                return 'mutation updateUserPhone($userId: String!, $number: String!){
                    usersUpdatePhone(userId: $userId, number: $number) {
                        name
                        phone
                        email
                    }
                }';
            case self::$UPDATE_USER_PREFS:
                return 'mutation updateUserPrefs($userId: String!, $prefs: Json!){
                    usersUpdatePrefs(userId: $userId, prefs: $prefs) {
                        data
                    }
                }';
            case self::$UPDATE_USER_EMAIL_VERIFICATION:
                return 'mutation updateUserEmailVerification($userId: String!, $emailVerification: Boolean!){
                    usersUpdateEmailVerification(userId: $userId, emailVerification: $emailVerification) {
                        name
                        email
                    }
                }';
            case self::$UPDATE_USER_PHONE_VERIFICATION:
                return 'mutation updateUserPhoneVerification($userId: String!, $phoneVerification: Boolean!){
                    usersUpdatePhoneVerification(userId: $userId, phoneVerification: $phoneVerification) {
                        _id
                        name
                        email
                    }
                }';
            case self::$DELETE_USER_SESSIONS:
                return 'mutation deleteUserSessions($userId: String!){
                    usersDeleteSessions(userId: $userId)
                }';
            case self::$DELETE_USER_SESSION:
                return 'mutation deleteUserSession($userId: String!, $sessionId: String!){
                    usersDeleteSession(userId: $userId, sessionId: $sessionId)
                }';
            case self::$DELETE_USER:
                return 'mutation deleteUser($userId: String!) {
                    usersDelete(userId: $userId)
                }';
            case self::$GET_LOCALE:
                return 'query getLocale {
                    localeGet {
                        ip
                        country
                        continent
                        currency
                    }
                }';
            case self::$LIST_COUNTRIES:
                return 'query listCountries {
                    localeListCountries{
                        total
                        countries {
                            name
                            code
                        }
                    }
                }';
            case self::$LIST_EU_COUNTRIES:
                return 'query listEuCountries {
                    localeListCountriesEU{
                        total
                        countries {
                            name
                            code
                        }
                    }
                }';
            case self::$LIST_COUNTRY_PHONE_CODES:
                return 'query listCountryPhoneCodes {
                    localeListCountriesPhones {
                        total
                        phones {
                            code
                            countryName
                        }
                    }
                }';
            case self::$LIST_CONTINENTS:
                return 'query listContinents {
                    localeListContinents{
                        total
                        continents {
                            name
                            code
                        }
                    }
                }';
            case self::$LIST_CURRENCIES:
                return 'query listCurrencies {
                    localeListCurrencies{
                        total
                        currencies {
                            name
                            code
                            symbol
                        }
                    }
                }';
            case self::$LIST_LANGUAGES:
                return 'query listLanguages {
                    localeListLanguages{
                        total
                        languages {
                            name
                            code
                        }
                    }
                }';
            case self::$GET_CREDIT_CARD_ICON:
                return 'query getCreditCardIcon($code: String!) {
                    avatarsGetCreditCard(code: $code)
                }';
            case self::$GET_BROWSER_ICON:
                return 'query getBrowserIcon($code: String!) {
                    avatarsGetBrowser(code: $code)
                }';
            case self::$GET_COUNTRY_FLAG:
                return 'query getCountryFlag($code: String!) {
                    avatarsGetFlag(code: $code)
                }';
            case self::$GET_IMAGE_FROM_URL:
                return 'query getImageFromUrl($url: String!) {
                    avatarsGetImage(url: $url)
                }';
            case self::$GET_FAVICON:
                return 'query getFavicon($url: String!) {
                    avatarsGetFavicon(url: $url)
                }';
            case self::$GET_QRCODE:
                return 'query getQrCode($text: String!) {
                    avatarsGetQR(text: $text)
                }';
            case self::$GET_USER_INITIALS:
                return 'query getUserInitials($name: String!) {
                    avatarsGetInitials(name: $name)
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
                    accountCreate(userId: $userId, email: $email, password: $password, name: $name) {
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
                    accountUpdateName(name: $name) {
                        _id
                        name
                        status
                        email
                        phone
                    }
                }';
            case self::$UPDATE_ACCOUNT_EMAIL:
                return 'mutation updateAccountEmail($email: String!, $password: String!){
                    accountUpdateEmail(email: $email, password: $password) {
                        _id
                        name
                        status
                        email
                    }
                }';
            case self::$UPDATE_ACCOUNT_PASSWORD:
                return 'mutation updateAccountPassword($password: String!, $oldPassword: String!){
                    accountUpdatePassword(password: $password, oldPassword: $oldPassword) {
                        _id
                        name
                        status
                        email
                    }
                }';
            case self::$UPDATE_ACCOUNT_PHONE:
                return 'mutation updateAccountPhone($phone: String!, $password: String!){
                    accountUpdatePhone(phone: $phone, password: $password) {
                        _id
                        name
                        status
                        email
                        phone
                    }
                }';
            case self::$UPDATE_ACCOUNT_PREFS:
                return 'mutation updateAccountPrefs($userId: String!, $prefs: Json!){
                    accountUpdatePrefs(userId: $userId, prefs: $prefs) {
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
                return 'mutation createAccountEmailSession($email: String!, $password: String!){
                    accountCreateEmailSession(email: $email, password: $password) {
                        _id
                        userId
                        expire
                        ip
                        current
                    }
                }';
            case self::$DELETE_ACCOUNT_SESSION:
                return 'mutation deleteAccountSession($sessionId: String!){
                    accountDeleteSession(sessionId: $sessionId)
                }';
            case self::$DELETE_ACCOUNT_SESSIONS:
                return 'mutation deleteAccountSessions {
                    accountDeleteSessions
                }';
            case self::$CREATE_MAGIC_URL:
                return 'mutation createMagicURL($userId: String!, $email: String!){
                    accountCreateMagicURLSession(userId: $userId, email: $email) {
                        userId
                        expire
                    }
                }';
            case self::$UPDATE_MAGIC_URL:
                return 'mutation confirmMagicURL($userId: String!, $secret: String!){
                    accountUpdateMagicURLSession(userId: $userId, secret: $secret) {
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
                return 'query listAccountSessions {
                    accountListSessions {
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
                    accountListLogs {
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
                    accountCreateRecovery(email: $email, url: $url) {
                        userId
                        secret
                        expire
                    }
                }';
            case self::$UPDATE_PASSWORD_RECOVERY:
                return 'mutation confirmPasswordRecovery($userId: String!, $secret: String!, $password: String!, $passwordAgain: String!) {
                    accountUpdateRecovery(userId: $userId, secret: $secret, password: $password, passwordAgain: $passwordAgain) {
                        userId
                        secret
                        expire
                    }
                }';
            case self::$CREATE_EMAIL_VERIFICATION:
                return 'mutation createVerification($url: String!){
                    accountCreateVerification(url: $url) {
                        userId
                        secret
                        expire
                    }
                }';
            case self::$UPDATE_EMAIL_VERIFICATION:
                return 'mutation confirmVerification($userId: String!, $secret: String!) {
                    accountUpdateVerification(userId: $userId, secret: $secret) {
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
                return 'mutation confirmPhoneVerification($userId: String!, $phoneVerification: Boolean!) {
                    accountUpdatePhoneVerification(userId: $userId, phoneVerification: $phoneVerification) {
                        userId
                        secret
                        expire
                    }
                }';
            case self::$GET_TEAM:
                return 'query getTeam($teamId: String!){
                    teamsGet(teamId: $teamId) {
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
                    teamsListMembership(teamId: $teamId, membershipId: $membershipId) {
                        _id
                        teamId
                        userId
                        userName
                        userEmail
                    }
                }';
            case self::$GET_TEAM_MEMBERSHIPS:
                return 'query listTeamMemberships($teamId: String!){
                    teamsListMemberships(teamId: $teamId) {
                        total
                        memberships {
                            _id
                            teamId
                            userId
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
                return 'mutation deleteTeamMembership($teamId: String!, $membershipId: String!){
                    teamsDeleteMembership(teamId: $teamId, membershipId: $membershipId)
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
            case self::$GET_FUNCTIONS:
                return 'query listFunctions {
                    functionsList {
                        total
                        functions {
                            _id
                            name
                            runtime
                            execute
                        }
                    }
                }';
            case self::$GET_RUNTIMES:
                return 'query listRuntimes {
                    functionsListRuntimes {
                        total
                        runtimes {
                            name
                            version
                            supports
                        }
                    }
                }';
            case self::$GET_DEPLOYMENTS:
                return 'query listDeployments($functionId: String!) {
                    functionsListDeployments(functionId: $functionId) {
                        total
                        deployments {
                            _id
                            buildStdout
                            buildStderr
                        }
                    }
                }';
            case self::$GET_DEPLOYMENT:
                return 'query getDeployment($functionId: String!, $deploymentId: String!) {
                    functionsGetDeployment(functionId: $functionId, deploymentId: $deploymentId) {
                        _id
                        buildId
                        buildStdout
                        buildStderr
                    }
                }';
            case self::$CREATE_FUNCTION:
                return 'mutation createFunction($functionId: String!, $name: String!, $execute: [String!]!, $runtime: String! $vars: Json, $events: [String], $schedule: String, $timeout: Int) {
                    functionsCreate(functionId: $functionId, name: $name, execute: $execute, runtime: $runtime, vars: $vars, events: $events, schedule: $schedule, timeout: $timeout) {
                        _id
                        name
                        runtime
                        execute
                    }
                }';
            case self::$UPDATE_FUNCTION:
                return 'mutation updateFunction($functionId: String!, $name: String!, $execute: [String!]!, $vars: Json, $events: [String], $schedule: String, $timeout: Int) {
                    functionsUpdate(functionId: $functionId, name: $name, execute: $execute, vars: $vars, events: $events, schedule: $schedule, timeout: $timeout) {
                        _id
                        name
                        runtime
                        execute
                    }
                }';
            case self::$UPDATE_DEPLOYMENT:
                return 'mutation updateFunctionDeployment($functionId: String!, $deploymentId: String!) {
                    functionsUpdateDeployment(functionId: $functionId, deploymentId: $deploymentId) {
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
            case self::$CREATE_DEPLOYMENT:
                return 'mutation createDeployment($functionId: String!, $entrypoint: String!, $code: InputFile!, $activate: Boolean!) {
                    functionsCreateDeployment(functionId: $functionId, entrypoint: $entrypoint, code: $code, activate: $activate) {
                        _id
                        buildId
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
                        stderr
                    }
                }';
            case self::$GET_EXECUTIONS:
                return 'query listExecutions($functionId: String!) {
                    functionsListExecutions(functionId: $functionId) {
                        total
                        executions {
                            _id
                            status
                            stderr
                        }
                    }
                }';
            case self::$CREATE_EXECUTION:
                return 'mutation createExecution($functionId: String!, $data: String, $async: Boolean) {
                    functionsCreateExecution(functionId: $functionId, data: $data, async: $async) {
                        _id
                        status
                        stderr
                    }
                }';
            case self::$DELETE_EXECUTION:
                return 'mutation deleteExecution($functionId: String!, $executionId: String!) {
                    functionsDeleteExecution(functionId: $functionId, executionId: $executionId)
                }';
            case self::$RETRY_BUILD:
                return 'mutation retryBuild($functionId: String!, $deploymentId: String!, $buildId: String!) {
                    functionsRetryBuild(functionId: $functionId, deploymentId: $deploymentId, buildId: $buildId)
                }';
            case self::$CREATE_BUCKET:
                return 'mutation createBucket($bucketId: String!, $name: String!, $fileSecurity: Boolean, $permissions: [String!]) {
                    storageCreateBucket(bucketId: $bucketId, name: $name, fileSecurity: $fileSecurity, permissions: $permissions) {
                        _id
                        _createdAt
                        _updatedAt
                        _permissions
                        name
                        enabled
                        fileSecurity
                    }
                }';
            case self::$GET_BUCKETS:
                return 'query getBuckets {
                    storageListBuckets {
                        total
                        buckets {
                            _id
                            name
                            enabled
                        }
                    }
                }';
            case self::$GET_BUCKET:
                return 'query getBucket($bucketId: String!) {
                    storageGetBucket(bucketId: $bucketId) {
                        _id
                        name
                        enabled
                    }
                }';
            case self::$UPDATE_BUCKET:
                return 'mutation updateBucket($bucketId: String!, $name: String!, $fileSecurity: Boolean, $permissions: [String!]) {
                    storageUpdateBucket(bucketId: $bucketId, name: $name, fileSecurity: $fileSecurity, permissions: $permissions) {
                        _id
                        name
                        enabled
                    }
                }';
            case self::$DELETE_BUCKET:
                return 'mutation deleteBucket($bucketId: String!) {
                    storageDeleteBucket(bucketId: $bucketId)
                }';
            case self::$CREATE_FILE:
                return 'mutation createFile($bucketId: String!, $fileId: String!, $file: InputFile!, $permissions: [String!]) {
                    storageCreateFile(bucketId: $bucketId, fileId: $fileId, file: $file, permissions: $permissions) {
                        _id
                        bucketId
                        name
                    }
                }';
            case self::$GET_FILES:
                return 'query getFiles($bucketId: String!) {
                    storageListFiles(bucketId: $bucketId) {
                        total
                        files {
                            _id
                            name
                        }
                    }
                }';
            case self::$GET_FILE:
                return 'query getFile($bucketId: String!, $fileId: String!) {
                    storageGetFile(bucketId: $bucketId, fileId: $fileId) {
                        _id
                        name
                    }
                }';
            case self::$GET_FILE_PREVIEW:
                return 'query getFilePreview($bucketId: String!, $fileId: String!) {
                    storageGetFilePreview(bucketId: $bucketId, fileId: $fileId)
                }';
            case self::$GET_FILE_DOWNLOAD:
                return 'query getFileDownload($bucketId: String!, $fileId: String!) {
                    storageGetFileDownload(bucketId: $bucketId, fileId: $fileId)
                }';
            case self::$GET_FILE_VIEW:
                return 'query getFileView($bucketId: String!, $fileId: String!) {
                    storageGetFileView(bucketId: $bucketId, fileId: $fileId)
                }';
            case self::$UPDATE_FILE:
                return 'mutation updateFile($bucketId: String!, $fileId: String!, $permissions: [String!]) {
                    storageUpdateFile(bucketId: $bucketId, fileId: $fileId, permissions: $permissions) {
                        _id
                        name
                    }
                }';
            case self::$DELETE_FILE:
                return 'mutation deleteFile($bucketId: String!, $fileId: String!) {
                    storageDeleteFile(bucketId: $bucketId, fileId: $fileId)
                }';
            case self::$GET_HTTP_HEALTH:
                return 'query getHttpHealth {
                    healthGet {
                        ping
                        status
                    }
                }';
            case self::$GET_DB_HEALTH:
                return 'query getDbHealth {
                    healthGetDB {
                        ping
                        status
                    }
                }';
            case self::$GET_CACHE_HEALTH:
                return 'query getCacheHealth {
                    healthGetCache {
                        ping
                        status
                    }
                }';
            case self::$GET_TIME_HEALTH:
                return 'query getTimeHealth {
                    healthGetTime {
                        remoteTime
                        localTime
                        diff
                    }
                }';
            case self::$GET_WEBHOOKS_QUEUE_HEALTH:
                return 'query getWebhooksQueueHealth {
                    healthGetQueueWebhooks {
                        size
                    }
                }';
            case self::$GET_LOGS_QUEUE_HEALTH:
                return 'query getLogsQueueHealth {
                    healthGetQueueLogs {
                        size
                    }
                }';
            case self::$GET_CERTIFICATES_QUEUE_HEALTH:
                return 'query getCertificatesQueueHealth {
                    healthGetQueueCertificates {
                        size
                    }
                }';
            case self::$GET_FUNCTION_QUEUE_HEALTH:
                return 'query getFunctionQueueHealth {
                    healthGetQueueFunctions {
                        size
                    }
                }';
            case self::$GET_LOCAL_STORAGE_HEALTH:
                return 'query getLocalStorageHealth {
                    healthGetStorageLocal {
                        ping
                        status
                    }
                }';
            case self::$GET_ANITVIRUS_HEALTH:
                return 'query getAntivirusHealth {
                    healthGetAntivirus {
                        version
                        status
                    }
                }';
            case self::$CREATE_DATABASE_STACK:
                return 'mutation complex($databaseId: String!, $databaseName: String!, $collectionId: String!, $collectionName: String!, $documentSecurity: Boolean!, $collectionPermissions: [String!]!) {
                    databasesCreate(databaseId: $databaseId, name: $databaseName) {
                        _id
                        name
                    }
                    databasesCreateCollection(databaseId: $databaseId, collectionId: $collectionId, name: $collectionName, documentSecurity: $documentSecurity, permissions: $collectionPermissions) {
                        _id
                        _createdAt
                        _updatedAt
                        _permissions
                        databaseId
                        name
                        documentSecurity
                        attributes {
                            key
                            type
                            status
                        }
                        indexes {
                            key
                            type
                            status
                        }
                    }
                    databasesCreateStringAttribute(databaseId: $databaseId, collectionId: $collectionId, key: "name", size: 255, required: true) {
                        key
                        type
                        status
                        size
                        required
                        default
                        array
                    }
                    databasesCreateIntegerAttribute(databaseId: $databaseId, collectionId: $collectionId, key: "age", min: 0, max: 150, required: true) {
                        key
                        type
                        status
                        required
                        min
                        max
                        default
                        array
                    }
                    databasesCreateBooleanAttribute(databaseId: $databaseId, collectionId: $collectionId, key: "alive", required: false, default: true) {
                        key
                        type
                        status
                        required
                        default
                        array
                    }
                    usersCreate(userId: "unique()", email: "test1@appwrite.io", password: "password", name: "Tester 1") {
                        _id
                        _createdAt
                        _updatedAt
                        name
                        phone
                        email
                        status
                        registration
                        passwordUpdate
                        emailVerification
                        phoneVerification
                        prefs {
                            data
                        }
                    }
                }';
        }

        throw new \InvalidArgumentException('Invalid query type');
    }
}
