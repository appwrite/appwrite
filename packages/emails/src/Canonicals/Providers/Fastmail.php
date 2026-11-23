<?php

namespace Utopia\Emails\Canonicals\Providers;

use Utopia\Emails\Canonicals\Provider;

/**
 * Fastmail
 *
 * Handles Fastmail email normalization
 * - Preserves all characters in local part (no subaddress or dot removal)
 * - Normalizes to fastmail.com domain
 */
class Fastmail extends Provider
{
    private const SUPPORTED_DOMAINS = ['fastmail.com', 'fastmail.fm'];

    private const CANONICAL_DOMAIN = 'fastmail.com';

    public function supports(string $domain): bool
    {
        return in_array($domain, self::SUPPORTED_DOMAINS, true);
    }

    public function getCanonical(string $local, string $domain): array
    {
        // Convert to lowercase
        $normalizedLocal = $this->toLowerCase($local);

        // Fastmail doesn't remove subaddresses or dots
        // Just normalize case and domain

        return [
            'local' => $normalizedLocal,
            'domain' => self::CANONICAL_DOMAIN,
        ];
    }

    public function getCanonicalDomain(): string
    {
        return self::CANONICAL_DOMAIN;
    }

    public function getSupportedDomains(): array
    {
        return self::SUPPORTED_DOMAINS;
    }
}
