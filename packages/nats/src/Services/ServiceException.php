<?php

declare(strict_types=1);

namespace Utopia\NATS\Services;

/**
 * Thrown by an endpoint handler to reply with a specific NATS micro
 * error code and description instead of the generic 500.
 */
final class ServiceException extends \RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
