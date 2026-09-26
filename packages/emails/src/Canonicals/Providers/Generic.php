<?php

namespace Utopia\Emails\Canonicals\Providers;

use Utopia\Emails\Canonicals\Provider;

/**
 * Generic
 *
 * Handles generic email normalization for unsupported providers
 * - Preserves all characters in local part (no subaddress or dot removal)
 * - Only converts to lowercase
 */
class Generic extends Provider
{
    public function supports(string $domain): bool
    {
        // Generic provider supports all domains
        return true;
    }

    public function getCanonical(string $local, string $domain): array
    {
        // Convert to lowercase
        $normalizedLocal = $this->toLowerCase($local);

        // Generic providers don't remove subaddresses or dots
        // Just normalize case

        return [
            'local' => $normalizedLocal,
            'domain' => $domain,
        ];
    }

    public function getCanonicalDomain(): string
    {
        // Generic provider doesn't have a canonical domain
        return '';
    }

    public function getSupportedDomains(): array
    {
        // Generic provider supports all domains
        return [];
    }
}
