<?php

namespace Utopia\Emails\Canonicals\Providers;

use Utopia\Emails\Canonicals\Provider;

/**
 * iCloud
 *
 * Handles Apple iCloud email normalization based on validator.js rules
 * - Removes plus addressing (subaddress)
 * - Preserves dots in local part
 * - Normalizes to icloud.com domain
 */
class Icloud extends Provider
{
    private const SUPPORTED_DOMAINS = ['icloud.com', 'me.com', 'mac.com'];

    private const CANONICAL_DOMAIN = 'icloud.com';

    public function supports(string $domain): bool
    {
        return in_array($domain, self::SUPPORTED_DOMAINS, true);
    }

    public function getCanonical(string $local, string $domain): array
    {
        // Convert to lowercase
        $normalizedLocal = $this->toLowerCase($local);

        // Remove plus addressing (subaddress) - everything after +
        $normalizedLocal = $this->removePlusAddressing($normalizedLocal);

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
