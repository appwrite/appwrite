<?php

namespace Utopia\Emails\Canonicals\Providers;

use Utopia\Emails\Canonicals\Provider;

/**
 * Walla
 *
 * Handles Walla email normalization
 * - Supports both walla.co.il and walla.com domains
 * - Normalizes to walla.co.il domain
 * - Preserves all characters in local part (no plus addressing or dot removal)
 */
class Walla extends Provider
{
    private const SUPPORTED_DOMAINS = ['walla.co.il', 'walla.com'];

    private const CANONICAL_DOMAIN = 'walla.co.il';

    public function supports(string $domain): bool
    {
        return in_array($domain, self::SUPPORTED_DOMAINS, true);
    }

    public function getCanonical(string $local, string $domain): array
    {
        // Convert to lowercase
        $normalizedLocal = $this->toLowerCase($local);

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
