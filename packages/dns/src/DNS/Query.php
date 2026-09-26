<?php

declare(strict_types=1);

namespace Utopia\DNS;

/**
 * A DNS query together with its source: the decoded message, the client
 * address, and the transport protocol it arrived over.
 *
 * Resolver decorators use the source for cross-cutting concerns such as
 * tracing and rate limiting. Note that UDP source addresses are spoofable;
 * stream transports (TCP, HTTPS) carry verified peer addresses.
 */
final readonly class Query
{
    public function __construct(
        public Message $message,
        public string $ip,
        public int $port,
        public Protocol $protocol,
    ) {}
}
