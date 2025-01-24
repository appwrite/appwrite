<?php

namespace Appwrite\Network\Validator;

use Utopia\Validator\Host;

/**
 * Redirect
 *
 * Validate that URL has an allowed host for redirect
 *
 * @package Utopia\Validator
 */
class Redirect extends Host
{
    /**
     * @param array $hostnames White list of allowed hostnames
     * @param array $schemes White list of allowed schemes
     */
    public function __construct(array $hostnames, array $schemes)
    {
        $this->schemes = $schemes;
        parent::__construct($hostnames);
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
        return "HTTP or HTTPS URL host must be one of: " .
            \implode(", ", $this->whitelist);
    }

    /**
     * Is valid
     *
     * Validation will pass when $value is a valid URL and the host is allowed
     *
     * @param  mixed $value
     * @return bool
     */
    public function isValid($value): bool
    {
        // `parse_url` returns false for URL with only a scheme
        // We need to handle parsing the scheme manually
        if (preg_match('/^([a-z][a-z0-9+\.-]*):\/+$/i', $value, $matches)) {
            $scheme = strtolower($matches[1]);
        }

        // If the scheme is not http or https, check the hostname
        if (\in_array($scheme, ["http", "https"])) {
            return parent::isValid($value);
        }

        // Otherwise, check the scheme whitelist
        return \in_array($scheme, $this->schemes);
    }
}
