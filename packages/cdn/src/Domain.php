<?php

declare(strict_types=1);

namespace Utopia\Cdn;

final class Domain
{
    /**
     * Normalize a domain to its canonical form.
     *
     * DNS labels are case-insensitive (RFC 4343), so case carries no meaning
     * and is folded rather than rejected. Callers must use the returned value:
     * it is what providers and equality checks are expected to see.
     */
    public static function validate(string $domain): string
    {
        $domain = strtolower($domain);

        if ($domain === '' || filter_var($domain, \FILTER_VALIDATE_DOMAIN, \FILTER_FLAG_HOSTNAME) === false) {
            throw new \InvalidArgumentException('Domain must be a hostname without a scheme, port, path, or trailing slash.');
        }

        return $domain;
    }

    /**
     * Cache paths are joined onto provider API routes (Fastly purges at
     * `/purge/{domain}{path}`), and HTTP clients resolve `.` and `..` segments
     * before sending, so such a segment would move the request off that route.
     * No cached URL contains one, so they are refused, percent-encoded or not.
     *
     * @param array<int, mixed> $paths
     * @return array<int, string>
     */
    public static function validatePaths(array $paths): array
    {
        foreach ($paths as $path) {
            if (!\is_string($path) || !str_starts_with($path, '/')) {
                throw new \InvalidArgumentException('Every cache path must be a string beginning with "/".');
            }

            if (self::hasDotSegment($path)) {
                throw new \InvalidArgumentException('Cache paths must not contain "." or ".." segments.');
            }
        }

        return $paths;
    }

    /**
     * Whether the path part, before any query or fragment, has a `.` or `..` segment.
     */
    private static function hasDotSegment(string $path): bool
    {
        $path = substr($path, 0, strcspn($path, '?#'));

        foreach (explode('/', $path) as $segment) {
            $segment = str_ireplace('%2e', '.', $segment);
            if ($segment === '.' || $segment === '..') {
                return true;
            }
        }

        return false;
    }
}
