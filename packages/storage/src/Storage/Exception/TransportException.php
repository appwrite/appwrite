<?php

declare(strict_types=1);

namespace Utopia\Storage\Exception;

/**
 * The HTTP client failed to deliver the request (DNS, connection, timeout).
 * The PSR-18 exception is attached as the previous throwable.
 */
class TransportException extends StorageException {}
