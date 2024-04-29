<?php

/**
 * List of server wide error codes and their respective messages.
 */

use Appwrite\Extend\Exception;
use Appwrite\Messaging\Status as MessageStatus;

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
    Exception::GENERAL_API_DISABLED => [
        'name' => Exception::GENERAL_API_DISABLED,
        'description' => 'The requested API is disabled. You can enable the API from the Appwrite console.',
        'code' => 403,
    ],
    Exception::GENERAL_SERVICE_DISABLED => [
        'name' => Exception::GENERAL_SERVICE_DISABLED,
        'description' => 'The requested service is disabled. You can enable the service from the Appwrite console.',
        'code' => 403,
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
        'code' => 426,
    ],
    Exception::GENERAL_CODES_DISABLED => [
        'name' => Exception::GENERAL_CODES_DISABLED,
        'description' => 'Invitation codes are disabled on this server. Please contact the server administrator.',
        'code' => 500,
    ],
    Exception::GENERAL_USAGE_DISABLED => [
        'name' => Exception::GENERAL_USAGE_DISABLED,
        'description' => 'Usage stats is not configured. Please check the value of the _APP_USAGE_STATS environment variable of your Appwrite server.',
        'code' => 501,
    ],
    Exception::GENERAL_NOT_IMPLEMENTED => [
        'name' => Exception::GENERAL_NOT_IMPLEMENTED,
        'description' => 'This method was not fully implemented yet. If you believe this is a mistake, please upgrade your Appwrite server version.',
        'code' => 405,
    ],
    Exception::GENERAL_INVALID_EMAIL => [
        'name' => Exception::GENERAL_INVALID_EMAIL,
        'description' => 'Value must be a valid email address.',
        'code' => 400,
    ],
    Exception::GENERAL_INVALID_PHONE => [
        'name' => Exception::GENERAL_INVALID_PHONE,
        'description' => 'Value must be a valid phone number. Format this number with a leading \'+\' and a country code, e.g., +16175551212.',
        'code' => 400,
    ],
    Exception::GENERAL_REGION_ACCESS_DENIED => [
        'name' => Exception::GENERAL_REGION_ACCESS_DENIED,
        'description' => 'Your location is not supported due to legal requirements.',
        'code' => 451,
    ],
    Exception::GENERAL_BAD_REQUEST => [
        'name' => Exception::GENERAL_BAD_REQUEST,
        'description' => 'There was an error processing your request. Please check the inputs and try again.',
        'code' => 400,
    ],

    /** User Errors */
    Exception::USER_COUNT_EXCEEDED => [
        'name' => Exception::USER_COUNT_EXCEEDED,
        'description' => 'The current project has exceeded the maximum number of users. Please check your user limit in the Appwrite console.',
        'code' => 501,
    ],
    Exception::USER_CONSOLE_COUNT_EXCEEDED => [
        'name' => Exception::USER_CONSOLE_COUNT_EXCEEDED,
        'description' => 'Sign up to the console is restricted. You can contact an administrator to update console sign up restrictions by setting _APP_CONSOLE_WHITELIST_ROOT to "disabled".',
        'code' => 501,
    ],
    Exception::USER_JWT_INVALID => [
        'name' => Exception::USER_JWT_INVALID,
        'description' => 'The JWT token is invalid. Please check the value of the X-Appwrite-JWT header to ensure the correct token is being used.',
        'code' => 401,
    ],
    Exception::USER_ALREADY_EXISTS => [
        'name' => Exception::USER_ALREADY_EXISTS,
        'description' => 'A user with the same id, email, or phone already exists in this project.',
        'code' => 409,
    ],
    Exception::USER_BLOCKED => [
        'name' => Exception::USER_BLOCKED,
        'description' => 'The current user has been blocked. You can unblock the user by making a request to the User API\'s "Update User Status" endpoint or in the Appwrite Console\'s Auth section.',
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
    Exception::USER_INVALID_CODE => [
        'name' => Exception::USER_INVALID_CODE,
        'description' => 'The specified code is not valid. Contact your administrator for more information.',
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
        'description' => 'Creation of a session is prohibited when a session is active.',
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
    Exception::USER_PASSWORD_RECENTLY_USED => [
        'name' => Exception::USER_PASSWORD_RECENTLY_USED,
        'description' => 'The password you are trying to use is similar to your previous password. For your security, please choose a different password and try again.',
        'code' => 400,
    ],
    Exception::USER_PASSWORD_PERSONAL_DATA => [
        'name' => Exception::USER_PASSWORD_PERSONAL_DATA,
        'description' => 'The password you are trying to use contains references to your name, email, phone or userID. For your security, please choose a different password and try again.',
        'code' => 400,
    ],
    Exception::USER_SESSION_NOT_FOUND => [
        'name' => Exception::USER_SESSION_NOT_FOUND,
        'description' => 'The current user session could not be found.',
        'code' => 404,
    ],
    Exception::USER_IDENTITY_NOT_FOUND => [
        'name' => Exception::USER_IDENTITY_NOT_FOUND,
        'description' => 'The identity could not be found. Please sign in with OAuth provider to create identity first.',
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
        'publish' => false,
    ],
    Exception::USER_PHONE_ALREADY_EXISTS => [
        'name' => Exception::USER_PHONE_ALREADY_EXISTS,
        'description' => 'A user with the same phone number already exists in the current project.',
        'code' => 409,
    ],
    Exception::USER_RECOVERY_CODES_ALREADY_EXISTS => [
        'name' => Exception::USER_RECOVERY_CODES_ALREADY_EXISTS,
        'description' => 'The current user already generated recovery codes and they can only be read once for security reasons.',
        'code' => 409,
    ],
    Exception::USER_AUTHENTICATOR_NOT_FOUND => [
        'name' => Exception::USER_AUTHENTICATOR_NOT_FOUND,
        'description' => 'Authenticator could not be found on the current user.',
        'code' => 404,
    ],
    Exception::USER_RECOVERY_CODES_NOT_FOUND => [
        'name' => Exception::USER_RECOVERY_CODES_NOT_FOUND,
        'description' => 'Recovery codes could not be found on the current user.',
        'code' => 404,
    ],
    Exception::USER_AUTHENTICATOR_ALREADY_VERIFIED => [
        'name' => Exception::USER_AUTHENTICATOR_ALREADY_VERIFIED,
        'description' => 'This authenticator is already verified on the current user.',
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
    Exception::USER_MORE_FACTORS_REQUIRED => [
        'name' => Exception::USER_MORE_FACTORS_REQUIRED,
        'description' => 'More factors are required to complete the sign in process.',
        'code' => 401,
    ],
    Exception::USER_CHALLENGE_REQUIRED => [
        'name' => Exception::USER_CHALLENGE_REQUIRED,
        'description' => 'A recently succeessful challenge is required to complete this action. A challenge is considered recent for 5 minutes.',
        'code' => 401,
    ],
    Exception::USER_OAUTH2_BAD_REQUEST => [
        'name' => Exception::USER_OAUTH2_BAD_REQUEST,
        'description' => 'OAuth2 provider rejected the bad request.',
        'code' => 400,
    ],
    Exception::USER_OAUTH2_UNAUTHORIZED => [
        'name' => Exception::USER_OAUTH2_UNAUTHORIZED,
        'description' => 'OAuth2 provider rejected the unauthorized request.',
        'code' => 401,
    ],
    Exception::USER_OAUTH2_PROVIDER_ERROR => [
        'name' => Exception::USER_OAUTH2_PROVIDER_ERROR,
        'description' => 'OAuth2 provider returned some error.',
        'code' => 424,
    ],
    Exception::USER_EMAIL_ALREADY_VERIFIED => [
        'name' => Exception::USER_EMAIL_ALREADY_VERIFIED,
        'description' => 'User email is already verified',
        'code' => 409,
    ],
    Exception::USER_PHONE_ALREADY_VERIFIED => [
        'name' => Exception::USER_PHONE_ALREADY_VERIFIED,
        'description' => 'User phone is already verified',
        'code' => 409
    ],
    Exception::USER_DELETION_PROHIBITED => [
        'name' => Exception::USER_DELETION_PROHIBITED,
        'description' => 'User deletion is not allowed for users with active memberships. Please delete all confirmed memberships before deleting the account.',
        'code' => 400
    ],
    Exception::USER_TARGET_NOT_FOUND => [
        'name' => Exception::USER_TARGET_NOT_FOUND,
        'description' => 'The target could not be found.',
        'code' => 404,
    ],
    Exception::USER_TARGET_ALREADY_EXISTS => [
        'name' => Exception::USER_TARGET_ALREADY_EXISTS,
        'description' => 'A target with the same ID already exists.',
        'code' => 409,
    ],
    Exception::USER_API_KEY_AND_SESSION_SET => [
        'name' => Exception::USER_API_KEY_AND_SESSION_SET,
        'description' => 'API key and session used in the same request. Use either `setSession` or `setKey`. Learn about which authentication method to use in the SSR docs: https://appwrite.io/docs/products/auth/server-side-rendering',
        'code' => 403,
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
        'description' => 'The team invitation secret is invalid. Please request  a new invitation and try again.',
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
    Exception::TEAM_ALREADY_EXISTS => [
        'name' => Exception::TEAM_ALREADY_EXISTS,
        'description' => 'Team with requested ID already exists. Please choose a different ID and try again.',
        'code' => 409,
    ],

    /** Membership */
    Exception::MEMBERSHIP_NOT_FOUND => [
        'name' => Exception::MEMBERSHIP_NOT_FOUND,
        'description' => 'Membership with the requested ID could not be found.',
        'code' => 404,
    ],
    Exception::MEMBERSHIP_ALREADY_CONFIRMED => [
        'name' => Exception::MEMBERSHIP_ALREADY_CONFIRMED,
        'description' => 'Membership is already confirmed.',
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
    Exception::STORAGE_FILE_ALREADY_EXISTS => [
        'name' => Exception::STORAGE_FILE_ALREADY_EXISTS,
        'description' => 'A storage file with the requested ID already exists.',
        'code' => 409,
    ],
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
        'description' => 'A storage bucket with the requested ID already exists. Try again with a different ID or use ID.unique() to generate a unique ID.',
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
    Exception::STORAGE_INVALID_APPWRITE_ID => [
        'name' => Exception::STORAGE_INVALID_APPWRITE_ID,
        'description' => 'The value for x-appwrite-id header is invalid. Please check the value of the x-appwrite-id header is a valid id and not unique().',
        'code' => 400,
    ],
    Exception::STORAGE_FILE_NOT_PUBLIC => [
        'name' => Exception::STORAGE_FILE_NOT_PUBLIC,
        'description' => 'The requested file is not publicly readable.',
        'code' => 403,
    ],

    /** VCS */
    Exception::INSTALLATION_NOT_FOUND => [
        'name' => Exception::INSTALLATION_NOT_FOUND,
        'description' => 'Installation with the requested ID could not be found. Check to see if the ID is correct, or create the installation.',
        'code' => 404,
    ],
    Exception::PROVIDER_REPOSITORY_NOT_FOUND => [
        'name' => Exception::PROVIDER_REPOSITORY_NOT_FOUND,
        'description' => 'VCS (Version Control System) repository with the requested ID could not be found. Check to see if the ID is correct, and if it belongs to installationId you provided.',
        'code' => 404,
    ],
    Exception::REPOSITORY_NOT_FOUND => [
        'name' => Exception::REPOSITORY_NOT_FOUND,
        'description' => 'Repository with the requested ID could not be found. Check to see if the ID is correct, or create the respository.',
        'code' => 404,
    ],
    Exception::PROVIDER_CONTRIBUTION_CONFLICT => [
        'name' => Exception::PROVIDER_CONTRIBUTION_CONFLICT,
        'description' => 'External contribution is already authorized.',
        'code' => 409,
    ],
    Exception::GENERAL_PROVIDER_FAILURE => [
        'name' => Exception::GENERAL_PROVIDER_FAILURE,
        'description' => 'VCS (Version Control System) provider failed to proccess the request. We believe this is an error with the VCS provider. Try again, or contact support for more information.',
        'code' => 400,
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
    Exception::FUNCTION_ENTRYPOINT_MISSING => [
        'name' => Exception::FUNCTION_RUNTIME_UNSUPPORTED,
        'description' => 'Entrypoint for your Appwrite Function is missing. Please specify it when making deployment or update the entrypoint under your function\'s "Settings" > "Configuration" > "Entrypoint".',
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
    Exception::DATABASE_TIMEOUT => [
        'name' => Exception::DATABASE_TIMEOUT,
        'description' => 'Database timed out. Try adjusting your queries or adding an index.',
        'code' => 408
    ],

    /** Collections */
    Exception::COLLECTION_NOT_FOUND => [
        'name' => Exception::COLLECTION_NOT_FOUND,
        'description' => 'Collection with the requested ID could not be found.',
        'code' => 404,
    ],
    Exception::COLLECTION_ALREADY_EXISTS => [
        'name' => Exception::COLLECTION_ALREADY_EXISTS,
        'description' => 'A collection with the requested ID already exists. Try again with a different ID or use ID.unique() to generate a unique ID.',
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
    Exception::DOCUMENT_MISSING_DATA => [
        'name' => Exception::DOCUMENT_MISSING_DATA,
        'description' => 'The document data is missing. Try again with document data populated',
        'code' => 400,
    ],
    Exception::DOCUMENT_MISSING_PAYLOAD => [
        'name' => Exception::DOCUMENT_MISSING_PAYLOAD,
        'description' => 'The document data and permissions are missing. You must provide either document data or permissions to be updated.',
        'code' => 400,
    ],
    Exception::DOCUMENT_ALREADY_EXISTS => [
        'name' => Exception::DOCUMENT_ALREADY_EXISTS,
        'description' => 'Document with the requested ID already exists. Try again with a different ID or use ID.unique() to generate a unique ID.',
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
        'description' => 'Attribute with the requested key already exists. Attribute keys must be unique, try again with a different key.',
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
        'description' => 'Index with the requested key already exists. Try again with a different key.',
        'code' => 409,
    ],
    Exception::INDEX_INVALID => [
        'name' => Exception::INDEX_INVALID,
        'description' => 'Index invalid.',
        'code' => 400,
    ],

    /** Project Errors */
    Exception::PROJECT_NOT_FOUND => [
        'name' => Exception::PROJECT_NOT_FOUND,
        'description' => 'Project with the requested ID could not be found. Please check the value of the X-Appwrite-Project header to ensure the correct project ID is being used.',
        'code' => 404,
    ],
    Exception::PROJECT_ALREADY_EXISTS => [
        'name' => Exception::PROJECT_ALREADY_EXISTS,
        'description' => 'Project with the requested ID already exists. Try again with a different ID or use ID.unique() to generate a unique ID.',
        'code' => 409,
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
    Exception::ROUTER_HOST_NOT_FOUND => [
        'name' => Exception::ROUTER_HOST_NOT_FOUND,
        'description' => 'Host is not trusted. This could occur because you have not configured a custom domain. Add a custom domain to your project first and try again.',
        'code' => 404,
    ],
    Exception::ROUTER_DOMAIN_NOT_CONFIGURED => [
        'name' => Exception::ROUTER_DOMAIN_NOT_CONFIGURED,
        'description' => '_APP_DOMAIN, _APP_DOMAIN_TARGET, and _APP_DOMAIN_FUNCTIONS environment variables have not been configured. Please configure the domain environment variables before accessing the Appwrite Console via any IP address or hostname other than localhost. This value could be an IP like 203.0.113.0 or a hostname like example.com.',
        'code' => 500,
    ],
    Exception::RULE_RESOURCE_NOT_FOUND => [
        'name' => Exception::RULE_RESOURCE_NOT_FOUND,
        'description' => 'Resource could not be found. Please check if the resourceId and resourceType are correct, or if the resource actually exists.',
        'code' => 404,
    ],
    Exception::RULE_NOT_FOUND => [
        'name' => Exception::RULE_NOT_FOUND,
        'description' => 'Rule with the requested ID could not be found. Please check if the ID provided is correct or if the rule actually exists.',
        'code' => 404,
    ],
    Exception::RULE_ALREADY_EXISTS => [
        'name' => Exception::RULE_ALREADY_EXISTS,
        'description' => 'Domain is already used. Please try again with a different domain.',
        'code' => 409,
    ],
    Exception::RULE_VERIFICATION_FAILED => [
        'name' => Exception::RULE_VERIFICATION_FAILED,
        'description' => 'Domain verification failed. Please check if your DNS records are correct and try again.',
        'code' => 401,
        'publish' => true
    ],
    Exception::PROJECT_SMTP_CONFIG_INVALID => [
        'name' => Exception::PROJECT_SMTP_CONFIG_INVALID,
        'description' => 'Provided SMTP config is invalid. Please check the configured values and try again.',
        'code' => 400,
    ],
    Exception::PROJECT_TEMPLATE_DEFAULT_DELETION => [
        'name' => Exception::PROJECT_TEMPLATE_DEFAULT_DELETION,
        'description' => 'You can\'t delete default template. If you are trying to reset your template changes, you can ignore this error as it\'s already been reset.',
        'code' => 401,
    ],
    Exception::PROJECT_REGION_UNSUPPORTED => [
        'name' => Exception::PROJECT_REGION_UNSUPPORTED,
        'description' => 'The requested region is either inactive or unsupported. Please check the value of the _APP_REGIONS environment variable.',
        'code' => 400,
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
    Exception::VARIABLE_NOT_FOUND => [
        'name' => Exception::VARIABLE_NOT_FOUND,
        'description' => 'Variable with the requested ID could not be found.',
        'code' => 404,
    ],
    Exception::VARIABLE_ALREADY_EXISTS => [
        'name' => Exception::VARIABLE_ALREADY_EXISTS,
        'description' => 'Variable with the same ID already exists in this project. Try again with a different ID.',
        'code' => 409,
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

    /** Migrations */
    Exception::MIGRATION_NOT_FOUND => [
        'name' => Exception::MIGRATION_NOT_FOUND,
        'description' => 'Migration with the requested ID could not be found. Please verify that the provided ID is correct and try again.',
        'code' => 404,
    ],
    Exception::MIGRATION_ALREADY_EXISTS => [
        'name' => Exception::MIGRATION_ALREADY_EXISTS,
        'description' => 'Migration with the requested ID already exists. Try again with a different ID.',
        'code' => 409,
    ],
    Exception::MIGRATION_IN_PROGRESS => [
        'name' => Exception::MIGRATION_IN_PROGRESS,
        'description' => 'Migration is already in progress. You can check the status of the migration in your Appwrite Console\'s "Settings" > "Migrations".',
        'code' => 409,
    ],

    /** Realtime */
    Exception::REALTIME_MESSAGE_FORMAT_INVALID => [
        'name' => Exception::REALTIME_MESSAGE_FORMAT_INVALID,
        'description' => 'Message format is not valid.',
        'code' => 1003,
    ],
    Exception::REALTIME_POLICY_VIOLATION => [
        'name' => Exception::REALTIME_POLICY_VIOLATION,
        'description' => 'Policy violation.',
        'code' => 1008,
    ],
    Exception::REALTIME_TOO_MANY_MESSAGES => [
        'name' => Exception::REALTIME_TOO_MANY_MESSAGES,
        'description' => 'Too many messages.',
        'code' => 1013,
    ],
    Exception::MIGRATION_PROVIDER_ERROR => [
        'name' => Exception::MIGRATION_PROVIDER_ERROR,
        'description' => 'An error occurred on the provider\'s side. Please try again later.',
        'code' => 400,
    ],

    /** Health */
    Exception::HEALTH_QUEUE_SIZE_EXCEEDED => [
        'name' => Exception::HEALTH_QUEUE_SIZE_EXCEEDED,
        'description' => 'Queue size threshold hit.',
        'code' => 503,
        'publish' => false
    ],

    Exception::HEALTH_CERTIFICATE_EXPIRED => [
        'name' => Exception::HEALTH_CERTIFICATE_EXPIRED,
        'description' => 'The SSL certificate for the specified domain has expired and is no longer valid.',
        'code' => 404,
    ],

    Exception::HEALTH_INVALID_HOST => [
        'name' => Exception::HEALTH_INVALID_HOST,
        'description' => 'Failed to establish a connection to the specified domain. Please verify the domain name and ensure that the server is running and accessible.',
        'code' => 404,
    ],

    /** Providers */
    Exception::PROVIDER_NOT_FOUND => [
        'name' => Exception::PROVIDER_NOT_FOUND,
        'description' => 'Provider with the requested ID could not be found.',
        'code' => 404,
    ],
    Exception::PROVIDER_ALREADY_EXISTS => [
        'name' => Exception::PROVIDER_ALREADY_EXISTS,
        'description' => 'Provider with the requested ID already exists.',
        'code' => 409,
    ],
    Exception::PROVIDER_INCORRECT_TYPE => [
        'name' => Exception::PROVIDER_INCORRECT_TYPE,
        'description' => 'Provider with the requested ID is of the incorrect type.',
        'code' => 400,
    ],
    Exception::PROVIDER_MISSING_CREDENTIALS => [
        'name' => Exception::PROVIDER_MISSING_CREDENTIALS,
        'description' => 'Provider with the requested ID is missing credentials.',
        'code' => 400,
    ],

    /** Topics */
    Exception::TOPIC_NOT_FOUND => [
        'name' => Exception::TOPIC_NOT_FOUND,
        'description' => 'Topic with the request ID could not be found.',
        'code' => 404,
    ],
    Exception::TOPIC_ALREADY_EXISTS => [
        'name' => Exception::TOPIC_ALREADY_EXISTS,
        'description' => 'Topic with the request ID already exists.',
        'code' => 409,
    ],

    /** Subscribers */
    Exception::SUBSCRIBER_NOT_FOUND => [
        'name' => Exception::SUBSCRIBER_NOT_FOUND,
        'description' => 'Subscriber with the request ID could not be found.',
        'code' => 404,
    ],
    Exception::SUBSCRIBER_ALREADY_EXISTS => [
        'name' => Exception::SUBSCRIBER_ALREADY_EXISTS,
        'description' => 'Subscriber with the request ID already exists.',
        'code' => 409,
    ],

    /** Messages */
    Exception::MESSAGE_NOT_FOUND => [
        'name' => Exception::MESSAGE_NOT_FOUND,
        'description' => 'Message with the requested ID could not be found.',
        'code' => 404,
    ],
    Exception::MESSAGE_MISSING_TARGET => [
        'name' => Exception::MESSAGE_MISSING_TARGET,
        'description' => 'Message with the requested ID has no recipients (topics or users or targets).',
        'code' => 400,
    ],
    Exception::MESSAGE_ALREADY_SENT => [
        'name' => Exception::MESSAGE_ALREADY_SENT,
        'description' => 'Message with the requested ID has already been sent.',
        'code' => 400,
    ],
    Exception::MESSAGE_ALREADY_PROCESSING => [
        'name' => Exception::MESSAGE_ALREADY_PROCESSING,
        'description' => 'Message with the requested ID is already being processed.',
        'code' => 400,
    ],
    Exception::MESSAGE_ALREADY_FAILED => [
        'name' => Exception::MESSAGE_ALREADY_FAILED,
        'description' => 'Message with the requested ID has already failed.',
        'code' => 400,
    ],
    Exception::MESSAGE_ALREADY_SCHEDULED => [
        'name' => Exception::MESSAGE_ALREADY_SCHEDULED,
        'description' => 'Message with the requested ID has already been scheduled for delivery.',
        'code' => 400,
    ],
    Exception::MESSAGE_TARGET_NOT_EMAIL => [
        'name' => Exception::MESSAGE_TARGET_NOT_EMAIL,
        'description' => 'Message with the target ID is not an email target.',
        'code' => 400,
    ],
    Exception::MESSAGE_TARGET_NOT_SMS => [
        'name' => Exception::MESSAGE_TARGET_NOT_SMS,
        'description' => 'Message with the target ID is not an SMS target.',
        'code' => 400,
    ],
    Exception::MESSAGE_TARGET_NOT_PUSH => [
        'name' => Exception::MESSAGE_TARGET_NOT_PUSH,
        'description' => 'Message with the target ID is not a push target.',
        'code' => 400,
    ],
    Exception::MESSAGE_MISSING_SCHEDULE => [
        'name' => Exception::MESSAGE_MISSING_SCHEDULE,
        'description' => 'Message can not have status ' . MessageStatus::SCHEDULED . ' without a schedule.',
        'code' => 400,
    ],
    Exception::SCHEDULE_NOT_FOUND => [
        'name' => Exception::SCHEDULE_NOT_FOUND,
        'description' => 'Schedule with the requested ID could not be found.',
        'code' => 404,
    ],

    /** Targets */
    Exception::TARGET_PROVIDER_INVALID_TYPE => [
        'name' => Exception::TARGET_PROVIDER_INVALID_TYPE,
        'description' => 'Target has an invalid provider type.',
        'code' => 400,
    ],
];
