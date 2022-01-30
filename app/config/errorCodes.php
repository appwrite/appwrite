<?php

/**
 * List of server wide error codes and their respective messages. 
 */

use Appwrite\Extend\Exception;

return [
    Exception::TYPE_PROJECT_NOT_FOUND => [
        'name' => Exception::TYPE_PROJECT_NOT_FOUND,
        'description' => 'The requested project could not be found. Please check the value of the X-Appwrite-Project header to ensure the correct project ID is being used.',
        'statusCode' => 404,
    ],
    Exception::TYPE_PROJECT_UNKNOWN => [
        'name' => Exception::TYPE_PROJECT_UNKNOWN,
        'description' => 'The project ID is either missing or not valid. Please check the value of the X-Appwrite-Project header to ensure the correct project ID is being used.',
        'statusCode' => 400,
    ],
    Exception::TYPE_INVALID_ORIGIN => [
        'name' => Exception::TYPE_INVALID_ORIGIN,
        'description' => 'The request originated from a non-whitelisted origin. If you trust this origin, please add it as a platform in the Appwrite console.',
        'statusCode' => 403,
    ],
    Exception::TYPE_SERVICE_DISABLED => [
        'name' => Exception::TYPE_SERVICE_DISABLED,
        'description' => 'The requested service is disabled. You can enable/disable a service from the Appwrite console or by contacting the project owner.',
        'statusCode' => 503,
    ],
    Exception::TYPE_UNAUTHORIZED_SCOPE => [
        'name' => Exception::TYPE_UNAUTHORIZED_SCOPE,
        'description' => 'The current user is not authorized to access the requested resource.',
        'statusCode' => 401,
    ],
    Exception::TYPE_PASSWORD_RESET_REQUIRED => [
        'name' => Exception::TYPE_PASSWORD_RESET_REQUIRED,
        'description' => 'The current user requires a password reset.',
        'statusCode' => 412,
    ],
    Exception::TYPE_STORAGE_ERROR => [
        'name' => Exception::TYPE_STORAGE_ERROR,
        'description' => 'Storage error',
        'statusCode' => 500,
    ],
    Exception::TYPE_RATE_LIMIT_EXCEEDED => [
        'name' => Exception::TYPE_RATE_LIMIT_EXCEEDED,
        'description' => 'Rate limit for the current endpoint has been exceeded. ',
        'statusCode' => 429,
    ],
    Exception::TYPE_SMTP_DISABLED => [
        'name' => Exception::TYPE_SMTP_DISABLED,
        'description' => 'SMTP is disabled on your Appwrite instance. Please contact your project ',
        'statusCode' => 503,
    ],
    Exception::TYPE_EMAIL_NOT_WHITELISTED => [
        'name' => Exception::TYPE_EMAIL_NOT_WHITELISTED,
        'description' => 'The user\'s email is not part of the whitelist. Please check the _APP_CONSOLE_WHITELIST_EMAILS environment variable of your Appwrite server.',
        'statusCode' => 401,
    ],
    Exception::TYPE_IP_NOT_WHITELISTED => [
        'name' => Exception::TYPE_IP_NOT_WHITELISTED,
        'description' => 'The user\'s IP address is not part of the whitelist. Please check the _APP_CONSOLE_WHITELIST_IPS environment variable of your Appwrite server.',
        'statusCode' => 401,
    ],
    Exception::TYPE_INVALID_CREDENTIALS => [
        'name' => Exception::TYPE_INVALID_CREDENTIALS,
        'description' => 'Invalid credentials. Please check the email and password.',
        'statusCode' => 401,
    ],
    Exception::TYPE_INVALID_TOKEN => [
        'name' => Exception::TYPE_INVALID_TOKEN,
        'description' => 'The used token is invalid.',
        'statusCode' => 401,
    ],
    Exception::TYPE_JWT_VERIFICATION_FAILED => [
        'name' => Exception::TYPE_JWT_VERIFICATION_FAILED,
        'description' => 'Invalid refresh token',
        'statusCode' => 403,
    ],
    Exception::TYPE_ANONYMOUS_CONSOLE_USER => [
        'name' => Exception::TYPE_ANONYMOUS_CONSOLE_USER,
        'description' => 'Anonymous session cannot be created for the console project.',
        'statusCode' => 401,
    ],
    Exception::TYPE_SESSION_NOT_FOUND => [
        'name' => Exception::TYPE_SESSION_NOT_FOUND,
        'description' => 'No valid session found.',
        'statusCode' => 404,
    ],
    Exception::TYPE_SESSION_ALREADY_EXISTS => [
        'name' => Exception::TYPE_SESSION_ALREADY_EXISTS,
        'description' => 'Cannot create anonymous session when there is an active session.',
        'statusCode' => 401,
    ],
    Exception::TYPE_USER_LIMIT_EXCEEDED => [
        'name' => Exception::TYPE_USER_LIMIT_EXCEEDED,
        'description' => 'The current project has exceeded the maximum number of users. Please check your user limit in the Appwrite console.',
        'statusCode' => 501,
    ],
    Exception::TYPE_USER_ALREADY_EXISTS => [
        'name' => Exception::TYPE_USER_ALREADY_EXISTS,
        'description' => 'A user with the same email ID already exists in your project.',
        'statusCode' => 409,
    ],
    Exception::TYPE_USER_BLOCKED => [
        'name' => Exception::TYPE_USER_BLOCKED,
        'description' => 'The current user has been blocked. Please contact the project administrator for more information.',
        'statusCode' => 401,
    ],
    Exception::TYPE_USER_CREATION_FAILED => [
        'name' => Exception::TYPE_USER_CREATION_FAILED,
        'description' => 'There was an internal server error while creating the user.',
        'statusCode' => 500,
    ],
    Exception::TYPE_USER_NOT_FOUND => [
        'name' => Exception::TYPE_USER_NOT_FOUND,
        'description' => 'User with the requested ID could not be found.',
        'statusCode' => 404,
    ],
    Exception::TYPE_EMAIL_ALREADY_EXISTS => [
        'name' => Exception::TYPE_EMAIL_ALREADY_EXISTS,
        'description' => 'Another user with the same email already exists in the current project.',
        'statusCode' => 409,
    ],
    Exception::TYPE_PASSWORD_MISMATCH => [
        'name' => Exception::TYPE_PASSWORD_MISMATCH,
        'description' => 'Passwords do not match. Please recheck.',
        'statusCode' => 400,
    ],
    Exception::TYPE_AUTH_METHOD_UNSUPPORTED => [
        'name' => Exception::TYPE_AUTH_METHOD_UNSUPPORTED,
        'description' => 'The requested authentication method is either disabled or unsupported.',
        'statusCode' => 501,
    ],
    Exception::TYPE_PROVIDER_DISABLED => [
        'name' => Exception::TYPE_PROVIDER_DISABLED,
        'description' => 'The chosen OAuth provider is disabled. Please contact your project administrator for more information.',
        'statusCode' => 412,
    ],
    Exception::TYPE_PROVIDER_UNSUPPORTED => [
        'name' => Exception::TYPE_PROVIDER_UNSUPPORTED,
        'description' => 'The chosen OAuth provider is unsupported.',
        'statusCode' => 501,
    ],
    Exception::TYPE_INVALID_LOGIN_STATE_PARAMS => [
        'name' => Exception::TYPE_INVALID_LOGIN_STATE_PARAMS,
        'description' => 'Failed to parse the login state params from the OAuth provider.',
        'statusCode' => 500,
    ],
    Exception::TYPE_INVALID_SUCCESS_URL => [
        'name' => Exception::TYPE_INVALID_SUCCESS_URL,
        'description' => 'Invalid URL received for OAuth success redirect.',
        'statusCode' => 400,
    ],
    Exception::TYPE_INVALID_FAILURE_URL => [
        'name' => Exception::TYPE_INVALID_FAILURE_URL,
        'description' => 'Invalid URL received for OAuth failure redirect.',
        'statusCode' => 400,
    ],
    Exception::TYPE_OAUTH_ACCESS_TOKEN_FAILED => [
        'name' => Exception::TYPE_OAUTH_ACCESS_TOKEN_FAILED,
        'description' => 'Failed to obtain access token from the OAuth provider.',
        'statusCode' => 500,
    ],
    Exception::TYPE_MISSING_USER_ID => [
        'name' => Exception::TYPE_MISSING_USER_ID,
        'description' => 'Failed to obtain user id from the OAuth provider.',
        'statusCode' => 400,
    ]
];