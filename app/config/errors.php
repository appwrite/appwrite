<?php

/**
 * List of server wide error codes and their respective messages. 
 */

use Appwrite\Extend\Exception;

return [
    /** General Errors */
    Exception::GENERAL_UNKNOWN_ORIGIN => [
        'name' => Exception::GENERAL_UNKNOWN_ORIGIN,
        'description' => 'The request originated from a non-whitelisted origin. If you trust this origin, please add it as a platform in the Appwrite console.',
        'code' => 403,
    ],
    Exception::GENERAL_SERVICE_DISABLED => [
        'name' => Exception::GENERAL_SERVICE_DISABLED,
        'description' => 'The requested service is disabled. You can enable/disable a service from the Appwrite console or by contacting the project owner.',
        'code' => 503,
    ],
    Exception::GENERAL_UNAUTHORIZED_SCOPE => [
        'name' => Exception::GENERAL_UNAUTHORIZED_SCOPE,
        'description' => 'The current user or API key does not have the required scopes to access the requested resource.',
        'code' => 401,
    ],
    Exception::STORAGE_ERROR => [
        'name' => Exception::STORAGE_ERROR,
        'description' => 'Storage error',
        'code' => 500,
    ],
    Exception::GENERAL_RATE_LIMIT_EXCEEDED => [
        'name' => Exception::GENERAL_RATE_LIMIT_EXCEEDED,
        'description' => 'Rate limit for the current endpoint has been exceeded. Please try again after some time.',
        'code' => 429,
    ],
    Exception::GENERAL_SMTP_DISABLED => [
        'name' => Exception::GENERAL_SMTP_DISABLED,
        'description' => 'SMTP is disabled on your Appwrite instance. Please contact your project ',
        'code' => 503,
    ],

    /** Project Errors */
    Exception::PROJECT_NOT_FOUND => [
        'name' => Exception::PROJECT_NOT_FOUND,
        'description' => 'The requested project could not be found. Please check the value of the X-Appwrite-Project header to ensure the correct project ID is being used.',
        'code' => 404,
    ],
    Exception::PROJECT_UNKNOWN => [
        'name' => Exception::PROJECT_UNKNOWN,
        'description' => 'The project ID is either missing or not valid. Please check the value of the X-Appwrite-Project header to ensure the correct project ID is being used.',
        'code' => 400,
    ],
    Exception::WEBHOOK_NOT_FOUND => [
        'name' => Exception::WEBHOOK_NOT_FOUND,
        'description' => 'The requested webhook could not be found.',
        'code' => 404,
    ],
    Exception::KEY_NOT_FOUND => [
        'name' => Exception::KEY_NOT_FOUND,
        'description' => 'The requested key could not be found.',
        'code' => 404,
    ],
    Exception::PLATFORM_NOT_FOUND => [
        'name' => Exception::PLATFORM_NOT_FOUND,
        'description' => 'The requested platform could not be found.',
        'code' => 404,
    ],
    Exception::DOMAIN_NOT_FOUND => [
        'name' => Exception::DOMAIN_NOT_FOUND,
        'description' => 'The requested domain could not be found.',
        'code' => 404,
    ],
    Exception::DOMAIN_ALREADY_EXISTS => [
        'name' => Exception::DOMAIN_ALREADY_EXISTS,
        'description' => 'The requested domain already exists.',
        'code' => 409,
    ],
    Exception::DOMAIN_UNREACHABLE => [
        'name' => Exception::DOMAIN_UNREACHABLE,
        'description' => 'The requested domain is not reachable.',
        'code' => 503,
    ],
    Exception::DOMAIN_VERIFICATION_FAILED => [
        'name' => Exception::DOMAIN_VERIFICATION_FAILED,
        'description' => 'The requested domain verification failed.',
        'code' => 503,
    ],

    /** User Errors */
    Exception::USER_COUNT_EXCEEDED => [
        'name' => Exception::USER_COUNT_EXCEEDED,
        'description' => 'The current project has exceeded the maximum number of users. Please check your user limit in the Appwrite console.',
        'code' => 501,
    ],
    Exception::USER_EMAIL_NOT_WHITELISTED => [
        'name' => Exception::USER_EMAIL_NOT_WHITELISTED,
        'description' => 'The user\'s email is not part of the whitelist. Please check the _APP_CONSOLE_WHITELIST_EMAILS environment variable of your Appwrite server.',
        'code' => 401,
    ],
    Exception::USER_JWT_INVALID => [
        'name' => Exception::USER_JWT_INVALID,
        'description' => 'The JWT token is invalid. Please check the value of the X-Appwrite-JWT header to ensure the correct token is being used.',
        'code' => 401,
    ],
    Exception::USER_PASSWORD_RESET_REQUIRED => [
        'name' => Exception::USER_PASSWORD_RESET_REQUIRED,
        'description' => 'The current user requires a password reset.',
        'code' => 412,
    ],
    Exception::USER_IP_NOT_WHITELISTED => [
        'name' => Exception::USER_IP_NOT_WHITELISTED,
        'description' => 'The user\'s IP address is not part of the whitelist. Please check the _APP_CONSOLE_WHITELIST_IPS environment variable of your Appwrite server.',
        'code' => 401,
    ],
    Exception::USER_INVALID_CREDENTIALS => [
        'name' => Exception::USER_INVALID_CREDENTIALS,
        'description' => 'Invalid credentials. Please check the email and password.',
        'code' => 401,
    ],
    Exception::USER_ALREADY_EXISTS => [
        'name' => Exception::USER_ALREADY_EXISTS,
        'description' => 'A user with the same email ID already exists in your project.',
        'code' => 409,
    ],
    Exception::USER_INVALID_TOKEN => [
        'name' => Exception::USER_INVALID_TOKEN,
        'description' => 'Invalid token.',
        'code' => 401,
    ],
    Exception::USER_BLOCKED => [
        'name' => Exception::USER_BLOCKED,
        'description' => 'The current user has been blocked. Please contact the project administrator for more information.',
        'code' => 401,
    ],
    Exception::USER_ANONYMOUS_CONSOLE_PROHIBITED => [
        'name' => Exception::USER_ANONYMOUS_CONSOLE_PROHIBITED,
        'description' => 'Anonymous users cannot be created for console project.',
        'code' => 401,
    ],
    Exception::USER_SESSION_ALREADY_EXISTS => [
        'name' => Exception::USER_SESSION_ALREADY_EXISTS,
        'description' => 'Cannot create anonymous user when a session is active.',
        'code' => 401,
    ],
    Exception::USER_NOT_FOUND => [
        'name' => Exception::USER_NOT_FOUND,
        'description' => 'User with the requested ID could not be found.',
        'code' => 404,
    ],
    Exception::USER_EMAIL_ALREADY_EXISTS => [
        'name' => Exception::USER_EMAIL_ALREADY_EXISTS,
        'description' => 'Another user with the same email already exists in the current project.',
        'code' => 409,
    ],
    Exception::USER_PASSWORD_MISMATCH => [
        'name' => Exception::USER_PASSWORD_MISMATCH,
        'description' => 'Passwords do not match. Please recheck.',
        'code' => 400,
    ],
    Exception::USER_SESSION_NOT_FOUND => [
        'name' => Exception::USER_SESSION_NOT_FOUND,
        'description' => 'The current user session could not be found.',
        'code' => 404,
    ],
    Exception::USER_UNAUTHORIZED => [
        'name' => Exception::USER_UNAUTHORIZED,
        'description' => 'The current user is not authorized to perform the requested action.',
        'code' => 401,
    ],
    Exception::USER_AUTH_METHOD_UNSUPPORTED => [
        'name' => Exception::USER_AUTH_METHOD_UNSUPPORTED,
        'description' => 'The requested authentication method is either disabled or unsupported.',
        'code' => 501,
    ],

    /** OAuth Errors */
    Exception::OAUTH_PROVIDER_DISABLED => [
        'name' => Exception::OAUTH_PROVIDER_DISABLED,
        'description' => 'The chosen OAuth provider is disabled. Please contact your project administrator for more information.',
        'code' => 412,
    ],
    Exception::OAUTH_PROVIDER_UNSUPPORTED => [
        'name' => Exception::OAUTH_PROVIDER_UNSUPPORTED,
        'description' => 'The chosen OAuth provider is unsupported.',
        'code' => 501,
    ],
    Exception::OAUTH_INVALID_SUCCESS_URL => [
        'name' => Exception::OAUTH_INVALID_SUCCESS_URL,
        'description' => 'Invalid URL received for OAuth success redirect.',
        'code' => 400,
    ],
    Exception::OAUTH_INVALID_FAILURE_URL => [
        'name' => Exception::OAUTH_INVALID_FAILURE_URL,
        'description' => 'Invalid URL received for OAuth failure redirect.',
        'code' => 400,
    ],
    Exception::OAUTH_MISSING_USER_ID => [
        'name' => Exception::OAUTH_MISSING_USER_ID,
        'description' => 'Failed to obtain user id from the OAuth provider.',
        'code' => 400,
    ],

    /** Teams */
    Exception::TEAM_NOT_FOUND => [
        'name' => Exception::TEAM_NOT_FOUND,
        'description' => 'Team with the requested ID could not be found.',
        'code' => 404,
    ],
    Exception::TEAM_INVITE_ALREADY_EXISTS => [
        'name' => Exception::TEAM_INVITE_ALREADY_EXISTS,
        'description' => 'The current user already has an invitation to this team.',
        'code' => 409,
    ],
    Exception::TEAM_INVITE_NOT_FOUND => [
        'name' => Exception::TEAM_INVITE_NOT_FOUND,
        'description' => 'The requested invitation could not be found.',
        'code' => 409,
    ],
    Exception::TEAM_INVALID_SECRET => [
        'name' => Exception::TEAM_INVALID_SECRET,
        'description' => 'The team invitation secret is invalid.',
        'code' => 401,
    ],
    Exception::TEAM_MEMBERSHIP_MISMATCH => [
        'name' => Exception::TEAM_MEMBERSHIP_MISMATCH,
        'description' => 'The membership ID does not belong to the team ID.',
        'code' => 404,
    ],
    Exception::TEAM_INVITE_MISMATCH => [
        'name' => Exception::TEAM_INVITE_MISMATCH,
        'description' => 'The invite does not belong to the current user.',
        'code' => 401,
    ],


    /** Membership */
    Exception::MEMBERSHIP_NOT_FOUND => [
        'name' => Exception::MEMBERSHIP_NOT_FOUND,
        'description' => 'Membership with the requested ID could not be found.',
        'code' => 404,
    ],

    /** Avatars */
    Exception::AVATAR_SET_NOT_FOUND => [
        'name' => Exception::AVATAR_SET_NOT_FOUND,
        'description' => 'The requested avatar set could not be found.',
        'code' => 404
    ],
    Exception::AVATAR_NOT_FOUND => [
        'name' => Exception::AVATAR_NOT_FOUND,
        'description' => 'The request avatar could not be found.',
        'code' => 404,
    ],
    Exception::AVATAR_IMAGE_NOT_FOUND => [
        'name' => Exception::AVATAR_IMAGE_NOT_FOUND,
        'description' => 'The requested image was not found.',
        'code' => 404,
    ],
    Exception::AVATAR_REMOTE_URL_FAILED => [
        'name' => Exception::AVATAR_REMOTE_URL_FAILED,
        'description' => 'The remote URL could not be fetched.',
        'code' => 404,
    ],
    Exception::AVATAR_ICON_NOT_FOUND => [
        'name' => Exception::AVATAR_ICON_NOT_FOUND,
        'description' => 'The requested favicon could not be found.',
        'code' => 404,
    ],

    /** Storage */
    Exception::STORAGE_FILE_NOT_FOUND => [
        'name' => Exception::STORAGE_FILE_NOT_FOUND,
        'description' => 'The requested file could not be found.',
        'code' => 404,
    ],
    Exception::STORAGE_DEVICE_NOT_FOUND => [
        'name' => Exception::STORAGE_DEVICE_NOT_FOUND,
        'description' => 'The requested storage device could not be found.',
        'code' => 400,
    ],
    Exception::STORAGE_FILE_EMPTY => [
        'name' => Exception::STORAGE_FILE_EMPTY,
        'description' => 'Empty file passed to the endpoint.',
        'code' => 400,
    ],
    Exception::STORAGE_FILE_TYPE_UNSUPPORTED => [
        'name' => Exception::STORAGE_FILE_TYPE_UNSUPPORTED,
        'description' => 'The file type is not supported.',
        'code' => 400,
    ],
    Exception::STORAGE_INVALID_FILE_SIZE => [
        'name' => Exception::STORAGE_INVALID_FILE_SIZE,
        'description' => 'The file size is either not valid or exceeds the maximum allowed size.',
        'code' => 400,
    ],
    Exception::STORAGE_INVALID_FILE => [
        'name' => Exception::STORAGE_INVALID_FILE,
        'description' => 'The uploaded file is invalid. Please check the file and try again.',
        'code' => 403,
    ],

    /** Functions  */
    Exception::FUNCTION_NOT_FOUND => [
        'name' => Exception::FUNCTION_NOT_FOUND,
        'description' => 'The requested function could not be found.',
        'code' => 404,
    ],

    /** Deployments */
    Exception::DEPLOYMENT_NOT_FOUND => [
        'name' => Exception::DEPLOYMENT_NOT_FOUND,
        'description' => 'The requested deployment could not be found.',
        'code' => 404,
    ],

    /** Executions */
    Exception::EXECUTION_NOT_FOUND => [
        'name' => Exception::EXECUTION_NOT_FOUND,
        'description' => 'The requested execution could not be found.',
        'code' => 404,
    ],

    /** Collections */
    Exception::COLLECTION_NOT_FOUND => [
        'name' => Exception::COLLECTION_NOT_FOUND,
        'description' => 'The requested collection could not be found.',
        'code' => 404,
    ],
    Exception::COLLECTION_ALREADY_EXISTS => [
        'name' => Exception::COLLECTION_ALREADY_EXISTS,
        'description' => 'The collection already exists.',
        'code' => 400,
    ],
    Exception::COLLECTION_LIMIT_EXCEEDED => [
        'name' => Exception::COLLECTION_LIMIT_EXCEEDED,
        'description' => 'The maximum number of collections has been reached.',
        'code' => 400,
    ],

    /** Documents */
    Exception::DOCUMENT_NOT_FOUND => [
        'name' => Exception::DOCUMENT_NOT_FOUND,
        'description' => 'The requested document could not be found.',
        'code' => 404,
    ],
    Exception::DOCUMENT_INVALID_STRUCTURE => [
        'name' => Exception::DOCUMENT_INVALID_STRUCTURE,
        'description' => 'The document structure is invalid.',
        'code' => 400,
    ],
    Exception::DOCUMENT_MISSING_PAYLOAD => [
        'name' => Exception::DOCUMENT_MISSING_PAYLOAD,
        'description' => 'The document payload is missing.',
        'code' => 400,
    ],
    Exception::DOCUMENT_ALREADY_EXISTS => [
        'name' => Exception::DOCUMENT_ALREADY_EXISTS,
        'description' => 'The document already exists.',
        'code' => 400,
    ],

    /** Attributes */
    Exception::ATTRIBUTE_NOT_FOUND => [
        'name' => Exception::ATTRIBUTE_NOT_FOUND,
        'description' => 'The requested attribute could not be found.',
        'code' => 404,
    ],
    Exception::ATTRIBUTE_UNKNOWN => [
        'name' => Exception::ATTRIBUTE_UNKNOWN,
        'description' => 'The requested attribute could not be found.',
        'code' => 404,
    ],
    Exception::ATTRIBUTE_NOT_AVAILABLE => [
        'name' => Exception::ATTRIBUTE_NOT_AVAILABLE,
        'description' => 'The requested attribute is not available.',
        'code' => 404,
    ],
    Exception::ATTRIBUTE_FORMAT_UNSUPPORTED => [
        'name' => Exception::ATTRIBUTE_FORMAT_UNSUPPORTED,
        'description' => 'The requested attribute format is not supported.',
        'code' => 400,
    ],
    Exception::ATTRIBUTE_DEFAULT_UNSUPPORTED => [
        'name' => Exception::ATTRIBUTE_DEFAULT_UNSUPPORTED,
        'description' => 'The requested attribute default value is not supported.',
        'code' => 400,
    ],
    Exception::ATTRIBUTE_ALREADY_EXISTS => [
        'name' => Exception::ATTRIBUTE_ALREADY_EXISTS,
        'description' => 'The attribute already exists.',
        'code' => 400,
    ],
    Exception::ATTRIBUTE_LIMIT_EXCEEDED => [
        'name' => Exception::ATTRIBUTE_LIMIT_EXCEEDED,
        'description' => 'The maximum number of attributes has been reached.',
        'code' => 400,
    ],
    Exception::ATTRIBUTE_VALUE_INVALID => [
        'name' => Exception::ATTRIBUTE_VALUE_INVALID,
        'description' => 'The attribute value is invalid.',
        'code' => 400,
    ],

    /** Indexes */
    Exception::INDEX_NOT_FOUND => [
        'name' => Exception::INDEX_NOT_FOUND,
        'description' => 'The requested index could not be found.',
        'code' => 404,
    ],
    Exception::INDEX_LIMIT_EXCEEDED => [
        'name' => Exception::INDEX_LIMIT_EXCEEDED,
        'description' => 'The maximum number of indexes has been reached.',
        'code' => 400,
    ],
    Exception::INDEX_ALREADY_EXISTS => [
        'name' => Exception::INDEX_ALREADY_EXISTS,
        'description' => 'The index already exists.',
        'code' => 400,
    ],

    /** Query */
    Exception::QUERY_LIMIT_EXCEEDED => [
        'name' => Exception::QUERY_LIMIT_EXCEEDED,
        'description' => 'The maximum number of results has been reached.',
        'code' => 400,
    ],
    Exception::QUERY_INVALID => [
        'name' => Exception::QUERY_INVALID,
        'description' => 'The query is invalid.',
        'code' => 400,
    ],
];