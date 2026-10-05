<?php

declare(strict_types=1);

namespace Utopia\DNS;

/**
 * The transport protocol a DNS query arrived over.
 */
enum Protocol: string
{
    case Udp = 'udp';
    case Tcp = 'tcp';
    case Https = 'https';

    /**
     * Maximum response size the protocol can carry: 512 bytes for plain UDP
     * per RFC 1035 Section 4.2.1, 65535 bytes for stream transports.
     */
    public function maxResponseSize(): int
    {
        return match ($this) {
            self::Udp => Message::MAX_UDP_SIZE,
            self::Tcp, self::Https => Message::MAX_SIZE,
        };
    }
}
