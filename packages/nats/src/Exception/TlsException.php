<?php

declare(strict_types=1);

namespace Utopia\NATS\Exception;

/**
 * The TLS handshake failed; retrying with the same configuration fails the same way.
 */
class TlsException extends ConnectionException
{
}
