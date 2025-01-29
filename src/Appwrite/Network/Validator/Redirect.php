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
     * @param array<string> $hostnames Allow list of allowed hostnames
     * @param array<string> $schemes Allow list of allowed schemes
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
        $hostnames = array_map(function ($hostname) {
            return "http://`$hostname`";
        }, $this->hostnames);

        return "URL scheme must be one of the following: " .
            \implode(", ", $this->schemes) .
            " or URL hostname must be one of the following: " .
            \implode(", ", $hostnames);
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
        if (empty($value) || !\is_string($value)) {
            return false;
        }

        // `\parse_url` returns false when the URL contains a scheme without a hostname.
        // In this case, we use a regex to reliably extract the scheme.
        $url = \parse_url($value);
        if (
            !isset($url["scheme"]) &&
            !preg_match('/^([a-z][a-z0-9+\.-]*):\/+$/i', $value, $matches)
        ) {
            return false;
        }
        $scheme = $url["scheme"] ?? $matches[1];
        if (empty($scheme)) {
            return false;
        }
        $scheme = strtolower($scheme);

        // These are dangerous schemes, may expose XSS vulnerabilities
        if (in_array($scheme, ["javascript", "data", "blob", "file"])) {
            return false;
        }

        // When the scheme is HTTP or HTTPS, use the hostname validator.
        if (in_array($scheme, ["http", "https"])) {
            return parent::isValid($value);
        }

        // Otherwise, check if the scheme is allowed.
        return in_array($scheme, $this->schemes);
    }
}
