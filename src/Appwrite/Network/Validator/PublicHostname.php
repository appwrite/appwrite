<?php

namespace Appwrite\Network\Validator;

use Utopia\Validator;

/**
 * Validates that a hostname (or IP literal) is publicly routable.
 *
 * Resolves A and AAAA records and rejects the value if any resolved address —
 * or the literal itself, when given an IP — falls inside a private, loopback,
 * link-local, multicast, or otherwise reserved range. Used to block SSRF on
 * endpoints that fetch user-controlled URLs.
 *
 * Distinct from Utopia\Validator\Hostname, which only checks string format
 * and an optional allow-list and does not touch DNS.
 *
 * Known limitation: there is a TOCTOU window between this DNS lookup and the
 * subsequent HTTP fetch. To fully prevent DNS rebinding the caller must pin
 * curl to a verified IP via CURLOPT_RESOLVE.
 */
class PublicHostname extends Validator
{
    /**
     * IPv4 ranges PHP's FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
     * does not cover, but which still must not be reachable from a public
     * fetch endpoint.
     */
    private const PRIVATE_IPV4_CIDRS = [
        '100.64.0.0/10',        // CGNAT (RFC 6598)
        '192.0.0.0/24',         // IETF protocol assignments
        '192.0.2.0/24',         // TEST-NET-1
        '198.18.0.0/15',        // Benchmarking
        '198.51.100.0/24',      // TEST-NET-2
        '203.0.113.0/24',       // TEST-NET-3
        '224.0.0.0/4',          // Multicast
        '255.255.255.255/32',   // Broadcast
    ];

    /**
     * IPv6 ranges PHP's filter flags miss — most importantly the
     * IPv4-mapped, IPv4-translated, and 6to4 ranges that can be used to
     * smuggle private IPv4 destinations past an IPv6-only check.
     */
    private const PRIVATE_IPV6_CIDRS = [
        '::/128',               // Unspecified
        '::ffff:0:0/96',        // IPv4-mapped (e.g. ::ffff:127.0.0.1)
        '64:ff9b::/96',         // IPv4/IPv6 translation
        '64:ff9b:1::/48',       // Local-use IPv4/IPv6 translation
        '100::/64',             // Discard
        '2001::/32',            // Teredo
        '2001:db8::/32',        // Documentation
        '2002::/16',            // 6to4 (covers 2002:7f00::/24 → 127.0.0.0/8 etc.)
        'ff00::/8',             // Multicast
    ];

    private string $reason = '';

    public function getDescription(): string
    {
        return $this->reason !== ''
            ? $this->reason
            : 'Value must be a publicly routable hostname or address.';
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
        $this->reason = '';

        if (!\is_string($value) || $value === '') {
            $this->reason = 'Hostname is empty.';
            return false;
        }

        $hostname = \strtolower(\trim($value, " \t\n\r\0\x0B[]"));

        // IP literals are checked directly, no DNS round-trip.
        if (\filter_var($hostname, FILTER_VALIDATE_IP) !== false) {
            if (!self::isPublicIp($hostname)) {
                $this->reason = "Address {$hostname} is in a private or reserved range.";
                return false;
            }
            return true;
        }

        $addresses = self::resolve($hostname);

        if (empty($addresses)) {
            $this->reason = "Hostname {$hostname} does not resolve.";
            return false;
        }

        foreach ($addresses as $ip) {
            if (!self::isPublicIp($ip)) {
                $this->reason = "Hostname {$hostname} resolves to private or reserved address {$ip}.";
                return false;
            }
        }

        return true;
    }

    /**
     * Resolves a hostname to all A and AAAA records.
     *
     * @return array<string>
     */
    public static function resolve(string $hostname): array
    {
        $ipv4 = [];
        $ipv6 = [];

        $records = @\dns_get_record($hostname, DNS_A | DNS_AAAA);
        if (\is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $ipv4[] = $record['ip'];
                }

                if (!empty($record['ipv6'])) {
                    $ipv6[] = $record['ipv6'];
                }
            }
        }

        return \array_values(\array_unique([...$ipv4, ...$ipv6]));
    }

    /**
     * Returns true only if the given IP literal sits in a globally routable range.
     */
    public static function isPublicIp(string $ip): bool
    {
        $filtered = \filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($filtered === false) {
            return false;
        }

        $isIpv4 = \filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $extra = $isIpv4 ? self::PRIVATE_IPV4_CIDRS : self::PRIVATE_IPV6_CIDRS;

        foreach ($extra as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Binary CIDR match. Works for both IPv4 and IPv6 as long as the
     * IP and subnet are the same family.
     */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $maskBits] = \explode('/', $cidr, 2);
        $maskBits = (int) $maskBits;

        $ipBinary = @\inet_pton($ip);
        $subnetBinary = @\inet_pton($subnet);

        if ($ipBinary === false || $subnetBinary === false) {
            return false;
        }

        if (\strlen($ipBinary) !== \strlen($subnetBinary)) {
            return false;
        }

        $fullBytes = \intdiv($maskBits, 8);
        $remainderBits = $maskBits % 8;

        if ($fullBytes > 0 && \substr($ipBinary, 0, $fullBytes) !== \substr($subnetBinary, 0, $fullBytes)) {
            return false;
        }

        if ($remainderBits > 0) {
            $mask = \chr(0xFF << (8 - $remainderBits) & 0xFF);
            if ((\ord($ipBinary[$fullBytes]) & \ord($mask)) !== (\ord($subnetBinary[$fullBytes]) & \ord($mask))) {
                return false;
            }
        }

        return true;
    }
}
