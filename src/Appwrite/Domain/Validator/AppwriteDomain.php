<?php

namespace Appwrite\Domain\Validator;

use Utopia\System\System;
use Utopia\Validator\Domain;

class AppwriteDomain extends Domain
{
    public function getDescription(): string
    {
        return 'Value must be a valid one-level subdomain';
    }

    public function isValid($value): bool
    {
        // Reject domains with leading/trailing whitespace
        if (!is_string($value) || $value !== trim($value)) {
            return false;
        }

        if (str_starts_with($value, '.') || str_ends_with($value, '.') || str_contains($value, '..')) {
            return false;
        }

        if (!parent::isValid($value)) {
            return false;
        }

        $domain = strtolower($value);

        if ($domain === 'localhost' || $domain === 'api.localhost') {
            return false;
        }

        $parts = explode('.', $domain);
        $firstLabel = $parts[0];
        if (str_starts_with($firstLabel, 'commit-') || str_starts_with($firstLabel, 'branch-')) {
            return false;
        }

        $managedDomains = [
            System::getEnv('_APP_DOMAIN_FUNCTIONS'),
            System::getEnv('_APP_DOMAIN_SITES')
        ];

        foreach ($managedDomains as $managedDomain) {
            if (empty($managedDomain)) {
                continue;
            }

            // Block exact match
            if ($domain === $managedDomain) {
                return false;
            }

            // Validate subdomains - reject sub-subdomains
            if (str_ends_with($domain, '.' . $managedDomain)) {
                $subdomain = substr($domain, 0, -strlen('.' . $managedDomain));

                // Reject sub-subdomains (contains dots) or invalid subdomain format
                if (
                    $subdomain === '' ||
                    strpos($subdomain, '.') !== false ||
                    strlen($subdomain) > 63 ||
                    !preg_match('/^[a-z0-9-]+$/i', $subdomain) ||
                    str_starts_with($subdomain, '-') ||
                    str_ends_with($subdomain, '-')
                ) {
                    return false;
                }
            }
        }

        return true;
    }
}
