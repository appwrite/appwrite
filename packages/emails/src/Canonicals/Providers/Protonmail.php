<?php

namespace Utopia\Emails\Canonicals\Providers;

use Utopia\Emails\Canonicals\Provider;

/**
 * ProtonMail
 *
 * Handles ProtonMail email normalization
 * - Removes plus addressing (subaddress) from local part
 * - Preserves dots in local part
 * - Does not normalize domains
 *
 * Docs: https://proton.me/support/creating-aliases#+Aliases
 */
class Protonmail extends Provider
{
    private const SUPPORTED_DOMAINS = ['protonmail.com', 'proton.me', 'pm.me', 'protonmail.ch'];

    private const CANONICAL_DOMAIN = 'protonmail.com';

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

        // protonmail.ch, protonmail.com - not subaddress, just different options during sign up
        // pm.me - technically subaddress, but costs monthly fee, and gives just +1 email. Costly already, no need to block. People get it for shorter email to type it quicker anyway
        return [
            'local' => $normalizedLocal,
            'domain' => \in_array($domain, self::SUPPORTED_DOMAINS, true) ? $domain : self::CANONICAL_DOMAIN,
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
