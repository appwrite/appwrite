<?php

namespace Appwrite\Alert\Probe;

use Utopia\Client\Destinations;
use Utopia\Client\Destinations\IPRange;

/**
 * Where probes may connect: anywhere outside the denied networks, and never
 * through a proxy, which would hide the address actually reached. Checked on
 * the resolved address of every connection, including redirects and the DNS
 * resolver, so a name that resolves into a denied network is refused before
 * it is dialled.
 */
final readonly class Egress implements Destinations
{
    /**
     * What `private` stands for: private, loopback, link-local (cloud metadata
     * at 169.254.169.254 included), CGNAT, unique local and unspecified ranges.
     */
    private const array PRIVATE = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '::/128',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
    ];

    /** @var list<IPRange> */
    private array $denied;

    public function __construct(IPRange ...$denied)
    {
        $this->denied = \array_values($denied);
    }

    /**
     * Reads a comma-separated list of CIDRs or addresses, where `private` stands for the ranges above.
     *
     * @throws \InvalidArgumentException When an entry is not an address or a range
     */
    public static function parse(string $value): self
    {
        $denied = [];
        foreach (\explode(',', $value) as $entry) {
            $entry = \trim($entry);
            if ($entry === '') {
                continue;
            }
            foreach ($entry === 'private' ? self::PRIVATE : [$entry] as $range) {
                $denied[] = new IPRange($range);
            }
        }

        return new self(...$denied);
    }

    public function allows(string $address): bool
    {
        $packed = @\inet_pton($address);
        // An IPv4-mapped IPv6 address reaches the IPv4 one, so it is checked as that.
        if ($packed !== false && \strlen($packed) === 16 && \str_starts_with($packed, \str_repeat("\0", 10) . "\xff\xff")) {
            $address = (string) \inet_ntop(\substr($packed, 12));
        }

        foreach ($this->denied as $range) {
            if ($range->contains($address)) {
                return false;
            }
        }

        return true;
    }

    public function permitsProxy(): bool
    {
        return false;
    }
}
