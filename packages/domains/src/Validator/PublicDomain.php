<?php

namespace Utopia\Domains\Validator;

use Utopia\Domains\Domain;
use Utopia\Validator;

/**
 * PublicDomain
 *
 * Validate that a domain is a public domain
 */
class PublicDomain extends Validator
{
    /**
     * @var array
     */
    protected static $allowedDomains = [];
    /**
     * Get Description
     *
     * Returns validator description
     */
    public function getDescription(): string
    {
        return 'Value must be a public domain';
    }

    /**
     * Is valid
     *
     * Validation will pass when $value is either a known domain or in the list of allowed domains
     *
     * @param  mixed $value
     */
    public function isValid($value): bool
    {
        // Extract domain from URL if provided
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $value = parse_url((string) $value, PHP_URL_HOST);
        }

        $domain = new Domain($value);
        if ($domain->isKnown()) {
            return true;
        }
        return \in_array($domain->get(), self::$allowedDomains);
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     */
    public function getType(): string
    {
        return self::TYPE_STRING;
    }

    /**
     * Allow domains
     *
     * Add domains to the allowed domains array
     */
    public static function allow(array $domains): void
    {
        self::$allowedDomains = array_merge(self::$allowedDomains, $domains);
    }
}
