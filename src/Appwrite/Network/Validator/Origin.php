<?php

namespace Appwrite\Network\Validator;

use Utopia\Validator\Host;

/**
 * Origin
 *
 * Validate that a URI is allowed as a origin
 *
 * @package Utopia\Validator
 */
class Origin extends Host
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
         $messages = [];

         if (!empty($this->hostnames)) {
             $messages[] = 'URL host must be one of added Web platforms: ' . \implode(', ', $this->hostnames);
         }

         if (!empty($this->schemes)) {
             $messages[] = 'URL scheme must be one of: ' . \implode(', ', $this->schemes);
         }

         return empty($messages)
             ? 'URL host and scheme constraints not configured'
             : \implode(' or ', $messages);
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
        $parsed = $this->parseUrl($value);

        if (!empty($parsed['scheme']) && \in_array($parsed['scheme'], $this->schemes)) {
            return true;
        }

        if (!in_array($parsed['scheme'], ['http', 'https'])) {
            return false;
        }

        if (!empty($parsed['host']) && \in_array($parsed['host'], $this->hostnames)) {
            return parent::isValid($value);
        }

        return false;
    }

    private function parseUrl($value): array
    {
        $parsed = \parse_url($value);

        $parsed['scheme'] = $parsed['scheme'] ??
            preg_match('/^([a-zA-Z][a-zA-Z0-9+.-]*):\/\//', $value, $matches) ? $matches[1] : null;

        return $parsed;
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
