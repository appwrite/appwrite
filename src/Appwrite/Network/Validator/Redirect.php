<?php
namespace Appwrite\Network\Validator;
use Utopia\Validator;

/**
 * Redirect
 *
 * Validate that a URI is allowed as a redirect
 *
 * @package Utopia\Validator
 */
class Redirect extends Validator
{
    protected $hostnames = [];
    protected $schemes = [];

    /**
     * @param array $hostnames
     * @param array $schemes
     */
    public function __construct(array $hostnames, array $schemes)
    {
        $this->hostnames = $hostnames;
        $this->schemes = $schemes;
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
        // Try parsing the URL
        $parsed = \parse_url($value);

        // Check hostname if it exists
        $hostname = $parsed['host'] ?? '';
        if (!empty($hostname) && \in_array($hostname, $this->hostnames)) {
            return true;
        }

        // Check scheme if it exists
        $scheme = $parsed['scheme'] ?? '';
        if (!empty($scheme) && \in_array($scheme, $this->schemes)) {
            return true;
        }

        // For URLs that parse_url couldn't handle, try extracting scheme with regex
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
