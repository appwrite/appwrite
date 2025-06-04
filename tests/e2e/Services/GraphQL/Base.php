<?php

namespace Tests\E2E\Services\GraphQL;

use CURLFile;
use Utopia\CLI\Console;

trait Base
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

    public static string $CREATE_RELATIONSHIP_ATTRIBUTE = 'create_relationship_attribute';
    public static string $UPDATE_STRING_ATTRIBUTE = 'update_string_attribute';
    public static string $UPDATE_INTEGER_ATTRIBUTE = 'update_integer_attribute';
    public static string $UPDATE_FLOAT_ATTRIBUTE = 'update_float_attribute';
    public static string $UPDATE_BOOLEAN_ATTRIBUTE = 'update_boolean_attribute';
    public static string $UPDATE_URL_ATTRIBUTE = 'update_url_attribute';
    public static string $UPDATE_EMAIL_ATTRIBUTE = 'update_email_attribute';
    public static string $UPDATE_IP_ATTRIBUTE = 'update_ip_attribute';
    public static string $UPDATE_ENUM_ATTRIBUTE = 'update_enum_attribute';
    public static string $UPDATE_DATETIME_ATTRIBUTE = 'update_datetime_attribute';

    public static string $UPDATE_RELATIONSHIP_ATTRIBUTE = 'update_relationship_attribute';
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
    public static string $CREATE_USER_TARGET = 'create_user_target';
    public static string $LIST_USER_TARGETS = 'list_user_targets';
    public static string $GET_USER_TARGET = 'get_user_target';
    public static string $UPDATE_USER_TARGET = 'update_user_target';
    public static string $DELETE_USER_TARGET = 'delete_user_target';

    // Teams
    public static string $GET_TEAM = 'get_team';
    public static string $GET_TEAM_PREFERENCES = 'get_team_preferences';
    public static string $GET_TEAMS = 'list_teams';
    public static string $CREATE_TEAM = 'create_team';
    public static string $UPDATE_TEAM_NAME = 'update_team_name';
    public static string $UPDATE_TEAM_PREFERENCES = 'update_team_preferences';

    public static string $DELETE_TEAM = 'delete_team';
    public static string $GET_TEAM_MEMBERSHIP = 'get_team_membership';
    public static string $GET_TEAM_MEMBERSHIPS = 'list_team_memberships';
    public static string $CREATE_TEAM_MEMBERSHIP = 'create_team_membership';
    public static string $UPDATE_TEAM_MEMBERSHIP = 'update_team_membership';
    public static string $UPDATE_TEAM_MEMBERSHIP_STATUS = 'update_membership_status';
    public static string $DELETE_TEAM_MEMBERSHIP = 'delete_team_membership';

    // Functions
    public static string $CREATE_FUNCTION = 'create_function';
    public static string $GET_FUNCTIONS = 'list_functions';
    public static string $GET_FUNCTION = 'get_function';
    public static string $GET_RUNTIMES = 'list_runtimes';
    public static string $UPDATE_FUNCTION = 'update_function';
    public static string $DELETE_FUNCTION = 'delete_function';
    // Variables
    public static string $CREATE_VARIABLE = 'create_variable';
    public static string $GET_VARIABLES = 'list_variables';
    public static string $GET_VARIABLE = 'get_variable';
    public static string $UPDATE_VARIABLE = 'update_variable';
    public static string $DELETE_VARIABLE = 'delete_variable';

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

    // Providers
    public static string $CREATE_MAILGUN_PROVIDER = 'create_mailgun_provider';
    public static string $CREATE_SENDGRID_PROVIDER = 'create_sendgrid_provider';
    public static string $CREATE_SMTP_PROVIDER = 'create_smtp_provider';
    public static string $CREATE_TWILIO_PROVIDER = 'create_twilio_provider';
    public static string $CREATE_TELESIGN_PROVIDER = 'create_telesign_provider';
    public static string $CREATE_TEXTMAGIC_PROVIDER = 'create_textmagic_provider';
    public static string $CREATE_MSG91_PROVIDER = 'create_msg91_provider';
    public static string $CREATE_VONAGE_PROVIDER = 'create_vonage_provider';
    public static string $CREATE_FCM_PROVIDER = 'create_fcm_provider';
    public static string $CREATE_APNS_PROVIDER = 'create_apns_provider';
    public static string $LIST_PROVIDERS = 'list_providers';
    public static string $GET_PROVIDER = 'get_provider';
    public static string $UPDATE_MAILGUN_PROVIDER = 'update_mailgun_provider';
    public static string $UPDATE_SENDGRID_PROVIDER = 'update_sendgrid_provider';
    public static string $UPDATE_SMTP_PROVIDER = 'update_smtp_provider';
    public static string $UPDATE_TWILIO_PROVIDER = 'update_twilio_provider';
    public static string $UPDATE_TELESIGN_PROVIDER = 'update_telesign_provider';
    public static string $UPDATE_TEXTMAGIC_PROVIDER = 'update_textmagic_provider';
    public static string $UPDATE_MSG91_PROVIDER = 'update_msg91_provider';
    public static string $UPDATE_VONAGE_PROVIDER = 'update_vonage_provider';
    public static string $UPDATE_FCM_PROVIDER = 'update_fcm_provider';
    public static string $UPDATE_APNS_PROVIDER = 'update_apns_provider';
    public static string $DELETE_PROVIDER = 'delete_provider';

    // Topics
    public static string $CREATE_TOPIC = 'create_topic';
    public static string $LIST_TOPICS = 'list_topics';
    public static string $GET_TOPIC = 'get_topic';
    public static string $UPDATE_TOPIC = 'update_topic';
    public static string $DELETE_TOPIC = 'delete_topic';

    // Subscriptions
    public static string $CREATE_SUBSCRIBER = 'create_subscriber';
    public static string $LIST_SUBSCRIBERS = 'list_subscribers';
    public static string $GET_SUBSCRIBER = 'get_subscriber';
    public static string $DELETE_SUBSCRIBER = 'delete_subscriber';

    // Messages
    public static string $CREATE_EMAIL = 'create_email';
    public static string $CREATE_SMS = 'create_sms';
    public static string $CREATE_PUSH_NOTIFICATION = 'create_push_notification';
    public static string $LIST_MESSAGES = 'list_messages';
    public static string $GET_MESSAGE = 'get_message';

    public static string $UPDATE_EMAIL = 'update_email';
    public static string $UPDATE_SMS = 'update_sms';
    public static string $UPDATE_PUSH_NOTIFICATION = 'update_push_notification';

    // Complex queries
    public static string $COMPLEX_QUERY = 'complex_query';

    // Fragments
    public static string $FRAGMENT_ATTRIBUTES = '
        fragment attributeProperties on Attributes {
            ... on AttributeString {
                key
                required
                array
                status
                default
                size
            }
            ... on AttributeInteger {
                key
                required
                array
                status
                intDefault: default
                intMin: min
                intMax: max
            }
            ... on AttributeFloat {
                key
                required
                array
                status
                floatDefault: default
                floatMin: min
                floatMax: max
            }
            ... on AttributeBoolean {
                key
                required
                array
                status
                boolDefault:default
            }
            ... on AttributeUrl {
                key
                required
                array
                status
                default
            }
            ... on AttributeEmail {
                key
                required
                array
                status
                default
            }
            ... on AttributeIp {
                key
                required
                array
                status
                default
            }
            ... on AttributeEnum {
                key
                required
                array
                status
                default
                elements
            }
            ... on AttributeDatetime {
                key
                required
                array
                status
                default
            }
        }
    ';

    public static string $FRAGMENT_HASH_OPTIONS = '
        fragment options on HashOptions {
            ... on AlgoArgon2 {
                memoryCost
                timeCost
                threads
            }
            ... on AlgoScrypt {
                costCpu
                costMemory
                costParallel
                length
            }
            ... on AlgoScryptModified {
                salt
                saltSeparator
                signerKey
            }
        }
    ';

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
                    databasesDelete(databaseId: $databaseId) {
                        status
                    }
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
                    databasesDeleteCollection(databaseId: $databaseId, collectionId: $collectionId) {
                        status
                    }
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
            case self::$CREATE_RELATIONSHIP_ATTRIBUTE:
                return 'mutation createRelationshipAttribute($databaseId: String!, $collectionId: String!, $relatedCollectionId: String!, $type: String!, $twoWay: Boolean, $key: String, $twoWayKey: String, $onDelete: String){
                    databasesCreateRelationshipAttribute(databaseId: $databaseId, collectionId: $collectionId, relatedCollectionId: $relatedCollectionId, type: $type, twoWay: $twoWay, key: $key, twoWayKey: $twoWayKey, onDelete: $onDelete) {
                        relatedCollection
                        relationType
                        twoWay
                        key
                        twoWayKey
                        onDelete
                    }
                }';
            case self::$UPDATE_STRING_ATTRIBUTE:
                return 'mutation updateStringAttribute($databaseId: String!, $collectionId: String!, $key: String!, $required: Boolean!, $default: String){
                        databasesUpdateStringAttribute(databaseId: $databaseId, collectionId: $collectionId, key: $key, required: $required, default: $default) {
                            required
                            default
                        }
                    }';
            case self::$UPDATE_INTEGER_ATTRIBUTE:
                return 'mutation updateIntegerAttribute($databaseId: String!, $collectionId: String!, $key: String!, $required: Boolean!, $min: Int!, $max: Int!, $default: Int){
                        databasesUpdateIntegerAttribute(databaseId: $databaseId, collectionId: $collectionId, key: $key, required: $required, min: $min, max: $max, default: $default) {
                            required
                            min
                            max
                            default
                        }
                    }';
            case self::$UPDATE_FLOAT_ATTRIBUTE:
                return 'mutation updateFloatAttribute($databaseId: String!, $collectionId: String!, $key: String!, $required: Boolean!, $min: Float!, $max: Float!, $default: Float){
                        databasesUpdateFloatAttribute(databaseId: $databaseId, collectionId: $collectionId, key: $key, min: $min, max: $max, required: $required, default: $default) {
                            required
                            min
                            max
                            default
                        }
                    }';
            case self::$UPDATE_BOOLEAN_ATTRIBUTE:
                return 'mutation updateBooleanAttribute($databaseId: String!, $collectionId: String!, $key: String!, $required: Boolean!, $default: Boolean){
                        databasesUpdateBooleanAttribute(databaseId: $databaseId, collectionId: $collectionId, key: $key, required: $required, default: $default) {
                            required
                            default
                        }
                    }';
            case self::$UPDATE_URL_ATTRIBUTE:
                return 'mutation updateUrlAttribute($databaseId: String!, $collectionId: String!, $key: String!, $required: Boolean!, $default: String){
                        databasesUpdateUrlAttribute(databaseId: $databaseId, collectionId: $collectionId, key: $key, required: $required, default: $default) {
                            required
                            default
                        }
                    }';
            case self::$UPDATE_EMAIL_ATTRIBUTE:
                return 'mutation updateEmailAttribute($databaseId: String!, $collectionId: String!, $key: String!, $required: Boolean!, $default: String){
                        databasesUpdateEmailAttribute(databaseId: $databaseId, collectionId: $collectionId, key: $key, required: $required, default: $default) {
                            required
                            default
                        }
                    }';
            case self::$UPDATE_IP_ATTRIBUTE:
                return 'mutation updateIpAttribute($databaseId: String!, $collectionId: String!, $key: String!, $required: Boolean!, $default: String){
                        databasesUpdateIpAttribute(databaseId: $databaseId, collectionId: $collectionId, key: $key, required: $required, default: $default) {
                            required
                            default
                        }
                    }';
            case self::$UPDATE_ENUM_ATTRIBUTE:
                return 'mutation updateEnumAttribute($databaseId: String!, $collectionId: String!, $key: String!, $elements: [String!]!, $required: Boolean!, $default: String){
                        databasesUpdateEnumAttribute(databaseId: $databaseId, collectionId: $collectionId, key: $key, elements: $elements, required: $required, default: $default) {
                            elements
                            required
                            default
                        }
                    }';
            case self::$UPDATE_DATETIME_ATTRIBUTE:
                return 'mutation updateDatetimeAttribute($databaseId: String!, $collectionId: String!, $key: String!, $required: Boolean!, $default: String){
                        databasesUpdateDatetimeAttribute(databaseId: $databaseId, collectionId: $collectionId, key: $key, required: $required, default: $default) {
                            required
                            default
                        }
                    }';
            case self::$UPDATE_RELATIONSHIP_ATTRIBUTE:
                return 'mutation updateRelationshipAttribute($databaseId: String!, $collectionId: String!, $key: String!, $onDelete: String){
                        databasesUpdateRelationshipAttribute(databaseId: $databaseId, collectionId: $collectionId, key: $key, onDelete: $onDelete) {
                            relatedCollection
                            relationType
                            twoWay
                            key
                            twoWayKey
                            onDelete
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
                return 'mutation deleteIndex($databaseId: String!, $collectionId: String!, $key: String!) {
                    databasesDeleteIndex(databaseId: $databaseId, collectionId: $collectionId, key: $key) {
                        status
                    }
                }';
            case self::$GET_ATTRIBUTES:
                return 'query listAttributes($databaseId: String!, $collectionId: String!) {
                    databasesListAttributes(databaseId: $databaseId, collectionId: $collectionId) {
                        total
                        attributes {
                            ...attributeProperties
                        }
                    }
                }' . PHP_EOL . self::$FRAGMENT_ATTRIBUTES;
            case self::$GET_ATTRIBUTE:
                return 'query getAttribute($databaseId: String!, $collectionId: String!, $key: String!) {
                    databasesGetAttribute(databaseId: $databaseId, collectionId: $collectionId, key: $key) {
                        ...attributeProperties
                    }
                }' . PHP_EOL . self::$FRAGMENT_ATTRIBUTES;
            case self::$DELETE_ATTRIBUTE:
                return 'mutation deleteAttribute($databaseId: String!, $collectionId: String!, $key: String!) {
                    databasesDeleteAttribute(databaseId: $databaseId, collectionId: $collectionId, key: $key) {
                        status
                    }
                }';
            case self::$GET_DOCUMENT:
                return 'query getDocument($databaseId: String!, $collectionId: String!, $documentId: String!) {
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
                return 'query getCustomEntities {
                    actorsList {
                        _id
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
            case self::$GET_CUSTOM_ENTITY:
                return 'query getCustomEntity($id: String!) {
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
                    databasesDeleteDocument(databaseId: $databaseId, collectionId: $collectionId, documentId: $documentId) {
                        status
                    }
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
                        hash
                        hashOptions {
                            ...options
                        }
                    }
                }' . PHP_EOL . self::$FRAGMENT_HASH_OPTIONS;
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
                return 'query listUsers($queries: [String!], $search: String) {
                    usersList(queries: $queries, search: $search) {
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
                return 'mutation updateUserPrefs($userId: String!, $prefs: Assoc!){
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
                    usersDeleteSessions(userId: $userId) {
                        status
                    }
                }';
            case self::$DELETE_USER_SESSION:
                return 'mutation deleteUserSession($userId: String!, $sessionId: String!){
                    usersDeleteSession(userId: $userId, sessionId: $sessionId) {
                        status
                    }
                }';
            case self::$DELETE_USER:
                return 'mutation deleteUser($userId: String!) {
                    usersDelete(userId: $userId) {
                        status
                    }
                }';
            case self::$CREATE_USER_TARGET:
                return 'mutation createUserTarget($userId: String!, $targetId: String!, $providerType: String!, $identifier: String! $providerId: String){
                    usersCreateTarget(userId: $userId, targetId: $targetId, providerType: $providerType, identifier: $identifier, providerId: $providerId) {
                        _id
                        userId
                        providerType
                        providerId
                        identifier
                    }
                }';
            case self::$LIST_USER_TARGETS:
                return 'query listUserTargets($userId: String!) {
                    usersListTargets(userId: $userId) {
                        total
                        targets {
                            _id
                            userId
                            providerType
                            providerId
                            identifier
                        }
                    }
                }';
            case self::$GET_USER_TARGET:
                return 'query getUserTarget($userId: String!, $targetId: String!) {
                    usersGetTarget(userId: $userId, targetId: $targetId) {
                        _id
                        userId
                        providerType
                        providerId
                        identifier
                    }
                }';
            case self::$UPDATE_USER_TARGET:
                return 'mutation updateUserTarget($userId: String!, $targetId: String!, $providerId: String, $identifier: String){
                    usersUpdateTarget(userId: $userId, targetId: $targetId, providerId: $providerId, identifier: $identifier) {
                        _id
                        userId
                        providerType
                        providerId
                        identifier
                    }
                }';
            case self::$DELETE_USER_TARGET:
                return 'mutation deleteUserTarget($userId: String!, $targetId: String!){
                    usersDeleteTarget(userId: $userId, targetId: $targetId) {
                        status
                    }
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
                    avatarsGetCreditCard(code: $code) {
                        status
                    }
                }';
            case self::$GET_BROWSER_ICON:
                return 'query getBrowserIcon($code: String!) {
                    avatarsGetBrowser(code: $code) {
                        status
                    }
                }';
            case self::$GET_COUNTRY_FLAG:
                return 'query getCountryFlag($code: String!) {
                    avatarsGetFlag(code: $code) {
                        status
                    }
                }';
            case self::$GET_IMAGE_FROM_URL:
                return 'query getImageFromUrl($url: String!) {
                    avatarsGetImage(url: $url) {
                        status
                    }
                }';
            case self::$GET_FAVICON:
                return 'query getFavicon($url: String!) {
                    avatarsGetFavicon(url: $url) {
                        status
                    }
                }';
            case self::$GET_QRCODE:
                return 'query getQrCode($text: String!) {
                    avatarsGetQR(text: $text) {
                        status
                    }
                }';
            case self::$GET_USER_INITIALS:
                return 'query getUserInitials($name: String!) {
                    avatarsGetInitials(name: $name) {
                        status
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
                return 'mutation updateAccountPrefs($prefs: Assoc!){
                    accountUpdatePrefs(prefs: $prefs) {
                        _id
                        name
                        registration
                        status
                        email
                        emailVerification
                        prefs {
                          data
                        }
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
                    accountCreateEmailPasswordSession(email: $email, password: $password) {
                        _id
                        userId
                        expire
                        ip
                        current
                    }
                }';
            case self::$DELETE_ACCOUNT_SESSION:
                return 'mutation deleteAccountSession($sessionId: String!){
                    accountDeleteSession(sessionId: $sessionId) {
                        status
                    }
                }';
            case self::$DELETE_ACCOUNT_SESSIONS:
                return 'mutation deleteAccountSessions {
                    accountDeleteSessions {
                        status
                    }
                }';
            case self::$CREATE_MAGIC_URL:
                return 'mutation createMagicURL($userId: String!, $email: String!){
                    accountCreateMagicURLToken(userId: $userId, email: $email) {
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
                return 'mutation confirmPasswordRecovery($userId: String!, $secret: String!, $password: String!) {
                    accountUpdateRecovery(userId: $userId, secret: $secret, password: $password) {
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
            case self::$GET_TEAM_PREFERENCES:
                return 'query getTeamPreferences($teamId: String!) {
                    teamsGetPrefs(teamId: $teamId) {
                        data
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
            case self::$UPDATE_TEAM_NAME:
                return 'mutation updateTeamName($teamId: String!, $name: String!){
                        teamsUpdateName(teamId: $teamId, name : $name) {
                            _id
                            name
                            total
                        }
                    }';
            case self::$UPDATE_TEAM_PREFERENCES:
                return 'mutation updateTeamPrefs($teamId: String!, $prefs: Assoc!){
                    teamsUpdatePrefs(teamId: $teamId, prefs: $prefs) {
                        data
                    }
                }';
            case self::$DELETE_TEAM:
                return 'mutation deleteTeam($teamId: String!){
                    teamsDelete(teamId: $teamId) {
                        status
                    }
                }';
            case self::$GET_TEAM_MEMBERSHIP:
                return 'query getTeamMembership($teamId: String!, $membershipId: String!){
                    teamsGetMembership(teamId: $teamId, membershipId: $membershipId) {
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
            case self::$UPDATE_TEAM_MEMBERSHIP:
                return 'mutation updateTeamMembership($teamId: String!, $membershipId: String!, $roles: [String!]!){
                    teamsUpdateMembership(teamId: $teamId, membershipId: $membershipId, roles: $roles) {
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
                    teamsDeleteMembership(teamId: $teamId, membershipId: $membershipId) {
                        status
                    }
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
                            buildLogs
                        }
                    }
                }';
            case self::$GET_DEPLOYMENT:
                return 'query getDeployment($functionId: String!, $deploymentId: String!) {
                    functionsGetDeployment(functionId: $functionId, deploymentId: $deploymentId) {
                        _id
                        resourceId
                        buildId
                        buildLogs
                        status
                    }
                }';
            case self::$CREATE_FUNCTION:
                return 'mutation createFunction($functionId: String!, $name: String!, $runtime: String!, $execute: [String!]!, $events: [String], $schedule: String, $timeout: Int, $entrypoint: String!) {
                    functionsCreate(functionId: $functionId, name: $name, execute: $execute, runtime: $runtime, events: $events, schedule: $schedule, timeout: $timeout, entrypoint: $entrypoint) {
                        _id
                        name
                        runtime
                        execute
                    }
                }';
            case self::$UPDATE_FUNCTION:
                return 'mutation updateFunction($functionId: String!, $name: String!, $execute: [String!]!, $runtime: String!, $entrypoint: String!, $events: [String], $schedule: String, $timeout: Int) {
                    functionsUpdate(functionId: $functionId, name: $name, execute: $execute, runtime: $runtime, entrypoint: $entrypoint, events: $events, schedule: $schedule, timeout: $timeout) {
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
                    functionsDelete(functionId: $functionId) {
                        status
                    }
                }';
            case self::$CREATE_VARIABLE:
                return 'mutation createVariable($functionId: String!, $key: String!, $value: String!) {
                    functionsCreateVariable(functionId: $functionId, key: $key, value: $value) {
                        _id
                        key
                        value
                    }
                }';
            case self::$GET_VARIABLES:
                return 'query listVariables($functionId: String!) {
                    functionsListVariables(functionId: $functionId) {
                        total
                        variables {
                            _id
                            key
                            value
                        }
                    }
                }';
            case self::$GET_VARIABLE:
                return 'query getVariable($functionId: String!, $variableId: String!) {
                    functionsGetVariable(functionId: $functionId, variableId: $variableId) {
                        _id
                        key
                        value
                    }
                }';
            case self::$UPDATE_VARIABLE:
                return 'mutation updateVariable($functionId: String!, $variableId: String!, $key: String!, $value: String) {
                    functionsUpdateVariable(functionId: $functionId, variableId: $variableId, key: $key, value: $value) {
                        _id
                        key
                        value
                    }
                }';
            case self::$DELETE_VARIABLE:
                return 'mutation deleteVariable($functionId: String!, $variableId: String!) {
                    functionsDeleteVariable(functionId: $functionId, variableId: $variableId) {
                        status
                    }
                }';
            case self::$CREATE_DEPLOYMENT:
                return 'mutation createDeployment($functionId: String!, $code: InputFile!, $activate: Boolean!) {
                    functionsCreateDeployment(functionId: $functionId, code: $code, activate: $activate) {
                        _id
                        buildId
                        entrypoint
                        buildSize
                        status
                        buildLogs
                    }
                }';
            case self::$DELETE_DEPLOYMENT:
                return 'mutation deleteDeployment($functionId: String!, $deploymentId: String!) {
                    functionsDeleteDeployment(functionId: $functionId, deploymentId: $deploymentId) {
                        status
                    }
                }';
            case self::$GET_EXECUTION:
                return 'query getExecution($functionId: String!$executionId: String!) {
                    functionsGetExecution(functionId: $functionId, executionId: $executionId) {
                        _id
                        functionId
                        status
                        logs
                        errors
                    }
                }';
            case self::$GET_EXECUTIONS:
                return 'query listExecutions($functionId: String!) {
                    functionsListExecutions(functionId: $functionId) {
                        total
                        executions {
                            _id
                            functionId
                            status
                            logs
                            errors
                        }
                    }
                }';
            case self::$CREATE_EXECUTION:
                return 'mutation createExecution($functionId: String!, $body: String, $async: Boolean) {
                    functionsCreateExecution(functionId: $functionId, body: $body, async: $async) {
                        _id
                        functionId
                        status
                        logs
                        errors
                    }
                }';
            case self::$DELETE_EXECUTION:
                return 'mutation deleteExecution($functionId: String!, $executionId: String!) {
                    functionsDeleteExecution(functionId: $functionId, executionId: $executionId) {
                        status
                    }
                }';
            case self::$RETRY_BUILD:
                return 'mutation retryBuild($functionId: String!, $deploymentId: String!, $buildId: String!) {
                    functionsCreateDuplicateDeployment(functionId: $functionId, deploymentId: $deploymentId, buildId: $buildId) {
                        status
                    }
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
                    storageDeleteBucket(bucketId: $bucketId) {
                        status
                    }
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
                    storageGetFilePreview(bucketId: $bucketId, fileId: $fileId) {
                        status
                    }
                }';
            case self::$GET_FILE_DOWNLOAD:
                return 'query getFileDownload($bucketId: String!, $fileId: String!) {
                    storageGetFileDownload(bucketId: $bucketId, fileId: $fileId) {
                        status
                    }
                }';
            case self::$GET_FILE_VIEW:
                return 'query getFileView($bucketId: String!, $fileId: String!) {
                    storageGetFileView(bucketId: $bucketId, fileId: $fileId) {
                        status
                    }
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
                    storageDeleteFile(bucketId: $bucketId, fileId: $fileId) {
                        status
                    }
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
            case self::$CREATE_MAILGUN_PROVIDER:
                return 'mutation createMailgunProvider($providerId: String!, $name: String!, $domain: String!, $apiKey: String!, $fromName: String!, $fromEmail: String!, $isEuRegion: Boolean!, $replyToName: String, $replyToEmail: String) {
                    messagingCreateMailgunProvider(providerId: $providerId, name: $name, domain: $domain, apiKey: $apiKey, fromName: $fromName, fromEmail: $fromEmail, isEuRegion: $isEuRegion, replyToName: $replyToName, replyToEmail: $replyToEmail) {
                        _id
                        name
                        provider
                        type
                        enabled
                    }
                }';
            case self::$CREATE_SENDGRID_PROVIDER:
                return 'mutation createSendgridProvider($providerId: String!, $name: String!, $fromName: String!, $fromEmail: String!, $apiKey: String!, $replyToName: String, $replyToEmail: String) {
                    messagingCreateSendgridProvider(providerId: $providerId, name: $name, fromName: $fromName, fromEmail: $fromEmail, apiKey: $apiKey, replyToName: $replyToName, replyToEmail: $replyToEmail) {
                        _id
                        name
                        provider
                        type
                        enabled
                    }
                }';
            case self::$CREATE_SMTP_PROVIDER:
                return 'mutation createSmtpProvider($providerId: String!, $name: String!, $host: String!, $port: Int!, $username: String!, $password: String!, $encryption: String!, $autoTLS: Boolean! $fromName: String!, $fromEmail: String!, $replyToName: String, $replyToEmail: String) {
                    messagingCreateSmtpProvider(providerId: $providerId, name: $name, host: $host, port: $port, username: $username, password: $password, encryption: $encryption, autoTLS: $autoTLS, fromName: $fromName, fromEmail: $fromEmail, replyToName: $replyToName, replyToEmail: $replyToEmail) {
                        _id
                        name
                        provider
                        type
                        enabled
                    }
                }';
            case self::$CREATE_TWILIO_PROVIDER:
                return 'mutation createTwilioProvider($providerId: String!, $name: String!, $from: String!, $accountSid: String!, $authToken: String!) {
                    messagingCreateTwilioProvider(providerId: $providerId, name: $name, from: $from, accountSid: $accountSid, authToken: $authToken) {
                        _id
                        name
                        provider
                        type
                        enabled
                    }
                }';
            case self::$CREATE_TELESIGN_PROVIDER:
                return 'mutation createTelesignProvider($providerId: String!, $name: String!, $from: String!, $customerId: String!, $apiKey: String!) {
                    messagingCreateTelesignProvider(providerId: $providerId, name: $name, from: $from, customerId: $customerId, apiKey: $apiKey) {
                        _id
                        name
                        provider
                        type
                        enabled
                    }
                }';
            case self::$CREATE_TEXTMAGIC_PROVIDER:
                return 'mutation createTextmagicProvider($providerId: String!, $name: String!, $from: String!, $username: String!, $apiKey: String!) {
                    messagingCreateTextmagicProvider(providerId: $providerId, name: $name, from: $from, username: $username, apiKey: $apiKey) {
                        _id
                        name
                        provider
                        type
                        enabled
                    }
                }';
            case self::$CREATE_MSG91_PROVIDER:
                return 'mutation createMsg91Provider($providerId: String!, $name: String!, $templateId: String!, $senderId: String!, $authKey: String!, $enabled: Boolean) {
                    messagingCreateMsg91Provider(providerId: $providerId, name: $name, templateId: $templateId, senderId: $senderId, authKey: $authKey, enabled: $enabled) {
                        _id
                        name
                        provider
                        type
                        enabled
                    }
                }';
            case self::$CREATE_VONAGE_PROVIDER:
                return 'mutation createVonageProvider($providerId: String!, $name: String!, $from: String!, $apiKey: String!, $apiSecret: String!) {
                    messagingCreateVonageProvider(providerId: $providerId, name: $name, from: $from, apiKey: $apiKey, apiSecret: $apiSecret) {
                        _id
                        name
                        provider
                        type
                        enabled
                    }
                }';
            case self::$CREATE_FCM_PROVIDER:
                return 'mutation createFcmProvider($providerId: String!, $name: String!, $serviceAccountJSON: Json) {
                    messagingCreateFcmProvider(providerId: $providerId, name: $name, serviceAccountJSON: $serviceAccountJSON) {
                        _id
                        name
                        provider
                        type
                        enabled
                    }
                }';
            case self::$CREATE_APNS_PROVIDER:
                return 'mutation createApnsProvider($providerId: String!, $name: String!, $authKey: String!, $authKeyId: String!, $teamId: String!, $bundleId: String!) {
                    messagingCreateApnsProvider(providerId: $providerId, name: $name, authKey: $authKey, authKeyId: $authKeyId, teamId: $teamId, bundleId: $bundleId) {
                        _id
                        name
                        provider
                        type
                        enabled
                    }
                }';
            case self::$LIST_PROVIDERS:
                return 'query listProviders {
                    messagingListProviders {
                        total
                        providers {
                            _id
                            name
                            provider
                            type

                            enabled
                        }
                    }
                }';
            case self::$GET_PROVIDER:
                return 'query getProvider($providerId: String!) {
                    messagingGetProvider(providerId: $providerId) {
                        _id
                        name
                        provider
                        type
                        enabled
                    }
                }';
            case self::$UPDATE_MAILGUN_PROVIDER:
                return 'mutation updateMailgunProvider($providerId: String!, $name: String!, $domain: String!, $apiKey: String!, $isEuRegion: Boolean, $enabled: Boolean, $fromName: String, $fromEmail: String) {
                    messagingUpdateMailgunProvider(providerId: $providerId, name: $name, domain: $domain, apiKey: $apiKey, isEuRegion: $isEuRegion, enabled: $enabled, fromName: $fromName, fromEmail: $fromEmail) {
                        _id
                        name
                        provider
                        type
                        enabled
                    }
                }';
            case self::$UPDATE_SENDGRID_PROVIDER:
                return 'mutation messagingUpdateSendgridProvider($providerId: String!, $name: String!, $apiKey: String!, $enabled: Boolean, $fromName: String, $fromEmail: String) {
                    messagingUpdateSendgridProvider(providerId: $providerId, name: $name, apiKey: $apiKey, enabled: $enabled, fromName: $fromName, fromEmail: $fromEmail) {
                        _id
                        name
                        provider
                        type
                        enabled
                    }
                }';
            case self::$UPDATE_SMTP_PROVIDER:
                return 'mutation updateSmtpProvider($providerId: String!, $name: String!, $host: String!, $port: Int!, $username: String!, $password: String!, $encryption: String!, $autoTLS: Boolean!, $fromName: String, $fromEmail: String, $enabled: Boolean) {
                    messagingUpdateSmtpProvider(providerId: $providerId, name: $name, host: $host, port: $port, username: $username, password: $password, encryption: $encryption, autoTLS: $autoTLS, fromName: $fromName, fromEmail: $fromEmail, enabled: $enabled) {
                        _id
                        name
                        provider
                        type
                        enabled
                    }
                }';
            case self::$UPDATE_TWILIO_PROVIDER:
                return 'mutation updateTwilioProvider($providerId: String!, $name: String!, $accountSid: String!, $authToken: String!) {
                    messagingUpdateTwilioProvider(providerId: $providerId, name: $name, accountSid: $accountSid, authToken: $authToken) {
                        _id
                        name
                        provider
                        type
                        enabled
                    }
                }';
            case self::$UPDATE_TELESIGN_PROVIDER:
                return 'mutation updateTelesignProvider($providerId: String!, $name: String!, $customerId: String!, $apiKey: String!) {
                    messagingUpdateTelesignProvider(providerId: $providerId, name: $name, customerId: $customerId, apiKey: $apiKey) {
                        _id
                        name
                        provider
                        type
                        enabled
                    }
                }';
            case self::$UPDATE_TEXTMAGIC_PROVIDER:
                return 'mutation updateTextmagicProvider($providerId: String!, $name: String!, $username: String!, $apiKey: String!) {
                    messagingUpdateTextmagicProvider(providerId: $providerId, name: $name, username: $username, apiKey: $apiKey) {
                        _id
                        name
                        provider
                        type
                        enabled
                    }
                }';
            case self::$UPDATE_MSG91_PROVIDER:
                return 'mutation updateMsg91Provider($providerId: String!, $name: String!, $templateId: String!, $senderId: String!, $authKey: String!) {
                    messagingUpdateMsg91Provider(providerId: $providerId, name: $name, templateId: $templateId, senderId: $senderId, authKey: $authKey) {
                        _id
                        name
                        provider
                        type
                        enabled
                    }
                }';
            case self::$UPDATE_VONAGE_PROVIDER:
                return 'mutation updateVonageProvider($providerId: String!, $name: String!, $apiKey: String!, $apiSecret: String!) {
                    messagingUpdateVonageProvider(providerId: $providerId, name: $name, apiKey: $apiKey, apiSecret: $apiSecret) {
                        _id
                        name
                        provider
                        type
                        enabled
                    }
                }';
            case self::$UPDATE_FCM_PROVIDER:
                return 'mutation updateFcmProvider($providerId: String!, $name: String!, $serviceAccountJSON: Json) {
                    messagingUpdateFcmProvider(providerId: $providerId, name: $name, serviceAccountJSON: $serviceAccountJSON) {
                        _id
                        name
                        provider
                        type
                        enabled
                    }
                }';
            case self::$UPDATE_APNS_PROVIDER:
                return 'mutation updateApnsProvider($providerId: String!, $name: String!, $authKey: String!, $authKeyId: String!, $teamId: String!, $bundleId: String!) {
                    messagingUpdateApnsProvider(providerId: $providerId, name: $name, authKey: $authKey, authKeyId: $authKeyId, teamId: $teamId, bundleId: $bundleId) {
                        _id
                        name
                        provider
                        type
                        enabled
                    }
                }';
            case self::$DELETE_PROVIDER:
                return 'mutation deleteProvider($providerId: String!) {
                    messagingDeleteProvider(providerId: $providerId) {
                        status
                    }
                }';
            case self::$CREATE_TOPIC:
                return 'mutation createTopic($topicId: String!, $name: String!) {
                    messagingCreateTopic(topicId: $topicId, name: $name) {
                        _id
                        name
                        emailTotal
                        smsTotal
                        pushTotal
                    }
                }';
            case self::$LIST_TOPICS:
                return 'query listTopics {
                    messagingListTopics {
                        total
                        topics {
                            _id
                            name
                            emailTotal
                            smsTotal
                            pushTotal
                        }
                    }
                }';
            case self::$GET_TOPIC:
                return 'query getTopic($topicId: String!) {
                    messagingGetTopic(topicId: $topicId) {
                        _id
                        name
                        emailTotal
                        smsTotal
                        pushTotal
                    }
                }';
            case self::$UPDATE_TOPIC:
                return 'mutation updateTopic($topicId: String!, $name: String!) {
                    messagingUpdateTopic(topicId: $topicId, name: $name) {
                        _id
                        name
                        emailTotal
                        smsTotal
                        pushTotal
                    }
                }';
            case self::$DELETE_TOPIC:
                return 'mutation deleteTopic($topicId: String!) {
                    messagingDeleteTopic(topicId: $topicId) {
                        status
                    }
                }';
            case self::$CREATE_SUBSCRIBER:
                return 'mutation createSubscriber($subscriberId: String!, $targetId: String!, $topicId: String!) {
                    messagingCreateSubscriber(subscriberId: $subscriberId, targetId: $targetId, topicId: $topicId) {
                        _id
                        targetId
                        topicId
                        userName
                        target {
                            _id
                            userId
                            name
                            providerType
                            identifier
                        }
                    }
                }';
            case self::$LIST_SUBSCRIBERS:
                return 'query listSubscribers($topicId: String!) {
                    messagingListSubscribers(topicId: $topicId) {
                        total
                        subscribers {
                            _id
                            targetId
                            topicId
                            userName
                            target {
                                _id
                                userId
                                name
                                providerType
                                identifier
                            }
                        }
                    }
                }';
            case self::$GET_SUBSCRIBER:
                return 'query getSubscriber($topicId: String!, $subscriberId: String!) {
                    messagingGetSubscriber(topicId: $topicId, subscriberId: $subscriberId) {
                        _id
                        targetId
                        topicId
                        userName
                        target {
                            _id
                            userId
                            name
                            providerType
                            identifier
                        }
                    }
                }';
            case self::$DELETE_SUBSCRIBER:
                return 'mutation deleteSubscriber($topicId: String!, $subscriberId: String!) {
                    messagingDeleteSubscriber(topicId: $topicId, subscriberId: $subscriberId) {
                        status
                    }
            }';
            case self::$CREATE_EMAIL:
                return 'mutation createEmail($messageId: String!, $topics: [String!], $users: [String!], $targets: [String!], $subject: String!, $content: String!, $status: String, $html: Boolean, $cc: [String], $bcc: [String], $scheduledAt: String) {
                    messagingCreateEmail(messageId: $messageId, topics: $topics, users: $users, targets: $targets, subject: $subject, content: $content, status: $status, html: $html, cc: $cc, bcc: $bcc, scheduledAt: $scheduledAt) {
                        _id
                        topics
                        users
                        targets
                        scheduledAt
                        deliveredAt
                        deliveryErrors
                        deliveredTotal
                        status
                    }
                }';
            case self::$CREATE_SMS:
                return 'mutation createSMS($messageId: String!, $topics: [String!], $users: [String!], $targets: [String!], $content: String!, $status: String, $scheduledAt: String) {
                    messagingCreateSMS(messageId: $messageId, topics: $topics, users: $users, targets: $targets, content: $content, status: $status, scheduledAt: $scheduledAt) {
                        _id
                        topics
                        users
                        targets
                        scheduledAt
                        deliveredAt
                        deliveryErrors
                        deliveredTotal
                        status
                    }
                }';
            case self::$CREATE_PUSH_NOTIFICATION:
                return 'mutation createPushNotification($messageId: String!, $topics: [String!], $users: [String!], $targets: [String!], $title: String!, $body: String!, $data: Json, $action: String, $icon: String, $sound: String, $color: String, $tag: String, $badge: String, $status: String, $scheduledAt: String) {
                    messagingCreatePushNotification(messageId: $messageId, topics: $topics, users: $users, targets: $targets, title: $title, body: $body, data: $data, action: $action, icon: $icon, sound: $sound, color: $color, tag: $tag, badge: $badge, status: $status, scheduledAt: $scheduledAt) {
                        _id
                        topics
                        users
                        targets
                        scheduledAt
                        deliveredAt
                        deliveryErrors
                        deliveredTotal
                        status
                    }
                }';
            case self::$LIST_MESSAGES:
                return 'query listMessages {
                    messagingListMessages {
                        total
                        messages {
                            _id
                            providerType
                            topics
                            users
                            targets
                            scheduledAt
                            deliveredAt
                            deliveryErrors
                            deliveredTotal
                            status
                        }
                    }
                }';
            case self::$GET_MESSAGE:
                return 'query getMessage($messageId: String!) {
                    messagingGetMessage(messageId: $messageId) {
                        _id
                        providerType
                        topics
                        users
                        targets
                        scheduledAt
                        deliveredAt
                        deliveryErrors
                        deliveredTotal
                        status
                    }
                }';
            case self::$UPDATE_EMAIL:
                return 'mutation updateEmail($messageId: String!, $topics: [String!], $users: [String!], $targets: [String!], $subject: String, $content: String, $status: String, , $html: Boolean, $cc: [String], $bcc: [String], $scheduledAt: String) {
                    messagingUpdateEmail(messageId: $messageId, topics: $topics, users: $users, targets: $targets, subject: $subject, content: $content, status: $status, html: $html, cc: $cc, bcc: $bcc, scheduledAt: $scheduledAt) {
                        _id
                        topics
                        users
                        targets
                        scheduledAt
                        deliveredAt
                        deliveryErrors
                        deliveredTotal
                        status
                    }
                }';
            case self::$UPDATE_SMS:
                return 'mutation updateSMS($messageId: String!, $topics: [String!], $users: [String!], $targets: [String!], $content: String, $status: String, $scheduledAt: String) {
                    messagingUpdateSMS(messageId: $messageId, topics: $topics, users: $users, targets: $targets, content: $content, status: $status, scheduledAt: $scheduledAt) {
                        _id
                        topics
                        users
                        targets
                        scheduledAt
                        deliveredAt
                        deliveryErrors
                        deliveredTotal
                        status
                    }
                }';
            case self::$UPDATE_PUSH_NOTIFICATION:
                return 'mutation updatePushNotification($messageId: String!, $topics: [String!], $users: [String!], $targets: [String!], $title: String, $body: String, $data: Json, $action: String, $icon: String, $sound: String, $color: String, $tag: String, $badge: String, $status: String, $scheduledAt: String) {
                    messagingUpdatePushNotification(messageId: $messageId, topics: $topics, users: $users, targets: $targets, title: $title, body: $body, data: $data, action: $action, icon: $icon, sound: $sound, color: $color, tag: $tag, badge: $badge, status: $status, scheduledAt: $scheduledAt) {
                        _id
                        topics
                        users
                        targets
                        scheduledAt
                        deliveredAt
                        deliveryErrors
                        deliveredTotal
                        status
                    }
                }';
            case self::$COMPLEX_QUERY:
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
                        _databaseId
                        name
                        documentSecurity
                        attributes {
                            ...attributeProperties
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
                    user1: usersCreate(userId: "unique()", email: "test1@appwrite.io", password: "password", name: "Tester 1") {
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
                    user2: usersCreate(userId: "unique()", email: "test2@appwrite.io", password: "password", name: "Tester 2") {
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
                    user3: usersCreate(userId: "unique()", email: "test3@appwrite.io", password: "password", name: "Tester 3") {
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
                    user4: usersCreate(userId: "unique()", email: "test4@appwrite.io", password: "password", name: "Tester 4") {
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
                    user5: usersCreate(userId: "unique()", email: "test5@appwrite.io", password: "password", name: "Tester 5") {
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
                    user6: usersCreate(userId: "unique()", email: "test6@appwrite.io", password: "password", name: "Tester 6") {
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
                    user7: usersCreate(userId: "unique()", email: "test7@appwrite.io", password: "password", name: "Tester 7") {
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
                    user8: usersCreate(userId: "unique()", email: "test8@appwrite.io", password: "password", name: "Tester 8") {
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
                    user9: usersCreate(userId: "unique()", email: "test9@appwrite.io", password: "password", name: "Tester 9") {
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
                    user10: usersCreate(userId: "unique()", email: "test10@appwrite.io", password: "password", name: "Tester 10") {
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
                    user11: usersCreate(userId: "unique()", email: "test11@appwrite.io", password: "password", name: "Tester 11") {
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
                    user12: usersCreate(userId: "unique()", email: "test12@appwrite.io", password: "password", name: "Tester 5") {
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
                }' . PHP_EOL . self::$FRAGMENT_ATTRIBUTES;
        }

        throw new \InvalidArgumentException('Invalid query type');
    }

    // Function-related methods
    protected string $stdout = '';
    protected string $stderr = '';

    protected function packageFunction(string $function): CURLFile
    {
        $folderPath = realpath(__DIR__ . '/../../../resources/functions') . "/$function";
        $tarPath = "$folderPath/code.tar.gz";

        Console::execute("cd $folderPath && tar --exclude code.tar.gz -czf code.tar.gz .", '', $this->stdout, $this->stderr);

        if (filesize($tarPath) > 1024 * 1024 * 5) {
            throw new \Exception('Code package is too large. Use the chunked upload method instead.');
        }

        return new CURLFile($tarPath, 'application/x-gzip', \basename($tarPath));
    }
}
