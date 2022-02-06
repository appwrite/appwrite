<?php

/**
 * List of server wide error codes and their respective messages. 
 */

use Appwrite\Extend\Exception;
use PhpParser\Node\Expr;

return [
    /** General Errors */
    Exception::UNKNOWN_ORIGIN => [
        'name' => Exception::UNKNOWN_ORIGIN,
        'description' => 'The request originated from a non-whitelisted origin. If you trust this origin, please add it as a platform in the Appwrite console.',
        'statusCode' => 403,
    ],
    Exception::SERVICE_DISABLED => [
        'name' => Exception::SERVICE_DISABLED,
        'description' => 'The requested service is disabled. You can enable/disable a service from the Appwrite console or by contacting the project owner.',
        'statusCode' => 503,
    ],
    Exception::INVALID_READ_PERMISSIONS => [
        'name' => Exception::INVALID_READ_PERMISSIONS,
        'description' => 'Invalid format for read permissions. Please check the documentation.',
        'statusCode' => 400,
    ],
    Exception::INVALID_WRITE_PERMISSIONS => [
        'name' => Exception::INVALID_WRITE_PERMISSIONS,
        'description' => 'Invalid format for write permissions. Please check the documentation.',
        'statusCode' => 400,
    ],
    Exception::UNAUTHORIZED_SCOPE => [
        'name' => Exception::UNAUTHORIZED_SCOPE,
        'description' => 'The current user or API key does not have the required scopes to access the requested resource.',
        'statusCode' => 401,
    ],
    Exception::STORAGE_ERROR => [
        'name' => Exception::STORAGE_ERROR,
        'description' => 'Storage error',
        'statusCode' => 500,
    ],
    Exception::RATE_LIMIT_EXCEEDED => [
        'name' => Exception::RATE_LIMIT_EXCEEDED,
        'description' => 'Rate limit for the current endpoint has been exceeded. Please try again after some time.',
        'statusCode' => 429,
    ],
    Exception::SMTP_DISABLED => [
        'name' => Exception::SMTP_DISABLED,
        'description' => 'SMTP is disabled on your Appwrite instance. Please contact your project ',
        'statusCode' => 503,
    ],
    Exception::DATABASE_NOT_AVAILABLE => [
        'name' => Exception::DATABASE_NOT_AVAILABLE,
        'description' => 'Database is not available. Please contact your project owner.',
        'statusCode' => 503,
    ],
    Exception::CACHE_NOT_AVAILABLE => [
        'name' => Exception::CACHE_NOT_AVAILABLE,
        'description' => 'Cache is not available. Please contact your project owner.',
        'statusCode' => 503,
    ],
    Exception::TIME_GAPS_DETECTED => [
        'name' => Exception::TIME_GAPS_DETECTED,
        'description' => 'Time gaps detected. Please contact your project owner.',
        'statusCode' => 503,
    ],
    Exception::STORAGE_NOT_READABLE => [
        'name' => Exception::STORAGE_NOT_READABLE,
        'description' => 'Storage is not readable. Please contact your project owner.',
        'statusCode' => 503,
    ],
    Exception::STORAGE_NOT_WRITABLE => [
        'name' => Exception::STORAGE_NOT_WRITABLE,
        'description' => 'Storage is not writable. Please contact your project owner.',
        'statusCode' => 503,
    ],
    Exception::ANTIVIRUS_NOT_AVAILABLE => [
        'name' => Exception::ANTIVIRUS_NOT_AVAILABLE,
        'description' => 'Antivirus is not available. Please contact your project owner.',
        'statusCode' => 503,
    ],

    /** Project Errors */
    Exception::PROJECT_NOT_FOUND => [
        'name' => Exception::PROJECT_NOT_FOUND,
        'description' => 'The requested project could not be found. Please check the value of the X-Appwrite-Project header to ensure the correct project ID is being used.',
        'statusCode' => 404,
    ],
    Exception::PROJECT_UNKNOWN => [
        'name' => Exception::PROJECT_UNKNOWN,
        'description' => 'The project ID is either missing or not valid. Please check the value of the X-Appwrite-Project header to ensure the correct project ID is being used.',
        'statusCode' => 400,
    ],
    Exception::WEBHOOK_NOT_FOUND => [
        'name' => Exception::WEBHOOK_NOT_FOUND,
        'description' => 'The requested webhook could not be found.',
        'statusCode' => 404,
    ],
    Exception::KEY_NOT_FOUND => [
        'name' => Exception::KEY_NOT_FOUND,
        'description' => 'The requested key could not be found.',
        'statusCode' => 404,
    ],
    Exception::PLATFORM_NOT_FOUND => [
        'name' => Exception::PLATFORM_NOT_FOUND,
        'description' => 'The requested platform could not be found.',
        'statusCode' => 404,
    ],
    Exception::DOMAIN_NOT_FOUND => [
        'name' => Exception::DOMAIN_NOT_FOUND,
        'description' => 'The requested domain could not be found.',
        'statusCode' => 404,
    ],
    Exception::DOMAIN_ALREADY_EXISTS => [
        'name' => Exception::DOMAIN_ALREADY_EXISTS,
        'description' => 'The requested domain already exists.',
        'statusCode' => 409,
    ],
    Exception::DOMAIN_UNREACHABLE => [
        'name' => Exception::DOMAIN_UNREACHABLE,
        'description' => 'The requested domain is not reachable.',
        'statusCode' => 503,
    ],
    Exception::DOMAIN_VERIFICATION_FAILED => [
        'name' => Exception::DOMAIN_VERIFICATION_FAILED,
        'description' => 'The requested domain verification failed.',
        'statusCode' => 503,
    ],

    /** User Errors */
    Exception::USER_COUNT_EXCEEDED => [
        'name' => Exception::USER_COUNT_EXCEEDED,
        'description' => 'The current project has exceeded the maximum number of users. Please check your user limit in the Appwrite console.',
        'statusCode' => 501,
    ],
    Exception::USER_EMAIL_NOT_WHITELISTED => [
        'name' => Exception::USER_EMAIL_NOT_WHITELISTED,
        'description' => 'The user\'s email is not part of the whitelist. Please check the _APP_CONSOLE_WHITELIST_EMAILS environment variable of your Appwrite server.',
        'statusCode' => 401,
    ],
    Exception::USER_PASSWORD_RESET_REQUIRED => [
        'name' => Exception::USER_PASSWORD_RESET_REQUIRED,
        'description' => 'The current user requires a password reset.',
        'statusCode' => 412,
    ],
    Exception::USER_IP_NOT_WHITELISTED => [
        'name' => Exception::USER_IP_NOT_WHITELISTED,
        'description' => 'The user\'s IP address is not part of the whitelist. Please check the _APP_CONSOLE_WHITELIST_IPS environment variable of your Appwrite server.',
        'statusCode' => 401,
    ],
    Exception::USER_INVALID_CREDENTIALS => [
        'name' => Exception::USER_INVALID_CREDENTIALS,
        'description' => 'Invalid credentials. Please check the email and password.',
        'statusCode' => 401,
    ],
    Exception::USER_ALREADY_EXISTS => [
        'name' => Exception::USER_ALREADY_EXISTS,
        'description' => 'A user with the same email ID already exists in your project.',
        'statusCode' => 409,
    ],
    Exception::USER_INVALID_TOKEN => [
        'name' => Exception::USER_INVALID_TOKEN,
        'description' => 'Invalid token.',
        'statusCode' => 401,
    ],
    Exception::USER_BLOCKED => [
        'name' => Exception::USER_BLOCKED,
        'description' => 'The current user has been blocked. Please contact the project administrator for more information.',
        'statusCode' => 401,
    ],
    Exception::USER_ANONYMOUS_CONSOLE_PROHIBITED => [
        'name' => Exception::USER_ANONYMOUS_CONSOLE_PROHIBITED,
        'description' => 'Anonymous users cannot be created for console project.',
        'statusCode' => 401,
    ],
    Exception::USER_SESSION_ALREADY_EXISTS => [
        'name' => Exception::USER_SESSION_ALREADY_EXISTS,
        'description' => 'Cannot create anonymous user when a session is active.',
        'statusCode' => 401,
    ],
    Exception::USER_NOT_FOUND => [
        'name' => Exception::USER_NOT_FOUND,
        'description' => 'User with the requested ID could not be found.',
        'statusCode' => 404,
    ],
    Exception::USER_EMAIL_ALREADY_EXISTS => [
        'name' => Exception::USER_EMAIL_ALREADY_EXISTS,
        'description' => 'Another user with the same email already exists in the current project.',
        'statusCode' => 409,
    ],
    Exception::USER_PASSWORD_MISMATCH => [
        'name' => Exception::USER_PASSWORD_MISMATCH,
        'description' => 'Passwords do not match. Please recheck.',
        'statusCode' => 400,
    ],
    Exception::USER_SESSION_NOT_FOUND => [
        'name' => Exception::USER_SESSION_NOT_FOUND,
        'description' => 'The current user session could not be found.',
        'statusCode' => 404,
    ],
    Exception::USER_UNAUTHORIZED => [
        'name' => Exception::USER_UNAUTHORIZED,
        'description' => 'The current user is not authorized to perform the requested action.',
        'statusCode' => 401,
    ],
    Exception::USER_AUTH_METHOD_UNSUPPORTED => [
        'name' => Exception::USER_AUTH_METHOD_UNSUPPORTED,
        'description' => 'The requested authentication method is either disabled or unsupported.',
        'statusCode' => 501,
    ],

    /** OAuth Errors */
    Exception::OAUTH_PROVIDER_DISABLED => [
        'name' => Exception::OAUTH_PROVIDER_DISABLED,
        'description' => 'The chosen OAuth provider is disabled. Please contact your project administrator for more information.',
        'statusCode' => 412,
    ],
    Exception::OAUTH_PROVIDER_UNSUPPORTED => [
        'name' => Exception::OAUTH_PROVIDER_UNSUPPORTED,
        'description' => 'The chosen OAuth provider is unsupported.',
        'statusCode' => 501,
    ],
    Exception::OAUTH_INVALID_SUCCESS_URL => [
        'name' => Exception::OAUTH_INVALID_SUCCESS_URL,
        'description' => 'Invalid URL received for OAuth success redirect.',
        'statusCode' => 400,
    ],
    Exception::OAUTH_INVALID_FAILURE_URL => [
        'name' => Exception::OAUTH_INVALID_FAILURE_URL,
        'description' => 'Invalid URL received for OAuth failure redirect.',
        'statusCode' => 400,
    ],
    Exception::OAUTH_MISSING_USER_ID => [
        'name' => Exception::OAUTH_MISSING_USER_ID,
        'description' => 'Failed to obtain user id from the OAuth provider.',
        'statusCode' => 400,
    ],

    /** Teams */
    Exception::TEAM_NOT_FOUND => [
        'name' => Exception::TEAM_NOT_FOUND,
        'description' => 'Team with the requested ID could not be found.',
        'statusCode' => 404,
    ],
    Exception::TEAM_DELETION_FAILED => [
        'name' => Exception::TEAM_DELETION_FAILED,
        'description' => 'Failed to delete team from the database.',
        'statusCode' => 500,
    ],
    Exception::TEAM_INVITE_ALREADY_EXISTS => [
        'name' => Exception::TEAM_INVITE_ALREADY_EXISTS,
        'description' => 'The current user already has an invitation to this team.',
        'statusCode' => 409,
    ],
    Exception::TEAM_INVITE_NOT_FOUND => [
        'name' => Exception::TEAM_INVITE_NOT_FOUND,
        'description' => 'The requested invitation could not be found.',
        'statusCode' => 409,
    ],
    Exception::TEAM_INVALID_SECRET => [
        'name' => Exception::TEAM_INVALID_SECRET,
        'description' => 'The team invitation secret is invalid.',
        'statusCode' => 401,
    ],
    Exception::TEAM_MEMBERSHIP_MISMATCH => [
        'name' => Exception::TEAM_MEMBERSHIP_MISMATCH,
        'description' => 'The membership ID does not belong to the team ID.',
        'statusCode' => 404,
    ],
    Exception::TEAM_INVITE_MISMATCH => [
        'name' => Exception::TEAM_INVITE_MISMATCH,
        'description' => 'The invite does not belong to the current user.',
        'statusCode' => 401,
    ],


    /** Membership */
    Exception::MEMBERSHIP_NOT_FOUND => [
        'name' => Exception::MEMBERSHIP_NOT_FOUND,
        'description' => 'Membership with the requested ID could not be found.',
        'statusCode' => 404,
    ],
    Exception::MEMBERSHIP_DELETION_FAILED => [
        'name' => Exception::MEMBERSHIP_DELETION_FAILED,
        'description' => 'Failed to delete membership from the database.',
        'statusCode' => 500,
    ],

    /** Avatars */
    Exception::AVATAR_SET_NOT_FOUND => [
        'name' => Exception::AVATAR_SET_NOT_FOUND,
        'description' => 'The requested avatar set could not be found.',
        'statusCode' => 404
    ],
    Exception::AVATAR_NOT_FOUND => [
        'name' => Exception::AVATAR_NOT_FOUND,
        'description' => 'The request avatar could not be found.',
        'statusCode' => 404,
    ],
    Exception::AVATAR_IMAGE_NOT_FOUND => [
        'name' => Exception::AVATAR_IMAGE_NOT_FOUND,
        'description' => 'The requested image was not found.',
        'statusCode' => 404,
    ],
    Exception::AVATAR_REMOTE_URL_FAILED => [
        'name' => Exception::AVATAR_REMOTE_URL_FAILED,
        'description' => 'The remote URL could not be fetched.',
        'statusCode' => 404,
    ],
    Exception::AVATAR_ICON_NOT_FOUND => [
        'name' => Exception::AVATAR_ICON_NOT_FOUND,
        'description' => 'The requested favicon could not be found.',
        'statusCode' => 404,
    ],

    /** Storage */
    Exception::STORAGE_FILE_NOT_FOUND => [
        'name' => Exception::STORAGE_FILE_NOT_FOUND,
        'description' => 'The requested file could not be found.',
        'statusCode' => 404,
    ],
    Exception::STORAGE_DEVICE_NOT_FOUND => [
        'name' => Exception::STORAGE_DEVICE_NOT_FOUND,
        'description' => 'The requested storage device could not be found.',
        'statusCode' => 400,
    ],
    Exception::STORAGE_FILE_DELETION_FAILED => [
        'name' => Exception::STORAGE_FILE_DELETION_FAILED,
        'description' => 'There was an issue deleting the file from the database.',
        'statusCode' => 500,
    ],
    Exception::STORAGE_FILE_EMPTY => [
        'name' => Exception::STORAGE_FILE_EMPTY,
        'description' => 'Empty file passed to the endpoint.',
        'statusCode' => 400,
    ],
    Exception::STORAGE_FILE_TYPE_UNSUPPORTED => [
        'name' => Exception::STORAGE_FILE_TYPE_UNSUPPORTED,
        'description' => 'The file type is not supported.',
        'statusCode' => 400,
    ],
    Exception::STORAGE_INVALID_FILE_SIZE => [
        'name' => Exception::STORAGE_INVALID_FILE_SIZE,
        'description' => 'The file size is either not valid or exceeds the maximum allowed size.',
        'statusCode' => 400,
    ],
    Exception::STORAGE_INVALID_FILE => [
        'name' => Exception::STORAGE_INVALID_FILE,
        'description' => 'The uploaded file is invalid. Please check the file and try again.',
        'statusCode' => 403,
    ],
    Exception::STORAGE_FAILED_TO_MOVE_FILE => [
        'name' => Exception::STORAGE_FAILED_TO_MOVE_FILE,
        'description' => 'Failed to move the uploaded file.',
        'statusCode' => 500,
    ],
    Exception::STORAGE_FAILED_TO_WRITE_FILE => [
        'name' => Exception::STORAGE_FAILED_TO_WRITE_FILE,
        'description' => 'Failed to save the uploaded file.',
        'statusCode' => 500,
    ],

    /** Functions  */
    Exception::FUNCTION_NOT_FOUND => [
        'name' => Exception::FUNCTION_NOT_FOUND,
        'description' => 'The requested function could not be found.',
        'statusCode' => 404,
    ],
    Exception::FUNCTION_DELETION_FAILED => [
        'name' => Exception::FUNCTION_DELETION_FAILED,
        'description' => 'Failed to delete the function from the database.',
        'statusCode' => 500,
    ],

    /** Deployments */
    Exception::DEPLOYMENT_NOT_FOUND => [
        'name' => Exception::DEPLOYMENT_NOT_FOUND,
        'description' => 'The requested deployment could not be found.',
        'statusCode' => 404,
    ],
    Exception::DEPLOYMENT_DELETION_FAILED => [
        'name' => Exception::DEPLOYMENT_DELETION_FAILED,
        'description' => 'Failed to delete the deployment from the database.',
        'statusCode' => 500,
    ],

    /** Executions */
    Exception::EXECUTION_NOT_FOUND => [
        'name' => Exception::EXECUTION_NOT_FOUND,
        'description' => 'The requested execution could not be found.',
        'statusCode' => 404,
    ],

    /** Collections */
    Exception::COLLECTION_NOT_FOUND => [
        'name' => Exception::COLLECTION_NOT_FOUND,
        'description' => 'The requested collection could not be found.',
        'statusCode' => 404,
    ],
    Exception::COLLECTION_ALREADY_EXISTS => [
        'name' => Exception::COLLECTION_ALREADY_EXISTS,
        'description' => 'The collection already exists.',
        'statusCode' => 400,
    ],
    Exception::COLLECTION_LIMIT_EXCEEDED => [
        'name' => Exception::COLLECTION_LIMIT_EXCEEDED,
        'description' => 'The maximum number of collections has been reached.',
        'statusCode' => 400,
    ],
    Exception::COLLECTION_DELETION_FAILED => [
        'name' => Exception::COLLECTION_DELETION_FAILED,
        'description' => 'Failed to delete the collection from the database.',
        'statusCode' => 500,
    ],

    /** Documents */
    Exception::DOCUMENT_NOT_FOUND => [
        'name' => Exception::DOCUMENT_NOT_FOUND,
        'description' => 'The requested document could not be found.',
        'statusCode' => 404,
    ],
    Exception::DOCUMENT_INVALID_STRUCTURE => [
        'name' => Exception::DOCUMENT_INVALID_STRUCTURE,
        'description' => 'The document structure is invalid.',
        'statusCode' => 400,
    ],
    Exception::DOCUMENT_MISSING_PAYLOAD => [
        'name' => Exception::DOCUMENT_MISSING_PAYLOAD,
        'description' => 'The document payload is missing.',
        'statusCode' => 400,
    ],
    Exception::DOCUMENT_ALREADY_EXISTS => [
        'name' => Exception::DOCUMENT_ALREADY_EXISTS,
        'description' => 'The document already exists.',
        'statusCode' => 400,
    ],

    /** Attributes */
    Exception::ATTRIBUTE_NOT_FOUND => [
        'name' => Exception::ATTRIBUTE_NOT_FOUND,
        'description' => 'The requested attribute could not be found.',
        'statusCode' => 404,
    ],
    Exception::ATTRIBUTE_UNKNOWN => [
        'name' => Exception::ATTRIBUTE_UNKNOWN,
        'description' => 'The requested attribute could not be found.',
        'statusCode' => 404,
    ],
    Exception::ATTRIBUTE_NOT_AVAILABLE => [
        'name' => Exception::ATTRIBUTE_NOT_AVAILABLE,
        'description' => 'The requested attribute is not available.',
        'statusCode' => 404,
    ],
    Exception::ATTRIBUTE_FORMAT_UNSUPPORTED => [
        'name' => Exception::ATTRIBUTE_FORMAT_UNSUPPORTED,
        'description' => 'The requested attribute format is not supported.',
        'statusCode' => 400,
    ],
    Exception::ATTRIBUTE_DEFAULT_UNSUPPORTED => [
        'name' => Exception::ATTRIBUTE_DEFAULT_UNSUPPORTED,
        'description' => 'The requested attribute default value is not supported.',
        'statusCode' => 400,
    ],
    Exception::ATTRIBUTE_ALREADY_EXISTS => [
        'name' => Exception::ATTRIBUTE_ALREADY_EXISTS,
        'description' => 'The attribute already exists.',
        'statusCode' => 400,
    ],
    Exception::ATTRIBUTE_LIMIT_EXCEEDED => [
        'name' => Exception::ATTRIBUTE_LIMIT_EXCEEDED,
        'description' => 'The maximum number of attributes has been reached.',
        'statusCode' => 400,
    ],
    Exception::ATTRIBUTE_VALUE_INVALID => [
        'name' => Exception::ATTRIBUTE_VALUE_INVALID,
        'description' => 'The attribute value is invalid.',
        'statusCode' => 400,
    ],

    /** Indexes */
    Exception::INDEX_NOT_FOUND => [
        'name' => Exception::INDEX_NOT_FOUND,
        'description' => 'The requested index could not be found.',
        'statusCode' => 404,
    ],
    Exception::INDEX_LIMIT_EXCEEDED => [
        'name' => Exception::INDEX_LIMIT_EXCEEDED,
        'description' => 'The maximum number of indexes has been reached.',
        'statusCode' => 400,
    ],
    Exception::INDEX_ALREADY_EXISTS => [
        'name' => Exception::INDEX_ALREADY_EXISTS,
        'description' => 'The index already exists.',
        'statusCode' => 400,
    ],

    /** Query */
    Exception::QUERY_LIMIT_EXCEEDED => [
        'name' => Exception::QUERY_LIMIT_EXCEEDED,
        'description' => 'The maximum number of results has been reached.',
        'statusCode' => 400,
    ],
    Exception::QUERY_INVALID => [
        'name' => Exception::QUERY_INVALID,
        'description' => 'The query is invalid.',
        'statusCode' => 400,
    ],
];