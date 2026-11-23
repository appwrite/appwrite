<?php

namespace Utopia\Domains\Validator;

use Utopia\Domains\Domain;

/**
 *
 * Validate that a domain is a public apex domain
 */
class ApexDomain extends PublicDomain
{
    /**
     * Returns error message when validation fails
     */
    #[\Override]
    public function getDescription(): string
    {
        return 'Value must be a public apex domain';
    }

    /**
     * Validate that the domain is a public apex domain
     *
     * @param  mixed $value
     */
    #[\Override]
    public function isValid($value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $value = parse_url((string) $value, PHP_URL_HOST);
        }

        if (!parent::isValid($value)) {
            return false;
        }

        $domain = new Domain($value);
        return $domain->getApex() === $value;
    }
}
