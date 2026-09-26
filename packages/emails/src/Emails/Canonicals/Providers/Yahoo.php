<?php

namespace Utopia\Emails\Canonicals\Providers;

use Utopia\Emails\Canonicals\Provider;

/**
 * Yahoo
 *
 * Handles Yahoo email normalization based on validator.js rules
 * - Removes hyphen-based subaddress (everything after last -)
 * - Preserves dots in local part
 * - Normalizes to yahoo.com domain
 */
class Yahoo extends Provider
{
    private const SUPPORTED_DOMAINS = [
        'yahoo.com', 'yahoo.co.uk', 'yahoo.ca', 'yahoo.de', 'yahoo.fr', 'yahoo.in', 'yahoo.it',
        'ymail.com', 'rocketmail.com',
    ];

    private const CANONICAL_DOMAIN = 'yahoo.com';

    public function supports(string $domain): bool
    {
        return in_array($domain, self::SUPPORTED_DOMAINS, true);
    }

    public function getCanonical(string $local, string $domain): array
    {
        // Convert to lowercase
        $normalizedLocal = $this->toLowerCase($local);

        // Remove hyphen-based subaddress (everything after last -)
        $normalizedLocal = $this->removeHyphenSubaddress($normalizedLocal);

        // Ensure local part is not empty after normalization
        if (empty($normalizedLocal)) {
            throw new \InvalidArgumentException('Email local part cannot be empty after normalization');
        }

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
