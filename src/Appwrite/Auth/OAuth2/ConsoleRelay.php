<?php

namespace Appwrite\Auth\OAuth2;

/**
 * Detects the console's native OAuth2 success/failure relay pages.
 *
 * Native SDKs (Flutter, Apple, Android) bounce through
 * `/auth/oauth2/success|failure` so the console can deep-link back into the
 * app via `appwrite-callback-{project}://`. Those pages need `project` (and
 * on success, session `key`/`secret`) in the query string.
 *
 * After the console moved onto its own origin (`_APP_CONSOLE_URL`), the same
 * relay path on Appwrite-owned API hosts (e.g. `cloud.appwrite.io`) still
 * 301s to the console. Treat those hosts as the console relay too, while
 * never handing session params to a customer platform that merely exposes
 * the same path.
 */
class ConsoleRelay
{
    /**
     * @param array<string, mixed> $platform
     */
    public static function matches(
        string $host,
        string $path,
        string $defaultPath,
        array $platform,
        ?string $requestHostname = null,
    ): bool {
        if ($path !== $defaultPath || $host === '') {
            return false;
        }

        foreach (self::ownedHostnames($platform, $requestHostname) as $owned) {
            if ($host === $owned) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rewrite a legacy API-host relay URL onto `consoleUrl` so the browser
     * lands on the console SPA directly (and session params attach).
     *
     * @param array<string, mixed> $platform
     */
    public static function normalize(
        string $url,
        string $defaultPath,
        string $redirectBase,
        array $platform,
        ?string $requestHostname = null,
    ): string {
        if ($url === '' || $redirectBase === '') {
            return $url;
        }

        $parts = \parse_url($url);
        if ($parts === false) {
            return $url;
        }

        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';
        if (!self::matches($host, $path, $defaultPath, $platform, $requestHostname)) {
            return $url;
        }

        $consoleHostname = \parse_url($platform['consoleUrl'] ?? '', PHP_URL_HOST) ?: null;
        if ($host === $consoleHostname) {
            return $url;
        }

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';

        return \rtrim($redirectBase, '/') . $defaultPath . $query . $fragment;
    }

    /**
     * Appwrite-controlled hosts that may serve or redirect the console relay.
     * Does not include per-project platform hostnames.
     *
     * @param array<string, mixed> $platform
     * @return list<string>
     */
    public static function ownedHostnames(array $platform, ?string $requestHostname = null): array
    {
        $hosts = [
            ...($platform['hostnames'] ?? []),
            $platform['apiHostname'] ?? null,
            $platform['consoleHostname'] ?? null,
            \parse_url($platform['consoleUrl'] ?? '', PHP_URL_HOST) ?: null,
            $requestHostname,
        ];

        $owned = [];
        foreach ($hosts as $host) {
            if (!\is_string($host) || $host === '') {
                continue;
            }
            $owned[$host] = true;
        }

        return \array_keys($owned);
    }
}
