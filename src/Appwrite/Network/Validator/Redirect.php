<?php

namespace Appwrite\Network\Validator;

use Utopia\Validator\Host;
use Utopia\CLI\Console;

/**
 * Redirect
 *
 * Validate that a URI is allowed as a redirect
 *
 * @package Utopia\Validator
 */
class Redirect extends Host
{
    protected $hostnames = [];
    protected $schemes = [];

    /**
     * @param array $hostnames
     * @param array $schemes
     */
    public function __construct(array $hostnames = [], array $schemes = [])
    {
        $this->hostnames = $hostnames;
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
        return 'URL host must be one of: ' . \implode(', ', $this->hostnames) . ' or URL scheme must be one of: ' . \implode(', ', $this->schemes);
    }

    /**
     * Is valid
     *
     * Validation will pass when $value matches the given hostnames or schemes.
     *
     * @param  mixed $value
     * @return bool
     */
    public function isValid($value): bool
    {
        if (empty($value)) {
            return false;
        }
        $parsed = \parse_url($value);

        $scheme = $parsed['scheme'] ?? '';
        if (!empty($scheme) && \in_array($scheme, $this->schemes)) {
            return true;
        }

        $hostname = $parsed['host'] ?? '';
        if (!empty($hostname) && \in_array($hostname, $this->hostnames) && in_array($scheme, ['http', 'https'])) {
            return parent::isValid($value);
        }

        // `parse_url` couldn't handle the URL, try extracting scheme with regex
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.-]*):\/\//', $value, $matches)) {
            $scheme = $matches[1];
            if (\in_array($scheme, $this->schemes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
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
