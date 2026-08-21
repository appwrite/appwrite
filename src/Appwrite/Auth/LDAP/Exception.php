<?php

namespace Appwrite\Auth\LDAP;

use Appwrite\Extend\Exception as AppwriteException;

/**
 * Raised when an LDAP configuration is invalid, or when a directory cannot be
 * reached or queried.
 *
 * This is never used to report a failed authentication: a wrong password is a
 * normal outcome, not an error, and must surface as the same invalid-credentials
 * response as any other sign-in method so that a directory cannot be probed for
 * valid usernames.
 */
class Exception extends AppwriteException
{
    public function __construct(string $message, string $type = AppwriteException::GENERAL_SERVER_ERROR, ?\Throwable $previous = null)
    {
        parent::__construct($type, $message, previous: $previous);
    }
}
