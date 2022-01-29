<?php

namespace Appwrite\Extend\Exception;

class Exception extends \Exception
{
    /**
     * Error Codes
     */
    const TYPE_NONE = '';

    /** API */
    const TYPE_PROJECT_NOT_FOUND       = 'project_not_found';
    const TYPE_PROJECT_UNKNOWN         = 'project_unknown';
    const TYPE_INVALID_ORIGIN          = 'invalid_origin';
    const TYPE_SERVICE_DISABLED        = 'service_disabled';
    const TYPE_UNAUTHORIZED_SCOPE      = 'unauthorized_scope';
    const TYPE_PASSWORD_RESET_REQUIRED = 'password_reset_required';
    const TYPE_STORAGE_ERROR           = 'storage_error';
    const TYPE_RATE_LIMIT_EXCEEDED     = 'rate_limit_exceeded';
    const TYPE_SMTP_DISABLED           = 'smtp_disabled';

    /** Users **/
    const TYPE_EMAIL_NOT_WHITELISTED   = 'email_not_whitelisted';
    const TYPE_IP_NOT_WHITELISTED      = 'ip_not_whitelisted';
    const TYPE_INVALID_CREDENTIALS     = 'invalid_credentials';
    const TYPE_INVALID_TOKEN           = 'invalid_token';
    const TYPE_JWT_VERIFICATION_FAILED = 'jwt_verification_failed';
    const TYPE_ANONYMOUS_CONSOLE_USER  = 'anonymous_console_user';
    const TYPE_SESSION_NOT_FOUND       = 'session_not_found';
    const TYPE_SESSION_ALREADY_EXISTS  = 'session_already_exists';
    const TYPE_USER_LIMIT_EXCEEDED     = 'user_limit_exceeded';
    const TYPE_USER_ALREADY_EXISTS     = 'user_already_exists';
    const TYPE_USER_BLOCKED            = 'user_blocked';
    const TYPE_USER_CREATION_FAILED    = 'user_creation_failed';
    const TYPE_USER_NOT_FOUND          = 'user_not_found';
    const TYPE_EMAIL_ALREADY_EXISTS    = 'email_already_exists';
    const TYPE_PASSWORD_MISMATCH       = 'password_mismatch';
    const TYPE_AUTH_METHOD_UNSUPPORTED = 'auth_method_unsupported';

    /** OAuth **/
    const TYPE_PROVIDER_DISABLED          = 'provider_disabled';
    const TYPE_PROVIDER_NOT_SUPPORTED     = 'provider_not_supported';
    const TYPE_INVALID_LOGIN_STATE_PARAMS = 'invalid_login_state_params';
    const TYPE_INVALID_SUCCESS_URL        = 'invalid_success_url';
    const TYPE_INVALID_FAILURE_URL        = 'invalid_failure_url';
    const TYPE_OAUTH_ACCESS_TOKEN_FAILED  = 'oauth_access_token_failed';
    const TYPE_MISSING_PROVIDER_ID        = 'missing_provider_id';

    private $errorCode = '';

    public function __construct(string $message, int $code = 0, string $errorCode = Exception::TYPE_NONE, \Throwable $previous = null)
    {
        $this->errorCode = $errorCode;

        parent::__construct($message, $code, $previous);
    }

    /**
     * @return string
     */ 
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}