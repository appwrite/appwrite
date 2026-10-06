<?php

declare(strict_types=1);

namespace Utopia\Auth\OAuth2;

/**
 * Raised when a Pushed Authorization Request request_uri is malformed or unknown.
 */
class InvalidRequestUriException extends \InvalidArgumentException
{
    /**
     * RFC 9126 uses OAuth2 invalid_request for malformed request_uri values.
     */
    public const string ERROR_CODE = 'invalid_request';
}
