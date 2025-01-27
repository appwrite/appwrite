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
    protected array $schemes = [];

    /**
     * @param array $hostnames White list of allowed hostnames
     * @param array $schemes White list of allowed schemes
     */
    public function __construct(array $hostnames = [], array $schemes = [])
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
        if (empty($value)) {
            return false;
        }

        $url = parse_url($value);
        if (!isset($url["scheme"])) {
            // `parse_url` does not URLs without hostname.
            // We handle this scenario with regex
            if (!preg_match('/^([a-z][a-z0-9+\.-]*):\/+$/i', $value, $matches)) {
                return false;
            }

            $scheme = strtolower($matches[1]);
        } else {
            $scheme = strtolower($url["scheme"]);
        }

        // These are dangerous schemes
        if (in_array($scheme, ["javascript", "data", "blob", "file"])) {
            return false;
        }

        // Check hostname if scheme is http or https
        if (in_array($scheme, ["http", "https"])) {
            return parent::isValid($value);
        }

        // Check scheme against white list
        return in_array($scheme, $this->schemes);
    }
}
