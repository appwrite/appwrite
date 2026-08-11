<?php

namespace Appwrite\Auth\SAML;

use Appwrite\Extend\Exception as AppwriteException;

/**
 * Raised when a SAML configuration is invalid, or when an incoming SAML
 * response fails validation.
 *
 * Messages on this exception surface to the project admin through the OAuth2
 * failure redirect, so they should name the misconfiguration and, where
 * possible, the fix. They must never echo attacker-controlled assertion
 * content back to the caller.
 */
class Exception extends AppwriteException
{
    public function __construct(string $message, string $type = AppwriteException::USER_OAUTH2_PROVIDER_ERROR, ?\Throwable $previous = null)
    {
        parent::__construct($type, $message, previous: $previous);
    }
}
