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
        $schemes = '';
        if (!empty($this->schemes)) {
            $schemes = "URL scheme must be one of the following: " . implode(", ", array_map(function ($scheme) {
                return "`$scheme`://";
            }, $this->schemes));
        }

        $hostnames = '';
        if (!empty($this->hostnames)) {
            $hostnames = "URL hostname must be one of the following: " . implode(", ", array_map(function ($hostname) {
                return "http://`$hostname`";
            }, $this->hostnames));
        }

        return $schemes . ($schemes && $hostnames ? " or " : "") . $hostnames;
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

        // Then check for scheme
        $scheme = '';
        if (preg_match('/^([a-z][a-z0-9+\.-]*):\/+/i', $value, $matches)) {
            $scheme = strtolower($matches[1]);
        }

        // These are dangerous schemes, may expose XSS vulnerabilities
        if (in_array($scheme, ["javascript", "data", "blob", "file"])) {
            return false;
        }

        // When the scheme is in the allowed list, the URL is valid.
        if (!empty($this->schemes) && in_array($scheme, $this->schemes)) {
            return true;
        }

        return parent::isValid($value);
    }
}
