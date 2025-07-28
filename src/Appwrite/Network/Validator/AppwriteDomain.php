<?php

namespace Appwrite\Network\Validator;

use Utopia\System\System;
use Utopia\Validator;

/**
 * AppwriteDomain
 *
 * Validate that a domain is a valid one-level subdomain of the configured
 * Appwrite sites domain (e.g., myapp.appwrite.network is valid, but
 * dev.test.appwrite.network is not).
 *
 * @package Appwrite\Network\Validator
 */
class AppwriteDomain extends Validator
{
    protected string $suffix;

    public function __construct()
    {
        $this->suffix = System::getEnv('_APP_DOMAIN_SITES', 'appwrite.network');
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
        return 'Value must be a valid one-level subdomain of ' . $this->suffix . ' (e.g., myapp.' . $this->suffix . ')';
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
        // Must be a string
        if (!is_string($value)) {
            return false;
        }

        // Must not be empty
        if (empty($value)) {
            return false;
        }

        // Must not contain spaces or other invalid characters
        if (preg_match('/\s/', $value)) {
            return false;
        }

        // Convert to lowercase for consistent validation
        $domain = strtolower(trim($value));

        // Must end with the configured suffix
        if (!str_ends_with($domain, '.' . $this->suffix)) {
            return false;
        }

        // Remove the suffix to get the subdomain part
        $subdomainPart = substr($domain, 0, -strlen('.' . $this->suffix));

        // Must not be empty after removing suffix
        if (empty($subdomainPart)) {
            return false;
        }

        // Must not start or end with a dot
        if (str_starts_with($subdomainPart, '.') || str_ends_with($subdomainPart, '.')) {
            return false;
        }

        // Must not contain any dots (only one-level subdomains allowed)
        if (strpos($subdomainPart, '.') !== false) {
            return false;
        }

        // Validate the subdomain format using basic domain rules
        // Must contain only alphanumeric characters and hyphens
        // Must not start or end with hyphen
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $subdomainPart)) {
            return false;
        }

        // Must not be longer than 63 characters (DNS limitation)
        if (strlen($subdomainPart) > 63) {
            return false;
        }

        return true;
    }

    /**
     * Is array
     *
     * Function will return false as this validator validates strings.
     *
     * @return bool
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     *
     * @return string
     */
    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}
