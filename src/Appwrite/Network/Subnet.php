<?php

declare(strict_types=1);

namespace Appwrite\Network;

final readonly class Subnet
{
    private function __construct(
        private string $network,
        private int $prefix,
    ) {
    }

    public static function parse(string $value): ?self
    {
        $parts = \explode('/', $value, 2);
        $address = $parts[0];

        if (\filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $binary = \inet_pton($address);
        if ($binary === false) {
            return null;
        }

        $bits = \strlen($binary) * 8;

        if (\count($parts) === 1) {
            return new self($binary, $bits);
        }

        $length = $parts[1];
        if (!\ctype_digit($length) || (int) $length > $bits) {
            return null;
        }

        $prefix = (int) $length;

        return new self(self::mask($binary, $prefix), $prefix);
    }

    public function contains(string $address): bool
    {
        if (\filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $binary = \inet_pton($address);
        if ($binary === false || \strlen($binary) !== \strlen($this->network)) {
            return false;
        }

        return self::mask($binary, $this->prefix) === $this->network;
    }

    private static function mask(string $binary, int $prefix): string
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
