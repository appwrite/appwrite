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
     * @param array $whitelist
     */
    public function __construct(array $whitelist)
    {
        parent::__construct($whitelist);
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
        // We need to check for this case separately
        if (preg_match('/^([a-z][a-z0-9+\.-]*):\/+$/i', $value, $matches)) {
            $scheme = strtolower($matches[1]);
            return $scheme !== 'javascript';
        }

        // `parse_url` returns false for invalid URLs
        $url = \parse_url($value);
        if ($url === false || !isset($url["scheme"])) {
            return false;
        }

        // If scheme is javascript, it's an XSS vector
        $scheme = strtolower($url["scheme"]);
        if ($scheme === "javascript") {
            return false;
        }

        // If scheme is not http or https, we don't need to check the host
        // Allow deep links to other user apps.
        if (!\in_array($scheme, ["http", "https"])) {
            return true;
        }

        return parent::isValid($value);
    }
}
