<?php

namespace Appwrite\Network\Validator;

use Utopia\System\System;
use Utopia\Validator;
use Utopia\Validator\Domain;

class AppwriteDomain extends Validator
{
    public function getDescription(): string
    {
        $suffix = System::getEnv('_APP_DOMAIN_SITES') ?: APP_DOMAIN_SITES;
        return "Must be a valid domain and sub-subdomains are not allowed for {$suffix}. Only one level of subdomain is permitted.";
    }

    public function isValid($value): bool
    {
        $suffix = System::getEnv('_APP_DOMAIN_SITES') ?: APP_DOMAIN_SITES;

        // For non-string values, reject them
        if (!is_string($value)) {
            return false;
        }

        // For empty strings, reject them
        if (empty($value)) {
            return false;
        }

        // Check for spaces in the domain
        if (\preg_match('/\s/', $value)) {
            return false;
        }

        // Check for leading dots
        if (\str_starts_with($value, '.')) {
            return false;
        }

        // Check for trailing dots
        if (\str_ends_with($value, '.')) {
            return false;
        }

        // Remove leading dot from suffix for comparison
        $suffixForComparison = ltrim($suffix, '.');

        // Check if domain ends with the suffix
        $domainLower = \strtolower($value);
        $suffixLower = \strtolower($suffixForComparison);

        if (!\str_ends_with($domainLower, $suffixLower)) {
            return false;
        }

        // Check that the domain has the correct structure (subdomain.suffix)
        // This prevents domains like 'notappwrite.network' from being accepted
        $expectedSuffix = '.' . $suffixLower;
        if (!\str_ends_with($domainLower, $expectedSuffix)) {
            return false;
        }

        // Extract subdomain by removing the suffix from the end
        $subdomain = \substr($domainLower, 0, -\strlen($suffixLower));
        // Remove trailing dot if present
        $subdomain = rtrim($subdomain, '.');

        // If there's no subdomain (just the root domain), it's invalid for this validator
        if (empty($subdomain)) {
            return false;
        }

        // Check if the subdomain contains dots (sub-subdomains)
        if (\str_contains($subdomain, '.')) {
            return false;
        }

        return true;
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}
