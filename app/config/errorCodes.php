<?php

/**
 * List of server wide error codes and their respective messages. 
 */

use Appwrite\Extend\Exception;

return [
    Exception::TYPE_NONE => [
        'name' => Exception::TYPE_NONE,
        'description' => 'Generic error',
        'statusCode' => 500,
    ],
    Exception::TYPE_PROJECT_NOT_FOUND => [
        'name' => Exception::TYPE_PROJECT_NOT_FOUND,
        'description' => 'Project not found',
        'statusCode' => 404,
    ],
    Exception::TYPE_PROJECT_UNKNOWN => [
        'name' => Exception::TYPE_PROJECT_UNKNOWN,
        'description' => 'Project unknown',
        'statusCode' => 500,
    ],
    Exception::TYPE_INVALID_ORIGIN => [
        'name' => Exception::TYPE_INVALID_ORIGIN,
        'description' => 'Invalid origin',
        'statusCode' => 403,
    ],
    Exception::TYPE_SERVICE_DISABLED => [
        'name' => Exception::TYPE_SERVICE_DISABLED,
        'description' => 'Service disabled',
        'statusCode' => 403,
    ],
    Exception::TYPE_UNAUTHORIZED_SCOPE => [
        'name' => Exception::TYPE_UNAUTHORIZED_SCOPE,
        'description' => 'Unauthorized scope',
        'statusCode' => 403,
    ],
    Exception::TYPE_PASSWORD_RESET_REQUIRED => [
        'name' => Exception::TYPE_PASSWORD_RESET_REQUIRED,
        'description' => 'Password reset required',
        'statusCode' => 403,
    ],
    Exception::TYPE_STORAGE_ERROR => [
        'name' => Exception::TYPE_STORAGE_ERROR,
        'description' => 'Storage error',
        'statusCode' => 500,
    ],
    Exception::TYPE_RATE_LIMIT_EXCEEDED => [
        'name' => Exception::TYPE_RATE_LIMIT_EXCEEDED,
        'description' => 'Rate limit exceeded',
        'statusCode' => 429,
    ],
    Exception::TYPE_SMTP_DISABLED => [
        'name' => Exception::TYPE_SMTP_DISABLED,
        'description' => 'SMTP disabled',
        'statusCode' => 500,
    ],
    Exception::TYPE_EMAIL_NOT_WHITELISTED => [
        'name' => Exception::TYPE_EMAIL_NOT_WHITELISTED,
        'description' => 'Email not whitelisted',
        'statusCode' => 403,
    ],
    Exception::TYPE_IP_NOT_WHITELISTED => [
        'name' => Exception::TYPE_IP_NOT_WHITELISTED,
        'description' => 'IP Address not whitelisted',
        'statusCode' => 404,
    ],
    Exception::TYPE_INVALID_CREDENTIALS => [
        'name' => Exception::TYPE_INVALID_CREDENTIALS,
        'description' => 'Invalid credentials',
        'statusCode' => 404,
    ],
    Exception::TYPE_INVALID_TOKEN => [
        'name' => Exception::TYPE_INVALID_TOKEN,
        'description' => 'Invalid token',
        'statusCode' => 403,
    ],
    Exception::TYPE_JWT_VERIFICATION_FAILED => [
        'name' => Exception::TYPE_JWT_VERIFICATION_FAILED,
        'description' => 'Invalid refresh token',
        'statusCode' => 403,
    ],
    Exception::TYPE_ANONYMOUS_CONSOLE_USER => [
        'name' => Exception::TYPE_ANONYMOUS_CONSOLE_USER,
        'description' => 'Anonymous session cannot be created for the console project.',
        'statusCode' => 403,
    ],
    Exception::TYPE_SESSION_NOT_FOUND => [
        'name' => Exception::TYPE_SESSION_NOT_FOUND,
        'description' => 'Session not found',
        'statusCode' => 400,
    ],
    Exception::TYPE_SESSION_ALREADY_EXISTS => [
        'name' => Exception::TYPE_SESSION_ALREADY_EXISTS,
        'description' => 'Session already exists',
        'statusCode' => 403,
    ],
    Exception::TYPE_USER_LIMIT_EXCEEDED => [
        'name' => Exception::TYPE_USER_LIMIT_EXCEEDED,
        'description' => 'Session expired',
        'statusCode' => 403,
    ],
    Exception::TYPE_USER_ALREADY_EXISTS => [
        'name' => Exception::TYPE_USER_ALREADY_EXISTS,
        'description' => 'Session expired',
        'statusCode' => 403,
    ],
    Exception::TYPE_USER_BLOCKED => [
        'name' => Exception::TYPE_USER_BLOCKED,
        'description' => 'Session expired',
        'statusCode' => 403,
    ],
    Exception::TYPE_USER_CREATION_FAILED => [
        'name' => Exception::TYPE_USER_CREATION_FAILED,
        'description' => 'Session expired',
        'statusCode' => 403,
    ],
    Exception::TYPE_USER_NOT_FOUND => [
        'name' => Exception::TYPE_USER_NOT_FOUND,
        'description' => 'Session expired',
        'statusCode' => 403,
    ],
    Exception::TYPE_EMAIL_ALREADY_EXISTS => [
        'name' => Exception::TYPE_EMAIL_ALREADY_EXISTS,
        'description' => 'Session expired',
        'statusCode' => 403,
    ],
    Exception::TYPE_PASSWORD_MISMATCH => [
        'name' => Exception::TYPE_PASSWORD_MISMATCH,
        'description' => 'Session expired',
        'statusCode' => 403,
    ],
    Exception::TYPE_AUTH_METHOD_UNSUPPORTED => [
        'name' => Exception::TYPE_AUTH_METHOD_UNSUPPORTED,
        'description' => 'Session expired',
        'statusCode' => 403,
    ],
    Exception::TYPE_PROVIDER_DISABLED => [
        'name' => Exception::TYPE_PROVIDER_DISABLED,
        'description' => 'Session expired',
        'statusCode' => 403,
    ],
    Exception::TYPE_PROVIDER_NOT_SUPPORTED => [
        'name' => Exception::TYPE_PROVIDER_NOT_SUPPORTED,
        'description' => 'Session expired',
        'statusCode' => 403,
    ],
    Exception::TYPE_INVALID_LOGIN_STATE_PARAMS => [
        'name' => Exception::TYPE_INVALID_LOGIN_STATE_PARAMS,
        'description' => 'Session expired',
        'statusCode' => 403,
    ],
    Exception::TYPE_INVALID_SUCCESS_URL => [
        'name' => Exception::TYPE_INVALID_SUCCESS_URL,
        'description' => 'Session expired',
        'statusCode' => 403,
    ],
    Exception::TYPE_INVALID_FAILURE_URL => [
        'name' => Exception::TYPE_INVALID_FAILURE_URL,
        'description' => 'Session expired',
        'statusCode' => 403,
    ],
    Exception::TYPE_OAUTH_ACCESS_TOKEN_FAILED => [
        'name' => Exception::TYPE_OAUTH_ACCESS_TOKEN_FAILED,
        'description' => 'Session expired',
        'statusCode' => 403,
    ],
    Exception::TYPE_MISSING_PROVIDER_ID => [
        'name' => Exception::TYPE_MISSING_PROVIDER_ID,
        'description' => 'Session expired',
        'statusCode' => 403,
    ]
];