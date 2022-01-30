<?php

namespace Appwrite\Network\Validator;

use Utopia\Validator;
use function PHPUnit\Framework\containsEqual;

/**
 * Host
 *
 * Validate that a host is allowed from given whitelisted hosts list
 *
 * @package Utopia\Validator
 */
class Host extends Validator
{
    protected $whitelist = [];

    /**
     * @param array $whitelist
     */
    public function __construct(array $whitelist)
    {
        $this->whitelist = $whitelist;
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
        return 'URL host must be one of: ' . \implode(', ', $this->whitelist);
    }

    /**
     * Is valid
     *
     * Validation will pass when $value starts with one of the given hosts
     *
     * @param  mixed $value
     * @return bool
     */
    public function isValid($value): bool
    {
        $urlValidator = new URL();

        // Check if value is valid URL
        if (!$urlValidator->isValid($value)) {
            return false;
        }

        // Extract hostname from value
        $valueHostname = \parse_url($value, PHP_URL_HOST);

        // Loop through all allowed hostnames until match is found
        foreach ($this->whitelist as $allowedHostname) {
            // If exact math; allow
            if($valueHostname === $allowedHostname) {
                return true;
            }

            // If wildcard symbol used
            if(\str_contains($allowedHostname, '*')) {
                // Split hostnames into sections (subdomains)
                $allowedSections = \explode('.', $allowedHostname);
                $valueSections = \explode('.', $valueHostname);

                // Only if amount of sections matches
                if(\count($allowedSections) === \count($valueSections)) {
                    $matchesAmount = 0;

                    // Loop through all sections
                    for ($sectionIndex = 0; $sectionIndex < \count($allowedSections); $sectionIndex++) {
                        $allowedSection = $allowedSections[$sectionIndex];

                        // If section matches, or wildcard symbol is used, increment match count
                        if($allowedSection === '*' || $allowedSection === $valueSections[$sectionIndex]) {
                            $matchesAmount++;
                        } else {
                            // If one fails, the whole check always fails; we can skip iterations
                            break;
                        }
                    }

                    // If every section matched; allow
                    if($matchesAmount === \count($allowedSections)) {
                        return true;
                    }
                }
            }
        }

        // If finished loop above without result, match is not found
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
