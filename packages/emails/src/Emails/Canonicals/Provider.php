<?php

namespace Utopia\Emails\Canonicals;

/**
 * Abstract Email Provider
 *
 * Base class for email provider normalization
 */
abstract class Provider
{
    /**
     * Check if this provider supports the given domain
     */
    abstract public function supports(string $domain): bool;

    /**
     * Get the canonical form of the email address according to provider rules
     *
     * @param  string  $local  The local part of the email (before @)
     * @param  string  $domain  The domain part of the email (after @)
     * @return array Array with 'local' and 'domain' keys containing canonical values
     */
    abstract public function getCanonical(string $local, string $domain): array;

    /**
     * Get the canonical domain for this provider
     */
    abstract public function getCanonicalDomain(): string;

    /**
     * Get all supported domains for this provider
     */
    abstract public function getSupportedDomains(): array;

    /**
     * Remove plus addressing from local part
     */
    protected function removePlusAddressing(string $local): string
    {
        $plusPos = strpos($local, '+');
        if ($plusPos !== false && $plusPos > 0) {
            return substr($local, 0, $plusPos);
        }

        return $local;
    }

    /**
     * Remove all dots from local part
     * Can be overridden by providers for custom behavior
     */
    protected function removeDots(string $local): string
    {
        return str_replace('.', '', $local);
    }

    /**
     * Remove all hyphens from local part
     */
    protected function removeHyphens(string $local): string
    {
        return str_replace('-', '', $local);
    }

    /**
     * Remove hyphen-based subaddress (Yahoo style)
     * Removes everything after the last hyphen
     */
    protected function removeHyphenSubaddress(string $local): string
    {
        $components = explode('-', $local);

        return count($components) > 1 ? implode('-', array_slice($components, 0, -1)) : $components[0];
    }

    /**
     * Convert local part to lowercase
     */
    protected function toLowerCase(string $local): string
    {
        return strtolower($local);
    }
}
