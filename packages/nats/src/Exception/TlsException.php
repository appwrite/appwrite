<?php

declare(strict_types=1);

namespace Utopia\NATS\Exception;

/**
 * The TLS handshake failed after the socket connected: a certificate the peer
 * does not trust, a name that does not match, or no protocol in common.
 * Connecting again presents the same configuration and fails the same way.
 */
class TlsException extends ConnectionException
{
}
