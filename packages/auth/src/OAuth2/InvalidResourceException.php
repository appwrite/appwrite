<?php

declare(strict_types=1);

namespace Utopia\Auth\OAuth2;

class InvalidResourceException extends \InvalidArgumentException
{
    /**
     * RFC 8707 Section 2.
     */
    public const ERROR_CODE = 'invalid_target';
}
