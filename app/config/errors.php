<?php

/**
 * List of server wide error codes and their respective messages.
 */

use Appwrite\Extend\Exception;

return [
    /** General Errors */
    Exception::GENERAL_UNKNOWN => [
        'name' => Exception::GENERAL_UNKNOWN,
        'description' => 'An unknown error has occured. Please check the logs for more information.',
        'code' => 500,
    ],
    Exception::GENERAL_MOCK => [
        'name' => Exception::GENERAL_MOCK,
        'description' => 'General errors thrown by the mock controller used for testing.',
        'code' => 400,
    ],
    Exception::GENERAL_ACCESS_FORBIDDEN => [
        'name' => Exception::GENERAL_ACCESS_FORBIDDEN,
        'description' => 'Access to this API is forbidden.',
        'code' => 401,
    ],
    Exception::GENERAL_UNKNOWN_ORIGIN => [
        'name' => Exception::GENERAL_UNKNOWN_ORIGIN,
        'description' => 'The request originated from an unknown origin. If you trust this domain, please list it as a trusted platform in the Appwrite console.',
        'code' => 403,
    ],
    Exception::GENERAL_SERVICE_DISABLED => [
        'name' => Exception::GENERAL_SERVICE_DISABLED,
        'description' => 'The requested service is disabled. You can enable the service from the Appwrite console.',
        'code' => 503,
    ],
    Exception::GENERAL_UNAUTHORIZED_SCOPE => [
        'name' => Exception::GENERAL_UNAUTHORIZED_SCOPE,
        'description' => 'The current user or API key does not have the required scopes to access the requested resource.',
        'code' => 401,
    ],
    Exception::GENERAL_RATE_LIMIT_EXCEEDED => [
        'name' => Exception::GENERAL_RATE_LIMIT_EXCEEDED,
        'description' => 'Rate limit for the current endpoint has been exceeded. Please try again after some time.',
        'code' => 429,
    ],
    Exception::GENERAL_SMTP_DISABLED => [
        'name' => Exception::GENERAL_SMTP_DISABLED,
        'description' => 'SMTP is disabled on your Appwrite instance. You can <a href="/docs/email-delivery">learn more about setting up SMTP</a> in our docs.',
        'code' => 503,
    ],
    Exception::GENERAL_PHONE_DISABLED => [
        'name' => Exception::GENERAL_PHONE_DISABLED,
        'description' => 'Phone provider is not configured. Please check the _APP_SMS_PROVIDER environment variable of your Appwrite server.',
        'code' => 503,
    ],
    Exception::GENERAL_ARGUMENT_INVALID => [
        'name' => Exception::GENERAL_ARGUMENT_INVALID,
        'description' => 'The request contains one or more invalid arguments. Please refer to the endpoint documentation.',
        'code' => 400,
    ],
    Exception::GENERAL_QUERY_LIMIT_EXCEEDED => [
        'name' => Exception::GENERAL_QUERY_LIMIT_EXCEEDED,
        'description' => 'Query limit exceeded for the current attribute. Usage of more than 100 query values on a single attribute is prohibited.',
        'code' => 400,
    ],
    Exception::GENERAL_QUERY_INVALID => [
        'name' => Exception::GENERAL_QUERY_INVALID,
        'description' => 'The query\'s syntax is invalid. Please check the query and try again.',
        'code' => 400,
    ],
    Exception::GENERAL_ROUTE_NOT_FOUND => [
        'name' => Exception::GENERAL_ROUTE_NOT_FOUND,
        'description' => 'The requested route was not found. Please refer to the API docs and try again.',
        'code' => 404,
    ],
    Exception::GENERAL_CURSOR_NOT_FOUND => [
        'name' => Exception::GENERAL_CURSOR_NOT_FOUND,
        'description' => 'The cursor is invalid. This can happen if the item represented by the cursor has been deleted.',
        'code' => 400,
    ],
    Exception::GENERAL_SERVER_ERROR => [
        'name' => Exception::GENERAL_SERVER_ERROR,
        'description' => 'An internal server error occurred.',
        'code' => 500,
    ],
    Exception::GENERAL_PROTOCOL_UNSUPPORTED => [
        'name' => Exception::GENERAL_PROTOCOL_UNSUPPORTED,
        'description' => 'The request cannot be fulfilled with the current protocol. Please check the value of the _APP_OPTIONS_FORCE_HTTPS environment variable.',
        'code' => 500,
    ],
    Exception::GENERAL_USAGE_DISABLED => [
        'name' => Exception::GENERAL_USAGE_DISABLED,
        'description' => 'Usage stats is not configured. Please check the value of the _APP_USAGE_STATS environment variable of your Appwrite server.',
        'code' => 501,
    ],

    /** User Errors */
    Exception::USER_COUNT_EXCEEDED => [
        'name' => Exception::USER_COUNT_EXCEEDED,
        'description' => 'The current project has exceeded the maximum number of users. Please check your user limit in the Appwrite console.',
        'code' => 501,
    ],
    Exception::USER_JWT_INVALID => [
        'name' => Exception::USER_JWT_INVALID,
        'description' => 'The JWT token is invalid. Please check the value of the X-Appwrite-JWT header to ensure the correct token is being used.',
        'code' => 401,
    ],
    Exception::USER_ALREADY_EXISTS => [
        'name' => Exception::USER_ALREADY_EXISTS,
        'description' => 'A user with the same id, email, or phone already exists in your project.',
        'code' => 409,
    ],
    Exception::USER_BLOCKED => [
        'name' => Exception::USER_BLOCKED,
        'description' => 'The current user has been blocked.',
        'code' => 401,
    ],
    Exception::USER_INVALID_TOKEN => [
        'name' => Exception::USER_INVALID_TOKEN,
        'description' => 'Invalid token passed in the request.',
        'code' => 401,
    ],
    Exception::USER_PASSWORD_RESET_REQUIRED => [
        'name' => Exception::USER_PASSWORD_RESET_REQUIRED,
        'description' => 'The current user requires a password reset.',
        'code' => 412,
    ],
    Exception::USER_EMAIL_NOT_WHITELISTED => [
        'name' => Exception::USER_EMAIL_NOT_WHITELISTED,
        'description' => 'Console registration is restricted to specific emails. Contact your administrator for more information.',
        'code' => 401,
    ],
    Exception::USER_IP_NOT_WHITELISTED => [
        'name' => Exception::USER_IP_NOT_WHITELISTED,
        'description' => 'Console registration is restricted to specific IPs. Contact your administrator for more information.',
        'code' => 401,
    ],
    Exception::USER_INVALID_CREDENTIALS => [
        'name' => Exception::USER_INVALID_CREDENTIALS,
        'description' => 'Invalid credentials. Please check the email and password.',
        'code' => 401,
    ],
    Exception::USER_ANONYMOUS_CONSOLE_PROHIBITED => [
        'name' => Exception::USER_ANONYMOUS_CONSOLE_PROHIBITED,
        'description' => 'Anonymous users cannot be created for the console project.',
        'code' => 401,
    ],
    Exception::USER_SESSION_ALREADY_EXISTS => [
        'name' => Exception::USER_SESSION_ALREADY_EXISTS,
        'description' => 'Creation of anonymous users is prohibited when a session is active.',
        'code' => 401,
    ],
    Exception::USER_NOT_FOUND => [
        'name' => Exception::USER_NOT_FOUND,
        'description' => 'User with the requested ID could not be found.',
        'code' => 404,
    ],
    Exception::USER_EMAIL_ALREADY_EXISTS => [
        'name' => Exception::USER_EMAIL_ALREADY_EXISTS,
        'description' => 'A user with the same email already exists in the current project.',
        'code' => 409,
    ],
    Exception::USER_PASSWORD_MISMATCH => [
        'name' => Exception::USER_PASSWORD_MISMATCH,
        'description' => 'Passwords do not match. Please check the password and confirm password.',
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
        'description' => 'The requested authentication method is either disabled or unsupported. Please check the supported authentication methods in the Appwrite console.',
        'code' => 501,
    ],
    Exception::USER_PHONE_ALREADY_EXISTS => [
        'name' => Exception::USER_PHONE_ALREADY_EXISTS,
        'description' => 'A user with the same phone number already exists in the current project.',
        'code' => 409,
    ],
    Exception::USER_PHONE_NOT_FOUND => [
        'name' => Exception::USER_PHONE_NOT_FOUND,
        'description' => 'The current user does not have a phone number associated with their account.',
        'code' => 400,
    ],
    Exception::USER_MISSING_ID => [
        'name' => Exception::USER_MISSING_ID,
        'description' => 'Missing ID from OAuth2 provider.',
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
        'description' => 'User has already been invited or is already a member of this team',
        'code' => 409,
    ],
    Exception::TEAM_INVITE_NOT_FOUND => [
        'name' => Exception::TEAM_INVITE_NOT_FOUND,
        'description' => 'The requested team invitation could not be found.',
        'code' => 404,
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
    Exception::MEMBERSHIP_ALREADY_CONFIRMED => [
        'name' => Exception::MEMBERSHIP_ALREADY_CONFIRMED,
        'description' => 'Membership already confirmed',
        'code' => 409,
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
        'description' => 'The requested image was not found at the URL.',
        'code' => 404,
    ],
    Exception::AVATAR_REMOTE_URL_FAILED => [
        'name' => Exception::AVATAR_REMOTE_URL_FAILED,
        'description' => 'Failed to fetch favicon from the requested URL.',
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
        'description' => 'The given file extension is not supported.',
        'code' => 400,
    ],
    Exception::STORAGE_INVALID_FILE_SIZE => [
        'name' => Exception::STORAGE_INVALID_FILE_SIZE,
        'description' => 'The file size is either not valid or exceeds the maximum allowed size. Please check the file or the value of the _APP_STORAGE_LIMIT environment variable.',
        'code' => 400,
    ],
    Exception::STORAGE_INVALID_FILE => [
        'name' => Exception::STORAGE_INVALID_FILE,
        'description' => 'The uploaded file is invalid. Please check the file and try again.',
        'code' => 403,
    ],
    Exception::STORAGE_BUCKET_ALREADY_EXISTS => [
        'name' => Exception::STORAGE_BUCKET_ALREADY_EXISTS,
        'description' => 'A storage bucket with the requested ID already exists.',
        'code' => 409,
    ],
    Exception::STORAGE_BUCKET_NOT_FOUND => [
        'name' => Exception::STORAGE_BUCKET_NOT_FOUND,
        'description' => 'Storage bucket with the requested ID could not be found.',
        'code' => 404,
    ],
    Exception::STORAGE_INVALID_CONTENT_RANGE => [
        'name' => Exception::STORAGE_INVALID_CONTENT_RANGE,
        'description' => 'The content range is invalid. Please check the value of the Content-Range header.',
        'code' => 400,
    ],
    Exception::STORAGE_INVALID_RANGE => [
        'name' => Exception::STORAGE_INVALID_RANGE,
        'description' => 'The requested range is not satisfiable. Please check the value of the Range header.',
        'code' => 416,
    ],

    /** Functions  */
    Exception::FUNCTION_NOT_FOUND => [
        'name' => Exception::FUNCTION_NOT_FOUND,
        'description' => 'Function with the requested ID could not be found.',
        'code' => 404,
    ],
    Exception::FUNCTION_RUNTIME_UNSUPPORTED => [
        'name' => Exception::FUNCTION_RUNTIME_UNSUPPORTED,
        'description' => 'The requested runtime is either inactive or unsupported. Please check the value of the _APP_FUNCTIONS_RUNTIMES environment variable.',
        'code' => 404,
    ],

    /** Builds  */
    Exception::BUILD_NOT_FOUND => [
        'name' => Exception::BUILD_NOT_FOUND,
        'description' => 'Build with the requested ID could not be found.',
        'code' => 404,
    ],
    Exception::BUILD_NOT_READY => [
        'name' => Exception::BUILD_NOT_READY,
        'description' => 'Build with the requested ID is building and not ready for execution.',
        'code' => 400,
    ],
    Exception::BUILD_IN_PROGRESS => [
        'name' => Exception::BUILD_IN_PROGRESS,
        'description' => 'Build with the requested ID is already in progress. Please wait before you can retry.',
        'code' => 400,
    ],

    /** Deployments */
    Exception::DEPLOYMENT_NOT_FOUND => [
        'name' => Exception::DEPLOYMENT_NOT_FOUND,
        'description' => 'Deployment with the requested ID could not be found.',
        'code' => 404,
    ],

    /** Executions */
    Exception::EXECUTION_NOT_FOUND => [
        'name' => Exception::EXECUTION_NOT_FOUND,
        'description' => 'Execution with the requested ID could not be found.',
        'code' => 404,
    ],

    /** Databases */
    Exception::DATABASE_NOT_FOUND => [
        'name' => Exception::DATABASE_NOT_FOUND,
        'description' => 'Database not found',
        'code' => 404
    ],

    Exception::DATABASE_ALREADY_EXISTS => [
        'name' => Exception::DATABASE_ALREADY_EXISTS,
        'description' => 'Database already exists',
        'code' => 409
    ],

    /** Collections */
    Exception::COLLECTION_NOT_FOUND => [
        'name' => Exception::COLLECTION_NOT_FOUND,
        'description' => 'Collection with the requested ID could not be found.',
        'code' => 404,
    ],
    Exception::COLLECTION_ALREADY_EXISTS => [
        'name' => Exception::COLLECTION_ALREADY_EXISTS,
        'description' => 'A collection with the requested ID already exists.',
        'code' => 409,
    ],
    Exception::COLLECTION_LIMIT_EXCEEDED => [
        'name' => Exception::COLLECTION_LIMIT_EXCEEDED,
        'description' => 'The maximum number of collections has been reached.',
        'code' => 400,
    ],

    /** Documents */
    Exception::DOCUMENT_NOT_FOUND => [
        'name' => Exception::DOCUMENT_NOT_FOUND,
        'description' => 'Document with the requested ID could not be found.',
        'code' => 404,
    ],
    Exception::DOCUMENT_INVALID_STRUCTURE => [
        'name' => Exception::DOCUMENT_INVALID_STRUCTURE,
        'description' => 'The document structure is invalid. Please ensure the attributes match the collection definition.',
        'code' => 400,
    ],
    Exception::DOCUMENT_MISSING_PAYLOAD => [
        'name' => Exception::DOCUMENT_MISSING_PAYLOAD,
        'description' => 'The document payload is missing.',
        'code' => 400,
    ],
    Exception::DOCUMENT_ALREADY_EXISTS => [
        'name' => Exception::DOCUMENT_ALREADY_EXISTS,
        'description' => 'Document with the requested ID already exists.',
        'code' => 409,
    ],
    Exception::DOCUMENT_UPDATE_CONFLICT => [
        'name' => Exception::DOCUMENT_UPDATE_CONFLICT,
        'description' => 'Remote document is newer than local.',
        'code' => 409,
    ],
    Exception::DOCUMENT_DELETE_RESTRICTED => [
        'name' => Exception::DOCUMENT_DELETE_RESTRICTED,
        'description' => 'Document cannot be deleted because it is referenced by another document.',
        'code' => 403,
    ],

    /** Attributes */
    Exception::ATTRIBUTE_NOT_FOUND => [
        'name' => Exception::ATTRIBUTE_NOT_FOUND,
        'description' => 'Attribute with the requested ID could not be found.',
        'code' => 404,
    ],
    Exception::ATTRIBUTE_UNKNOWN => [
        'name' => Exception::ATTRIBUTE_UNKNOWN,
        'description' => 'The attribute required for the index could not be found. Please confirm all your attributes are in the available state.',
        'code' => 400,
    ],
    Exception::ATTRIBUTE_NOT_AVAILABLE => [
        'name' => Exception::ATTRIBUTE_NOT_AVAILABLE,
        'description' => 'The requested attribute is not yet available. Please try again later.',
        'code' => 400,
    ],
    Exception::ATTRIBUTE_FORMAT_UNSUPPORTED => [
        'name' => Exception::ATTRIBUTE_FORMAT_UNSUPPORTED,
        'description' => 'The requested attribute format is not supported.',
        'code' => 400,
    ],
    Exception::ATTRIBUTE_DEFAULT_UNSUPPORTED => [
        'name' => Exception::ATTRIBUTE_DEFAULT_UNSUPPORTED,
        'description' => 'Default values cannot be set for array or required attributes.',
        'code' => 400,
    ],
    Exception::ATTRIBUTE_ALREADY_EXISTS => [
        'name' => Exception::ATTRIBUTE_ALREADY_EXISTS,
        'description' => 'Attribute with the requested ID already exists.',
        'code' => 409,
    ],
    Exception::ATTRIBUTE_LIMIT_EXCEEDED => [
        'name' => Exception::ATTRIBUTE_LIMIT_EXCEEDED,
        'description' => 'The maximum number of attributes has been reached.',
        'code' => 400,
    ],
    Exception::ATTRIBUTE_VALUE_INVALID => [
        'name' => Exception::ATTRIBUTE_VALUE_INVALID,
        'description' => 'The attribute value is invalid. Please check the type, range and value of the attribute.',
        'code' => 400,
    ],
    Exception::ATTRIBUTE_TYPE_INVALID => [
        'name' => Exception::ATTRIBUTE_TYPE_INVALID,
        'description' => 'The attribute type is invalid.',
        'code' => 400,
    ],

    /** Indexes */
    Exception::INDEX_NOT_FOUND => [
        'name' => Exception::INDEX_NOT_FOUND,
        'description' => 'Index with the requested ID could not be found.',
        'code' => 404,
    ],
    Exception::INDEX_LIMIT_EXCEEDED => [
        'name' => Exception::INDEX_LIMIT_EXCEEDED,
        'description' => 'The maximum number of indexes has been reached.',
        'code' => 400,
    ],
    Exception::INDEX_ALREADY_EXISTS => [
        'name' => Exception::INDEX_ALREADY_EXISTS,
        'description' => 'Index with the requested ID already exists.',
        'code' => 409,
    ],

    /** Project Errors */
    Exception::PROJECT_NOT_FOUND => [
        'name' => Exception::PROJECT_NOT_FOUND,
        'description' => 'Project with the requested ID could not be found. Please check the value of the X-Appwrite-Project header to ensure the correct project ID is being used.',
        'code' => 404,
    ],
    Exception::PROJECT_ALREADY_EXISTS => [
        'name' => Exception::PROJECT_ALREADY_EXISTS,
        'description' => 'Project with the requested ID already exists.',
        'code' => 409,
    ],
    Exception::PROJECT_UNKNOWN => [
        'name' => Exception::PROJECT_UNKNOWN,
        'description' => 'The project ID is either missing or not valid. Please check the value of the X-Appwrite-Project header to ensure the correct project ID is being used.',
        'code' => 400,
    ],
    Exception::PROJECT_PROVIDER_DISABLED => [
        'name' => Exception::PROJECT_PROVIDER_DISABLED,
        'description' => 'The chosen OAuth provider is disabled. You can enable the OAuth provider using the Appwrite console.',
        'code' => 412,
    ],
    Exception::PROJECT_PROVIDER_UNSUPPORTED => [
        'name' => Exception::PROJECT_PROVIDER_UNSUPPORTED,
        'description' => 'The chosen OAuth provider is unsupported. Please check the <a href="/docs/client/account?sdk=web-default#accountCreateOAuth2Session">Create OAuth2 Session docs</a> for the complete list of supported OAuth providers.',
        'code' => 501,
    ],
    Exception::PROJECT_INVALID_SUCCESS_URL => [
        'name' => Exception::PROJECT_INVALID_SUCCESS_URL,
        'description' => 'Invalid redirect URL for OAuth success.',
        'code' => 400,
    ],
    Exception::PROJECT_INVALID_FAILURE_URL => [
        'name' => Exception::PROJECT_INVALID_FAILURE_URL,
        'description' => 'Invalid redirect URL for OAuth failure.',
        'code' => 400,
    ],
    Exception::PROJECT_RESERVED_PROJECT => [
        'name' => Exception::PROJECT_RESERVED_PROJECT,
        'description' => 'The project ID is reserved. Please choose another project ID.',
        'code' => 400,
    ],
    Exception::PROJECT_KEY_EXPIRED => [
        'name' => Exception::PROJECT_KEY_EXPIRED,
        'description' => 'The project key has expired. Please generate a new key using the Appwrite console.',
        'code' => 401,
    ],
    Exception::WEBHOOK_NOT_FOUND => [
        'name' => Exception::WEBHOOK_NOT_FOUND,
        'description' => 'Webhook with the requested ID could not be found.',
        'code' => 404,
    ],
    Exception::KEY_NOT_FOUND => [
        'name' => Exception::KEY_NOT_FOUND,
        'description' => 'Key with the requested ID could not be found.',
        'code' => 404,
    ],
    Exception::PLATFORM_NOT_FOUND => [
        'name' => Exception::PLATFORM_NOT_FOUND,
        'description' => 'Platform with the requested ID could not be found.',
        'code' => 404,
    ],
    Exception::DOMAIN_NOT_FOUND => [
        'name' => Exception::DOMAIN_NOT_FOUND,
        'description' => 'Domain with the requested ID could not be found.',
        'code' => 404,
    ],
    Exception::DOMAIN_ALREADY_EXISTS => [
        'name' => Exception::DOMAIN_ALREADY_EXISTS,
        'description' => 'A Domain with the requested ID already exists.',
        'code' => 409,
    ],
    Exception::VARIABLE_NOT_FOUND => [
        'name' => Exception::VARIABLE_NOT_FOUND,
        'description' => 'Variable with the requested ID could not be found.',
        'code' => 404,
    ],
    Exception::VARIABLE_ALREADY_EXISTS => [
        'name' => Exception::VARIABLE_ALREADY_EXISTS,
        'description' => 'Variable with the same ID already exists in your project.',
        'code' => 409,
    ],
    Exception::DOMAIN_VERIFICATION_FAILED => [
        'name' => Exception::DOMAIN_VERIFICATION_FAILED,
        'description' => 'Domain verification for the requested domain has failed.',
        'code' => 401,
    ],
    Exception::DOMAIN_TARGET_INVALID => [
        'name' => Exception::DOMAIN_TARGET_INVALID,
        'description' => 'Your Appwrite instance is not publicly accessible. Please check the _APP_DOMAIN_TARGET environment variable of your Appwrite server.',
        'code' => 501,
    ],
    Exception::GRAPHQL_NO_QUERY => [
        'name' => Exception::GRAPHQL_NO_QUERY,
        'description' => 'Param "query" is not optional.',
        'code' => 400,
    ],
    Exception::GRAPHQL_TOO_MANY_QUERIES => [
        'name' => Exception::GRAPHQL_TOO_MANY_QUERIES,
        'description' => 'Too many queries.',
        'code' => 400,
    ],
];
