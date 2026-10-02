<?php

namespace Appwrite\Network;

/**
 * Connection addresses that may supply client IP headers such as X-Forwarded-For.
 *
 * An unset `_APP_TRUSTED_PROXIES` trusts loopback and private networks, where
 * the bundled Traefik runs. An empty value trusts no hop: the connection
 * address is used and client-controlled forwarding headers are ignored.
 */
final class TrustedProxies
{
    public const string DEFAULT = '127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16';

    /**
     * @param list<string> $cidrs
     */
    public function __construct(
        private readonly array $cidrs,
    ) {
    }

    public static function fromEnv(?string $value = null): self
    {
        $raw = $value ?? getenv('_APP_TRUSTED_PROXIES');
        if ($raw === false) {
            $raw = self::DEFAULT;
        }
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
