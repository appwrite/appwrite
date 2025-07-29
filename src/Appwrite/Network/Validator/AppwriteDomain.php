<?php

namespace Appwrite\Network\Validator;

use Utopia\System\System;
use Utopia\Validator\Domain as ValidatorDomain;

/**
 * AppwriteDomain
 *
 * Validate that a domain is a valid one-level subdomain of the configured
 * Appwrite sites domain (e.g., myapp.appwrite.network is valid, but
 * dev.test.appwrite.network is not).
 *
 * @package Appwrite\Network\Validator
 */
class AppwriteDomain extends ValidatorDomain
{
    protected string $suffix;

    public function __construct()
    {
        $this->suffix = System::getEnv('_APP_DOMAIN_SITES', APP_DOMAIN_SITES_SUFFIX);
    }

    /**
     * Get Description
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Value must be a valid Appwrite subdomain (one-level subdomain ending in .' . $this->suffix . ').';
    }

    /**
     * Is valid
     *
     * Validation will pass when $value is a valid one-level subdomain
     * of the configured Appwrite sites domain.
     *
     * @param  mixed $value
     * @return bool
     */
    public function isValid($value): bool
    {
        // Basic validation - check type and spaces first
        if (!is_string($value) || empty($value)) {
            return false;
        }

        // Check for spaces (before and after trimming)
        if (preg_match('/\s/', $value)) {
            return false;
        }

        // First check if it's a valid domain using parent validator
        if (!parent::isValid($value)) {
            return false;
        }

        $domain = strtolower(trim($value));

        // Check if domain ends with the correct suffix
        if (!str_ends_with($domain, '.' . $this->suffix)) {
            return false;
        }

        // Extract the subdomain part
        $subdomainPart = substr($domain, 0, -strlen('.' . $this->suffix));

        // Check for empty subdomain or leading/trailing dots
        if (empty($subdomainPart) || str_starts_with($subdomainPart, '.') || str_ends_with($subdomainPart, '.')) {
            return false;
        }

        // Check for multiple levels (sub-subdomains)
        if (strpos($subdomainPart, '.') !== false) {
            return false;
        }

        // Check for invalid characters (only lowercase letters, numbers, and hyphens)
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $subdomainPart)) {
            return false;
        }

        // Check length limit (63 characters max for subdomain)
        if (strlen($subdomainPart) > 63) {
            return false;
        }

        // Check for forbidden prefixes
        if (str_starts_with($subdomainPart, 'commit-') || str_starts_with($subdomainPart, 'branch-')) {
            return false;
        }

        return true;
    }
}
