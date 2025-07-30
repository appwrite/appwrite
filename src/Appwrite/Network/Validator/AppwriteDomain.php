<?php

namespace Appwrite\Network\Validator;

use Utopia\System\System;
use Utopia\Validator\Domain as ValidatorDomain;

class AppwriteDomain extends ValidatorDomain
{
    public function getDescription(): string
    {
        $sitesDomain = System::getEnv('_APP_DOMAIN_SITES', defined('APP_DOMAIN_SITES_SUFFIX') ? APP_DOMAIN_SITES_SUFFIX : 'appwrite.network');
        return "Value must be a valid domain name. For Appwrite-managed domains (e.g., {$sitesDomain}, functions.localhost, appwrite.network), only one-level subdomain is allowed.";
    }

    public function isValid($value): bool
    {
        // 1. Must be a non-empty string
        if (!is_string($value) || $value === '') {
            return false;
        }

        // 2. No leading or trailing spaces
        if ($value !== trim($value)) {
            return false;
        }

        $domain = strtolower($value);

        // 3. No spaces
        if (preg_match('/\\s/', $domain)) {
            return false;
        }

        // 4. No underscores
        if (strpos($domain, '_') !== false) {
            return false;
        }

        // 5. Handle URLs with protocols (let Domain class handle extraction)
        if (preg_match('/^[a-z]+:\/\//', $domain)) {
            // Extract hostname from URL for validation
            $parsed = parse_url($domain);
            if ($parsed === false || !isset($parsed['host'])) {
                return false;
            }
            $domain = $parsed['host'];
        }

        // 6. Only a-z, 0-9, hyphen, dot
        if (!preg_match('/^[a-z0-9.-]+$/', $domain)) {
            return false;
        }

        // 7. No leading/trailing/consecutive dots
        if (str_starts_with($domain, '.') || str_ends_with($domain, '.') || strpos($domain, '..') !== false) {
            return false;
        }

        // 8. Must have at least one dot, and non-empty subdomain before first dot
        $parts = explode('.', $domain);
        if (count($parts) < 2 || empty($parts[0])) {
            return false;
        }

        // 9. No empty labels, no leading/trailing hyphens, each label ≤ 63 chars, TLD ≥ 2 chars
        foreach ($parts as $i => $label) {
            if ($label === '' || strlen($label) > 63) {
                return false;
            }
            if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label)) {
                return false;
            }
        }
        $tld = end($parts);
        if (strlen($tld) < 2) {
            return false;
        }

        // 10. Global forbidden prefixes check (commit-, branch-)
        $firstLabel = $parts[0];
        $firstLabelLower = strtolower($firstLabel);
        if (str_starts_with($firstLabelLower, 'commit-') || str_starts_with($firstLabelLower, 'branch-')) {
            return false;
        }

        // 11. Appwrite-managed domains: only single-level subdomains, no forbidden prefixes
        $functionsDomain = System::getEnv('_APP_DOMAIN_FUNCTIONS', 'functions.localhost');
        $sitesDomain = System::getEnv('_APP_DOMAIN_SITES', defined('APP_DOMAIN_SITES_SUFFIX') ? APP_DOMAIN_SITES_SUFFIX : 'appwrite.network');
        $appwriteDomain = defined('APP_DOMAIN_SITES_SUFFIX') ? APP_DOMAIN_SITES_SUFFIX : 'appwrite.network';

        foreach ([$functionsDomain, $sitesDomain, $appwriteDomain] as $managedDomain) {
            if (!empty($managedDomain)) {
                // Check if domain is exactly the managed domain (should be rejected)
                if ($domain === $managedDomain) {
                    return false;
                }

                // Check if domain ends with the managed domain (subdomain case)
                if (str_ends_with($domain, '.' . $managedDomain)) {
                    $subdomain = substr($domain, 0, -strlen('.' . $managedDomain));
                    // Must be non-empty, no dot (single-level), valid chars, ≤ 63 chars, no leading/trailing hyphens, no forbidden prefixes
                    if (
                        $subdomain === '' ||
                        strpos($subdomain, '.') !== false ||
                        strlen($subdomain) > 63 ||
                        !preg_match('/^[a-z0-9-]+$/', $subdomain) ||
                        str_starts_with($subdomain, '-') ||
                        str_ends_with($subdomain, '-')
                    ) {
                        return false;
                    }
                    $subdomainLower = strtolower($subdomain);
                    if (str_starts_with($subdomainLower, 'commit-') || str_starts_with($subdomainLower, 'branch-')) {
                        return false;
                    }
                    return true;
                }
            }
        }

        // 12. RFC domain validation (parent)
        if (!parent::isValid($value)) {
            return false;
        }

        // 13. All other domains: already validated above
        return true;
    }

    public function getType(): string
    {
        return 'string';
    }

    public function isArray(): bool
    {
        return false;
    }
}
