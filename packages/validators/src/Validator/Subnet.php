<?php

declare(strict_types=1);

namespace Utopia\Validator;

use InvalidArgumentException;
use Utopia\Validator;

/**
 * Validates that a value is an IPv4 or IPv6 address inside a CIDR range, or
 * equal to a single address when the range has no prefix length. Addresses of
 * the other family never match, so an IPv4-mapped IPv6 address is outside every
 * IPv4 range.
 */
class Subnet extends Validator
{
    private readonly string $network;

    private readonly int $prefix;

    /**
     * @param string $range CIDR range (10.0.0.0/8, fd00::/8) or a single address (10.0.0.5, fd12::1)
     *
     * @throws InvalidArgumentException When the range is not an IP address with an optional prefix length
     */
    public function __construct(private readonly string $range)
    {
        $parts = \explode('/', $range, 2);
        $binary = $this->toBinary($parts[0]);

        if ($binary === null) {
            throw new InvalidArgumentException("Invalid subnet: {$range}");
        }

        $bits = \strlen($binary) * 8;
        $length = $parts[1] ?? (string) $bits;

        if (!\ctype_digit($length) || (int) $length > $bits) {
            throw new InvalidArgumentException("Invalid subnet: {$range}");
        }

        $this->prefix = (int) $length;
        $this->network = $this->mask($binary, $this->prefix);
    }

    public function getDescription(): string
    {
        return "Value must be an IP address in {$this->range}.";
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }

    public function isValid(mixed $value): bool
    {
        if (!\is_string($value)) {
            return false;
        }

        $binary = $this->toBinary($value);

        if ($binary === null || \strlen($binary) !== \strlen($this->network)) {
            return false;
        }

        return $this->mask($binary, $this->prefix) === $this->network;
    }

    private function toBinary(string $address): ?string
    {
        if (\filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $binary = \inet_pton($address);

        return $binary === false ? null : $binary;
    }

    private function mask(string $binary, int $prefix): string
    {
        $bytes = \intdiv($prefix, 8);
        $remainder = $prefix % 8;
        $masked = \substr($binary, 0, $bytes);

        if ($remainder > 0) {
            $masked .= \chr(\ord($binary[$bytes]) & (0xFF << (8 - $remainder)) & 0xFF);
            $bytes++;
        }

        return $masked . \str_repeat("\0", \strlen($binary) - $bytes);
    }
}
