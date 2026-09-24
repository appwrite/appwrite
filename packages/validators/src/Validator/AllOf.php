<?php

namespace Utopia\Validator;

use Utopia\Validator;

/**
 * Ensure all of the validators from a list passed the check
 *
 * @package Utopia\Validator
 */
class AllOf extends Validator
{
    protected ?Validator $failedRule = null;

    /**
     * @param array<Validator> $validators
     */
    public function __construct(protected array $validators, protected string $type = self::TYPE_MIXED) {}

    /**
     * Get Description
     *
     * Returns validator description
     */
    public function getDescription(): string
    {
        if (!(\is_null($this->failedRule))) {
            return $this->failedRule->getDescription();
        }

        return $this->validators[0]->getDescription();
    }

    /**
     * Is valid
     *
     * Validation will pass when all rules are valid if only one of the rules is invalid validation will fail.
     */
    public function isValid(mixed $value): bool
    {
        foreach ($this->validators as $rule) {
            $valid = $rule->isValid($value);

            if (!$valid) {
                $this->failedRule = $rule;
                return false;
            }
        }

        return true;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
     */
    public function isArray(): bool
    {
        return true;
    }
}
