<?php

declare(strict_types=1);

namespace Utopia\Storage\Exception;

/**
 * The storage service answered, but with an error or a malformed response.
 * The HTTP status is the exception code; `errorCode` carries the service's
 * own error identifier (for example an S3 error code) when one was returned.
 */
class RemoteException extends StorageException
{
    public function __construct(
        string $message,
        int $code = 0,
        public readonly ?string $errorCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
