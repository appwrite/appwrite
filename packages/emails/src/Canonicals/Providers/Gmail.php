<?php

namespace Utopia\Emails\Canonicals\Providers;

use Utopia\Emails\Canonicals\Provider;

/**
 * Gmail
 *
 * Handles Gmail and Googlemail email normalization based on validator.js rules
 * - Removes all dots from local part
 * - Removes plus addressing (subaddress)
 * - Normalizes to gmail.com domain
 * - Converts googlemail.com to gmail.com
 */
class Gmail extends Provider
{
    private const SUPPORTED_DOMAINS = ['gmail.com', 'googlemail.com'];

    private const CANONICAL_DOMAIN = 'gmail.com';

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

        // Remove dots from local part
        $normalizedLocal = $this->removeDots($normalizedLocal);

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
