<?php

namespace Appwrite\Network;

use Utopia\Domains\Domain;
use Utopia\Validator\IP;

/**
 * Parses comma-separated DNS verification target environment variables.
 */
final class DomainTargets
{
    /**
     * Split a comma-separated configuration value into trimmed non-empty entries.
     *
     * @return list<string>
     */
    public static function entries(string $value): array
    {
        $entries = [];

        foreach (\explode(',', $value) as $part) {
            $part = \trim($part);
            if ($part !== '') {
                $entries[] = $part;
            }
        }

        return $entries;
    }

    /**
     * Extract normalized hostnames from bare hosts, host:port values, or URLs.
     *
     * @return list<string>
     */
    public static function hosts(string $value): array
    {
        $hosts = [];

        foreach (self::entries($value) as $entry) {
            $host = self::extractHost($entry);
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    /**
     * Parse configured CNAME targets into Domain instances, skipping invalid values.
     *
     * @return list<Domain>
     */
    public static function domains(string $value): array
    {
        $domains = [];

        foreach (self::hosts($value) as $host) {
            try {
                $domains[] = new Domain($host);
            } catch (\Throwable) {
                // Skip malformed environment values instead of crashing callers with HTTP 500.
            }
        }

        return $domains;
    }

    /**
     * Parse valid IPv4 A record targets.
     *
     * @return list<string>
     */
    public static function ipv4(string $value): array
    {
        return self::validIps($value, IP::V4);
    }

    /**
     * Parse valid IPv6 AAAA record targets.
     *
     * @return list<string>
     */
    public static function ipv6(string $value): array
    {
        return self::validIps($value, IP::V6);
    }

    /**
     * @return list<string>
     */
    private static function validIps(string $value, string $type): array
    {
        $ips = [];
        $validator = new IP($type);

        foreach (self::entries($value) as $entry) {
            if ($validator->isValid($entry)) {
                $ips[] = $entry;
            }
        }

        return $ips;
    }

    private static function extractHost(string $entry): string
    {
        $url = \str_contains($entry, '://') ? $entry : 'http://' . $entry;
        $host = \parse_url($url, PHP_URL_HOST);

        if (!\is_string($host) || $host === '' || \strpbrk($host, " \t\r\n") !== false) {
            return '';
        }

        return \mb_strtolower($host);
    }
}
