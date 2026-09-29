<?php

namespace Appwrite\Auth\Passkey\Validator;

use Utopia\Validator;

/**
 * Web origin allowed to run ceremonies for a relying party: HTTPS on the RP ID or one of its subdomains,
 * with no path, query, fragment or credentials. HTTP is only allowed for `localhost`.
 */
class Origin extends Validator
{
    private const array DEFAULT_PORTS = [
        'https' => 443,
        'http' => 80,
    ];

    public function __construct(private string $rpId)
    {
    }

    public function getDescription(): string
    {
        return 'Origin must be an HTTPS URL without a path, such as "https://' . $this->rpId . '", on "' . $this->rpId . '" or one of its subdomains. HTTP is only allowed when the relying party ID is "localhost".';
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }

    public function isValid($value): bool
    {
        return \is_string($value) && $this->normalize($value) !== null;
    }

    /**
     * Canonical `scheme://host[:port]` form, with default ports dropped, or null when invalid.
     */
    public function normalize(string $origin): ?string
    {
        $parts = \parse_url($origin);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        if (!\in_array($parts['path'] ?? '', ['', '/'], true) || \str_ends_with($origin, '?') || \str_ends_with($origin, '#')) {
            return null;
        }

        $scheme = $parts['scheme'];
        $host = $parts['host'];
        if ($scheme !== \strtolower($scheme) || $host !== \strtolower($host)) {
            return null;
        }

        $secure = $scheme === 'https';
        $local = $scheme === 'http' && $host === Host::LOCALHOST && $this->rpId === Host::LOCALHOST;
        if (!$secure && !$local) {
            return null;
        }

        if ($host !== $this->rpId && !\str_ends_with($host, '.' . $this->rpId)) {
            return null;
        }

        $port = $parts['port'] ?? null;
        if ($port === null || $port === self::DEFAULT_PORTS[$scheme]) {
            return $scheme . '://' . $host;
        }

        return $scheme . '://' . $host . ':' . $port;
    }
}
