<?php

declare(strict_types=1);

namespace Utopia\Auth\OAuth2;

/**
 * Registered redirect URIs for an OAuth2 client (RFC 6749 Section 3.1.2).
 *
 * Matching is exact string comparison, with one opt-in carve-out: when both
 * the registered and the presented URI are http loopback URIs, the port is
 * ignored per RFC 8252 Section 7.3. Native and CLI clients bind an ephemeral
 * port per run and cannot register it ahead of time.
 *
 * RFC 8252 scopes the carve-out to native apps, which are public clients
 * (Section 8.4), so it is off by default: enable it only for public clients
 * and keep confidential clients on exact matching.
 */
class RedirectUris
{
    private const array LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /**
     * @param list<non-empty-string> $uris
     */
    private function __construct(private readonly array $uris) {}

    /**
     * Wrap a client's stored registered URIs. Non-string and empty entries
     * are ignored rather than rejected: the list is existing data, not
     * boundary input, and a malformed entry should never match anything.
     *
     * @param array<int, mixed> $uris
     */
    public static function from(array $uris): self
    {
        $filtered = [];

        foreach ($uris as $uri) {
            if (\is_string($uri) && $uri !== '') {
                $filtered[] = $uri;
            }
        }

        return new self($filtered);
    }

    /**
     * True when $presented exactly matches a registered URI, or — with
     * $allowLoopback enabled — matches a registered http loopback
     * URI on everything except the port. Enable the variance for public
     * clients only (RFC 8252 Sections 7.3 and 8.4).
     */
    public function matches(string $presented, bool $allowLoopback = false): bool
    {
        if ($presented === '') {
            return false;
        }

        if (\in_array($presented, $this->uris, true)) {
            return true;
        }

        if (!$allowLoopback) {
            return false;
        }

        $presentedParts = $this->loopbackParts($presented);

        if ($presentedParts === null) {
            return false;
        }

        foreach ($this->uris as $registered) {
            if ($presentedParts === $this->loopbackParts($registered)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<non-empty-string>
     */
    public function toArray(): array
    {
        return $this->uris;
    }

    /**
     * Parse a URI into its port-insensitive comparison parts when it is an
     * http loopback URI (RFC 8252 Section 7.3); null otherwise.
     *
     * Loopback hosts are an exact allowlist, so lookalikes such as
     * `localhost.evil.com` never qualify. URIs carrying userinfo or a
     * fragment fall back to exact matching only.
     *
     * @return array{host: string, path: string, query: string}|null
     */
    private function loopbackParts(string $uri): ?array
    {
        $parts = parse_url($uri);

        if (!\is_array($parts)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'http'
            || !\in_array($host, self::LOOPBACK_HOSTS, true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            return null;
        }

        return [
            'host' => $host,
            'path' => ($parts['path'] ?? '') === '' ? '/' : $parts['path'],
            'query' => $parts['query'] ?? '',
        ];
    }
}
