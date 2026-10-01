<?php

declare(strict_types=1);

namespace Utopia\Client\Destinations;

use Utopia\Client\Destinations;

/**
 * Globally routable addresses only: never a private, loopback, link-local, multicast or
 * otherwise reserved one (cloud metadata, the host, the internal network), unless one of
 * the given ranges explicitly admits it. For fetching a URL a user supplied.
 */
final readonly class PublicInternet implements Destinations
{
    /**
     * Reserved ranges PHP's FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE miss.
     * The IPv6 ones include the IPv4-mapped, translated and 6to4 ranges that could
     * smuggle a private IPv4 destination past an IPv6-only check.
     */
    private const array RESERVED = [
        '100.64.0.0/10',        // CGNAT (RFC 6598)
        '192.0.0.0/24',         // IETF protocol assignments
        '192.0.2.0/24',         // TEST-NET-1
        '198.18.0.0/15',        // Benchmarking
        '198.51.100.0/24',      // TEST-NET-2
        '203.0.113.0/24',       // TEST-NET-3
        '224.0.0.0/4',          // Multicast
        '255.255.255.255/32',   // Broadcast
        '::/128',               // Unspecified
        '::ffff:0:0/96',        // IPv4-mapped (::ffff:127.0.0.1)
        '64:ff9b::/96',         // IPv4/IPv6 translation
        '64:ff9b:1::/48',       // Local-use IPv4/IPv6 translation
        '100::/64',             // Discard
        '2001::/32',            // Teredo
        '2001:db8::/32',        // Documentation
        '2002::/16',            // 6to4 (2002:7f00::/24 is 127.0.0.0/8)
        'ff00::/8',             // Multicast
    ];

    /** @var list<IPRange> */
    private array $reserved;

    /** @var list<IPRange> */
    private array $alsoAllowed;

    /**
     * @param IPRange ...$alsoAllowed Private or reserved ranges this destination admits anyway
     */
    public function __construct(IPRange ...$alsoAllowed)
    {
        $this->reserved = \array_map(fn (string $range): IPRange => new IPRange($range), self::RESERVED);
        $this->alsoAllowed = \array_values($alsoAllowed);
    }

    public function allows(string $address): bool
    {
        foreach ($this->alsoAllowed as $range) {
            if ($range->contains($address)) {
                return true;
            }
        }

        if (\filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        return array_all($this->reserved, fn (IPRange $range): bool => !$range->contains($address));
    }

    public function permitsProxy(): bool
    {
        return false;
    }
}
