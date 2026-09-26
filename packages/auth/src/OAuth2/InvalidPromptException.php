<?php

declare(strict_types=1);

namespace Utopia\Auth\OAuth2;

/**
 * Raised when an OpenID Connect prompt request parameter is invalid.
 */
class InvalidPromptException extends \InvalidArgumentException
{
    /**
     * OpenID Connect Core Section 3.1.2.6.
     */
    public const string ERROR_CODE = 'invalid_request';
}
