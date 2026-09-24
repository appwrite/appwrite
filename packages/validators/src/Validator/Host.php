<?php

declare(strict_types=1);

namespace Utopia\Validator;

use Utopia\Validator;

/**
 * Host
 *
 * Validate that a host is allowed from given whitelisted hosts list
 *
 * @package Utopia\Validator
 */
class Host extends Validator
{
    public function __construct(protected array $whitelist) {}

    /**
     * Get Description
     *
     * Returns validator description
     */
    public function getDescription(): string
    {
        return 'URL host must be one of: ' . implode(', ', $this->whitelist);
    }

    /**
     * Is valid
     *
     * Validation will pass when $value starts with one of the given hosts
     *
     * @param  mixed $value
     */
    public function isValid($value): bool
    {
        $urlValidator = new URL();

        if (!$urlValidator->isValid($value)) {
            return false;
        }

        $hostnameValidator = new Hostname($this->whitelist);

        return $hostnameValidator->isValid(parse_url((string) $value, PHP_URL_HOST));
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
}
