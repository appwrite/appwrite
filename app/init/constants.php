<?php

const APP_NAME = 'Appwrite';
const APP_DOMAIN = 'appwrite.io';
const APP_EMAIL_TEAM = 'team@localhost.test'; // Default email address
const APP_EMAIL_SECURITY = ''; // Default security email address
const APP_USERAGENT = APP_NAME . '-Server v%s. Please report abuse at %s';
const APP_MODE_DEFAULT = 'default';
const APP_MODE_ADMIN = 'admin';
const APP_PAGING_LIMIT = 12;
const APP_LIMIT_COUNT = 5000;
const APP_LIMIT_USERS = 10_000;
const APP_LIMIT_USER_PASSWORD_HISTORY = 20;
const APP_LIMIT_USER_SESSIONS_MAX = 100;
const APP_LIMIT_USER_SESSIONS_DEFAULT = 10;
const APP_LIMIT_ANTIVIRUS = 20_000_000; //20MB
const APP_LIMIT_ENCRYPTION = 20_000_000; //20MB
const APP_LIMIT_COMPRESSION = 20_000_000; //20MB
const APP_LIMIT_ARRAY_PARAMS_SIZE = 100; // Default maximum of how many elements can there be in API parameter that expects array value
const APP_LIMIT_ARRAY_LABELS_SIZE = 1000; // Default maximum of how many labels elements can there be in API parameter that expects array value
const APP_LIMIT_ARRAY_ELEMENT_SIZE = 4096; // Default maximum length of element in array parameter represented by maximum URL length.
const APP_LIMIT_SUBQUERY = 1000;
const APP_LIMIT_SUBSCRIBERS_SUBQUERY = 1_000_000;
const APP_LIMIT_WRITE_RATE_DEFAULT = 60; // Default maximum write rate per rate period
const APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT = 60; // Default maximum write rate period in seconds
const APP_LIMIT_LIST_DEFAULT = 25; // Default maximum number of items to return in list API calls
const APP_KEY_ACCCESS = 24 * 60 * 60; // 24 hours
const APP_USER_ACCCESS = 24 * 60 * 60; // 24 hours
const APP_CACHE_UPDATE = 24 * 60 * 60; // 24 hours
const APP_CACHE_BUSTER = 331;
const APP_VERSION_STABLE = '1.5.0';
const APP_DATABASE_ATTRIBUTE_EMAIL = 'email';
const APP_DATABASE_ATTRIBUTE_ENUM = 'enum';
const APP_DATABASE_ATTRIBUTE_IP = 'ip';
const APP_DATABASE_ATTRIBUTE_DATETIME = 'datetime';
const APP_DATABASE_ATTRIBUTE_URL = 'url';
const APP_DATABASE_ATTRIBUTE_INT_RANGE = 'intRange';
const APP_DATABASE_ATTRIBUTE_FLOAT_RANGE = 'floatRange';
const APP_DATABASE_ATTRIBUTE_STRING_MAX_LENGTH = 1_073_741_824; // 2^32 bits / 4 bits per char
const APP_DATABASE_TIMEOUT_MILLISECONDS = 15_000;
const APP_STORAGE_UPLOADS = '/storage/uploads';
const APP_STORAGE_FUNCTIONS = '/storage/functions';
const APP_STORAGE_BUILDS = '/storage/builds';
const APP_STORAGE_CACHE = '/storage/cache';
const APP_STORAGE_CERTIFICATES = '/storage/certificates';
const APP_STORAGE_CONFIG = '/storage/config';
const APP_STORAGE_READ_BUFFER = 20 * (1000 * 1000); //20MB other names `APP_STORAGE_MEMORY_LIMIT`, `APP_STORAGE_MEMORY_BUFFER`, `APP_STORAGE_READ_LIMIT`, `APP_STORAGE_BUFFER_LIMIT`
const APP_SOCIAL_TWITTER = 'https://twitter.com/appwrite';
const APP_SOCIAL_TWITTER_HANDLE = 'appwrite';
const APP_SOCIAL_FACEBOOK = 'https://www.facebook.com/appwrite.io';
const APP_SOCIAL_LINKEDIN = 'https://www.linkedin.com/company/appwrite';
const APP_SOCIAL_INSTAGRAM = 'https://www.instagram.com/appwrite.io';
const APP_SOCIAL_GITHUB = 'https://github.com/appwrite';
const APP_SOCIAL_DISCORD = 'https://appwrite.io/discord';
const APP_SOCIAL_DISCORD_CHANNEL = '564160730845151244';
const APP_SOCIAL_DEV = 'https://dev.to/appwrite';
const APP_SOCIAL_STACKSHARE = 'https://stackshare.io/appwrite';
const APP_SOCIAL_YOUTUBE = 'https://www.youtube.com/c/appwrite?sub_confirmation=1';
const APP_HOSTNAME_INTERNAL = 'appwrite';

// Database Reconnect
const DATABASE_RECONNECT_SLEEP = 2;
const DATABASE_RECONNECT_MAX_ATTEMPTS = 10;

// Database Worker Types
const DATABASE_TYPE_CREATE_ATTRIBUTE = 'createAttribute';
const DATABASE_TYPE_CREATE_INDEX = 'createIndex';
const DATABASE_TYPE_DELETE_ATTRIBUTE = 'deleteAttribute';
const DATABASE_TYPE_DELETE_INDEX = 'deleteIndex';
const DATABASE_TYPE_DELETE_COLLECTION = 'deleteCollection';
const DATABASE_TYPE_DELETE_DATABASE = 'deleteDatabase';

// Build Worker Types
const BUILD_TYPE_DEPLOYMENT = 'deployment';
const BUILD_TYPE_RETRY = 'retry';

// Deletion Types
const DELETE_TYPE_DATABASES = 'databases';
const DELETE_TYPE_DOCUMENT = 'document';
const DELETE_TYPE_COLLECTIONS = 'collections';
const DELETE_TYPE_PROJECTS = 'projects';
const DELETE_TYPE_FUNCTIONS = 'functions';
const DELETE_TYPE_DEPLOYMENTS = 'deployments';
const DELETE_TYPE_USERS = 'users';
const DELETE_TYPE_TEAMS = 'teams';
const DELETE_TYPE_EXECUTIONS = 'executions';
const DELETE_TYPE_AUDIT = 'audit';
const DELETE_TYPE_ABUSE = 'abuse';
const DELETE_TYPE_USAGE = 'usage';
const DELETE_TYPE_REALTIME = 'realtime';
const DELETE_TYPE_BUCKETS = 'buckets';
const DELETE_TYPE_INSTALLATIONS = 'installations';
const DELETE_TYPE_RULES = 'rules';
const DELETE_TYPE_SESSIONS = 'sessions';
const DELETE_TYPE_CACHE_BY_TIMESTAMP = 'cacheByTimeStamp';
const DELETE_TYPE_CACHE_BY_RESOURCE  = 'cacheByResource';
const DELETE_TYPE_SCHEDULES = 'schedules';
const DELETE_TYPE_TOPIC = 'topic';
const DELETE_TYPE_TARGET = 'target';
const DELETE_TYPE_EXPIRED_TARGETS = 'invalid_targets';
const DELETE_TYPE_SESSION_TARGETS = 'session_targets';

// Message types
const MESSAGE_SEND_TYPE_INTERNAL = 'internal';
const MESSAGE_SEND_TYPE_EXTERNAL = 'external';
// Mail Types
const MAIL_TYPE_VERIFICATION = 'verification';
const MAIL_TYPE_MAGIC_SESSION = 'magicSession';
const MAIL_TYPE_RECOVERY = 'recovery';
const MAIL_TYPE_INVITATION = 'invitation';
const MAIL_TYPE_CERTIFICATE = 'certificate';
// Auth Types
const APP_AUTH_TYPE_SESSION = 'Session';
const APP_AUTH_TYPE_JWT = 'JWT';
const APP_AUTH_TYPE_KEY = 'Key';
const APP_AUTH_TYPE_ADMIN = 'Admin';
// Response related
const MAX_OUTPUT_CHUNK_SIZE = 2 * 1024 * 1024; // 2MB
// Function headers
const FUNCTION_ALLOWLIST_HEADERS_REQUEST = ['content-type', 'agent', 'content-length', 'host'];
const FUNCTION_ALLOWLIST_HEADERS_RESPONSE = ['content-type', 'content-length'];
// Message types
const MESSAGE_TYPE_EMAIL = 'email';
const MESSAGE_TYPE_SMS = 'sms';
const MESSAGE_TYPE_PUSH = 'push';
// Usage metrics
const METRIC_TEAMS = 'teams';
const METRIC_USERS = 'users';
const METRIC_MESSAGES  = 'messages';
const METRIC_SESSIONS  = 'sessions';
const METRIC_DATABASES = 'databases';
const METRIC_COLLECTIONS = 'collections';
const METRIC_DATABASE_ID_COLLECTIONS = '{databaseInternalId}.collections';
const METRIC_DOCUMENTS = 'documents';
const METRIC_DATABASE_ID_DOCUMENTS = '{databaseInternalId}.documents';
const METRIC_DATABASE_ID_COLLECTION_ID_DOCUMENTS = '{databaseInternalId}.{collectionInternalId}.documents';
const METRIC_BUCKETS = 'buckets';
const METRIC_FILES  = 'files';
const METRIC_FILES_STORAGE  = 'files.storage';
const METRIC_BUCKET_ID_FILES = '{bucketInternalId}.files';
const METRIC_BUCKET_ID_FILES_STORAGE  = '{bucketInternalId}.files.storage';
const METRIC_FUNCTIONS  = 'functions';
const METRIC_DEPLOYMENTS  = 'deployments';
const METRIC_DEPLOYMENTS_STORAGE  = 'deployments.storage';
const METRIC_BUILDS  = 'builds';
const METRIC_BUILDS_STORAGE  = 'builds.storage';
const METRIC_BUILDS_COMPUTE  = 'builds.compute';
const METRIC_FUNCTION_ID_BUILDS  = '{functionInternalId}.builds';
const METRIC_FUNCTION_ID_BUILDS_STORAGE = '{functionInternalId}.builds.storage';
const METRIC_FUNCTION_ID_BUILDS_COMPUTE  = '{functionInternalId}.builds.compute';
const METRIC_FUNCTION_ID_DEPLOYMENTS  = '{resourceType}.{resourceInternalId}.deployments';
const METRIC_FUNCTION_ID_DEPLOYMENTS_STORAGE  = '{resourceType}.{resourceInternalId}.deployments.storage';
const METRIC_EXECUTIONS  = 'executions';
const METRIC_EXECUTIONS_COMPUTE  = 'executions.compute';
const METRIC_FUNCTION_ID_EXECUTIONS  = '{functionInternalId}.executions';
const METRIC_FUNCTION_ID_EXECUTIONS_COMPUTE  = '{functionInternalId}.executions.compute';
const METRIC_NETWORK_REQUESTS  = 'network.requests';
const METRIC_NETWORK_INBOUND  = 'network.inbound';
const METRIC_NETWORK_OUTBOUND  = 'network.outbound';