<?php

namespace Appwrite\Network;

use Utopia\System\System;

/**
 * Connection addresses that may supply client IP headers such as X-Forwarded-For.
 *
 * An empty list means no hop is trusted: use the connection address and ignore
 * client-controlled forwarding headers. Traefik / load-balancer deployments
 * must list those proxy CIDRs in `_APP_TRUSTED_PROXIES`.
 */
final class TrustedProxies
{
    /**
     * @param list<string> $cidrs
     */
    public function __construct(
        private readonly array $cidrs,
    ) {
    }

    public static function fromEnv(?string $value = null): self
    {
        $raw = $value ?? System::getEnv('_APP_TRUSTED_PROXIES', '');
        $cidrs = [];

        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $cidrs[] = $part;
            }
        }

        return new self($cidrs);
    }

    public function isEmpty(): bool
    {
        return $this->cidrs === [];
    }

    public function contains(string $ip): bool
    {
        foreach ($this->cidrs as $cidr) {
            if (self::matches($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private static function matches(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $maskBits] = explode('/', $cidr, 2);

        if ($maskBits === '' || !ctype_digit($maskBits)) {
            return false;
        }

        $maskBits = (int) $maskBits;
        $ipBinary = @inet_pton($ip);
        $subnetBinary = @inet_pton($subnet);

        if ($ipBinary === false || $subnetBinary === false) {
            return false;
        }

        $length = strlen($ipBinary);
        if ($length !== strlen($subnetBinary)) {
            return false;
        }

        $maxBits = $length * 8;
        if ($maskBits < 0 || $maskBits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($maskBits, 8);
        $remainderBits = $maskBits % 8;

        if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($subnetBinary, 0, $fullBytes)) {
            return false;
        }

        if ($remainderBits === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remainderBits)) & 0xFF);

        return (ord($ipBinary[$fullBytes]) & ord($mask)) === (ord($subnetBinary[$fullBytes]) & ord($mask));
    }
}
