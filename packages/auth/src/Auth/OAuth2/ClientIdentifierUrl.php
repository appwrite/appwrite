<?php

declare(strict_types=1);

namespace Utopia\Auth\OAuth2;

/**
 * Client Identifier URL from the OAuth Client ID Metadata Document specification.
 *
 * The original URL is retained because client identifiers use simple string
 * comparison. Semantically equivalent URLs are different client identifiers.
 */
class ClientIdentifierUrl
{
    private function __construct(
        private readonly string $value,
        private readonly string $host,
    ) {}

    /**
     * Detect values which should be handled as Client Identifier URLs.
     *
     * This intentionally recognizes insecure HTTP candidates too. A production
     * authorization server should report them as invalid metadata rather than
     * accidentally treating them as opaque, pre-registered client IDs.
     */
    public static function isCandidate(string $value): bool
    {
        $value = strtolower($value);

        return str_starts_with($value, 'https://') || str_starts_with($value, 'http://');
    }

    /**
     * Parse and validate a Client Identifier URL.
     *
     * HTTP is only intended for an authorization server's explicitly isolated
     * development environment. Production callers must keep $allowHttp false.
     *
     * @throws InvalidClientMetadataException
     */
    public static function fromString(string $value, bool $allowHttp = false): self
    {
        $parts = parse_url($value);

        if (!\is_array($parts) || filter_var($value, \FILTER_VALIDATE_URL) === false) {
            throw new InvalidClientMetadataException('Client Identifier URL is malformed.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https' && (!$allowHttp || $scheme !== 'http')) {
            throw new InvalidClientMetadataException('Client Identifier URL must use the https scheme.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidClientMetadataException('Client Identifier URL must not contain a userinfo component.');
        }

        if (isset($parts['fragment'])) {
            throw new InvalidClientMetadataException('Client Identifier URL must not contain a fragment component.');
        }

        $host = (string) ($parts['host'] ?? '');
        $path = (string) ($parts['path'] ?? '');
        if ($host === '' || $path === '') {
            throw new InvalidClientMetadataException('Client Identifier URL must contain a host and a path component.');
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidClientMetadataException('Client Identifier URL must not contain dot path segments.');
            }
        }

        return new self($value, $host);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function host(): string
    {
        return $this->host;
    }
}
