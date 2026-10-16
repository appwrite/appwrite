<?php

declare(strict_types=1);

namespace Utopia\Client\Destinations;

use InvalidArgumentException;

/**
 * An IPv4 or IPv6 address or CIDR range. A single address is its /32 or /128, so every
 * spelling of an address (::1, 0:0:0:0:0:0:0:1) is the same range.
 */
final readonly class IPRange
{
    private string $network;

    private int $prefix;

    /**
     * @throws InvalidArgumentException When the range is not an address or a CIDR with a numeric prefix in bounds
     */
    public function __construct(string $range)
    {
        $parts = \explode('/', \trim($range), 2);
        $network = @\inet_pton($parts[0]);

        if ($network === false) {
            throw new InvalidArgumentException("Not an IP address or range: '{$range}'.");
        }

        $bits = \strlen($network) * 8;
        $prefix = $parts[1] ?? (string) $bits;

        // "10.0.0.0/" or "10.0.0.0/x" must not read as /0, which would match everything
        if (!\ctype_digit($prefix) || (int) $prefix > $bits) {
            throw new InvalidArgumentException("Not an IP address or range: '{$range}'.");
        }

        $this->network = $network;
        $this->prefix = (int) $prefix;
    }

    public function contains(string $address): bool
    {
        $packed = @\inet_pton($address);

        if ($packed === false || \strlen($packed) !== \strlen($this->network)) {
            return false;
        }

        $bytes = \intdiv($this->prefix, 8);
        if (\substr($packed, 0, $bytes) !== \substr($this->network, 0, $bytes)) {
            return false;
        }

        $bits = $this->prefix % 8;
        if ($bits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $bits)) & 0xFF;

        return (\ord($packed[$bytes]) & $mask) === (\ord($this->network[$bytes]) & $mask);
    }
}
